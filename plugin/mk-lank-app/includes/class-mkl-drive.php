<?php
/**
 * Lesender Zugriff auf Google Drive und, über denselben Zugang, auf den Google Kalender.
 *
 * Zwei Wege, in dieser Reihenfolge:
 *  1. „Mit Google verbinden“ (OAuth): Ein Admin meldet sich einmal mit dem
 *     Google-Konto an, das den Galerie-Ordner sehen darf. Gespeichert wird nur
 *     das Refresh-Token. Braucht keinen Dienstkontoschlüssel und funktioniert
 *     deshalb auch, wenn Google das Erstellen solcher Schlüssel verbietet.
 *  2. Dienstkonto (JSON-Schlüssel): Der Galerie-Ordner muss für die
 *     E-Mail-Adresse des Dienstkontos freigegeben sein (Betrachter genügt).
 *     Das Zugriffstoken wird per signiertem JWT (RS256) geholt.
 *
 * Den Kalender liest MKL_Calendar mit access_token(), wenn calendar_access() zutrifft:
 * bei der Google-Verbindung nur, wenn beim Verbinden auch der Kalender erlaubt wurde;
 * beim Dienstkonto muss der Kalender für dessen E-Mail-Adresse freigegeben sein.
 *
 * Zugangsdaten verlassen nie den Server. Ohne Bibliothek.
 */

defined( 'ABSPATH' ) || exit;

class MKL_Drive {

	const OPTION_KEY   = 'mkl_drive_service_account';
	const OAUTH_OPTION = 'mkl_drive_oauth';
	const SCOPE        = 'https://www.googleapis.com/auth/drive.readonly';
	const CAL_SCOPE    = 'https://www.googleapis.com/auth/calendar.readonly';
	const AUTH_URL     = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URI    = 'https://oauth2.googleapis.com/token';
	const REVOKE_URL   = 'https://oauth2.googleapis.com/revoke';
	const FILES_URL    = 'https://www.googleapis.com/drive/v3/files';
	const ABOUT_URL    = 'https://www.googleapis.com/drive/v3/about';
	const FOLDER_MIME  = 'application/vnd.google-apps.folder';
	const TOKEN_CACHE  = 'mkl_drive_token';
	const STATE_PREFIX = 'mkl_drive_';

	/** @return array{client_email:string, private_key:string}|null */
	public static function service_account() {
		$json = get_option( self::OPTION_KEY, '' );
		$data = $json ? json_decode( $json, true ) : null;
		return is_array( $data ) && ! empty( $data['client_email'] ) && ! empty( $data['private_key'] ) ? $data : null;
	}

	public static function client_email() {
		$sa = self::service_account();
		return $sa ? $sa['client_email'] : '';
	}

	/* ------------------------------------------------------------------ */
	/* OAuth („Mit Google verbinden“)                                      */
	/* ------------------------------------------------------------------ */

	/** Angeforderte Berechtigungen: Drive für die Galerie, Kalender für die Termine (beide nur Lesen). */
	public static function scopes() {
		return self::SCOPE . ' ' . self::CAL_SCOPE;
	}

	/** @return array{client_id?:string, client_secret?:string, refresh_token?:string, email?:string, scope?:string} */
	public static function oauth() {
		$data = get_option( self::OAUTH_OPTION, array() );
		return is_array( $data ) ? $data : array();
	}

	private static function update_oauth( array $changes ) {
		update_option( self::OAUTH_OPTION, array_merge( self::oauth(), $changes ), false );
		delete_transient( self::TOKEN_CACHE );
	}

	public static function oauth_connected() {
		$o = self::oauth();
		return ! empty( $o['client_id'] ) && ! empty( $o['client_secret'] ) && ! empty( $o['refresh_token'] );
	}

	/** Google-Konto der OAuth-Verbindung. */
	public static function oauth_email() {
		$o = self::oauth();
		return self::oauth_connected() && ! empty( $o['email'] ) ? $o['email'] : '';
	}

	/**
	 * Darf der aktive Zugang den Kalender lesen? Verbindungen von vor dieser
	 * Erweiterung oder ohne Häkchen für den Kalender müssen neu verbunden werden.
	 */
	public static function calendar_access() {
		if ( self::oauth_connected() ) {
			$o = self::oauth();
			return ! empty( $o['scope'] ) && false !== strpos( ' ' . $o['scope'] . ' ', ' ' . self::CAL_SCOPE . ' ' );
		}
		return null !== self::service_account();
	}

