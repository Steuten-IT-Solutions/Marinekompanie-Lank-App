<?php
/**
 * Push-Benachrichtigungen (Web Push).
 *
 * - Verschlüsselung nach RFC 8291 (aes128gcm), Absenderkennung per VAPID nach
 *   RFC 8292 (JWT mit ES256). Nur PHP-Bordmittel (OpenSSL), keine Bibliothek.
 * - Das VAPID-Schlüsselpaar entsteht beim ersten Bedarf und liegt in einer
 *   Option; der private Schlüssel wird nie ausgegeben.
 * - Ein Abo gehört zu einem Gerät (Endpoint) und einem Konto, bei Gästen ohne
 *   Anmeldung zu keinem Konto (user_id 0). Je Abo wird gespeichert, welche
 *   Ereignisse es erhält und ob Strafen mit Betrag und Grund angezeigt werden.
 *   Gäste weisen sich beim Ändern und Abbestellen mit dem auth-Geheimnis ihres
 *   Abos aus, das nur ihr Browser kennt.
 * - Termine: Kalender-Berechtigte (Anwärter, Mitglieder) erhalten Nachrichten
 *   zum Google Kalender, alle anderen zu den öffentlichen Terminen. Die
 *   öffentlichen Termine stehen auch im Google Kalender, so kommt nichts doppelt.
 * - Nachrichten landen in einer Warteschlange und werden über WP-Cron
 *   verschickt, damit z. B. das Vergeben einer Strafe nicht auf die Push-Dienste
 *   wartet. Abos, die der Push-Dienst nicht mehr kennt (404/410), werden gelöscht.
 */

defined( 'ABSPATH' ) || exit;

class MKL_Push {

	const VAPID_OPTION    = 'mkl_push_vapid';
	const CRON_HOOK       = 'mkl_push_send';
	const WATCH_HOOK      = 'mkl_push_watch';
	const WATCH_SCHEDULE  = 'mkl_push_15min';
	const SNAPSHOT_OPTION = 'mkl_push_calendar_snapshot';
	const REMINDER_OPTION = 'mkl_push_reminder_date';
	/** Einstellungen der Kalenderquelle (Einstellungen → Kompanie-App). */
	const CALENDAR_SOURCE_OPTIONS = array( 'mkl_ics_url', 'mkl_calendar_ids', 'mkl_google_api_key' );

	const TYPE_STRAFEN    = 'strafen';
	const TYPE_PUNKTE     = 'punkte';
	const TYPE_DIENSTGRAD = 'dienstgrad';
	const TYPE_TERMINE    = 'termine';
	const TYPE_ERINNERUNG = 'erinnerung';
	const TYPE_NEWS       = 'news';

	/** Ereignistypen mit Bezeichnung für die App, in Anzeigereihenfolge. */
	const TYPE_LABELS = array(
		self::TYPE_STRAFEN    => 'Neue Strafen',
		self::TYPE_PUNKTE     => 'Punkte',
		self::TYPE_DIENSTGRAD => 'Dienstgrad',
		self::TYPE_TERMINE    => 'Neue und geänderte Termine',
		self::TYPE_ERINNERUNG => 'Erinnerung am Vortag (18 Uhr)',
		self::TYPE_NEWS       => 'Neuigkeiten',
	);

	/** Ab dieser Uhrzeit (lokal) geht die Erinnerung für morgen raus, bis REMINDER_UNTIL. */
	const REMINDER_HOUR  = 18;
	const REMINDER_UNTIL = 22;
	/** Neue Kalendertermine weiter in der Zukunft werden nur gemerkt (Rand des Abrufzeitraums von 1 Jahr). */
	const NEW_EVENT_HORIZON = 300 * DAY_IN_SECONDS;
	/** Ab so vielen neuen Terminen auf einmal gibt es eine Sammelnachricht. */
	const SUMMARY_FROM = 4;
	/** Neue Gäste-Abos je IP-Adresse und Stunde. */
	const GUEST_RATE = 20;
	/** Höchstzahl Gäste-Abos insgesamt. */
	const MAX_GUESTS = 2000;

	/** Höchstzahl Geräte je Konto; beim Überschreiten fällt das älteste Abo weg. */
	const MAX_PER_USER = 10;
	/** Nachrichten je Cron-Lauf. */
	const BATCH = 50;
	/** Versuche bei vorübergehenden Fehlern (429/5xx/Netz). */
	const MAX_ATTEMPTS = 3;
	/** Gültigkeit einer Nachricht beim Push-Dienst: 1 Tag. */
	const TTL = DAY_IN_SECONDS;
	/** Größe eines Datensatzes (rs) in RFC 8188; die Nachricht passt immer in einen. */
	const RECORD_SIZE = 4096;

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron' ) );
		add_action( self::WATCH_HOOK, array( __CLASS__, 'watch' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'deleted_user', array( __CLASS__, 'delete_for_user' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'post_status_changed' ), 10, 3 );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( 'init', array( __CLASS__, 'schedule_watch' ) );
		foreach ( self::CALENDAR_SOURCE_OPTIONS as $option ) {
			add_action( "add_option_{$option}", array( __CLASS__, 'calendar_source_changed' ) );
			add_action( "update_option_{$option}", array( __CLASS__, 'calendar_source_changed' ) );
		}
	}

	/**
	 * Neue Kalenderquelle: Google-API und iCal bilden die Termin-IDs unterschiedlich, sonst
	 * hielte der nächste Abgleich alle Termine für neu. Ohne gemerkten Stand merkt er sich
	 * nur den neuen Stand und verschickt nichts. Der Zwischenspeicher hält noch Termine der
	 * alten Quelle und wird deshalb mit geleert.
	 */
	public static function calendar_source_changed() {
		delete_option( self::SNAPSHOT_OPTION );
		MKL_Calendar::clear_cache();
	}

	public static function cron_schedules( $schedules ) {
		$schedules[ self::WATCH_SCHEDULE ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => 'Alle 15 Minuten (Benachrichtigungen)',
		);
		return $schedules;
	}

