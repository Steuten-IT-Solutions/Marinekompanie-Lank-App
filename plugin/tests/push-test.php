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
$ohnedev = mkl_device( 'ohne' );
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
mkl_assert( 200 === $s && array( 'termine', 'erinnerung', 'news' ) === wp_list_pluck( $d['types'], 'key' ) && $d['public_key'] === $keys['public'], 'Ohne Rolle: Termine, Erinnerung, News; öffentlicher Schlüssel' );
list( $s, $d ) = $sub( $ohnedev, array( 'types' => array( 'strafen', 'termine', 'news' ) ) );
mkl_assert( 200 === $s && array( 'termine', 'news' ) === $d['types'], 'Ohne Rolle: Abo ohne persönliche Typen' );

wp_set_current_user( $m1 );
list( $s, $d ) = mkl_req( 'POST', '/me/push/status', array() );
mkl_assert( 200 === $s && array( 'strafen', 'punkte', 'dienstgrad', 'termine', 'erinnerung', 'news' ) === wp_list_pluck( $d['types'], 'key' ) && null === $d['subscription'], 'Mitglied: sechs Ereignistypen, noch kein Abo' );
list( $s ) = $sub( array( 'endpoint' => 'http://push.example.test/x' ) + $phone );
mkl_assert( 400 === $s, 'Nur https-Endpoints' );
list( $s ) = $sub( array( 'p256dh' => MKL_Push::b64u_encode( random_bytes( 65 ) ) ) + $phone );
mkl_assert( 400 === $s, 'Ungültiger Geräteschlüssel abgelehnt' );
list( $s ) = $sub( array( 'auth' => MKL_Push::b64u_encode( random_bytes( 8 ) ) ) + $phone );
mkl_assert( 400 === $s, 'auth-Geheimnis mit falscher Länge abgelehnt' );
list( $s, $d ) = $sub( $phone );
mkl_assert( 200 === $s && array( 'strafen', 'punkte', 'dienstgrad', 'termine', 'erinnerung', 'news' ) === $d['types'] && false === $d['details'], 'Abo: alle Typen, Strafen ohne Einzelheiten' );
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
mkl_assert( 200 === $s && (int) $row['user_id'] === $m2 && array( 'strafen', 'punkte', 'dienstgrad', 'termine', 'erinnerung', 'news' ) === $d['types'] && false === $d['details'], 'Anderes Konto auf demselben Gerät: Abo übernommen, Einstellungen zurückgesetzt' );
$fake = array( 'auth' => MKL_Push::b64u_encode( random_bytes( 16 ) ) ) + $tablet;
list( $s ) = $sub( $fake );
mkl_assert( 403 === $s, 'Fremdes Gerät (anderes auth-Geheimnis) kann ein Abo nicht übernehmen' );
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
echo "\nGäste ohne Anmeldung\n";
$q_count = function () use ( $wpdb, $q ) {
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $q" ); // phpcs:ignore WordPress.DB.PreparedSQL
};
$q_payloads = function () use ( $wpdb, $q ) {
	$out = array();
	foreach ( $wpdb->get_results( "SELECT subscription_id, payload FROM $q ORDER BY id", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
		$out[] = array( 'sub' => (int) $r['subscription_id'] ) + json_decode( $r['payload'], true );
	}
	return $out;
};
$sub_id = function ( array $d ) {
	return (int) MKL_Push::get_by_endpoint( $d['endpoint'] )['id'];
};
wp_set_current_user( 0 );
$guest = mkl_device( 'guest' );
$gsub  = function ( array $d ) {
	return mkl_req( 'POST', '/push', array( 'endpoint' => $d['endpoint'], 'keys' => array( 'p256dh' => $d['p256dh'], 'auth' => $d['auth'] ) ) );
};
list( $s, $d ) = mkl_req( 'POST', '/push/status', array() );
mkl_assert( 200 === $s && array( 'termine', 'erinnerung', 'news' ) === wp_list_pluck( $d['types'], 'key' ), 'Gast: Termine, Erinnerung, News' );
list( $s, $d ) = $gsub( $guest );
$grow = MKL_Push::get_by_endpoint( $guest['endpoint'] );
mkl_assert( 200 === $s && array( 'termine', 'erinnerung', 'news' ) === $d['types'] && 0 === (int) $grow['user_id'], 'Gast abonniert ohne Konto' );
list( $s, $d ) = mkl_req( 'POST', '/push/status', array( 'endpoint' => $guest['endpoint'], 'auth' => $guest['auth'] ) );
mkl_assert( null !== $d['subscription'], 'Gast: Status mit auth-Geheimnis zeigt das Abo' );
$wrong = MKL_Push::b64u_encode( random_bytes( 16 ) );
list( $s, $d ) = mkl_req( 'POST', '/push/status', array( 'endpoint' => $guest['endpoint'], 'auth' => $wrong ) );
mkl_assert( null === $d['subscription'], 'Gast: falsches Geheimnis zeigt nichts' );
list( $s ) = mkl_req( 'PUT', '/push', array( 'endpoint' => $guest['endpoint'], 'auth' => $wrong, 'types' => array() ) );
mkl_assert( 404 === $s, 'Gast: Ändern mit falschem Geheimnis abgelehnt' );
list( $s, $d ) = mkl_req( 'DELETE', '/push', array( 'endpoint' => $guest['endpoint'], 'auth' => $wrong ) );
mkl_assert( false === $d['deleted'] && null !== MKL_Push::get_by_endpoint( $guest['endpoint'] ), 'Gast: Abbestellen mit falschem Geheimnis wirkungslos' );
list( $s, $d ) = mkl_req( 'PUT', '/push', array( 'endpoint' => $guest['endpoint'], 'auth' => $guest['auth'], 'types' => array( 'erinnerung', 'termine', 'strafen' ) ) );
mkl_assert( 200 === $s && array( 'termine', 'erinnerung' ) === $d['types'], 'Gast ändert Einstellungen (keine persönlichen Typen)' );
list( $s ) = mkl_req( 'POST', '/push/status', array() );
wp_set_current_user( $m1 );
list( $s ) = mkl_req( 'PUT', '/me/push', array( 'endpoint' => $guest['endpoint'], 'types' => array() ) );
mkl_assert( 404 === $s, 'Angemeldetes Konto kann Gäste-Abo nicht über /me/push ändern' );
wp_set_current_user( 0 );
$rl_ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
$rl_key = 'mkl_push_rl_' . md5( $rl_ip . wp_salt( 'nonce' ) );
set_transient( $rl_key, MKL_Push::GUEST_RATE, HOUR_IN_SECONDS );
$flood = mkl_device( 'flood' );
list( $s ) = $gsub( $flood );
mkl_assert( 429 === $s && null === MKL_Push::get_by_endpoint( $flood['endpoint'] ), 'Gast: zu viele neue Abos je IP-Adresse → 429' );
delete_transient( $rl_key );

// ------------------------------------------------------------------
echo "\nÖffentliche Termine\n";
$admin = wp_insert_user(
	array(
		'user_login' => 'mkl_push_admin',
		'user_pass'  => wp_generate_password(),
		'role'       => 'administrator',
	)
);
wp_set_current_user( $admin );
$tomorrow = wp_date( 'Y-m-d', time() + DAY_IN_SECONDS );
$wpdb->query( "DELETE FROM $q" ); // phpcs:ignore WordPress.DB.PreparedSQL
list( $s, $ev ) = mkl_req( 'POST', '/events', array( 'title' => 'Pushfest', 'start_date' => $tomorrow, 'start_time' => '14:00', 'location' => 'Schulhof' ) );
$p    = $q_payloads();
$subs = wp_list_pluck( $p, 'sub' );
sort( $subs );
$want = array( $sub_id( $guest ), $sub_id( $ohnedev ) );
sort( $want );
mkl_assert( 201 === $s && $want === $subs, 'Neuer öffentlicher Termin: an Gast und Konto ohne Rolle, nicht an Mitglieder' );
mkl_assert( 'Neuer Termin' === $p[0]['title'] && false !== strpos( $p[0]['body'], 'Pushfest – ' ) && false !== strpos( $p[0]['body'], '14:00 Uhr – Schulhof' ), 'Text: Titel, Datum mit Uhrzeit, Ort' );
$wpdb->query( "DELETE FROM $q" ); // phpcs:ignore WordPress.DB.PreparedSQL
mkl_req( 'PATCH', '/events/' . $ev['id'], array( 'title' => 'Pushfest 2026', 'description' => 'Neu' ) );
mkl_assert( 0 === $q_count(), 'Nur Titel/Beschreibung geändert: keine Nachricht' );
mkl_req( 'PATCH', '/events/' . $ev['id'], array( 'location' => 'Schützenplatz' ) );
$p = $q_payloads();
mkl_assert( 2 === count( $p ) && 'Termin geändert' === $p[0]['title'] && false !== strpos( $p[0]['body'], 'Schützenplatz' ), 'Ort geändert: „Termin geändert“' );
$wpdb->query( "DELETE FROM $q" ); // phpcs:ignore WordPress.DB.PreparedSQL
list( $s, $past ) = mkl_req( 'POST', '/events', array( 'title' => 'Gestern', 'start_date' => wp_date( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ) ) );
mkl_assert( 201 === $s && 0 === $q_count(), 'Vergangener Termin: keine Nachricht' );
mkl_assert( 'Fr 04.09.2026 – Mo 07.09.2026' === MKL_Push::format_when( array( 'start' => '2026-09-04', 'end' => '2026-09-08', 'all_day' => true ) ), 'Mehrtägig ganztägig: „Fr 04.09.2026 – Mo 07.09.2026“' );

// ------------------------------------------------------------------
echo "\nNews\n";
$wpdb->query( "DELETE FROM $q" ); // phpcs:ignore WordPress.DB.PreparedSQL
$post = wp_insert_post( array( 'post_title' => 'Ahoi &amp; willkommen', 'post_status' => 'publish', 'post_content' => 'x' ) );
$p    = $q_payloads();
$subs = wp_list_pluck( $p, 'sub' );
sort( $subs );
$want = array( $sub_id( $ohnedev ), $sub_id( $m2dev ) );
sort( $want );
mkl_assert( $want === $subs, 'Neuer Beitrag: an alle mit Typ „News“ (Gast hat ihn abgewählt)' );
mkl_assert( 'Neuigkeiten' === $p[0]['title'] && 'Ahoi & willkommen' === $p[0]['body'] && false !== strpos( $p[0]['url'], '#/news/' . $post ), 'Text: Beitragstitel, Link zum Beitrag' );
$wpdb->query( "DELETE FROM $q" ); // phpcs:ignore WordPress.DB.PreparedSQL
wp_update_post( array( 'ID' => $post, 'post_title' => 'Ahoi (korrigiert)' ) );
$draft  = wp_insert_post( array( 'post_title' => 'Entwurf', 'post_status' => 'draft' ) );
$secret = wp_insert_post( array( 'post_title' => 'Geheim', 'post_status' => 'publish', 'post_password' => 'pw' ) );
mkl_assert( 0 === $q_count(), 'Keine Nachricht bei Änderung, Entwurf oder passwortgeschütztem Beitrag' );

// ------------------------------------------------------------------
echo "\nKalender-Abgleich (interne Termine)\n";
// Feste Basiszeit: sonst ändert ein Sekundenwechsel zwischen zwei Ständen die Startzeit.
$cal_base  = time();
$cal_event = function ( $id, $days, $location = '' ) use ( $cal_base ) {
	$ts = $cal_base + $days * DAY_IN_SECONDS;
	return array(
		'id'       => $id,
		'title'    => 'Termin ' . $id,
		'start'    => wp_date( 'c', $ts ),
		'end'      => wp_date( 'c', $ts + HOUR_IN_SECONDS ),
		'all_day'  => false,
		'location' => $location,
	);
};
$saved_cache = get_transient( MKL_Calendar::CACHE_KEY );
delete_option( MKL_Push::SNAPSHOT_OPTION );
$wpdb->query( "DELETE FROM $q" ); // phpcs:ignore WordPress.DB.PreparedSQL
set_transient( MKL_Calendar::CACHE_KEY, array( $cal_event( 'a', 3, 'Halle' ), $cal_event( 'b', 20 ) ), HOUR_IN_SECONDS );
mkl_assert( 0 === MKL_Push::check_calendar() && is_array( get_option( MKL_Push::SNAPSHOT_OPTION ) ), 'Erster Abgleich: nur Stand merken, nichts verschicken' );
set_transient( MKL_Calendar::CACHE_KEY, array( $cal_event( 'a', 3, 'Festzelt' ), $cal_event( 'b', 20 ), $cal_event( 'c', 10 ), $cal_event( 'fern', 360 ) ), HOUR_IN_SECONDS );
$n = MKL_Push::check_calendar();
$p = $q_payloads();
mkl_assert( 2 === $n && array( $sub_id( $m2dev ), $sub_id( $m2dev ) ) === wp_list_pluck( $p, 'sub' ), 'Neuer und geänderter Termin nur an Kalender-Berechtigte mit Typ „Termine“' );
mkl_assert( array( 'Neuer Termin', 'Termin geändert' ) === wp_list_pluck( $p, 'title' ) && false !== strpos( $p[1]['body'], 'Festzelt' ), 'Texte: „Neuer Termin“ (c), „Termin geändert“ (a, neuer Ort); fern in der Zukunft nur gemerkt' );
$wpdb->query( "DELETE FROM $q" ); // phpcs:ignore WordPress.DB.PreparedSQL
mkl_assert( 0 === MKL_Push::check_calendar(), 'Unverändert: keine Nachricht' );
$many = array( $cal_event( 'a', 3, 'Festzelt' ) );
foreach ( range( 1, 5 ) as $i ) {
	$many[] = $cal_event( 'neu' . $i, 30 + $i );
}
set_transient( MKL_Calendar::CACHE_KEY, $many, HOUR_IN_SECONDS );
MKL_Push::check_calendar();
$p = $q_payloads();
mkl_assert( 1 === count( $p ) && 'Neue Termine' === $p[0]['title'] && 0 === strpos( $p[0]['body'], '5 neue Termine' ), 'Viele neue Termine: eine Sammelnachricht' );

// ------------------------------------------------------------------
echo "\nErinnerung am Vortag\n";
$wpdb->query( "DELETE FROM $q" ); // phpcs:ignore WordPress.DB.PreparedSQL
delete_option( MKL_Push::REMINDER_OPTION );
$tm = new DateTimeImmutable( $tomorrow . ' 19:30', wp_timezone() );
set_transient(
	MKL_Calendar::CACHE_KEY,
	array(
		array( 'id' => 'r1', 'title' => 'Kompanieabend', 'start' => $tm->format( 'c' ), 'end' => $tm->modify( '+3 hours' )->format( 'c' ), 'all_day' => false, 'location' => 'Vereinsheim' ),
		array( 'id' => 'r2', 'title' => 'Heute', 'start' => wp_date( 'Y-m-d' ), 'end' => $tomorrow, 'all_day' => true, 'location' => '' ),
	),
	HOUR_IN_SECONDS
);
$today = new DateTimeImmutable( 'today', wp_timezone() );
mkl_assert( 0 === MKL_Push::send_reminders( $today->setTime( 17, 0 ) ) && 0 === $q_count(), 'Vor 18 Uhr: noch keine Erinnerung' );
$n = MKL_Push::send_reminders( $today->setTime( 18, 5 ) );
$p = $q_payloads();
$by = array();
foreach ( $p as $x ) {
	$by[ $x['sub'] ] = $x;
}
mkl_assert( 2 === $n && isset( $by[ $sub_id( $m2dev ) ], $by[ $sub_id( $guest ) ] ), 'Ab 18 Uhr: Mitglied (Kalender) und Gast (öffentliche Termine)' );
mkl_assert( 'Morgen: Kompanieabend' === $by[ $sub_id( $m2dev ) ]['title'] && 'Kompanieabend (19:30 Uhr, Vereinsheim)' === $by[ $sub_id( $m2dev ) ]['body'], 'Mitglied: Termin aus dem Kalender mit Uhrzeit und Ort' );
mkl_assert( 'Morgen: Pushfest 2026' === $by[ $sub_id( $guest ) ]['title'], 'Gast: öffentlicher Termin' );
mkl_assert( 0 === MKL_Push::send_reminders( $today->setTime( 19, 0 ) ), 'Am selben Tag nur einmal' );
delete_option( MKL_Push::REMINDER_OPTION );
mkl_assert( 0 === MKL_Push::send_reminders( $today->setTime( 22, 30 ) ), 'Nach 22 Uhr: keine Erinnerung mehr' );

wp_clear_scheduled_hook( MKL_Push::WATCH_HOOK );
MKL_Push::schedule_watch();
mkl_assert( MKL_Push::WATCH_SCHEDULE === wp_get_schedule( MKL_Push::WATCH_HOOK ), 'Mit Abos: Abgleich und Erinnerung alle 15 Minuten eingeplant' );
wp_clear_scheduled_hook( MKL_Push::WATCH_HOOK );

// Aufräumen Teil 2.
$wpdb->query( "DELETE FROM $q" ); // phpcs:ignore WordPress.DB.PreparedSQL
false === $saved_cache ? delete_transient( MKL_Calendar::CACHE_KEY ) : set_transient( MKL_Calendar::CACHE_KEY, $saved_cache, HOUR_IN_SECONDS );
delete_option( MKL_Push::SNAPSHOT_OPTION );
delete_option( MKL_Push::REMINDER_OPTION );
MKL_Events::delete( $ev['id'] );
MKL_Events::delete( $past['id'] );
foreach ( array( $post, $draft, $secret ) as $pid ) {
	wp_delete_post( $pid, true );
}
MKL_Push::delete_subscription( $sub_id( $guest ) );
wp_delete_user( $admin );
wp_set_current_user( $m1 );

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