	/** Ist irgendein Zugang eingerichtet? */
	public static function is_configured() {
		return self::oauth_connected() || null !== self::service_account();
	}

	/**
	 * Weiterleitungs-URI, die im OAuth-Client bei Google eingetragen sein muss.
	 * Bewusst ohne Query-Teil, weil Google die Adresse exakt vergleicht.
	 */
	public static function redirect_uri() {
		return admin_url( 'admin-post.php' );
	}

	/**
	 * Speichert Client-ID und -Secret. Ein leeres Secret behält das bisherige.
	 *
	 * @return true|WP_Error
	 */
	public static function save_client( $client_id, $client_secret ) {
		$client_id     = trim( (string) $client_id );
		$client_secret = trim( (string) $client_secret );
		$old           = self::oauth();
		if ( ! preg_match( '/^[0-9A-Za-z_.-]+\.apps\.googleusercontent\.com$/', $client_id ) ) {
			return new WP_Error( 'mkl_drive', 'Die Client-ID sieht nicht richtig aus. Sie endet auf „.apps.googleusercontent.com“.' );
		}
		if ( '' === $client_secret ) {
			if ( empty( $old['client_secret'] ) || $old['client_id'] !== $client_id ) {
				return new WP_Error( 'mkl_drive', 'Bitte auch den Clientschlüssel (Client-Secret) eintragen.' );
			}
			$client_secret = $old['client_secret'];
		}
		$changes = array(
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
		);
		// Ein anderer Client macht das bisherige Refresh-Token ungültig.
		if ( empty( $old['client_id'] ) || $old['client_id'] !== $client_id || $old['client_secret'] !== $client_secret ) {
			$changes['refresh_token'] = '';
			$changes['email']         = '';
			$changes['scope']         = '';
		}
		self::update_oauth( $changes );
		return true;
	}

	/** Neues, einmaliges state für den aktuellen Benutzer (CSRF-Schutz, 15 Minuten gültig). */
	public static function new_state() {
		$state = self::STATE_PREFIX . wp_generate_password( 32, false );
		set_transient( 'mkl_drive_state_' . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS );
		return $state;
	}

	public static function check_state( $state ) {
		$key      = 'mkl_drive_state_' . get_current_user_id();
		$expected = get_transient( $key );
		delete_transient( $key );
		return is_string( $expected ) && is_string( $state ) && hash_equals( $expected, $state );
	}