	/** Kalender-Abgleich und Erinnerungen laufen nur, wenn es Abos gibt (Prüfung bei jedem Seitenaufruf, daher billig). */
	public static function schedule_watch() {
		global $wpdb;
		if ( wp_next_scheduled( self::WATCH_HOOK ) || ! $wpdb->get_var( 'SELECT 1 FROM ' . self::subscriptions_table() . ' LIMIT 1' ) || ! self::is_supported() ) { // phpcs:ignore WordPress.DB.PreparedSQL
			return;
		}
		wp_schedule_event( time() + 60, self::WATCH_SCHEDULE, self::WATCH_HOOK );
	}

	public static function subscriptions_table() {
		return MKL_DB::table( 'push_subscriptions' );
	}

	public static function queue_table() {
		return MKL_DB::table( 'push_queue' );
	}

	/** Für MKL_DB::install(). */
	public static function schema( $charset ) {
		$subs  = self::subscriptions_table();
		$queue = self::queue_table();
		return array(
			"CREATE TABLE $subs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  endpoint text NOT NULL,
  endpoint_hash char(64) NOT NULL,
  p256dh varchar(100) NOT NULL,
  auth varchar(40) NOT NULL,
  types varchar(191) NOT NULL DEFAULT '',
  details tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  last_sent_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY endpoint_hash (endpoint_hash),
  KEY user_id (user_id)
) $charset;",
			"CREATE TABLE $queue (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  subscription_id bigint(20) unsigned NOT NULL,
  payload text NOT NULL,
  urgency varchar(10) NOT NULL DEFAULT 'normal',
  attempts int(11) NOT NULL DEFAULT 0,
  next_try datetime NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY next_try (next_try),
  KEY subscription_id (subscription_id)
) $charset;",
		);
	}

	private static function now() {
		return current_time( 'mysql', true );
	}

	/* ------------------------------------------------------------------ */
	/* Voraussetzungen und Schlüssel                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Fehlende Voraussetzungen auf dem Server, leer = Push möglich.
	 *
	 * @return string[]
	 */
	public static function missing_requirements() {
		$missing = array();
		if ( ! extension_loaded( 'openssl' ) ) {
			return array( 'PHP-Erweiterung OpenSSL' );
		}
		if ( ! function_exists( 'openssl_pkey_derive' ) ) {
			$missing[] = 'openssl_pkey_derive (ab PHP 7.3)';
		}
		if ( ! in_array( 'aes-128-gcm', array_map( 'strtolower', openssl_get_cipher_methods() ), true ) ) {
			$missing[] = 'Verschlüsselung aes-128-gcm';
		}
		if ( ! function_exists( 'openssl_get_curve_names' ) || ! in_array( 'prime256v1', (array) openssl_get_curve_names(), true ) ) {
			$missing[] = 'Kurve prime256v1 (P-256)';
		}
		return $missing;
	}

	public static function is_supported() {
		return ! self::missing_requirements();
	}

	/**
	 * VAPID-Schlüsselpaar; wird beim ersten Aufruf erzeugt.
	 *
	 * @return array{public:string, private:string}|null public = base64url (65 Byte), private = PEM.
	 */
	public static function vapid() {
		$keys = get_option( self::VAPID_OPTION );
		if ( is_array( $keys ) && ! empty( $keys['public'] ) && ! empty( $keys['private'] ) ) {
			return $keys;
		}
		if ( ! self::is_supported() ) {
			return null;
		}
		$key = self::new_key();
		if ( ! $key || ! openssl_pkey_export( $key, $pem ) ) {
			return null;
		}
		$keys = array(
			'public'  => self::b64u_encode( self::raw_public( $key ) ),
			'private' => $pem,
		);
		update_option( self::VAPID_OPTION, $keys, false );
		return $keys;
	}

	/** Öffentlicher VAPID-Schlüssel für die App (applicationServerKey), null ohne Unterstützung. */
	public static function public_key() {
		$keys = self::vapid();
		return $keys ? $keys['public'] : null;
	}

	/* ------------------------------------------------------------------ */
	/* Kryptografie                                                        */
	/* ------------------------------------------------------------------ */

	public static function b64u_encode( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	/** @return string|false */
	public static function b64u_decode( $str ) {
		if ( ! is_string( $str ) || ! preg_match( '/^[A-Za-z0-9_\-]*={0,2}$/', $str ) ) {
			return false;
		}
		return base64_decode( strtr( rtrim( $str, '=' ), '-_', '+/' ), true );
	}

	private static function new_key() {
		return openssl_pkey_new(
			array(
				'private_key_type' => OPENSSL_KEYTYPE_EC,
				'curve_name'       => 'prime256v1',
			)
		);
	}

	/** Öffentlicher Punkt als 65 Byte (0x04 || X || Y). */
	public static function raw_public( $key ) {
		$d = openssl_pkey_get_details( $key );
		return "\x04" . str_pad( $d['ec']['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( $d['ec']['y'], 32, "\0", STR_PAD_LEFT );
	}

	/** OpenSSL-Schlüssel aus einem rohen öffentlichen P-256-Punkt (SubjectPublicKeyInfo). */
	public static function public_key_from_raw( $raw ) {
		$der = hex2bin( '3059301306072a8648ce3d020106082a8648ce3d030107034200' ) . $raw;
		$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
		return openssl_pkey_get_public( $pem );
	}

	private static function hmac( $key, $data ) {
		return hash_hmac( 'sha256', $data, $key, true );
	}

	/**
	 * Verschlüsselt eine Nachricht nach RFC 8291 (Content-Encoding aes128gcm).
	 *
	 * @param string     $plaintext Inhalt (JSON).
	 * @param string     $ua_public Öffentlicher Schlüssel des Geräts, 65 Byte roh.
	 * @param string     $auth      Authentifizierungsgeheimnis des Geräts, 16 Byte roh.
	 * @param string     $salt      Nur für Tests: fester Salt (16 Byte).
	 * @param mixed      $as_key    Nur für Tests: festes Absender-Schlüsselpaar.
	 * @return string|WP_Error Body der Push-Nachricht (Header + Chiffrat).
	 */
	public static function encrypt( $plaintext, $ua_public, $auth, $salt = null, $as_key = null ) {
		$as_key = $as_key ? $as_key : self::new_key();
		$ua_key = self::public_key_from_raw( $ua_public );
		if ( ! $as_key || ! $ua_key ) {
			return new WP_Error( 'mkl_push_key', 'Ungültiger Schlüssel des Geräts.' );
		}
		$ecdh = openssl_pkey_derive( $ua_key, $as_key, 32 );
		if ( false === $ecdh ) {
			return new WP_Error( 'mkl_push_key', 'Schlüsselaustausch fehlgeschlagen.' );
		}
		$as_public = self::raw_public( $as_key );
		$salt      = $salt ? $salt : random_bytes( 16 );

		// RFC 8291, Abschnitt 3.4: HKDF mit nur einem Ausgabeblock (je ≤ 32 Byte).
		$prk_key = self::hmac( $auth, $ecdh );
		$ikm     = self::hmac( $prk_key, "WebPush: info\0" . $ua_public . $as_public . "\x01" );
		$prk     = self::hmac( $salt, $ikm );
		$cek     = substr( self::hmac( $prk, "Content-Encoding: aes128gcm\0\x01" ), 0, 16 );
		$nonce   = substr( self::hmac( $prk, "Content-Encoding: nonce\0\x01" ), 0, 12 );

		// Ein einziger (letzter) Datensatz: Trennbyte 0x02, keine Auffüllung.
		$tag    = '';
		$cipher = openssl_encrypt( $plaintext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag );
		if ( false === $cipher ) {
			return new WP_Error( 'mkl_push_encrypt', 'Verschlüsselung fehlgeschlagen.' );
		}
		return $salt . pack( 'N', self::RECORD_SIZE ) . chr( strlen( $as_public ) ) . $as_public . $cipher . $tag;
	}

	/**
	 * VAPID-JWT für den Push-Dienst (Audience = Ursprung des Endpoints).
	 *
	 * @return string|WP_Error
	 */
	public static function vapid_jwt( $endpoint, $keys = null, $now = null ) {
		$keys = $keys ? $keys : self::vapid();
		if ( ! $keys ) {
			return new WP_Error( 'mkl_push_unsupported', 'Push wird auf diesem Server nicht unterstützt.' );
		}
		$parts = wp_parse_url( $endpoint );
		$aud   = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$now   = $now ? $now : time();
		$email = get_option( 'admin_email' );
		$input = self::b64u_encode( wp_json_encode( array( 'typ' => 'JWT', 'alg' => 'ES256' ) ) ) . '.' . self::b64u_encode(
			wp_json_encode(
				array(
					'aud' => $aud,
					'exp' => $now + 12 * HOUR_IN_SECONDS,
					'sub' => $email ? 'mailto:' . $email : home_url( '/' ),
				),
				JSON_UNESCAPED_SLASHES
			)
		);
		$priv = openssl_pkey_get_private( $keys['private'] );
		if ( ! $priv || ! openssl_sign( $input, $der, $priv, OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'mkl_push_sign', 'Signieren fehlgeschlagen.' );
		}
		$raw = self::der_to_raw_signature( $der );
		if ( ! $raw ) {
			return new WP_Error( 'mkl_push_sign', 'Unerwartetes Signaturformat.' );
		}
		return $input . '.' . self::b64u_encode( $raw );
	}

	/** ECDSA-Signatur von DER (SEQUENCE aus r und s) nach roh r || s (je 32 Byte). */
	public static function der_to_raw_signature( $der ) {
		$pos = 0;
		if ( ord( $der[ $pos++ ] ) !== 0x30 ) {
			return false;
		}
		$len = ord( $der[ $pos++ ] );
		if ( $len & 0x80 ) {
			$pos += $len & 0x7f;
		}
		$out = '';
		for ( $i = 0; $i < 2; $i++ ) {
			if ( ord( $der[ $pos++ ] ) !== 0x02 ) {
				return false;
			}
			$n    = ord( $der[ $pos++ ] );
			$int  = ltrim( substr( $der, $pos, $n ), "\0" );
			$pos += $n;
			if ( strlen( $int ) > 32 ) {
				return false;
			}
			$out .= str_pad( $int, 32, "\0", STR_PAD_LEFT );
		}
		return $out;
	}

	/** Umkehrung von der_to_raw_signature() – für openssl_verify() in Tests. */
	public static function raw_to_der_signature( $raw ) {
		$der = '';
		foreach ( str_split( $raw, 32 ) as $int ) {
			$int = ltrim( $int, "\0" );
			if ( '' === $int || ord( $int[0] ) & 0x80 ) {
				$int = "\0" . $int;
			}
			$der .= "\x02" . chr( strlen( $int ) ) . $int;
		}
		return "\x30" . chr( strlen( $der ) ) . $der;
	}

	/* ------------------------------------------------------------------ */
	/* Abos                                                                */
	/* ------------------------------------------------------------------ */

	/** Ereignistypen, die ein Konto (0 = Gast ohne Anmeldung) abonnieren kann. */
	public static function available_types( $user_id ) {
		$types = array( self::TYPE_TERMINE, self::TYPE_ERINNERUNG, self::TYPE_NEWS );
		if ( $user_id && MKL_Roles::is_member( $user_id ) ) {
			$types = array_merge( array( self::TYPE_STRAFEN, self::TYPE_PUNKTE, self::TYPE_DIENSTGRAD ), $types );
		}
		return $types;
	}

	/** Termine aus dem Google Kalender (true) oder aus der öffentlichen Liste (false). */
	public static function uses_calendar( $user_id ) {
		return $user_id && MKL_Roles::can_see_calendar( $user_id );
	}

	public static function get_by_endpoint( $endpoint ) {
		global $wpdb;
		$t   = self::subscriptions_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE endpoint_hash = %s", hash( 'sha256', $endpoint ) ), ARRAY_A );
		return $row ? $row : null;
	}

	/** @return array[] */
	public static function for_user( $user_id ) {
		global $wpdb;
		$t = self::subscriptions_table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE user_id = %d ORDER BY id ASC", $user_id ), ARRAY_A );
	}

	public static function count() {
		global $wpdb;
		$t = self::subscriptions_table();
		return array(
			'devices'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ), // phpcs:ignore WordPress.DB.PreparedSQL
			'accounts' => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM $t WHERE user_id > 0" ), // phpcs:ignore WordPress.DB.PreparedSQL
			'queued'   => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::queue_table() ), // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/** Liste gültiger Typen aus der Eingabe, beschränkt auf die erlaubten. */
	private static function clean_types( $raw, array $allowed ) {
		if ( ! is_array( $raw ) ) {
			return $allowed;
		}
		return array_values( array_intersect( $allowed, array_map( 'strval', $raw ) ) );
	}

	/**
	 * Legt ein Abo an oder übernimmt es (z. B. wenn sich auf dem Gerät jemand
	 * anderes angemeldet hat). Ein bestehendes Abo desselben Kontos behält seine
	 * Einstellungen, wenn keine neuen mitgeschickt werden.
	 *
	 * @return array|WP_Error Das gespeicherte Abo.
	 */
	public static function subscribe( $user_id, $endpoint, $p256dh, $auth, $types = null, $details = null ) {
		global $wpdb;
		$t        = self::subscriptions_table();
		$allowed  = self::available_types( $user_id );
		$existing = self::get_by_endpoint( $endpoint );
		$now      = self::now();
		$same     = $existing && (int) $existing['user_id'] === (int) $user_id;

		// Ein bestehendes Abo übernimmt nur, wer dasselbe Gerät hat (gleiches auth-Geheimnis).
		if ( $existing && ! hash_equals( (string) $existing['auth'], (string) $auth ) ) {
			return new WP_Error( 'mkl_forbidden', 'Dieses Gerät ist bereits anders angemeldet.', array( 'status' => 403 ) );
		}

		$types   = null !== $types ? self::clean_types( $types, $allowed ) : ( $same ? self::clean_types( self::split_types( $existing['types'] ), $allowed ) : $allowed );
		$details = null !== $details ? (bool) $details : ( $same && (bool) $existing['details'] );
		$data    = array(
			'user_id'    => $user_id,
			'endpoint'   => $endpoint,
			'p256dh'     => $p256dh,
			'auth'       => $auth,
			'types'      => implode( ',', $types ),
			'details'    => $details ? 1 : 0,
			'updated_at' => $now,
		);
		if ( $existing ) {
			$wpdb->update( $t, $data, array( 'id' => $existing['id'] ) );
			if ( ! $same ) {
				// Nachrichten für das vorige Konto nicht mehr zustellen.
				$wpdb->delete( self::queue_table(), array( 'subscription_id' => $existing['id'] ), array( '%d' ) );
			}
		} else {
			$data['endpoint_hash'] = hash( 'sha256', $endpoint );
			$data['created_at']    = $now;
			if ( ! $wpdb->insert( $t, $data ) ) {
				return new WP_Error( 'mkl_push_db', 'Das Abo konnte nicht gespeichert werden.' );
			}
			$subs = self::for_user( $user_id );
			foreach ( array_slice( $subs, 0, max( 0, count( $subs ) - self::MAX_PER_USER ) ) as $old ) {
				self::delete_subscription( (int) $old['id'] );
			}
		}
		return self::get_by_endpoint( $endpoint );
	}

	public static function split_types( $str ) {
		return '' === (string) $str ? array() : explode( ',', $str );
	}

	public static function delete_subscription( $id ) {
		global $wpdb;
		$wpdb->delete( self::queue_table(), array( 'subscription_id' => $id ), array( '%d' ) );
		return (bool) $wpdb->delete( self::subscriptions_table(), array( 'id' => $id ), array( '%d' ) );
	}

	/** Beim Löschen eines Kontos (Hook deleted_user). */
	public static function delete_for_user( $user_id ) {
		foreach ( self::for_user( (int) $user_id ) as $sub ) {
			self::delete_subscription( (int) $sub['id'] );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Nachrichten                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Inhalt einer Benachrichtigung.
	 *
	 * @param string $route Ziel in der App, z. B. „meins/strafen“.
	 */
	public static function message( $title, $body, $route, $tag = '' ) {
		return array(
			'title' => $title,
			'body'  => function_exists( 'mb_substr' ) ? mb_substr( $body, 0, 300 ) : substr( $body, 0, 300 ),
			'url'   => MKL_App_Page::app_url() . '#/' . $route,
			'tag'   => $tag,
			'icon'  => MKL_URL . 'app-dist/icons/icon-192.png',
		);
	}

	/**
	 * Stellt eine Nachricht an alle Geräte eines Kontos zu, die den Typ abonniert haben.
	 *
	 * @param array      $message Siehe message().
	 * @param array|null $detail  Variante mit Einzelheiten für Abos mit details = 1.
	 * @return int Anzahl eingereihter Nachrichten.
	 */
	public static function notify_user( $user_id, $type, array $message, $detail = null ) {
		global $wpdb;
		$count = 0;
		foreach ( self::for_user( $user_id ) as $sub ) {
			if ( ! in_array( $type, self::split_types( $sub['types'] ), true ) ) {
				continue;
			}
			$payload = $detail && $sub['details'] ? $detail : $message;
			$wpdb->insert(
				self::queue_table(),
				array(
					'subscription_id' => $sub['id'],
					'payload'         => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
					'urgency'         => 'normal',
					'attempts'        => 0,
					'next_try'        => self::now(),
					'created_at'      => self::now(),
				)
			);
			++$count;
		}
		if ( $count ) {
			self::schedule();
		}
		return $count;
	}

	/**
	 * Stellt eine Nachricht an alle Abos mit dem Typ zu.
	 *
	 * @param bool|null $calendar true = nur Kalender-Berechtigte, false = nur alle anderen
	 *                              (Gäste, Konten ohne Kalender), null = alle.
	 * @return int Anzahl eingereihter Nachrichten.
	 */
	public static function notify_all( $type, array $message, $calendar = null ) {
		global $wpdb;
		$t     = self::subscriptions_table();
		$rows  = $wpdb->get_results( "SELECT id, user_id, types FROM $t", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$json  = wp_json_encode( $message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$count = 0;
		foreach ( $rows as $sub ) {
			if ( ! in_array( $type, self::split_types( $sub['types'] ), true ) ) {
				continue;
			}
			if ( null !== $calendar && self::uses_calendar( (int) $sub['user_id'] ) !== $calendar ) {
				continue;
			}
			$wpdb->insert(
				self::queue_table(),
				array(
					'subscription_id' => $sub['id'],
					'payload'         => $json,
					'urgency'         => 'normal',
					'attempts'        => 0,
					'next_try'        => self::now(),
					'created_at'      => self::now(),
				)
			);
			++$count;
		}
		if ( $count ) {
			self::schedule();
		}
		return $count;
	}

	/** Versand im Hintergrund anstoßen. */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}
		if ( ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			spawn_cron();
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::WATCH_HOOK );
	}

	public static function cron() {
		self::process_queue();
	}

	/**
	 * Verschickt fällige Nachrichten aus der Warteschlange.
	 *
	 * @return int Anzahl erfolgreich zugestellter Nachrichten.
	 */
	public static function process_queue( $limit = self::BATCH ) {
		global $wpdb;
		$q    = self::queue_table();
		$s    = self::subscriptions_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT q.*, s.endpoint, s.p256dh, s.auth FROM $q q LEFT JOIN $s s ON s.id = q.subscription_id WHERE q.next_try <= %s ORDER BY q.id ASC LIMIT %d", self::now(), $limit ),
			ARRAY_A
		);
		$sent = 0;
		foreach ( $rows as $row ) {
			if ( null === $row['endpoint'] ) {
				$wpdb->delete( $q, array( 'id' => $row['id'] ), array( '%d' ) );
				continue;
			}
			$result = self::send( $row, $row['payload'], $row['urgency'] );
			if ( 'ok' === $result['status'] ) {
				++$sent;
				$wpdb->delete( $q, array( 'id' => $row['id'] ), array( '%d' ) );
			} elseif ( 'gone' === $result['status'] ) {
				self::delete_subscription( (int) $row['subscription_id'] );
			} elseif ( 'retry' === $result['status'] && (int) $row['attempts'] + 1 < self::MAX_ATTEMPTS ) {
				$wpdb->update(
					$q,
					array(
						'attempts' => (int) $row['attempts'] + 1,
						'next_try' => gmdate( 'Y-m-d H:i:s', time() + 5 * MINUTE_IN_SECONDS * ( (int) $row['attempts'] + 1 ) ),
					),
					array( 'id' => $row['id'] )
				);
			} else {
				$wpdb->delete( $q, array( 'id' => $row['id'] ), array( '%d' ) );
			}
		}
		$next = $wpdb->get_var( "SELECT MIN(next_try) FROM $q" ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $next && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( max( time(), strtotime( $next . ' UTC' ) ), self::CRON_HOOK );
		}
		return $sent;
	}

	/**
	 * Schickt eine Nachricht sofort an ein Abo.
	 *
	 * @param array  $sub     Zeile mit endpoint, p256dh, auth (und id).
	 * @param string $payload JSON.
	 * @return array{status:string, code:int, message:string} status: ok | gone | retry | error
	 */
	public static function send( array $sub, $payload, $urgency = 'normal' ) {
		$jwt  = self::vapid_jwt( $sub['endpoint'] );
		$body = is_wp_error( $jwt ) ? $jwt : self::encrypt( $payload, self::b64u_decode( $sub['p256dh'] ), self::b64u_decode( $sub['auth'] ) );
		if ( is_wp_error( $body ) ) {
			return array(
				'status'  => 'error',
				'code'    => 0,
				'message' => $body->get_error_message(),
			);
		}
		$res = wp_safe_remote_post(
			$sub['endpoint'],
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'     => 'application/octet-stream',
					'Content-Encoding' => 'aes128gcm',
					'TTL'              => (string) self::TTL,
					'Urgency'          => $urgency,
					'Authorization'    => 'vapid t=' . $jwt . ', k=' . self::public_key(),
				),
				'body'        => $body,
			)
		);
		if ( is_wp_error( $res ) ) {
			return array(
				'status'  => 'retry',
				'code'    => 0,
				'message' => $res->get_error_message(),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code >= 200 && $code < 300 ) {
			// Zeilen aus der Warteschlange tragen die Abo-ID in subscription_id.
			$sub_id = isset( $sub['subscription_id'] ) ? (int) $sub['subscription_id'] : ( isset( $sub['id'] ) ? (int) $sub['id'] : 0 );
			if ( $sub_id ) {
				global $wpdb;
				$wpdb->update( self::subscriptions_table(), array( 'last_sent_at' => self::now() ), array( 'id' => $sub_id ) );
			}
			$status = 'ok';
		} elseif ( 404 === $code || 410 === $code ) {
			$status = 'gone';
		} elseif ( 429 === $code || $code >= 500 ) {
			$status = 'retry';
		} else {
			$status = 'error';
		}
		return array(
			'status'  => $status,
			'code'    => $code,
			'message' => 'ok' === $status ? '' : sprintf( 'Push-Dienst antwortet mit %d: %s', $code, wp_strip_all_tags( substr( (string) wp_remote_retrieve_body( $res ), 0, 200 ) ) ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Ereignisse                                                          */
	/* ------------------------------------------------------------------ */

	/** @param array[] $rows Formatierte Punkte-Einträge (MKL_DB::format_points_row). */
	public static function points_added( array $rows ) {
		foreach ( $rows as $r ) {
			$value = $r['points'];
			$title = $value < 0 ? 'Punkte abgezogen' : 'Punkte erhalten';
			$body  = ( $value > 0 ? '+' : '−' ) . MKL_DB::format_points( abs( $value ) ) . ' ' . ( 1 == abs( $value ) ? 'Punkt' : 'Punkte' ) . ' – ' . $r['reason']; // phpcs:ignore WordPress.PHP.StrictComparisons
			self::notify_user( $r['user_id'], self::TYPE_PUNKTE, self::message( $title, $body, 'meins/punkte', 'punkte-' . $r['id'] ) );
		}
	}

	/** @param array[] $rows Formatierte Strafen (MKL_DB::format_penalty_row). */
	public static function penalties_added( array $rows ) {
		foreach ( $rows as $r ) {
			$tag = 'strafe-' . $r['id'];
			self::notify_user(
				$r['user_id'],
				self::TYPE_STRAFEN,
				self::message( 'Neue Strafe', 'Tippen zum Ansehen.', 'meins/strafen', $tag ),
				self::message( 'Neue Strafe', self::format_euro( $r['amount_cents'] ) . ' – ' . $r['reason'], 'meins/strafen', $tag )
			);
		}
	}

	public static function rank_changed( $user_id, $target, $kind ) {
		$body  = $target ? 'Neuer Dienstgrad: ' . $target['title'] : 'Kein Dienstgrad mehr.';
		$title = MKL_Ranks::KIND_PROMOTION === $kind ? 'Befördert' : 'Dienstgrad geändert';
		self::notify_user( $user_id, self::TYPE_DIENSTGRAD, self::message( $title, $body, 'meins/rang', 'dienstgrad' ) );
	}

	public static function format_euro( $cents ) {
		return number_format( $cents / 100, 2, ',', '.' ) . ' €';
	}

	/* ------------------------------------------------------------------ */
	/* Termine, News, Erinnerungen                                         */
	/* ------------------------------------------------------------------ */

	/** Beginn eines Termins (Format von MKL_Calendar/MKL_Events) in lokaler Zeit. */
	private static function event_start( array $ev ) {
		$tz = wp_timezone();
		return ( new DateTimeImmutable( $ev['start'], $tz ) )->setTimezone( $tz );
	}

	/** „Sa 05.09.2026, 14:00 Uhr“ bzw. „Fr 04.09.2026 – Mo 07.09.2026“. */
	public static function format_when( array $ev ) {
		$days  = array( 'So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa' );
		$day   = function ( DateTimeImmutable $d ) use ( $days ) {
			return $days[ (int) $d->format( 'w' ) ] . ' ' . $d->format( 'd.m.Y' );
		};
		$start = self::event_start( $ev );
		if ( ! empty( $ev['all_day'] ) ) {
			$last = ( new DateTimeImmutable( $ev['end'], wp_timezone() ) )->modify( '-1 day' );
			return $last > $start ? $day( $start ) . ' – ' . $day( $last ) : $day( $start );
		}
		return $day( $start ) . ', ' . $start->format( 'H:i' ) . ' Uhr';
	}

	private static function event_body( array $ev ) {
		return $ev['title'] . ' – ' . self::format_when( $ev ) . ( ! empty( $ev['location'] ) ? ' – ' . $ev['location'] : '' );
	}

	/** Für Nachrichten wichtige Angaben: Änderungen am Titel oder an der Beschreibung lösen nichts aus. */
	private static function event_signature( array $ev ) {
		return array( (string) $ev['start'], (string) $ev['end'], trim( (string) $ev['location'] ) );
	}

	/** Liegt der Termin (oder sein Ende) heute oder später? */
	private static function is_upcoming( array $ev ) {
		$end = ( new DateTimeImmutable( $ev['end'], wp_timezone() ) )->setTimezone( wp_timezone() );
		if ( ! empty( $ev['all_day'] ) ) {
			$end = $end->modify( '-1 day' ); // Ende exklusiv
		}
		return $end->format( 'Y-m-d' ) >= wp_date( 'Y-m-d' );
	}

	/**
	 * Öffentlicher Termin angelegt oder geändert (MKL_Events::format()).
	 * Geht an Gäste und Konten ohne Kalender.
	 */
	public static function public_event_saved( array $ev, $before = null ) {
		if ( ! self::is_upcoming( $ev ) ) {
			return 0;
		}
		if ( $before && self::event_signature( $before ) === self::event_signature( $ev ) ) {
			return 0;
		}
		$title = $before ? 'Termin geändert' : 'Neuer Termin';
		return self::notify_all( self::TYPE_TERMINE, self::message( $title, self::event_body( $ev ), 'termine', 'termin-p' . $ev['id'] ), false );
	}

	/** Neuer Blogbeitrag veröffentlicht (Hook transition_post_status). */
	public static function post_status_changed( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status || 'post' !== $post->post_type || '' !== $post->post_password ) {
			return;
		}
		$title = wp_strip_all_tags( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) );
		self::notify_all( self::TYPE_NEWS, self::message( 'Neuigkeiten', $title, 'news/' . $post->ID, 'news-' . $post->ID ) );
	}

	/** Alle 15 Minuten: Kalender abgleichen, Erinnerungen verschicken. */
	public static function watch() {
		self::check_calendar();
		self::send_reminders();
	}

	/**
	 * Vergleicht den Kalender mit dem letzten Stand.
	 *
	 * @param array $snapshot Letzter Stand: id → Signatur.
	 * @param array $events   Aktuelle Termine (MKL_Calendar).
	 * @return array{new: array[], changed: array[], snapshot: array}
	 */
	public static function calendar_changes( array $snapshot, array $events, $now = null ) {
		$now     = $now ? $now : time();
		$new     = array();
		$changed = array();
		$next    = array();
		foreach ( $events as $ev ) {
			$sig               = self::event_signature( $ev );
			$next[ $ev['id'] ] = $sig;
			$start             = self::event_start( $ev )->getTimestamp();
			if ( ! self::is_upcoming( $ev ) ) {
				continue;
			}
			if ( ! isset( $snapshot[ $ev['id'] ] ) ) {
				if ( $start <= $now + self::NEW_EVENT_HORIZON ) {
					$new[] = $ev;
				}
			} elseif ( $snapshot[ $ev['id'] ] !== $sig ) {
				$changed[] = $ev;
			}
		}
		return array(
			'new'      => $new,
			'changed'  => $changed,
			'snapshot' => $next,
		);
	}

	/**
	 * Neue und geänderte Kalendertermine an Kalender-Berechtigte. Beim ersten
	 * Lauf wird nur der Stand gemerkt; bei einem Abruffehler bleibt er unverändert.
	 *
	 * @return int Anzahl eingereihter Nachrichten.
	 */
	public static function check_calendar() {
		$events = MKL_Calendar::get_events();
		if ( is_wp_error( $events ) ) {
			return 0;
		}
		$snapshot = get_option( self::SNAPSHOT_OPTION, null );
		$diff     = self::calendar_changes( is_array( $snapshot ) ? $snapshot : array(), $events );
		update_option( self::SNAPSHOT_OPTION, $diff['snapshot'], false );
		if ( ! is_array( $snapshot ) ) {
			return 0;
		}
		$count = 0;
		if ( count( $diff['new'] ) >= self::SUMMARY_FROM ) {
			$titles = implode( ', ', wp_list_pluck( array_slice( $diff['new'], 0, 3 ), 'title' ) );
			$count += self::notify_all( self::TYPE_TERMINE, self::message( 'Neue Termine', sprintf( '%d neue Termine im Kalender, z. B. %s', count( $diff['new'] ), $titles ), 'termine', 'termine-neu' ), true );
		} else {
			foreach ( $diff['new'] as $ev ) {
				$count += self::notify_all( self::TYPE_TERMINE, self::message( 'Neuer Termin', self::event_body( $ev ), 'termine', 'termin-' . $ev['id'] ), true );
			}
		}
		foreach ( $diff['changed'] as $ev ) {
			$count += self::notify_all( self::TYPE_TERMINE, self::message( 'Termin geändert', self::event_body( $ev ), 'termine', 'termin-' . $ev['id'] ), true );
		}
		return $count;
	}

	/** Termine, die am Tag $date (Y-m-d, lokal) beginnen. */
	public static function events_on( array $events, $date ) {
		return array_values(
			array_filter(
				$events,
				function ( $ev ) use ( $date ) {
					return self::event_start( $ev )->format( 'Y-m-d' ) === $date;
				}
			)
		);
	}

	private static function reminder_message( array $events ) {
		$parts = array();
		foreach ( $events as $ev ) {
			$extra   = array_filter( array( empty( $ev['all_day'] ) ? self::event_start( $ev )->format( 'H:i' ) . ' Uhr' : '', (string) $ev['location'] ) );
			$parts[] = $ev['title'] . ( $extra ? ' (' . implode( ', ', $extra ) . ')' : '' );
		}
		$title = 1 === count( $events ) ? 'Morgen: ' . $events[0]['title'] : sprintf( 'Morgen: %d Termine', count( $events ) );
		return self::message( $title, implode( ' · ', $parts ), 'termine', 'erinnerung' );
	}

	/**
	 * Erinnerung an die Termine von morgen, einmal am Tag ab 18 Uhr (lokal).
	 *
	 * @param DateTimeImmutable|null $now Nur für Tests.
	 * @return int Anzahl eingereihter Nachrichten.
	 */
	public static function send_reminders( $now = null ) {
		$now  = $now ? $now : new DateTimeImmutable( 'now', wp_timezone() );
		$hour = (int) $now->format( 'G' );
		if ( $hour < self::REMINDER_HOUR || $hour >= self::REMINDER_UNTIL || get_option( self::REMINDER_OPTION ) === $now->format( 'Y-m-d' ) ) {
			return 0;
		}
		update_option( self::REMINDER_OPTION, $now->format( 'Y-m-d' ), false );
		$tomorrow = $now->modify( '+1 day' )->format( 'Y-m-d' );
		$count    = 0;

		$calendar = MKL_Calendar::get_events();
		if ( ! is_wp_error( $calendar ) ) {
			$list = self::events_on( $calendar, $tomorrow );
			if ( $list ) {
				$count += self::notify_all( self::TYPE_ERINNERUNG, self::reminder_message( $list ), true );
			}
		}
		$public = self::events_on( MKL_Events::upcoming(), $tomorrow );
		if ( $public ) {
			$count += self::notify_all( self::TYPE_ERINNERUNG, self::reminder_message( $public ), false );
		}
		return $count;
	}

	/* ------------------------------------------------------------------ */
	/* REST                                                                */
	/* ------------------------------------------------------------------ */

	public static function register_routes() {
		$logged_in = array( 'MKL_REST', 'can_read' );
		$public    = '__return_true';
		foreach ( array( '/me/push' => $logged_in, '/push' => $public ) as $base => $perm ) {
			$guest = '/push' === $base;
			$cb    = function ( $handler ) use ( $guest ) {
				return function ( WP_REST_Request $req ) use ( $handler, $guest ) {
					return call_user_func( array( __CLASS__, $handler ), $req, $guest ? 0 : get_current_user_id() );
				};
			};
			register_rest_route( MKL_REST::NS, $base . '/status', array( 'methods' => 'POST', 'callback' => $cb( 'rest_status' ), 'permission_callback' => $perm ) );
			register_rest_route(
				MKL_REST::NS,
				$base,
				array(
					array( 'methods' => 'POST', 'callback' => $cb( 'rest_subscribe' ), 'permission_callback' => $perm ),
					array( 'methods' => 'PUT', 'callback' => $cb( 'rest_update' ), 'permission_callback' => $perm ),
					array( 'methods' => 'DELETE', 'callback' => $cb( 'rest_unsubscribe' ), 'permission_callback' => $perm ),
				)
			);
			register_rest_route( MKL_REST::NS, $base . '/test', array( 'methods' => 'POST', 'callback' => $cb( 'rest_test' ), 'permission_callback' => $perm ) );
		}
	}

	private static function error( $message, $status = 400 ) {
		return new WP_Error( 'mkl_invalid', $message, array( 'status' => $status ) );
	}

	/**
	 * Endpoint aus der Anfrage; nur https-Adressen. Interne Adressen fängt beim
	 * Versand wp_safe_remote_post() ab.
	 */
	private static function endpoint_param( WP_REST_Request $req ) {
		$endpoint = $req->get_param( 'endpoint' );
		if ( ! is_string( $endpoint ) || strlen( $endpoint ) > 2048 || 'https' !== wp_parse_url( $endpoint, PHP_URL_SCHEME ) || ! wp_parse_url( $endpoint, PHP_URL_HOST ) ) {
			return self::error( 'Ungültige Push-Adresse.' );
		}
		return $endpoint;
	}

	/**
	 * Abo zum Endpoint, das dem Anfragenden gehört, sonst 404. Konten über die
	 * Anmeldung, Gäste (0) über das auth-Geheimnis des Abos.
	 */
	private static function own_subscription( WP_REST_Request $req, $user_id ) {
		$endpoint = self::endpoint_param( $req );
		if ( is_wp_error( $endpoint ) ) {
			return $endpoint;
		}
		$sub = self::get_by_endpoint( $endpoint );
		$ok  = $sub && (int) $sub['user_id'] === (int) $user_id;
		if ( $ok && ! $user_id ) {
			$auth = self::b64u_decode( (string) $req->get_param( 'auth' ) );
			$ok   = $auth && hash_equals( (string) $sub['auth'], self::b64u_encode( $auth ) );
		}
		if ( ! $ok ) {
			return self::error( 'Für dieses Gerät sind keine Benachrichtigungen aktiviert.', 404 );
		}
		return $sub;
	}

	private static function format_subscription( $sub ) {
		return $sub ? array(
			'types'   => self::split_types( $sub['types'] ),
			'details' => (bool) $sub['details'],
		) : null;
	}

	/** Verfügbare Typen und das Abo dieses Geräts (Endpoint im Body, nicht in der Adresse). */
	public static function rest_status( WP_REST_Request $req, $user_id ) {
		$sub = null;
		if ( $req->get_param( 'endpoint' ) ) {
			$sub = self::own_subscription( $req, $user_id );
			if ( is_wp_error( $sub ) ) {
				$sub = null;
			}
		}
		$types = array();
		foreach ( self::available_types( $user_id ) as $type ) {
			$types[] = array(
				'key'   => $type,
				'label' => self::TYPE_LABELS[ $type ],
			);
		}
		return rest_ensure_response(
			array(
				'supported'    => self::is_supported(),
				'public_key'   => self::public_key(),
				'types'        => $types,
				'subscription' => self::format_subscription( $sub ),
			)
		);
	}

	/** Gäste: Begrenzung neuer Abos je IP-Adresse und insgesamt. */
	private static function guest_limit() {
		global $wpdb;
		$t = self::subscriptions_table();
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE user_id = 0" ) >= self::MAX_GUESTS ) { // phpcs:ignore WordPress.DB.PreparedSQL
			return self::error( 'Zurzeit sind keine weiteren Abos möglich.', 503 );
		}
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'mkl_push_rl_' . md5( $ip . wp_salt( 'nonce' ) );
		$n   = (int) get_transient( $key );
		if ( $n >= self::GUEST_RATE ) {
			return self::error( 'Zu viele Anfragen. Bitte später erneut versuchen.', 429 );
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return true;
	}

	public static function rest_subscribe( WP_REST_Request $req, $user_id ) {
		if ( ! self::is_supported() ) {
			return self::error( 'Push-Benachrichtigungen werden auf diesem Server nicht unterstützt.', 501 );
		}
		$endpoint = self::endpoint_param( $req );
		if ( is_wp_error( $endpoint ) ) {
			return $endpoint;
		}
		$keys   = $req->get_param( 'keys' );
		$p256dh = is_array( $keys ) && isset( $keys['p256dh'] ) ? (string) $keys['p256dh'] : '';
		$auth   = is_array( $keys ) && isset( $keys['auth'] ) ? (string) $keys['auth'] : '';
		$raw_pk = self::b64u_decode( $p256dh );
		$raw_au = self::b64u_decode( $auth );
		if ( ! $raw_pk || 65 !== strlen( $raw_pk ) || "\x04" !== $raw_pk[0] || ! self::public_key_from_raw( $raw_pk ) || ! $raw_au || 16 !== strlen( $raw_au ) ) {
			return self::error( 'Ungültige Schlüssel des Geräts.' );
		}
		if ( ! $user_id && ! self::get_by_endpoint( $endpoint ) ) {
			$limit = self::guest_limit();
			if ( is_wp_error( $limit ) ) {
				return $limit;
			}
		}
		$details = $req->get_param( 'details' );
		$sub     = self::subscribe( $user_id, $endpoint, self::b64u_encode( $raw_pk ), self::b64u_encode( $raw_au ), $req->get_param( 'types' ), null === $details ? null : (bool) $details );
		if ( is_wp_error( $sub ) ) {
			return $sub;
		}
		return rest_ensure_response( self::format_subscription( $sub ) );
	}

	public static function rest_update( WP_REST_Request $req, $user_id ) {
		$sub = self::own_subscription( $req, $user_id );
		if ( is_wp_error( $sub ) ) {
			return $sub;
		}
		global $wpdb;
		$data = array( 'updated_at' => self::now() );
		if ( null !== $req->get_param( 'types' ) ) {
			$data['types'] = implode( ',', self::clean_types( (array) $req->get_param( 'types' ), self::available_types( $user_id ) ) );
		}
		if ( null !== $req->get_param( 'details' ) ) {
			$data['details'] = $req->get_param( 'details' ) ? 1 : 0;
		}
		$wpdb->update( self::subscriptions_table(), $data, array( 'id' => $sub['id'] ) );
		return rest_ensure_response( self::format_subscription( self::get_by_endpoint( $sub['endpoint'] ) ) );
	}

	public static function rest_unsubscribe( WP_REST_Request $req, $user_id ) {
		$sub = self::own_subscription( $req, $user_id );
		if ( is_wp_error( $sub ) ) {
			return 404 === $sub->get_error_data()['status'] ? rest_ensure_response( array( 'deleted' => false ) ) : $sub;
		}
		return rest_ensure_response( array( 'deleted' => self::delete_subscription( (int) $sub['id'] ) ) );
	}

	/** Testbenachrichtigung sofort an dieses Gerät, mit der Antwort des Push-Dienstes. */
	public static function rest_test( WP_REST_Request $req, $user_id ) {
		$sub = self::own_subscription( $req, $user_id );
		if ( is_wp_error( $sub ) ) {
			return $sub;
		}
		$result = self::send_test( $sub );
		if ( 'gone' === $result['status'] ) {
			self::delete_subscription( (int) $sub['id'] );
			return self::error( 'Der Push-Dienst kennt dieses Gerät nicht mehr. Bitte Benachrichtigungen neu aktivieren.', 410 );
		}
		if ( 'ok' !== $result['status'] ) {
			return self::error( $result['message'], 502 );
		}
		return rest_ensure_response( array( 'sent' => true ) );
	}

	public static function send_test( array $sub ) {
		$msg = self::message( 'Marinekompanie', 'Benachrichtigungen funktionieren. Ahoi!', 'konto', 'test' );
		return self::send( $sub, wp_json_encode( $msg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 'high' );
	}
}
