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

/** Legt einen Testbenutzer an; $level: ohne|mitglied|spiess|admin. */
function mkl_test_user( $login, $level = 'mitglied' ) {
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
	if ( 'admin' === $level ) {
		( new WP_User( $id ) )->set_role( 'administrator' );
	} else {
		MKL_Roles::set_level( $id, $level );
	}
	return $id;
}

$spiess = mkl_test_user( 'mkltest_spiess', 'spiess' );
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

echo "Mitglieder anlegen (nur Admin)\n";
$admin = mkl_test_user( 'mkltest_admin', 'admin' );
$created = array();

wp_set_current_user( $m1 );
list( $s ) = mkl_req( 'POST', '/users', array( 'name' => 'Hans Hacker' ) );
mkl_assert( 403 === $s, 'Mitglied darf keine Benutzer anlegen → 403' );
wp_set_current_user( $spiess );
list( $s ) = mkl_req( 'POST', '/users', array( 'name' => 'Hans Hacker' ) );
mkl_assert( 403 === $s, 'Spieß darf keine Benutzer anlegen → 403' );
list( $s, $d ) = mkl_req( 'GET', '/me' );
mkl_assert( false === $d['can_create_users'], 'GET /me als Spieß → can_create_users=false' );

wp_set_current_user( $admin );
list( $s, $d ) = mkl_req( 'GET', '/me' );
mkl_assert( true === $d['can_create_users'], 'GET /me als Admin → can_create_users=true' );

foreach ( array( 'mkltest.mueller', 'mkltest.mueller2' ) as $old_login ) {
	$old = get_user_by( 'login', $old_login );
	if ( $old ) {
		wp_delete_user( $old->ID );
	}
}
list( $s, $u1 ) = mkl_req( 'POST', '/users', array( 'name' => 'Mkltest Müller' ) );
$created[]      = isset( $u1['id'] ) ? $u1['id'] : 0;
mkl_assert( 201 === $s && 'mkltest.mueller' === $u1['username'], 'Admin legt Mitglied an, Benutzername aus Name (Umlaut → ue)' );
mkl_assert( 12 === strlen( $u1['password'] ) && ! preg_match( '/[0O1lI]/', $u1['password'] ), 'Startpasswort: 12 Zeichen, keine verwechselbaren Zeichen' );
$auth = wp_authenticate( $u1['username'], $u1['password'] );
mkl_assert( $auth instanceof WP_User && $auth->ID === $u1['id'], 'Anmeldung mit Startpasswort funktioniert' );
mkl_assert( array( MKL_ROLE_MITGLIED ) === array_values( get_userdata( $u1['id'] )->roles ) && 'mitglied' === MKL_Roles::get_level( $u1['id'] ), 'Neues Mitglied hat die Rolle Mitglied' );
mkl_assert( 'Mkltest Müller' === get_userdata( $u1['id'] )->display_name, 'Anzeigename gesetzt' );

list( $s, $u2 ) = mkl_req( 'POST', '/users', array( 'name' => 'Mkltest Müller' ) );
$created[]      = isset( $u2['id'] ) ? $u2['id'] : 0;
mkl_assert( 201 === $s && 'mkltest.mueller2' === $u2['username'], 'Gleicher Name → eindeutiger Benutzername mit Zähler' );

list( $s ) = mkl_req( 'POST', '/users', array( 'name' => 'X', 'username' => 'mkltest.mueller' ) );
mkl_assert( 400 === $s, 'Vergebener Benutzername → 400' );
list( $s ) = mkl_req( 'POST', '/users', array( 'name' => 'X', 'username' => 'Mit Leerzeichen!' ) );
mkl_assert( 400 === $s, 'Ungültiger Benutzername → 400' );
list( $s ) = mkl_req( 'POST', '/users', array( 'name' => 'X', 'email' => 'keine-mail' ) );
mkl_assert( 400 === $s, 'Ungültige E-Mail → 400' );
list( $s ) = mkl_req( 'POST', '/users', array( 'name' => '   ' ) );
mkl_assert( 400 === $s, 'Leerer Name → 400' );

list( $s, $members ) = mkl_req( 'GET', '/members' );
mkl_assert( in_array( $u1['id'], wp_list_pluck( $members, 'id' ), true ), 'Neues Mitglied erscheint in der Mitgliederliste' );
foreach ( $created as $uid ) {
	if ( $uid ) {
		wp_delete_user( $uid );
	}
}
wp_delete_user( $admin );