	public static function auth_url( $state ) {
		$o = self::oauth();
		return self::AUTH_URL . '?' . http_build_query(
			array(
				'client_id'     => isset( $o['client_id'] ) ? $o['client_id'] : '',
				'redirect_uri'  => self::redirect_uri(),
				'response_type' => 'code',
				'scope'         => self::scopes(),
				'access_type'   => 'offline',
				// Sonst liefert Google bei erneuter Verbindung kein Refresh-Token.
				'prompt'        => 'consent',
				'state'         => $state,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * Tauscht den Code aus der Google-Weiterleitung gegen ein Refresh-Token.
	 *
	 * @return true|WP_Error
	 */
	public static function exchange_code( $code ) {
		$o = self::oauth();
		if ( empty( $o['client_id'] ) || empty( $o['client_secret'] ) ) {
			return new WP_Error( 'mkl_drive', 'Es sind noch keine Client-ID und kein Clientschlüssel gespeichert.' );
		}
		$body = self::token_request(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => (string) $code,
				'client_id'     => $o['client_id'],
				'client_secret' => $o['client_secret'],
				'redirect_uri'  => self::redirect_uri(),
			)
		);
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		if ( empty( $body['refresh_token'] ) ) {
			return new WP_Error( 'mkl_drive', 'Google hat kein Refresh-Token geliefert. Bitte die Verbindung erneut herstellen.' );
		}
		if ( isset( $body['scope'] ) && false === strpos( ' ' . $body['scope'] . ' ', ' ' . self::SCOPE . ' ' ) ) {
			return new WP_Error( 'mkl_drive', 'Der Zugriff auf Google Drive wurde nicht erlaubt. Bitte beim Verbinden das Häkchen für Google Drive setzen.' );
		}
		self::update_oauth(
			array(
				'refresh_token' => $body['refresh_token'],
				'email'         => '',
				// Bei Google lassen sich einzelne Berechtigungen abwählen; gemerkt wird, was erlaubt wurde.
				'scope'         => isset( $body['scope'] ) ? (string) $body['scope'] : self::scopes(),
			)
		);
		self::cache_token( $body );

		// Nur zur Anzeige: Mit welchem Konto ist verbunden?
		$res = wp_remote_get(
			self::ABOUT_URL . '?fields=user(emailAddress)',
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $body['access_token'] ),
			)
		);
		$about = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! empty( $about['user']['emailAddress'] ) ) {
			update_option( self::OAUTH_OPTION, array_merge( self::oauth(), array( 'email' => sanitize_email( $about['user']['emailAddress'] ) ) ), false );
		}
		return true;
	}

	/** Trennt die Verbindung und widerruft das Token bei Google (Fehler dabei sind egal). */
	public static function disconnect() {
		$o = self::oauth();
		if ( ! empty( $o['refresh_token'] ) ) {
			wp_remote_post(
				self::REVOKE_URL,
				array(
					'timeout' => 15,
					'body'    => array( 'token' => $o['refresh_token'] ),
				)
			);
		}
		self::update_oauth(
			array(
				'refresh_token' => '',
				'email'         => '',
				'scope'         => '',
			)
		);
	}

	/** Kennung des aktiven Zugangs, damit ein zwischengespeichertes Token nicht zum falschen gehört. */
	private static function identity() {
		if ( self::oauth_connected() ) {
			$o = self::oauth();
			return 'oauth:' . md5( $o['client_id'] . '|' . $o['refresh_token'] );
		}
		$sa = self::service_account();
		// Mit den Berechtigungen, damit ein Token von vor der Kalender-Erweiterung nicht weiterverwendet wird.
		return $sa ? 'sa:' . $sa['client_email'] . '|' . self::scopes() : '';
	}

	private static function cache_token( array $body ) {
		$ttl = max( 60, (int) ( isset( $body['expires_in'] ) ? $body['expires_in'] : 3600 ) - 300 );
		set_transient(
			self::TOKEN_CACHE,
			array(
				'id'    => self::identity(),
				'token' => $body['access_token'],
			),
			$ttl
		);
	}

	/** @return array|WP_Error Antwort des Token-Endpunkts. */
	private static function token_request( array $params, $uri = self::TOKEN_URI ) {
		$res = wp_remote_post(
			$uri,
			array(
				'timeout' => 20,
				'body'    => $params,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $res ) || empty( $body['access_token'] ) ) {
			$err = isset( $body['error'] ) && is_string( $body['error'] ) ? $body['error'] : '';
			$msg = isset( $body['error_description'] ) ? $body['error_description'] : ( $err ? $err : 'HTTP ' . wp_remote_retrieve_response_code( $res ) );
			return new WP_Error( 'mkl_drive', $msg, array( 'error' => $err ) );
		}
		return $body;
	}

	/**
	 * Prüft und speichert den JSON-Schlüssel.
	 *
	 * @return true|WP_Error
	 */
	public static function save_service_account( $json ) {
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) || empty( $data['client_email'] ) || empty( $data['private_key'] ) ) {
			return new WP_Error( 'mkl_drive', 'Das ist kein gültiger JSON-Schlüssel eines Dienstkontos (client_email/private_key fehlen).' );
		}
		if ( ! openssl_pkey_get_private( $data['private_key'] ) ) {
			return new WP_Error( 'mkl_drive', 'Der private Schlüssel im JSON kann nicht gelesen werden.' );
		}
		$keep = array_intersect_key( $data, array_flip( array( 'type', 'project_id', 'private_key_id', 'private_key', 'client_email', 'client_id', 'token_uri' ) ) );
		update_option( self::OPTION_KEY, wp_json_encode( $keep ), false );
		delete_transient( self::TOKEN_CACHE );
		return true;
	}

	private static function b64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/** Signiertes JWT für den Token-Austausch (RFC 7523). */
	public static function build_jwt( array $sa, $now = null ) {
		$now    = $now ? $now : time();
		$header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);
		$claims = array(
			'iss'   => $sa['client_email'],
			'scope' => self::scopes(),
			'aud'   => ! empty( $sa['token_uri'] ) ? $sa['token_uri'] : self::TOKEN_URI,
			'iat'   => $now,
			'exp'   => $now + 3600,
		);
		$input  = self::b64url( wp_json_encode( $header ) ) . '.' . self::b64url( wp_json_encode( $claims ) );
		$sig    = '';
		if ( ! openssl_sign( $input, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'mkl_drive', 'JWT konnte nicht signiert werden.' );
		}
		return $input . '.' . self::b64url( $sig );
	}

	/** @return string|WP_Error */
	public static function access_token() {
		if ( ! self::is_configured() ) {
			return new WP_Error( 'mkl_drive', 'Es ist noch kein Zugang zu Google Drive eingerichtet.' );
		}
		$cached = get_transient( self::TOKEN_CACHE );
		if ( is_array( $cached ) && isset( $cached['id'] ) && $cached['id'] === self::identity() ) {
			return $cached['token'];
		}

		if ( self::oauth_connected() ) {
			$o    = self::oauth();
			$body = self::token_request(
				array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $o['refresh_token'],
					'client_id'     => $o['client_id'],
					'client_secret' => $o['client_secret'],
				)
			);
			if ( is_wp_error( $body ) ) {
				$data = $body->get_error_data();
				if ( isset( $data['error'] ) && 'invalid_grant' === $data['error'] ) {
					return new WP_Error( 'mkl_drive', 'Die Verbindung zu Google ist abgelaufen oder wurde widerrufen. Bitte unter Einstellungen → Kompanie-App → Galerie neu mit Google verbinden.' );
				}
				return new WP_Error( 'mkl_drive', 'Google-Anmeldung fehlgeschlagen: ' . $body->get_error_message() );
			}
		} else {
			$sa  = self::service_account();
			$jwt = self::build_jwt( $sa );
			if ( is_wp_error( $jwt ) ) {
				return $jwt;
			}
			$body = self::token_request(
				array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
				! empty( $sa['token_uri'] ) ? $sa['token_uri'] : self::TOKEN_URI
			);
			if ( is_wp_error( $body ) ) {
				return new WP_Error( 'mkl_drive', 'Google-Anmeldung des Dienstkontos fehlgeschlagen: ' . $body->get_error_message() );
			}
		}
		self::cache_token( $body );
		return $body['access_token'];
	}

	/**
	 * Alle Kinder eines Ordners (mit Paging).
	 *
	 * @return array[]|WP_Error
	 */
	public static function list_children( $folder_id ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$files      = array();
		$page_token = null;
		do {
			$params = array(
				'q'                         => sprintf( "'%s' in parents and trashed = false", str_replace( "'", "\\'", $folder_id ) ),
				'fields'                    => 'nextPageToken,files(id,name,mimeType,createdTime,modifiedTime,md5Checksum,imageMediaMetadata(width,height,time))',
				'pageSize'                  => 1000,
				'orderBy'                   => 'name',
				'supportsAllDrives'         => 'true',
				'includeItemsFromAllDrives' => 'true',
			);
			if ( $page_token ) {
				$params['pageToken'] = $page_token;
			}
			$res = wp_remote_get(
				self::FILES_URL . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ),
				array(
					'timeout' => 30,
					'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				)
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$body = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( 200 !== wp_remote_retrieve_response_code( $res ) || ! is_array( $body ) ) {
				$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . wp_remote_retrieve_response_code( $res );
				return new WP_Error( 'mkl_drive', 'Google Drive: ' . $msg );
			}
			foreach ( isset( $body['files'] ) ? $body['files'] : array() as $f ) {
				$files[] = $f;
			}
			$page_token = isset( $body['nextPageToken'] ) ? $body['nextPageToken'] : null;
		} while ( $page_token );
		return $files;
	}

	/**
	 * Lädt eine Datei in eine temporäre Datei.
	 *
	 * @return string|WP_Error Pfad der temporären Datei (Aufrufer löscht sie).
	 */
	public static function download( $file_id ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$res = wp_remote_get(
			self::FILES_URL . '/' . rawurlencode( $file_id ) . '?alt=media&supportsAllDrives=true',
			array(
				'timeout' => 90,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== wp_remote_retrieve_response_code( $res ) ) {
			return new WP_Error( 'mkl_drive', 'Download fehlgeschlagen: HTTP ' . wp_remote_retrieve_response_code( $res ) );
		}
		$tmp = wp_tempnam( 'mkl-drive-' . $file_id );
		file_put_contents( $tmp, wp_remote_retrieve_body( $res ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $tmp;
	}

	public static function is_folder( array $f ) {
		return self::FOLDER_MIME === $f['mimeType'];
	}

	public static function is_image( array $f ) {
		return 0 === strpos( $f['mimeType'], 'image/' );
	}

	/** Ordner-ID aus einer Drive-URL oder direkt. */
	public static function parse_folder_id( $input ) {
		$input = trim( (string) $input );
		if ( preg_match( '#/folders/([A-Za-z0-9_-]+)#', $input, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '#[?&]id=([A-Za-z0-9_-]+)#', $input, $m ) ) {
			return $m[1];
		}
		return preg_match( '/^[A-Za-z0-9_-]+$/', $input ) ? $input : '';
	}
}
