<?php
/**
 * Integrationstests für die REST-API des Plugins.
 *
 * Ausführen gegen eine Test-WordPress-Installation mit aktivem Plugin:
 *   wp eval-file plugin/tests/rest-api-test.php
 *
 * Legt eigene Testbenutzer an und räumt sie am Ende wieder auf.
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

function mkl_req( $method, $route, $params = array(), $query = array() ) {
	$req = new WP_REST_Request( $method, '/mk-lank/v1' . $route );
	foreach ( $query as $k => $v ) {
		$req->set_query_params( array_merge( $req->get_query_params(), array( $k => $v ) ) );
	}
	if ( $params ) {
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( $params ) );
	}
	$res = rest_do_request( $req );
	return array( $res->get_status(), $res->get_data() );
}

function mkl_test_user( $login, $spiess = false ) {
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
	if ( $spiess ) {
		( new WP_User( $id ) )->add_role( MKL_ROLE_SPIESS );
	}
	return $id;
}

$spiess = mkl_test_user( 'mkltest_spiess', true );
$m1     = mkl_test_user( 'mkltest_mariner1' );
$m2     = mkl_test_user( 'mkltest_mariner2' );

// rest_do_request benötigt geladene Routen.
do_action( 'rest_api_init', rest_get_server() );

echo "Nicht angemeldet\n";
wp_set_current_user( 0 );
list( $s ) = mkl_req( 'GET', '/me' );
mkl_assert( 401 === $s, 'GET /me ohne Login → 401' );
list( $s ) = mkl_req( 'GET', '/me/points' );
mkl_assert( 401 === $s, 'GET /me/points ohne Login → 401' );

echo "Mitglied darf nichts verwalten\n";
wp_set_current_user( $m1 );
list( $s ) = mkl_req( 'GET', '/members' );
mkl_assert( 403 === $s, 'GET /members als Mitglied → 403' );
list( $s ) = mkl_req( 'GET', '/members/' . $m2 );
mkl_assert( 403 === $s, 'GET /members/{id} als Mitglied → 403' );
list( $s ) = mkl_req( 'POST', '/points', array( 'user_ids' => array( $m1 ), 'points' => 100, 'reason' => 'Selbstbedienung' ) );
mkl_assert( 403 === $s, 'POST /points als Mitglied → 403' );
list( $s ) = mkl_req( 'POST', '/penalties', array( 'user_ids' => array( $m2 ), 'amount_cents' => 500, 'reason' => 'x' ) );
mkl_assert( 403 === $s, 'POST /penalties als Mitglied → 403' );
list( $s ) = mkl_req( 'POST', '/catalog', array( 'title' => 'x', 'points' => 1 ) );
mkl_assert( 403 === $s, 'POST /catalog als Mitglied → 403' );
list( $s, $d ) = mkl_req( 'GET', '/me' );
mkl_assert( 200 === $s && false === $d['is_spiess'], 'GET /me als Mitglied → is_spiess=false' );

echo "Spieß vergibt Punkte und Strafen\n";
wp_set_current_user( $spiess );
list( $s, $d ) = mkl_req( 'GET', '/me' );
mkl_assert( 200 === $s && true === $d['is_spiess'], 'GET /me als Spieß → is_spiess=true' );

list( $s, $cat ) = mkl_req( 'POST', '/catalog', array( 'title' => 'Teilnahme Schützenfest', 'points' => 5 ) );
mkl_assert( 201 === $s && 5 === $cat['points'], 'Katalogeintrag anlegen' );

list( $s, $d ) = mkl_req( 'POST', '/points', array( 'user_ids' => array( $m1, $m2 ), 'catalog_id' => $cat['id'], 'date' => '2026-06-27' ) );
mkl_assert( 201 === $s && 2 === count( $d ), 'Punkte aus Katalog an zwei Mitglieder' );
mkl_assert( 5 === $d[0]['points'] && 'Teilnahme Schützenfest' === $d[0]['reason'], 'Punkte/Grund aus Katalog übernommen' );

list( $s, $d ) = mkl_req( 'POST', '/points', array( 'user_ids' => array( $m1 ), 'points' => 3, 'reason' => 'Aufbau Maifest' ) );
mkl_assert( 201 === $s, 'Freie Punkte an Mitglied 1' );
$free_point_id = $d[0]['id'];

list( $s ) = mkl_req( 'POST', '/points', array( 'user_ids' => array( $m1 ), 'points' => 0, 'reason' => 'x' ) );
mkl_assert( 400 === $s, '0 Punkte → 400' );
list( $s ) = mkl_req( 'POST', '/points', array( 'user_ids' => array( $m1 ), 'points' => 1.5, 'reason' => 'x' ) );
mkl_assert( 400 === $s, 'Kommazahl bei Punkten → 400' );
list( $s ) = mkl_req( 'POST', '/points', array( 'user_ids' => array( 999999 ), 'points' => 1, 'reason' => 'x' ) );
mkl_assert( 400 === $s, 'Unbekanntes Mitglied → 400' );
list( $s ) = mkl_req( 'POST', '/points', array( 'user_ids' => array( $m1 ), 'points' => 1, 'reason' => 'x', 'date' => '2026-02-30' ) );
mkl_assert( 400 === $s, 'Ungültiges Datum → 400' );

list( $s, $d ) = mkl_req( 'POST', '/penalties', array( 'user_ids' => array( $m1 ), 'amount_cents' => 250, 'reason' => 'Zu spät beim Antreten' ) );
mkl_assert( 201 === $s && 250 === $d[0]['amount_cents'] && 'open' === $d[0]['status'], 'Strafe 2,50 € offen angelegt' );
$pen1 = $d[0]['id'];
list( $s, $d ) = mkl_req( 'POST', '/penalties', array( 'user_ids' => array( $m1 ), 'amount_cents' => 1000, 'reason' => 'Mütze vergessen' ) );
$pen2 = $d[0]['id'];
list( $s, $d ) = mkl_req( 'POST', '/penalties', array( 'user_ids' => array( $m2 ), 'amount_cents' => 700, 'reason' => 'Handy beim Antreten' ) );
$pen_m2 = $d[0]['id'];

list( $s ) = mkl_req( 'POST', '/penalties', array( 'user_ids' => array( $m1 ), 'amount_cents' => 0, 'reason' => 'x' ) );
mkl_assert( 400 === $s, 'Strafe 0 € → 400' );
list( $s ) = mkl_req( 'POST', '/penalties', array( 'user_ids' => array( $m1 ), 'amount_cents' => 100001, 'reason' => 'x' ) );
mkl_assert( 400 === $s, 'Strafe > 1.000 € → 400' );
list( $s ) = mkl_req( 'POST', '/penalties', array( 'user_ids' => array( $m1 ), 'amount_cents' => 500, 'reason' => '' ) );
mkl_assert( 400 === $s, 'Strafe ohne Grund → 400' );

list( $s, $d ) = mkl_req( 'PATCH', '/penalties/' . $pen2, array( 'status' => 'paid' ) );
mkl_assert( 200 === $s && 'paid' === $d['status'] && $d['paid_at'], 'Strafe als bezahlt markiert' );
list( $s ) = mkl_req( 'PATCH', '/penalties/' . $pen2, array( 'status' => 'egal' ) );
mkl_assert( 400 === $s, 'Ungültiger Status → 400' );

list( $s ) = mkl_req( 'DELETE', '/points/' . $free_point_id );
mkl_assert( 200 === $s, 'Punkte-Eintrag storniert' );
list( $s ) = mkl_req( 'DELETE', '/points/' . $free_point_id );
mkl_assert( 404 === $s, 'Doppelte Stornierung → 404' );

list( $s, $members ) = mkl_req( 'GET', '/members' );
$by_id               = array();
foreach ( $members as $m ) {
	$by_id[ $m['id'] ] = $m;
}
mkl_assert( 200 === $s && 5 === $by_id[ $m1 ]['points'] && 250 === $by_id[ $m1 ]['open_cents'], 'Übersicht Mitglied 1: 5 Punkte, 2,50 € offen' );
mkl_assert( 5 === $by_id[ $m2 ]['points'] && 700 === $by_id[ $m2 ]['open_cents'], 'Übersicht Mitglied 2: 5 Punkte, 7,00 € offen' );

list( $s, $d ) = mkl_req( 'GET', '/members/' . $m1 );
mkl_assert( 200 === $s && 1 === count( $d['point_list'] ) && 2 === count( $d['penalties'] ), 'Detail Mitglied 1 (stornierte Punkte ausgeblendet)' );

echo "Mitglied sieht nur eigene Daten\n";
wp_set_current_user( $m1 );
list( $s, $d ) = mkl_req( 'GET', '/me' );
mkl_assert( 5 === $d['points'] && 250 === $d['open_cents'], 'GET /me Summen Mitglied 1' );
list( $s, $d ) = mkl_req( 'GET', '/me/points', array(), array( 'user_id' => $m2 ) );
$foreign = array_filter(
	$d,
	function ( $r ) use ( $m1 ) {
		return $r['user_id'] !== $m1;
	}
);
mkl_assert( 200 === $s && 1 === count( $d ) && ! $foreign, 'GET /me/points?user_id=fremd liefert nur eigene Einträge' );
list( $s, $d ) = mkl_req( 'GET', '/me/penalties', array(), array( 'user_id' => $m2 ) );
$ids = wp_list_pluck( $d, 'id' );
mkl_assert( 2 === count( $d ) && ! in_array( $pen_m2, $ids, true ), 'GET /me/penalties zeigt keine fremden Strafen' );
mkl_assert( in_array( $pen1, $ids, true ), 'Eigene offene Strafe enthalten' );
list( $s ) = mkl_req( 'PATCH', '/penalties/' . $pen1, array( 'status' => 'paid' ) );
mkl_assert( 403 === $s, 'Mitglied kann eigene Strafe nicht auf bezahlt setzen' );
list( $s ) = mkl_req( 'DELETE', '/penalties/' . $pen1 );
mkl_assert( 403 === $s, 'Mitglied kann eigene Strafe nicht stornieren' );

echo "ICS-Parser\n";
$ics    = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:a1\r\nDTSTART;VALUE=DATE:" . gmdate( 'Ymd', time() + 10 * DAY_IN_SECONDS ) . "\r\nDTEND;VALUE=DATE:" . gmdate( 'Ymd', time() + 11 * DAY_IN_SECONDS ) . "\r\nSUMMARY:Schützenfest\\, Lank\r\nLOCATION:Schützenplatz\r\nEND:VEVENT\r\n"
	. "BEGIN:VEVENT\r\nUID:a2\r\nDTSTART;TZID=Europe/Berlin:" . gmdate( 'Ymd', time() + 20 * DAY_IN_SECONDS ) . "T190000\r\nDTEND;TZID=Europe/Berlin:" . gmdate( 'Ymd', time() + 20 * DAY_IN_SECONDS ) . "T220000\r\nSUMMARY:Kompanie\r\n abend\r\nEND:VEVENT\r\n"
	. "BEGIN:VEVENT\r\nUID:old\r\nDTSTART:20200101T100000Z\r\nDTEND:20200101T110000Z\r\nSUMMARY:Alt\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$events = MKL_Calendar::parse_ics( $ics );
mkl_assert( 2 === count( $events ), 'ICS: vergangene Termine gefiltert' );
mkl_assert( 'Schützenfest, Lank' === $events[0]['title'] && $events[0]['all_day'], 'ICS: ganztägig + Escapes' );
mkl_assert( 'Kompanieabend' === $events[1]['title'] && false === $events[1]['all_day'] && strtotime( $events[1]['start'] ) === ( new DateTimeImmutable( gmdate( 'Y-m-d', time() + 20 * DAY_IN_SECONDS ) . ' 19:00:00', new DateTimeZone( 'Europe/Berlin' ) ) )->getTimestamp(), 'ICS: Zeilenfaltung + TZID' );

// Aufräumen.
global $wpdb;
foreach ( array( $spiess, $m1, $m2 ) as $uid ) {
	$wpdb->delete( MKL_DB::table( 'points' ), array( 'user_id' => $uid ) );
	$wpdb->delete( MKL_DB::table( 'penalties' ), array( 'user_id' => $uid ) );
	wp_delete_user( $uid );
}
$wpdb->delete( MKL_DB::table( 'point_catalog' ), array( 'id' => $cat['id'] ) );

printf( "\n%d bestanden, %d fehlgeschlagen\n", $GLOBALS['mkl_passes'], $GLOBALS['mkl_failures'] );
if ( $GLOBALS['mkl_failures'] ) {
	exit( 1 );
}