echo "Eigenes Passwort ändern\n";
wp_set_password( 'altes-passwort', $m2 );
wp_set_current_user( 0 );
list( $s ) = mkl_req( 'POST', '/me/password', array( 'current_password' => 'altes-passwort', 'new_password' => 'neues-passwort' ) );
mkl_assert( 401 === $s, 'Ohne Login → 401' );
wp_set_current_user( $m2 );
list( $s ) = mkl_req( 'POST', '/me/password', array( 'current_password' => 'falsch', 'new_password' => 'neues-passwort' ) );
mkl_assert( 400 === $s, 'Falsches aktuelles Passwort → 400' );
list( $s ) = mkl_req( 'POST', '/me/password', array( 'current_password' => 'altes-passwort', 'new_password' => 'kurz' ) );
mkl_assert( 400 === $s, 'Neues Passwort zu kurz → 400' );
list( $s ) = mkl_req( 'POST', '/me/password', array( 'current_password' => 'altes-passwort', 'new_password' => 'altes-passwort' ) );
mkl_assert( 400 === $s, 'Neues = altes Passwort → 400' );
list( $s ) = mkl_req( 'POST', '/me/password', array( 'current_password' => 'altes-passwort', 'new_password' => 'neues-passwort', 'user_id' => $m1 ) );
mkl_assert( 200 === $s, 'Passwort geändert' );
clean_user_cache( $m2 );
clean_user_cache( $m1 );
mkl_assert( wp_check_password( 'neues-passwort', get_userdata( $m2 )->user_pass, $m2 ), 'Neues Passwort gilt für das eigene Konto' );
mkl_assert( ! wp_check_password( 'neues-passwort', get_userdata( $m1 )->user_pass, $m1 ), 'Fremdes Konto (user_id-Parameter) bleibt unverändert' );

echo "Rollen: ohne Rolle, Mitglied, Spieß, Admin\n";
$ohne  = mkl_test_user( 'mkltest_ohne', 'ohne' );
$admin = mkl_test_user( 'mkltest_admin2', 'admin' );
mkl_assert( 'ohne' === MKL_Roles::get_level( $ohne ) && 'mitglied' === MKL_Roles::get_level( $m1 ) && 'spiess' === MKL_Roles::get_level( $spiess ) && 'admin' === MKL_Roles::get_level( $admin ), 'Rollen werden erkannt' );

// Rechte-Matrix: [Methode, Route, Body, erwarteter Status je Rolle (Gast = anonym, ohne Rolle, mitglied, spiess, admin)]
$matrix = array(
	array( 'GET', '/events', array(), array( 200, 200, 200, 200, 200 ) ),
	array( 'GET', '/news', array(), array( 200, 200, 200, 200, 200 ) ),
	array( 'GET', '/me', array(), array( 401, 200, 200, 200, 200 ) ),
	array( 'GET', '/me/points', array(), array( 401, 403, 200, 200, 200 ) ),
	array( 'GET', '/me/penalties', array(), array( 401, 403, 200, 200, 200 ) ),
	array( 'GET', '/members', array(), array( 401, 403, 403, 200, 200 ) ),
	array( 'POST', '/events', array( 'title' => '' ), array( 401, 403, 403, 403, 400 ) ),
	array( 'PATCH', '/members/' . $m2 . '/role', array( 'level' => 'egal' ), array( 401, 403, 403, 403, 400 ) ),
	array( 'POST', '/users', array( 'name' => '' ), array( 401, 403, 403, 403, 400 ) ),
);
$who = array( 0, $ohne, $m1, $spiess, $admin );
foreach ( $matrix as $row ) {
	list( $method, $route, $body, $expected ) = $row;
	$got = array();
	foreach ( $who as $uid ) {
		wp_set_current_user( $uid );
		list( $st ) = mkl_req( $method, $route, $body );
		$got[]      = $st;
	}
	mkl_assert( $got === $expected, sprintf( '%s %s → %s', $method, $route, implode( '/', $got ) ) );
}

wp_set_current_user( $ohne );
list( $s, $d ) = mkl_req( 'GET', '/me' );
mkl_assert( 'ohne' === $d['level'] && false === $d['is_member'], 'GET /me als Konto ohne Rolle → level ohne' );

