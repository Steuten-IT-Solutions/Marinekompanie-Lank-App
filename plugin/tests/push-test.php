<?php
/**
 * Tests für Push-Benachrichtigungen (Verschlüsselung, VAPID, REST, Versand).
 *
 * Ausführen gegen eine Test-WordPress-Installation mit aktivem Plugin:
 *   wp eval-file plugin/tests/push-test.php
 *
 * Der Push-Dienst wird über pre_http_request simuliert; es geht nichts ins Netz.
 * Exit-Code 1, wenn ein Test fehlschlägt.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/user.php';

$GLOBALS['mkl_failures'] = 0;
$GLOBALS['mkl_passes']   = 0;

function mkl_assert( $cond, $label ) {
	if ( $cond ) {
		$GLOBALS['mkl_passes']++;
		echo "  ok   $label\n";
	} else {
		$GLOBALS['mkl_failures']++;
		echo "  FAIL $label\n";
	}
}

function mkl_req( $method, $route, $params = array() ) {
	$req = new WP_REST_Request( $method, '/mk-lank/v1' . $route );
	if ( $params ) {
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( $params ) );
	}
	$res = rest_do_request( $req );
	return array( $res->get_status(), $res->get_data() );
}

function mkl_push_user( $login, $member ) {
	$existing = get_user_by( 'login', $login );
	if ( $existing ) {
		wp_delete_user( $existing->ID );
	}
	$id = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password(),
			'display_name' => ucfirst( $login ),
			'role'         => 'subscriber',
		)
	);
	MKL_Roles::set_level( $id, $member );
	return $id;
}

/** Schlüssel aus rohem privatem Skalar und öffentlichem Punkt (SEC1/RFC 5915). */
function mkl_ec_private_key( $d, $pub ) {
	$der = hex2bin( '30770201010420' ) . $d . hex2bin( 'a00a06082a8648ce3d030107a144034200' ) . $pub;
	return openssl_pkey_get_private( "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END EC PRIVATE KEY-----\n" );
}

/** Ein simuliertes Gerät: Schlüsselpaar, auth-Geheimnis und Endpoint. */
function mkl_device( $name ) {
	$key = openssl_pkey_new(
		array(
			'private_key_type' => OPENSSL_KEYTYPE_EC,
			'curve_name'       => 'prime256v1',
		)
	);
	return array(
		'key'      => $key,
		'endpoint' => 'https://push.example.test/send/' . $name,
		'p256dh'   => MKL_Push::b64u_encode( MKL_Push::raw_public( $key ) ),
		'auth'     => MKL_Push::b64u_encode( random_bytes( 16 ) ),
	);
}