wp_set_current_user( $spiess );
list( $s, $members ) = mkl_req( 'GET', '/members' );
mkl_assert( ! in_array( $ohne, wp_list_pluck( $members, 'id' ), true ), 'Mitgliederliste enthält keine Konten ohne Rolle' );
list( $s, $members ) = mkl_req( 'GET', '/members', array(), array( 'all' => 1 ) );
mkl_assert( ! in_array( $ohne, wp_list_pluck( $members, 'id' ), true ), 'Spieß bekommt auch mit all=1 keine Konten ohne Rolle' );
list( $s ) = mkl_req( 'POST', '/points', array( 'user_ids' => array( $ohne ), 'points' => 1, 'reason' => 'x' ) );
mkl_assert( 400 === $s, 'Konto ohne Rolle kann keine Punkte bekommen → 400' );

wp_set_current_user( $admin );
list( $s, $members ) = mkl_req( 'GET', '/members', array(), array( 'all' => 1 ) );
$levels = wp_list_pluck( $members, 'level', 'id' );
mkl_assert( isset( $levels[ $ohne ] ) && 'ohne' === $levels[ $ohne ], 'Admin sieht mit all=1 auch Konten ohne Rolle' );

list( $s, $d ) = mkl_req( 'PATCH', '/members/' . $ohne . '/role', array( 'level' => 'mitglied' ) );
mkl_assert( 200 === $s && 'mitglied' === $d['level'] && MKL_Roles::is_member( $ohne ), 'Admin macht Konto ohne Rolle zum Mitglied' );
list( $s, $d ) = mkl_req( 'PATCH', '/members/' . $ohne . '/role', array( 'level' => 'spiess' ) );
mkl_assert( 200 === $s && MKL_Roles::is_spiess( $ohne ), 'Admin macht Mitglied zum Spieß' );
list( $s, $d ) = mkl_req( 'PATCH', '/members/' . $ohne . '/role', array( 'level' => 'ohne' ) );
clean_user_cache( $ohne );
mkl_assert( 200 === $s && 'ohne' === MKL_Roles::get_level( $ohne ) && array( 'subscriber' ) === array_values( get_userdata( $ohne )->roles ), 'Rolle entzogen: Konto behält Rolle Abonnent' );
list( $s ) = mkl_req( 'PATCH', '/members/' . $ohne . '/role', array( 'level' => 'gast' ) );
mkl_assert( 400 === $s, '„gast“ ist keine vergebbare Rolle → 400' );
list( $s ) = mkl_req( 'PATCH', '/members/' . $admin . '/role', array( 'level' => 'ohne' ) );
mkl_assert( 400 === $s, 'Eigene Rolle nicht änderbar → 400' );
$admin_other = mkl_test_user( 'mkltest_admin3', 'admin' );
list( $s ) = mkl_req( 'PATCH', '/members/' . $admin_other . '/role', array( 'level' => 'ohne' ) );
mkl_assert( 400 === $s && 'admin' === MKL_Roles::get_level( $admin_other ), 'Admins sind in der App nicht herabstufbar' );

echo "Migration\n";
$old_sub = mkl_test_user( 'mkltest_altabonnent', 'ohne' );
MKL_Roles::migrate_subscribers_to_members();
clean_user_cache( $old_sub );
mkl_assert( 'mitglied' === MKL_Roles::get_level( $old_sub ), 'Bisherige Abonnenten werden zu Mitgliedern' );

echo "Öffentliche Termine\n";
wp_set_current_user( $admin );
$tomorrow = wp_date( 'Y-m-d', time() + DAY_IN_SECONDS );
$nextweek = wp_date( 'Y-m-d', time() + 7 * DAY_IN_SECONDS );
list( $s, $ev1 ) = mkl_req( 'POST', '/events', array( 'title' => 'Mkltest Schützenfest', 'start_date' => $tomorrow, 'end_date' => $nextweek, 'location' => 'Schützenplatz', 'url' => 'https://example.org/fest' ) );
mkl_assert( 201 === $s && true === $ev1['all_day'] && $ev1['end'] === wp_date( 'Y-m-d', time() + 8 * DAY_IN_SECONDS ), 'Mehrtägigen Termin anlegen (Ende exklusiv)' );
list( $s, $ev2 ) = mkl_req( 'POST', '/events', array( 'title' => 'Mkltest Kompanieabend', 'start_date' => $tomorrow, 'start_time' => '19:00', 'end_time' => '23:00' ) );
mkl_assert( 201 === $s && false === $ev2['all_day'] && '19:00' === $ev2['start_time'], 'Termin mit Uhrzeit anlegen' );
list( $s ) = mkl_req( 'POST', '/events', array( 'title' => 'Alt', 'start_date' => '2020-01-01' ) );
list( $s ) = mkl_req( 'POST', '/events', array( 'title' => 'X', 'start_date' => '2026-02-30' ) );
mkl_assert( 400 === $s, 'Ungültiges Datum → 400' );
list( $s ) = mkl_req( 'POST', '/events', array( 'title' => 'X', 'start_date' => $nextweek, 'end_date' => $tomorrow ) );
mkl_assert( 400 === $s, 'Ende vor Start → 400' );
list( $s ) = mkl_req( 'POST', '/events', array( 'title' => 'X', 'start_date' => $tomorrow, 'start_time' => '25:00' ) );
mkl_assert( 400 === $s, 'Ungültige Uhrzeit → 400' );
list( $s ) = mkl_req( 'POST', '/events', array( 'title' => 'X', 'start_date' => $tomorrow, 'url' => 'javascript:alert(1)' ) );
mkl_assert( 400 === $s, 'javascript:-Link → 400' );
list( $s, $d ) = mkl_req( 'PATCH', '/events/' . $ev2['id'], array( 'location' => 'Vereinslokal' ) );
mkl_assert( 200 === $s && 'Vereinslokal' === $d['location'] && '19:00' === $d['start_time'], 'Termin teilweise ändern' );

wp_set_current_user( 0 );
list( $s, $list ) = mkl_req( 'GET', '/events' );
$titles = wp_list_pluck( $list, 'title' );
mkl_assert( in_array( 'Mkltest Schützenfest', $titles, true ) && ! in_array( 'Alt', $titles, true ), 'Gast sieht kommende, aber keine vergangenen Termine' );

$html = do_shortcode( '[mkl_termine anzahl="50"]' );
mkl_assert( false !== strpos( $html, 'Mkltest Schützenfest' ) && false !== strpos( $html, 'href="https://example.org/fest"' ) && false !== strpos( $html, '19:00–23:00 Uhr' ), 'Shortcode [mkl_termine] zeigt Termine mit Link und Uhrzeit' );

wp_set_current_user( $admin );
list( $s ) = mkl_req( 'DELETE', '/events/' . $ev2['id'] );
mkl_assert( 200 === $s && ! MKL_Events::get( $ev2['id'] ), 'Termin löschen' );
global $wpdb;
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . MKL_Events::table() . " WHERE title LIKE %s OR title = 'Alt'", 'Mkltest%' ) );

echo "Neuigkeiten\n";
$pub   = wp_insert_post( array( 'post_title' => 'Mkltest Beitrag', 'post_content' => '<p>Hallo <strong>Kompanie</strong></p>', 'post_status' => 'publish' ) );
$draft = wp_insert_post( array( 'post_title' => 'Mkltest Entwurf', 'post_content' => 'geheim', 'post_status' => 'draft' ) );
$pw    = wp_insert_post( array( 'post_title' => 'Mkltest Kennwort', 'post_content' => 'geheim', 'post_status' => 'publish', 'post_password' => 'x' ) );
wp_set_current_user( 0 );
list( $s, $news ) = mkl_req( 'GET', '/news' );
$titles = wp_list_pluck( $news, 'title' );
mkl_assert( 200 === $s && in_array( 'Mkltest Beitrag', $titles, true ) && ! in_array( 'Mkltest Entwurf', $titles, true ) && ! in_array( 'Mkltest Kennwort', $titles, true ), 'News: nur veröffentlichte Beiträge ohne Kennwort' );
list( $s, $item ) = mkl_req( 'GET', '/news/' . $pub );
mkl_assert( 200 === $s && false !== strpos( $item['content'], '<strong>Kompanie</strong>' ), 'News-Detail mit Inhalt' );
list( $s ) = mkl_req( 'GET', '/news/' . $draft );
mkl_assert( 404 === $s, 'Entwurf → 404' );
list( $s ) = mkl_req( 'GET', '/news/' . $pw );
mkl_assert( 404 === $s, 'Kennwortgeschützter Beitrag → 404' );
foreach ( array( $pub, $draft, $pw ) as $pid ) {
	wp_delete_post( $pid, true );
}
foreach ( array( $ohne, $admin, $admin_other, $old_sub ) as $uid ) {
	wp_delete_user( $uid );
}

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