/** Entschlüsselt eine Push-Nachricht als Gerät (Gegenstück zu MKL_Push::encrypt). */
function mkl_decrypt( $body, array $device ) {
	$salt      = substr( $body, 0, 16 );
	$idlen     = ord( $body[20] );
	$as_public = substr( $body, 21, $idlen );
	$data      = substr( $body, 21 + $idlen );
	$ua_public = MKL_Push::raw_public( $device['key'] );
	$ecdh      = openssl_pkey_derive( MKL_Push::public_key_from_raw( $as_public ), $device['key'], 32 );
	$auth      = MKL_Push::b64u_decode( $device['auth'] );
	$prk_key   = hash_hmac( 'sha256', $ecdh, $auth, true );
	$ikm       = hash_hmac( 'sha256', "WebPush: info\0" . $ua_public . $as_public . "\x01", $prk_key, true );
	$prk       = hash_hmac( 'sha256', $ikm, $salt, true );
	$cek       = substr( hash_hmac( 'sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true ), 0, 16 );
	$nonce     = substr( hash_hmac( 'sha256', "Content-Encoding: nonce\0\x01", $prk, true ), 0, 12 );
	$plain     = openssl_decrypt( substr( $data, 0, -16 ), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr( $data, -16 ) );
	return false === $plain ? false : rtrim( $plain, "\x02" );
}

echo "Voraussetzungen\n";
mkl_assert( MKL_Push::is_supported(), 'OpenSSL mit P-256 und AES-GCM vorhanden' );

// ------------------------------------------------------------------
echo "\nRFC 8291, Anhang A (offizieller Testvektor)\n";
$v        = function ( $s ) {
	return MKL_Push::b64u_decode( preg_replace( '/\s+/', '', $s ) );
};
$as_pub   = $v( 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8' );
$as_key   = mkl_ec_private_key( $v( 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw' ), $as_pub );
$ua_pub   = $v( 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4' );
$ecdh     = openssl_pkey_derive( MKL_Push::public_key_from_raw( $ua_pub ), $as_key, 32 );
mkl_assert( MKL_Push::b64u_encode( $ecdh ) === 'kyrL1jIIOHEzg3sM2ZWRHDRB62YACZhhSlknJ672kSs', 'ECDH-Geheimnis wie im RFC' );
$body     = MKL_Push::encrypt(
	$v( 'V2hlbiBJIGdyb3cgdXAsIEkgd2FudCB0byBiZSBhIHdhdGVybWVsb24' ),
	$ua_pub,
	$v( 'BTBZMqHH6r4Tts7J_aSIgg' ),
	$v( 'DGv6ra1nlYgDCS1FRnbzlw' ),
	$as_key
);
// Header (86 Byte) und Chiffrat stehen im RFC getrennt; 86 ist nicht durch 3 teilbar, daher einzeln vergleichen.
mkl_assert( ! is_wp_error( $body ) && MKL_Push::b64u_encode( substr( $body, 0, 86 ) ) === 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8', 'Header (Salt, rs = 4096, Absenderschlüssel) wie im RFC' );
mkl_assert( ! is_wp_error( $body ) && MKL_Push::b64u_encode( substr( $body, 86 ) ) === '8pfeW0KbunFT06SuDKoJH9Ql87S1QUrdirN6GcG7sFz1y1sqLgVi1VhjVkHsUoEsbI_0LpXMuGvnzQ', 'Chiffrat wie im RFC' );

$dev   = mkl_device( 'roundtrip' );
$plain = '{"title":"Größe ẞ äöü"}';
$enc   = MKL_Push::encrypt( $plain, MKL_Push::b64u_decode( $dev['p256dh'] ), MKL_Push::b64u_decode( $dev['auth'] ) );
mkl_assert( mkl_decrypt( $enc, $dev ) === $plain, 'Zufälliger Salt/Schlüssel: Gerät kann entschlüsseln' );
mkl_assert( strlen( $enc ) === 86 + strlen( $plain ) + 1 + 16, 'Länge: 86 Byte Header + Inhalt + Trennbyte + 16 Byte Tag' );

// ------------------------------------------------------------------
echo "\nVAPID (RFC 8292)\n";
$keys = MKL_Push::vapid();
mkl_assert( $keys && 65 === strlen( MKL_Push::b64u_decode( $keys['public'] ) ), 'Schlüsselpaar erzeugt, öffentlicher Schlüssel 65 Byte' );
mkl_assert( MKL_Push::vapid() === $keys, 'Schlüsselpaar bleibt gespeichert' );
$now    = time();
$jwt    = MKL_Push::vapid_jwt( 'https://push.example.test:8443/send/abc', null, $now );
$parts  = explode( '.', $jwt );
$head   = json_decode( MKL_Push::b64u_decode( $parts[0] ), true );
$claims = json_decode( MKL_Push::b64u_decode( $parts[1] ), true );
mkl_assert( 'ES256' === $head['alg'] && 'JWT' === $head['typ'], 'JWT-Header ES256' );
mkl_assert( 'https://push.example.test:8443' === $claims['aud'] && $now + 12 * HOUR_IN_SECONDS === $claims['exp'] && 0 === strpos( $claims['sub'], 'mailto:' ), 'Claims: aud = Ursprung, exp = 12 h, sub = mailto' );
$pub_key = MKL_Push::public_key_from_raw( MKL_Push::b64u_decode( $keys['public'] ) );
$valid   = true;
for ( $i = 0; $i < 25; $i++ ) {
	$p      = explode( '.', MKL_Push::vapid_jwt( 'https://push.example.test/x' . $i ) );
	$sig    = MKL_Push::b64u_decode( $p[2] );
	$valid  = $valid && 64 === strlen( $sig ) && 1 === openssl_verify( $p[0] . '.' . $p[1], MKL_Push::raw_to_der_signature( $sig ), $pub_key, OPENSSL_ALGO_SHA256 );
}
mkl_assert( $valid, '25 Signaturen (roh r||s, 64 Byte) lassen sich mit dem öffentlichen Schlüssel prüfen' );

// ------------------------------------------------------------------
echo "\nREST\n";
$spiess = mkl_push_user( 'mkl_push_spiess', MKL_Roles::LEVEL_SPIESS );
$m1     = mkl_push_user( 'mkl_push_m1', MKL_Roles::LEVEL_MITGLIED );
$m2     = mkl_push_user( 'mkl_push_m2', MKL_Roles::LEVEL_MITGLIED );
$ohne   = mkl_push_user( 'mkl_push_ohne', MKL_Roles::LEVEL_OHNE );
$phone  = mkl_device( 'phone' );
$tablet = mkl_device( 'tablet' );
$sub    = function ( array $d, array $extra = array() ) {
	return mkl_req(
		'POST',
		'/me/push',
		array(
			'endpoint' => $d['endpoint'],
			'keys'     => array(
				'p256dh' => $d['p256dh'],
				'auth'   => $d['auth'],
			),
		) + $extra
	);
};

wp_set_current_user( 0 );
list( $s ) = mkl_req( 'POST', '/me/push/status', array() );
mkl_assert( 401 === $s, 'Ohne Anmeldung: 401' );

wp_set_current_user( $ohne );
list( $s, $d ) = mkl_req( 'POST', '/me/push/status', array() );
mkl_assert( 200 === $s && array() === $d['types'] && $d['public_key'] === $keys['public'], 'Ohne Rolle: keine Ereignistypen, öffentlicher Schlüssel' );
list( $s ) = $sub( $phone );
mkl_assert( 403 === $s, 'Ohne Rolle: Abo abgelehnt' );

wp_set_current_user( $m1 );
list( $s, $d ) = mkl_req( 'POST', '/me/push/status', array() );
mkl_assert( 200 === $s && array( 'strafen', 'punkte', 'dienstgrad' ) === wp_list_pluck( $d['types'], 'key' ) && null === $d['subscription'], 'Mitglied: drei Ereignistypen, noch kein Abo' );
list( $s ) = $sub( array( 'endpoint' => 'http://push.example.test/x' ) + $phone );
mkl_assert( 400 === $s, 'Nur https-Endpoints' );
list( $s ) = $sub( array( 'p256dh' => MKL_Push::b64u_encode( random_bytes( 65 ) ) ) + $phone );
mkl_assert( 400 === $s, 'Ungültiger Geräteschlüssel abgelehnt' );
list( $s ) = $sub( array( 'auth' => MKL_Push::b64u_encode( random_bytes( 8 ) ) ) + $phone );
mkl_assert( 400 === $s, 'auth-Geheimnis mit falscher Länge abgelehnt' );
list( $s, $d ) = $sub( $phone );
mkl_assert( 200 === $s && array( 'strafen', 'punkte', 'dienstgrad' ) === $d['types'] && false === $d['details'], 'Abo: alle Typen, Strafen ohne Einzelheiten' );
list( $s, $d ) = $sub( $tablet, array( 'types' => array( 'strafen', 'unbekannt' ), 'details' => true ) );
mkl_assert( 200 === $s && array( 'strafen' ) === $d['types'] && true === $d['details'], 'Zweites Gerät: nur Strafen, mit Einzelheiten; unbekannte Typen ignoriert' );
list( $s, $d ) = mkl_req( 'PUT', '/me/push', array( 'endpoint' => $phone['endpoint'], 'types' => array( 'strafen', 'dienstgrad' ) ) );
mkl_assert( 200 === $s && array( 'strafen', 'dienstgrad' ) === $d['types'], 'Einstellungen ändern' );
list( $s, $d ) = $sub( $phone );
mkl_assert( 200 === $s && array( 'strafen', 'dienstgrad' ) === $d['types'], 'Erneutes Abo desselben Kontos behält die Einstellungen' );
list( $s, $d ) = mkl_req( 'POST', '/me/push/status', array( 'endpoint' => $phone['endpoint'] ) );
mkl_assert( array( 'strafen', 'dienstgrad' ) === $d['subscription']['types'], 'Status liefert das Abo dieses Geräts' );

wp_set_current_user( $m2 );
list( $s ) = mkl_req( 'PUT', '/me/push', array( 'endpoint' => $phone['endpoint'], 'types' => array() ) );
mkl_assert( 404 === $s, 'Fremdes Abo ändern: 404' );
list( $s ) = mkl_req( 'POST', '/me/push/test', array( 'endpoint' => $phone['endpoint'] ) );
mkl_assert( 404 === $s, 'Fremdes Abo testen: 404' );
list( $s, $d ) = mkl_req( 'POST', '/me/push/status', array( 'endpoint' => $phone['endpoint'] ) );
mkl_assert( null === $d['subscription'], 'Fremdes Abo im Status nicht sichtbar' );

// Gerätewechsel: Mitglied 2 meldet sich auf dem Tablet an.
$m2_tablet = $tablet;
list( $s, $d ) = $sub( $m2_tablet );
$row = MKL_Push::get_by_endpoint( $tablet['endpoint'] );
mkl_assert( 200 === $s && (int) $row['user_id'] === $m2 && array( 'strafen', 'punkte', 'dienstgrad' ) === $d['types'] && false === $d['details'], 'Anderes Konto auf demselben Gerät: Abo übernommen, Einstellungen zurückgesetzt' );
wp_set_current_user( $m1 );
$sub( $tablet, array( 'types' => array( 'strafen' ), 'details' => true ) );
wp_set_current_user( $m2 );
$m2dev = mkl_device( 'm2phone' );
$sub( $m2dev );

// ------------------------------------------------------------------
echo "\nEreignisse und Warteschlange\n";
global $wpdb;
$q = MKL_Push::queue_table();
$wpdb->query( "DELETE FROM $q" ); // phpcs:ignore WordPress.DB.PreparedSQL
wp_set_current_user( $spiess );
list( $s ) = mkl_req( 'POST', '/penalties', array( 'user_ids' => array( $m1 ), 'amount_cents' => 250, 'reason' => 'Zu spät' ) );
$queued = $wpdb->get_results( "SELECT * FROM $q ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
$by_sub = array();
foreach ( $queued as $r ) {
	$by_sub[ (int) $r['subscription_id'] ] = json_decode( $r['payload'], true );
}
$phone_id  = (int) MKL_Push::get_by_endpoint( $phone['endpoint'] )['id'];
$tablet_id = (int) MKL_Push::get_by_endpoint( $tablet['endpoint'] )['id'];
mkl_assert( 201 === $s && 2 === count( $queued ), 'Strafe: je Gerät eine Nachricht' );
mkl_assert( 'Neue Strafe' === $by_sub[ $phone_id ]['title'] && 'Tippen zum Ansehen.' === $by_sub[ $phone_id ]['body'], 'Ohne Einzelheiten: nur Hinweis' );
mkl_assert( '2,50 € – Zu spät' === $by_sub[ $tablet_id ]['body'], 'Mit Einzelheiten: Betrag und Grund' );
mkl_assert( false !== strpos( $by_sub[ $phone_id ]['url'], '#/meins/strafen' ), 'Tippen öffnet die Strafen' );
mkl_assert( false !== wp_next_scheduled( MKL_Push::CRON_HOOK ), 'Versand per WP-Cron geplant' );

$wpdb->query( "DELETE FROM $q" ); // phpcs:ignore WordPress.DB.PreparedSQL
list( $s ) = mkl_req( 'POST', '/points', array( 'user_ids' => array( $m1, $m2 ), 'points' => 3, 'reason' => 'Arbeitsdienst' ) );
$queued = $wpdb->get_results( "SELECT * FROM $q", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
$p      = json_decode( $queued[0]['payload'], true );
mkl_assert( 201 === $s && 1 === count( $queued ) && (int) $queued[0]['subscription_id'] === (int) MKL_Push::get_by_endpoint( $m2dev['endpoint'] )['id'], 'Punkte: nur Geräte mit Typ „Punkte“ (Mitglied 2)' );
mkl_assert( 'Punkte erhalten' === $p['title'] && '+3 Punkte – Arbeitsdienst' === $p['body'], 'Punkte-Text' );
$wpdb->query( "DELETE FROM $q" ); // phpcs:ignore WordPress.DB.PreparedSQL
MKL_Push::points_added( array( array( 'id' => 1, 'user_id' => $m2, 'points' => -1, 'reason' => 'Fehlt' ) ) );
$p = json_decode( $wpdb->get_var( "SELECT payload FROM $q" ), true ); // phpcs:ignore WordPress.DB.PreparedSQL
mkl_assert( 'Punkte abgezogen' === $p['title'] && '−1 Punkt – Fehlt' === $p['body'], 'Abzug: Text mit Minus, Einzahl' );

$wpdb->query( "DELETE FROM $q" ); // phpcs:ignore WordPress.DB.PreparedSQL
MKL_Push::rank_changed( $m1, array( 'title' => 'Maat' ), MKL_Ranks::KIND_PROMOTION );
$p = json_decode( $wpdb->get_var( "SELECT payload FROM $q" ), true ); // phpcs:ignore WordPress.DB.PreparedSQL
mkl_assert( 'Befördert' === $p['title'] && 'Neuer Dienstgrad: Maat' === $p['body'], 'Dienstgrad: Beförderung (nur Telefon hat den Typ)' );

// ------------------------------------------------------------------
echo "\nVersand (simulierter Push-Dienst)\n";
$GLOBALS['mkl_push_calls'] = array();
$GLOBALS['mkl_push_codes'] = array();
$mock                      = function ( $pre, $args, $url ) {
	if ( 'push.example.test' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
		return $pre;
	}
	$GLOBALS['mkl_push_calls'][] = array(
		'url'  => $url,
		'args' => $args,
	);
	$name = basename( $url );
	$code = isset( $GLOBALS['mkl_push_codes'][ $name ] ) ? $GLOBALS['mkl_push_codes'][ $name ] : 201;
	return array(
		'headers'  => array(),
		'body'     => '',
		'response' => array(
			'code'    => $code,
			'message' => '',
		),
		'cookies'  => array(),
	);
};
add_filter( 'pre_http_request', $mock, 10, 3 );

$wpdb->query( "DELETE FROM $q" ); // phpcs:ignore WordPress.DB.PreparedSQL
wp_set_current_user( $spiess );
mkl_req( 'POST', '/penalties', array( 'user_ids' => array( $m1 ), 'amount_cents' => 1000, 'reason' => 'Uniform' ) );
$GLOBALS['mkl_push_codes'] = array(
	'phone'  => 201,
	'tablet' => 410,
);
$sent  = MKL_Push::process_queue();
$calls = $GLOBALS['mkl_push_calls'];
$call  = null;
foreach ( $calls as $c ) {
	if ( $c['url'] === $phone['endpoint'] ) {
		$call = $c;
	}
}
$h = $call['args']['headers'];
mkl_assert( 1 === $sent && 2 === count( $calls ), 'Zwei Zustellversuche, einer erfolgreich' );
mkl_assert( 'aes128gcm' === $h['Content-Encoding'] && (string) DAY_IN_SECONDS === $h['TTL'] && 'normal' === $h['Urgency'], 'Header: aes128gcm, TTL, Urgency' );
mkl_assert( 0 === strpos( $h['Authorization'], 'vapid t=' ) && false !== strpos( $h['Authorization'], ', k=' . $keys['public'] ), 'Authorization: vapid t=…, k=öffentlicher Schlüssel' );
$msg = json_decode( (string) mkl_decrypt( $call['args']['body'], $phone ), true );
mkl_assert( is_array( $msg ) && 'Neue Strafe' === $msg['title'], 'Gerät entschlüsselt die zugestellte Nachricht' );
mkl_assert( null === MKL_Push::get_by_endpoint( $tablet['endpoint'] ), '410: Abo gelöscht' );
mkl_assert( null !== MKL_Push::get_by_endpoint( $phone['endpoint'] )['last_sent_at'], 'Erfolg: Zeitpunkt gespeichert' );
mkl_assert( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $q" ), 'Warteschlange leer' ); // phpcs:ignore WordPress.DB.PreparedSQL

$GLOBALS['mkl_push_codes'] = array( 'phone' => 503 );
$GLOBALS['mkl_push_calls'] = array();
MKL_Push::rank_changed( $m1, null, MKL_Ranks::KIND_DEMOTION );
MKL_Push::process_queue();
$left = $wpdb->get_row( "SELECT * FROM $q", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
mkl_assert( $left && 1 === (int) $left['attempts'] && strtotime( $left['next_try'] . ' UTC' ) > time(), '503: später erneut versuchen' );
MKL_Push::process_queue();
mkl_assert( 1 === count( $GLOBALS['mkl_push_calls'] ), 'Vor dem nächsten Versuch kein erneuter Versand' );
$wpdb->query( "DELETE FROM $q" ); // phpcs:ignore WordPress.DB.PreparedSQL

wp_set_current_user( $m1 );
$GLOBALS['mkl_push_codes'] = array( 'phone' => 201 );
list( $s, $d ) = mkl_req( 'POST', '/me/push/test', array( 'endpoint' => $phone['endpoint'] ) );
mkl_assert( 200 === $s && true === $d['sent'], 'Testbenachrichtigung zugestellt' );
$GLOBALS['mkl_push_codes'] = array( 'phone' => 400 );
list( $s ) = mkl_req( 'POST', '/me/push/test', array( 'endpoint' => $phone['endpoint'] ) );
mkl_assert( 502 === $s && null !== MKL_Push::get_by_endpoint( $phone['endpoint'] ), 'Test mit Fehler 400: Meldung, Abo bleibt' );
$GLOBALS['mkl_push_codes'] = array( 'phone' => 404 );
list( $s ) = mkl_req( 'POST', '/me/push/test', array( 'endpoint' => $phone['endpoint'] ) );
mkl_assert( 410 === $s && null === MKL_Push::get_by_endpoint( $phone['endpoint'] ), 'Test mit 404: Abo entfernt' );
remove_filter( 'pre_http_request', $mock, 10 );

// ------------------------------------------------------------------
echo "\nAbbestellen und Konto löschen\n";
$sub( $phone );
list( $s, $d ) = mkl_req( 'DELETE', '/me/push', array( 'endpoint' => $phone['endpoint'] ) );
mkl_assert( 200 === $s && true === $d['deleted'] && null === MKL_Push::get_by_endpoint( $phone['endpoint'] ), 'Abbestellen' );
list( $s, $d ) = mkl_req( 'DELETE', '/me/push', array( 'endpoint' => $phone['endpoint'] ) );
mkl_assert( 200 === $s && false === $d['deleted'], 'Erneut abbestellen: kein Fehler' );
$sub( $phone );
$sub( $tablet );
MKL_Push::notify_user( $m1, MKL_Push::TYPE_STRAFEN, MKL_Push::message( 'x', 'y', 'konto' ) );
wp_delete_user( $m1 );
mkl_assert( array() === MKL_Push::for_user( $m1 ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $q" ), 'Konto gelöscht: Abos und Nachrichten entfernt' ); // phpcs:ignore WordPress.DB.PreparedSQL

// Aufräumen.
foreach ( array( $spiess, $m2, $ohne ) as $uid ) {
	wp_delete_user( $uid );
}
wp_clear_scheduled_hook( MKL_Push::CRON_HOOK );

printf( "\n%d bestanden, %d fehlgeschlagen\n", $GLOBALS['mkl_passes'], $GLOBALS['mkl_failures'] );
if ( $GLOBALS['mkl_failures'] ) {
	exit( 1 );
}
