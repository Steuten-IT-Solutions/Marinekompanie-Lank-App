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

list( $s, $tmp ) = mkl_req( 'POST', '/catalog', array( 'title' => 'Vorlage zum Löschen', 'points' => 2 ) );
list( $s, $d )   = mkl_req( 'POST', '/points', array( 'user_ids' => array( $m2 ), 'catalog_id' => $tmp['id'] ) );
$tmp_point_id    = $d[0]['id'];
wp_set_current_user( $m1 );
list( $s ) = mkl_req( 'DELETE', '/catalog/' . $tmp['id'] );
mkl_assert( 403 === $s, 'DELETE /catalog/{id} als Mitglied → 403' );
wp_set_current_user( $spiess );
list( $s, $d ) = mkl_req( 'DELETE', '/catalog/' . $tmp['id'] );
mkl_assert( 200 === $s && true === $d['deleted'], 'Katalogeintrag löschen' );
list( $s, $d ) = mkl_req( 'GET', '/catalog', array(), array( 'all' => '1' ) );
mkl_assert( ! in_array( $tmp['id'], wp_list_pluck( $d, 'id' ), true ), 'Gelöschter Eintrag fehlt im Katalog' );
$row = MKL_DB::get_points_row( $tmp_point_id );
mkl_assert( 2 === $row['points'] && 'Vorlage zum Löschen' === $row['reason'] && null === $row['catalog_id'] && ! $row['deleted'], 'Vergebene Punkte bleiben nach dem Löschen erhalten' );
list( $s ) = mkl_req( 'DELETE', '/catalog/' . $tmp['id'] );
mkl_assert( 404 === $s, 'Bereits gelöschten Eintrag erneut löschen → 404' );
global $wpdb;
$wpdb->delete( MKL_DB::table( 'points' ), array( 'id' => $tmp_point_id ) );

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
	array( 'DELETE', '/catalog/999999', array(), array( 401, 403, 403, 404, 404 ) ),
	array( 'POST', '/events', array( 'title' => '' ), array( 401, 403, 403, 403, 400 ) ),
	array( 'PATCH', '/members/' . $m2 . '/role', array( 'level' => 'egal' ), array( 401, 403, 403, 403, 400 ) ),
	array( 'POST', '/users', array( 'name' => '' ), array( 401, 403, 403, 403, 400 ) ),
	array( 'GET', '/ranks', array(), array( 401, 403, 200, 200, 200 ) ),
	array( 'POST', '/ranks', array( 'title' => '', 'min_points' => 1 ), array( 401, 403, 403, 400, 400 ) ),
	array( 'GET', '/promotions', array(), array( 401, 403, 403, 200, 200 ) ),
	array( 'GET', '/me/rank-history', array(), array( 401, 403, 200, 200, 200 ) ),
	array( 'PUT', '/members/' . $m2 . '/rank', array( 'rank_id' => 'x' ), array( 401, 403, 403, 400, 400 ) ),
	array( 'GET', '/documents', array(), array( 401, 403, 200, 200, 200 ) ),
	array( 'POST', '/documents', array( 'date' => '2026-01-01' ), array( 401, 403, 403, 403, 400 ) ),
	array( 'DELETE', '/documents/999999', array(), array( 401, 403, 403, 403, 404 ) ),
	array( 'POST', '/document-categories', array( 'title' => '' ), array( 401, 403, 403, 403, 400 ) ),
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

echo "Ränge\n";
global $wpdb;
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . MKL_Ranks::table() . ' WHERE title LIKE %s', 'Mkltest%' ) );
$r1   = mkl_test_user( 'mkltest_rang1' );
$r2   = mkl_test_user( 'mkltest_rang2' );
$r3   = mkl_test_user( 'mkltest_rang3' );
$gast = mkl_test_user( 'mkltest_ranggast', 'ohne' );
$boss = mkl_test_user( 'mkltest_rangadmin', 'admin' );

wp_set_current_user( $m1 );
list( $s ) = mkl_req( 'POST', '/ranks', array( 'title' => 'Mkltest Selbst', 'min_points' => 1 ) );
mkl_assert( 403 === $s, 'Mitglied darf keine Ränge anlegen → 403' );

wp_set_current_user( $spiess );
list( $s, $gefreiter ) = mkl_req( 'POST', '/ranks', array( 'title' => 'Mkltest Gefreiter', 'min_points' => 10 ) );
mkl_assert( 201 === $s && 10 === $gefreiter['min_points'] && true === $gefreiter['active'], 'Spieß legt Rang an' );
list( $s, $maat ) = mkl_req( 'POST', '/ranks', array( 'title' => 'Mkltest Maat', 'min_points' => 20 ) );
wp_set_current_user( $boss );
list( $s, $bootsmann ) = mkl_req( 'POST', '/ranks', array( 'title' => 'Mkltest Bootsmann', 'min_points' => 40 ) );
mkl_assert( 201 === $s, 'Admin legt Rang an' );
wp_set_current_user( $spiess );
list( $s ) = mkl_req( 'POST', '/ranks', array( 'title' => 'Mkltest Doppelt', 'min_points' => 20 ) );
mkl_assert( 400 === $s, 'Gleiche Schwelle wie ein aktiver Rang → 400' );
list( $s ) = mkl_req( 'POST', '/ranks', array( 'title' => 'Mkltest Negativ', 'min_points' => -1 ) );
mkl_assert( 400 === $s, 'Negative Schwelle → 400' );
list( $s, $inaktiv ) = mkl_req( 'POST', '/ranks', array( 'title' => 'Mkltest Inaktiv', 'min_points' => 5 ) );
list( $s, $d ) = mkl_req( 'PATCH', '/ranks/' . $inaktiv['id'], array( 'active' => false ) );
mkl_assert( 200 === $s && false === $d['active'], 'Rang deaktivieren' );
list( $s ) = mkl_req( 'PATCH', '/ranks/' . $maat['id'], array( 'min_points' => 10 ) );
mkl_assert( 400 === $s, 'Schwelle auf die eines anderen aktiven Rangs ändern → 400' );

wp_set_current_user( $r1 );
list( $s, $list ) = mkl_req( 'GET', '/ranks', array(), array( 'all' => 1 ) );
$titles = wp_list_pluck( $list, 'title' );
mkl_assert( 200 === $s && in_array( 'Mkltest Maat', $titles, true ) && ! in_array( 'Mkltest Inaktiv', $titles, true ) && ! isset( $list[0]['member_count'] ), 'Mitglied sieht nur aktive Ränge (auch mit all=1)' );
list( $s, $me ) = mkl_req( 'GET', '/me' );
mkl_assert( null === $me['rank'] && 0 === $me['points'], 'Neues Mitglied: kein Rang, 0 Punkte' );
mkl_assert( $gefreiter['id'] === $me['next_rank']['id'] && 10 === $me['points_to_next'], 'Ohne Rang ist der nächste Rang der niedrigste aktive' );

// Für die folgenden Prüfungen nur die Test-Ränge betrachten.
$status = function ( $uid, $points ) use ( $gefreiter, $maat, $bootsmann, $inaktiv ) {
	$timeline = MKL_DB::get_point_timeline( $uid );
	$mine     = array();
	foreach ( MKL_Ranks::all( false ) as $r ) {
		if ( in_array( $r['id'], array( $gefreiter['id'], $maat['id'], $bootsmann['id'], $inaktiv['id'] ), true ) ) {
			$mine[] = $r;
		}
	}
	return MKL_Ranks::status( MKL_Ranks::user_rank_id( $uid ), $points, isset( $timeline[ $uid ] ) ? $timeline[ $uid ] : array(), $mine, null );
};
$st = $status( $r1, 0 );
mkl_assert( $gefreiter['id'] === $st['next_rank']['id'] && 10 === $st['points_to_next'] && null === $st['threshold_reached_since'], 'Ohne Rang: nächster Rang ist der niedrigste aktive (deaktivierter übersprungen)' );

wp_set_current_user( $spiess );
mkl_req( 'POST', '/points', array( 'user_ids' => array( $r1 ), 'points' => 12, 'reason' => 'Mkltest', 'date' => '2026-01-10' ) );
$st = $status( $r1, 12 );
mkl_assert( -2 === $st['points_to_next'] && '2026-01-10' === $st['threshold_reached_since'] && $gefreiter['id'] === $st['reachable_rank']['id'], 'Schwelle überschritten: −2 fehlende Punkte, erreicht am 10.01.' );
mkl_assert( null === MKL_Ranks::user_rank_id( $r1 ), 'Punkte allein vergeben keinen Rang' );
mkl_req( 'POST', '/points', array( 'user_ids' => array( $r1 ), 'points' => -5, 'reason' => 'Mkltest', 'date' => '2026-02-01' ) );
mkl_req( 'POST', '/points', array( 'user_ids' => array( $r1 ), 'points' => 5, 'reason' => 'Mkltest', 'date' => '2026-03-01' ) );
$st = $status( $r1, 12 );
mkl_assert( '2026-03-01' === $st['threshold_reached_since'], 'Nach Unterschreiten und erneutem Erreichen zählt das spätere Datum' );
mkl_assert( null === MKL_Ranks::threshold_reached_since( array( array( 'points' => 5, 'date' => '2026-01-01' ) ), 10 ), 'Unter der Schwelle → kein Datum' );
mkl_assert( '2026-01-02' === MKL_Ranks::threshold_reached_since( array( array( 'points' => 5, 'date' => '2026-01-01' ), array( 'points' => 5, 'date' => '2026-01-02' ) ), 10 ), 'Genau auf der Schwelle zählt als erreicht' );

list( $s, $d ) = mkl_req( 'PUT', '/members/' . $r1 . '/rank', array( 'rank_id' => $maat['id'] ) );
mkl_assert( 400 === $s && false !== strpos( $d['message'], '8 Punkte' ), 'Befördern ohne erreichte Schwelle → 400 mit fehlenden Punkten' );
list( $s ) = mkl_req( 'PUT', '/members/' . $r1 . '/rank', array( 'rank_id' => $inaktiv['id'] ) );
mkl_assert( 400 === $s, 'Befördern auf deaktivierten Rang → 400' );
list( $s ) = mkl_req( 'PUT', '/members/' . $gast . '/rank', array( 'rank_id' => $gefreiter['id'] ) );
mkl_assert( 400 === $s, 'Konto ohne Rolle kann keinen Rang bekommen → 400' );
list( $s, $d ) = mkl_req( 'PUT', '/members/' . $r1 . '/rank', array( 'rank_id' => $gefreiter['id'], 'reason' => 'Schützenfest' ) );
mkl_assert( 200 === $s && $gefreiter['id'] === $d['rank_status']['rank']['id'] && 1 === count( $d['rank_history'] ), 'Spieß befördert zum Gefreiten' );
mkl_assert( 'befoerderung' === $d['rank_history'][0]['kind'] && 'Schützenfest' === $d['rank_history'][0]['reason'] && 'Mkltest_spiess' === $d['rank_history'][0]['changed_by_name'] && 12 === $d['rank_history'][0]['points'], 'Verlauf mit Begründung, Spieß und Punktestand' );
list( $s ) = mkl_req( 'PUT', '/members/' . $r1 . '/rank', array( 'rank_id' => $gefreiter['id'] ) );
mkl_assert( 400 === $s, 'Gleicher Rang noch einmal → 400' );

wp_set_current_user( $r1 );
list( $s, $me ) = mkl_req( 'GET', '/me' );
mkl_assert( $gefreiter['id'] === $me['rank']['id'] && $maat['id'] === $me['next_rank']['id'] && 8 === $me['points_to_next'], 'Mitglied sieht eigenen Rang und fehlende Punkte zum nächsten' );
list( $s ) = mkl_req( 'PUT', '/members/' . $r1 . '/rank', array( 'rank_id' => $bootsmann['id'] ) );
mkl_assert( 403 === $s, 'Mitglied kann sich nicht selbst befördern → 403' );

wp_set_current_user( $spiess );
mkl_req( 'POST', '/points', array( 'user_ids' => array( $r1 ), 'points' => 30, 'reason' => 'Mkltest', 'date' => '2026-04-01' ) );
list( $s, $d ) = mkl_req( 'PUT', '/members/' . $r1 . '/rank', array( 'rank_id' => $bootsmann['id'] ) );
mkl_assert( 200 === $s && $bootsmann['id'] === $d['rank_status']['rank']['id'], 'Mehrere Ränge auf einmal überspringen (42 Punkte → Bootsmann)' );
mkl_assert( null === $d['rank_status']['next_rank'] || ! in_array( $d['rank_status']['next_rank']['id'], array( $gefreiter['id'], $maat['id'], $bootsmann['id'] ), true ), 'Über dem höchsten Test-Rang gibt es keinen nächsten Test-Rang' );
list( $s, $d ) = mkl_req( 'PUT', '/members/' . $r1 . '/rank', array( 'rank_id' => $maat['id'] ) );
mkl_assert( 400 === $s && false !== strpos( $d['message'], 'Degradieren' ), 'Degradieren bei ausreichenden Punkten → 400' );
wp_set_current_user( $boss );
list( $s ) = mkl_req( 'PUT', '/members/' . $r1 . '/rank', array( 'rank_id' => $maat['id'] ) );
mkl_assert( 400 === $s, 'Auch ein Admin kann bei ausreichenden Punkten nicht degradieren' );
list( $s ) = mkl_req( 'PUT', '/members/' . $r2 . '/rank', array( 'rank_id' => $gefreiter['id'] ) );
mkl_assert( 400 === $s, 'Auch ein Admin kann ohne erreichte Schwelle nicht befördern' );

wp_set_current_user( $spiess );
mkl_req( 'POST', '/points', array( 'user_ids' => array( $r1 ), 'points' => -10, 'reason' => 'Mkltest', 'date' => '2026-05-01' ) );
$st = $status( $r1, 32 );
mkl_assert( true === $st['below_current_threshold'] && $bootsmann['id'] === MKL_Ranks::user_rank_id( $r1 ), 'Unter die Schwelle gerutscht: Rang bleibt, Hinweis „unter Schwelle“' );
list( $s ) = mkl_req( 'PUT', '/members/' . $r1 . '/rank', array( 'rank_id' => null ) );
mkl_assert( 200 === $s && null === MKL_Ranks::user_rank_id( $r1 ), 'Degradieren auf „kein Rang“ bei Punkten unter der Schwelle' );
list( $s ) = mkl_req( 'PUT', '/members/' . $r1 . '/rank', array( 'rank_id' => $maat['id'] ) );
mkl_assert( 200 === $s, 'Wieder befördern (32 Punkte → Maat)' );

list( $s, $d ) = mkl_req( 'DELETE', '/ranks/' . $maat['id'] );
mkl_assert( 400 === $s && false !== strpos( $d['message'], 'deaktivieren' ), 'Zugewiesenen Rang löschen → 400' );
list( $s ) = mkl_req( 'PATCH', '/ranks/' . $maat['id'], array( 'title' => 'Mkltest Obermaat' ) );
mkl_assert( 200 === $s, 'Rang umbenennen' );
list( $s, $list ) = mkl_req( 'GET', '/ranks', array(), array( 'all' => 1 ) );
$counts = wp_list_pluck( $list, 'member_count', 'id' );
mkl_assert( 1 === $counts[ $maat['id'] ] && isset( $counts[ $inaktiv['id'] ] ), 'Spieß sieht mit all=1 inaktive Ränge und Anzahl Zugewiesener' );

// Zweites Mitglied für Verlauf und Übersicht.
mkl_req( 'POST', '/points', array( 'user_ids' => array( $r2 ), 'points' => 10, 'reason' => 'Mkltest', 'date' => '2026-01-01' ) );
mkl_req( 'PUT', '/members/' . $r2 . '/rank', array( 'rank_id' => $gefreiter['id'] ) );
mkl_req( 'POST', '/points', array( 'user_ids' => array( $r3 ), 'points' => 18, 'reason' => 'Mkltest', 'date' => '2026-02-15' ) );

wp_set_current_user( $r1 );
list( $s, $hist ) = mkl_req( 'GET', '/me/rank-history', array(), array( 'user_id' => $r2 ) );
mkl_assert( 200 === $s && 4 === count( $hist ), 'Eigener Verlauf: 4 Einträge (fremde nicht enthalten)' );
mkl_assert( 'Mkltest Maat' === $hist[0]['to_title'] && 'befoerderung' === $hist[0]['kind'] && 'degradierung' === $hist[1]['kind'] && null === $hist[1]['to_title'], 'Verlauf: neueste zuerst, Titel als Momentaufnahme, Degradierung erkannt' );
list( $s, $me ) = mkl_req( 'GET', '/me' );
mkl_assert( ! isset( $me['reachable_rank'] ) && ! isset( $me['threshold_reached_since'] ), '/me enthält nur eigene Rangdaten ohne Spieß-Auswertung' );

wp_set_current_user( $spiess );
list( $s, $promo ) = mkl_req( 'GET', '/promotions' );
$pos = array_flip( wp_list_pluck( $promo, 'user_id' ) );
mkl_assert( 200 === $s && ! isset( $pos[ $gast ] ), 'Beförderungsübersicht ohne Konten ohne Rolle' );
$e3 = $promo[ $pos[ $r3 ] ];
mkl_assert( $e3['points_to_next'] <= 0 && '2026-02-15' === $e3['threshold_reached_since'], 'Übersicht: Mitglied ohne Rang mit 18 Punkten ist fällig seit 15.02.' );
$order_ok = true;
$prev     = null;
foreach ( $promo as $e ) {
	$g = null === $e['points_to_next'] ? 2 : ( $e['points_to_next'] <= 0 ? 0 : 1 );
	if ( $prev && ( $g < $prev[0] || ( $g === $prev[0] && 0 === $g && strcmp( (string) $e['threshold_reached_since'], (string) $prev[1] ) < 0 ) || ( $g === $prev[0] && 1 === $g && $e['points_to_next'] < $prev[2] ) ) ) {
		$order_ok = false;
	}
	$prev = array( $g, $e['threshold_reached_since'], $e['points_to_next'] );
}
mkl_assert( $order_ok, 'Übersicht sortiert: fällige (älteste zuerst), dann nach fehlenden Punkten, höchster Rang zuletzt' );
list( $s, $d ) = mkl_req( 'GET', '/members/' . $r1 );
mkl_assert( 200 === $s && $maat['id'] === $d['rank_status']['rank']['id'] && 4 === count( $d['rank_history'] ), 'Mitgliedsdetails mit Rang-Stand und Verlauf' );
list( $s, $members ) = mkl_req( 'GET', '/members' );
$ranks_by_user = wp_list_pluck( $members, 'rank', 'id' );
mkl_assert( 'Mkltest Obermaat' === $ranks_by_user[ $r1 ]['title'] && null === $ranks_by_user[ $r3 ], 'Mitgliederliste zeigt den zugewiesenen Rang' );

echo "Mitglied mit Anfangsbestand und Rang anlegen\n";
wp_set_current_user( $boss );
foreach ( array( 'mkltest.uebernahme', 'mkltest.zuwenig', 'mkltest.ohne' ) as $old_login ) {
	$old = get_user_by( 'login', $old_login );
	if ( $old ) {
		wp_delete_user( $old->ID );
	}
}
list( $s, $d ) = mkl_req( 'POST', '/users', array( 'name' => 'X', 'username' => 'mkltest.zuwenig', 'initial_points' => 39, 'rank_id' => $bootsmann['id'] ) );
mkl_assert( 400 === $s && ! username_exists( 'mkltest.zuwenig' ), 'Rang mit zu wenig Anfangspunkten → 400, kein Konto angelegt' );
list( $s ) = mkl_req( 'POST', '/users', array( 'name' => 'X', 'username' => 'mkltest.zuwenig', 'initial_points' => 50, 'rank_id' => $inaktiv['id'] ) );
mkl_assert( 400 === $s && ! username_exists( 'mkltest.zuwenig' ), 'Deaktivierter Rang → 400' );
list( $s ) = mkl_req( 'POST', '/users', array( 'name' => 'X', 'username' => 'mkltest.zuwenig', 'initial_points' => -3 ) );
mkl_assert( 400 === $s && ! username_exists( 'mkltest.zuwenig' ), 'Negativer Anfangsbestand → 400' );
list( $s, $u ) = mkl_req( 'POST', '/users', array( 'name' => 'Mkltest Übernahme', 'username' => 'mkltest.uebernahme', 'initial_points' => 45, 'initial_points_date' => '2025-12-31', 'rank_id' => $bootsmann['id'] ) );
mkl_assert( 201 === $s && 45 === $u['points'] && $bootsmann['id'] === $u['rank']['id'], 'Mitglied mit 45 Punkten und Rang Bootsmann angelegt' );
$entries = MKL_DB::get_points_for_user( $u['id'] );
mkl_assert( 1 === count( $entries ) && 45 === $entries[0]['points'] && 'Anfangsbestand bei Übernahme' === $entries[0]['reason'] && '2025-12-31' === $entries[0]['date'], 'Anfangsbestand als normale Buchung mit Datum' );
$hist = MKL_Ranks::history( $u['id'] );
mkl_assert( $bootsmann['id'] === MKL_Ranks::user_rank_id( $u['id'] ) && 1 === count( $hist ) && 'Ersteinstufung bei Anlage' === $hist[0]['reason'] && 45 === $hist[0]['points'], 'Ersteinstufung im Verlauf' );
list( $s, $u2 ) = mkl_req( 'POST', '/users', array( 'name' => 'Mkltest Ohne', 'username' => 'mkltest.ohne' ) );
mkl_assert( 201 === $s && 0 === $u2['points'] && null === $u2['rank'] && ! MKL_DB::get_points_for_user( $u2['id'] ), 'Ohne Angaben: kein Rang, keine Buchung' );
foreach ( array( $u['id'], $u2['id'] ) as $uid ) {
	$wpdb->delete( MKL_DB::table( 'points' ), array( 'user_id' => $uid ) );
	$wpdb->delete( MKL_Ranks::history_table(), array( 'user_id' => $uid ) );
	wp_delete_user( $uid );
}

list( $s ) = mkl_req( 'DELETE', '/ranks/' . $inaktiv['id'] );
mkl_assert( 200 === $s && ! MKL_Ranks::get( $inaktiv['id'] ), 'Nicht zugewiesenen Rang löschen' );

foreach ( array( $r1, $r2, $r3, $gast, $boss ) as $uid ) {
	$wpdb->delete( MKL_DB::table( 'points' ), array( 'user_id' => $uid ) );
	$wpdb->delete( MKL_Ranks::history_table(), array( 'user_id' => $uid ) );
	wp_delete_user( $uid );
}
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . MKL_Ranks::table() . ' WHERE title LIKE %s', 'Mkltest%' ) );

echo "Dokumente\n";
$doc_admin = mkl_test_user( 'mkltest_docadmin', 'admin' );
$doc_ohne  = mkl_test_user( 'mkltest_docohne', 'ohne' );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . MKL_Documents::table() . ' WHERE title LIKE %s', 'Mkltest%' ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . MKL_Documents::categories_table() . ' WHERE title LIKE %s', 'Mkltest%' ) );

/** Lädt eine Datei hoch (Body = Datei, Angaben als Query-Parameter). */
function mkl_upload( $route, $bytes, $query, $type = 'application/octet-stream' ) {
	$req = new WP_REST_Request( 'POST', '/mk-lank/v1' . $route );
	if ( '' !== $type ) {
		$req->set_header( 'content-type', $type );
	}
	$req->set_query_params( $query );
	$req->set_body( $bytes );
	$res = rest_do_request( $req );
	return array( $res->get_status(), $res->get_data() );
}
$pdf1 = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n";
$pdf2 = "%PDF-1.7\n% Neue Fassung\n%%EOF\n";
$docx = "PK\x03\x04" . str_repeat( 'x', 40 );
$png  = "\x89PNG\r\n\x1A\n" . str_repeat( 'y', 20 );
$doc_meta = function ( $title, $filename, $extra = array() ) {
	return array_merge(
		array(
			'title'    => $title,
			'filename' => $filename,
			'date'     => '2026-03-01',
		),
		$extra
	);
};

wp_set_current_user( $m1 );
list( $s, $d ) = mkl_req( 'GET', '/documents' );
mkl_assert( 200 === $s && isset( $d['documents'], $d['categories'] ), 'Mitglied ruft Dokumente und Kategorien ab' );
list( $s ) = mkl_upload( '/documents', $pdf1, $doc_meta( 'Mkltest Satzung', 'satzung.pdf' ) );
mkl_assert( 403 === $s, 'Mitglied darf nicht hochladen → 403' );
wp_set_current_user( $spiess );
list( $s ) = mkl_upload( '/documents', $pdf1, $doc_meta( 'Mkltest Satzung', 'satzung.pdf' ) );
mkl_assert( 403 === $s, 'Spieß darf nicht hochladen → 403' );
list( $s ) = mkl_req( 'POST', '/document-categories', array( 'title' => 'Mkltest X' ) );
mkl_assert( 403 === $s, 'Spieß darf keine Kategorien anlegen → 403' );

wp_set_current_user( $doc_admin );
list( $s, $cat ) = mkl_req( 'POST', '/document-categories', array( 'title' => 'Mkltest Protokolle' ) );
mkl_assert( 201 === $s && 'Mkltest Protokolle' === $cat['title'], 'Admin legt Kategorie an' );
list( $s, $d ) = mkl_req( 'PATCH', '/document-categories/' . $cat['id'], array( 'title' => 'Mkltest Protokolle & Berichte' ) );
mkl_assert( 200 === $s && 'Mkltest Protokolle &amp; Berichte' !== $d['title'] && false !== strpos( $d['title'], 'Berichte' ), 'Kategorie umbenennen' );

list( $s, $d ) = mkl_upload( '/documents', 'Hallo Welt', $doc_meta( 'Mkltest X', 'notiz.txt' ) );
mkl_assert( 400 === $s && false !== strpos( $d['message'], 'Erlaubt sind' ), 'Nicht erlaubter Dateityp (.txt) → 400' );
list( $s, $d ) = mkl_upload( '/documents', 'Keine PDF, nur umbenannt', $doc_meta( 'Mkltest X', 'falsch.pdf' ) );
mkl_assert( 400 === $s && false !== strpos( $d['message'], 'keine gültige .pdf' ), 'Falsche Signatur (umbenannte Datei) → 400' );
list( $s ) = mkl_upload( '/documents', $pdf1, $doc_meta( 'Mkltest X', 'x.exe' ) );
mkl_assert( 400 === $s, '.exe auch mit PDF-Inhalt → 400' );
list( $s ) = mkl_upload( '/documents', '', $doc_meta( 'Mkltest X', 'x.pdf' ) );
mkl_assert( 400 === $s, 'Leere Datei → 400' );
list( $s ) = mkl_upload( '/documents', $pdf1, $doc_meta( '', 'x.pdf' ) );
mkl_assert( 400 === $s, 'Ohne Titel → 400' );
list( $s ) = mkl_upload( '/documents', $pdf1, $doc_meta( 'Mkltest X', 'x.pdf', array( 'date' => '2026-02-30' ) ) );
mkl_assert( 400 === $s, 'Ungültiger Stand → 400' );
list( $s ) = mkl_upload( '/documents', $pdf1, $doc_meta( 'Mkltest X', 'x.pdf', array( 'visibility' => 'alle' ) ) );
mkl_assert( 400 === $s, 'Ungültige Sichtbarkeit → 400' );
list( $s ) = mkl_upload( '/documents', $pdf1, $doc_meta( 'Mkltest X', 'x.pdf', array( 'category_id' => 999999 ) ) );
mkl_assert( 400 === $s, 'Unbekannte Kategorie → 400' );
list( $s ) = mkl_upload( '/documents', $pdf1, $doc_meta( 'Mkltest X', 'x.pdf' ), 'application/json' );
mkl_assert( 400 === $s, 'Datei als JSON gesendet → 400' );
list( $s ) = mkl_upload( '/documents', $pdf1, $doc_meta( 'Mkltest X', 'x.pdf' ), '' );
mkl_assert( 400 === $s, 'Ohne Content-Type → 400' );

list( $s, $satzung ) = mkl_upload( '/documents', $pdf1, $doc_meta( 'Mkltest Satzung', 'Satzung 2026.PDF' ), 'application/pdf' );
mkl_assert( 201 === $s && 'pdf' === $satzung['ext'] && 'pdf' === $satzung['kind'] && 'mitglieder' === $satzung['visibility'] && null === $satzung['category_id'], 'PDF für alle Mitglieder ohne Kategorie' );
mkl_assert( false !== strpos( $satzung['url'], '/app/dokument/' . $satzung['id'] . '?v=' . $satzung['version'] ) && false === strpos( $satzung['url'], 'mkl-private' ), 'URL zeigt auf den geschützten Abruf' );
list( $s, $protokoll ) = mkl_upload( '/documents', $docx, $doc_meta( 'Mkltest Protokoll März', 'protokoll.docx', array( 'category_id' => $cat['id'], 'visibility' => 'spiess' ) ) );
mkl_assert( 201 === $s && 'docx' === $protokoll['ext'] && 'datei' === $protokoll['kind'] && $cat['id'] === $protokoll['category_id'] && 'spiess' === $protokoll['visibility'], 'Word-Datei nur für Spieße in Kategorie' );
list( $s, $bild ) = mkl_upload( '/documents', $png, $doc_meta( 'Mkltest Lageplan', 'plan.png' ) );
mkl_assert( 201 === $s && 'bild' === $bild['kind'] && 'image/png' === $bild['mime'], 'PNG als Bild' );
$dir = MKL_Documents::dir();
mkl_assert( file_exists( $dir . '/.htaccess' ) && false !== strpos( file_get_contents( $dir . '/.htaccess' ), 'Require all denied' ) && file_exists( $dir . '/index.php' ), 'Ablage gegen direkten Zugriff gesperrt' );

$old_files = glob( $dir . '/dok-*' );
list( $s, $neu ) = mkl_upload( '/documents/' . $satzung['id'] . '/file', $pdf2, array( 'filename' => 'neu.pdf' ) );
$new_files = glob( $dir . '/dok-*' );
mkl_assert( 200 === $s && $neu['version'] !== $satzung['version'] && 'Mkltest Satzung' === $neu['title'] && count( $old_files ) === count( $new_files ), 'Neue Fassung ersetzt die alte Datei, Angaben bleiben' );
list( $s ) = mkl_upload( '/documents/' . $satzung['id'] . '/file', $docx, array( 'filename' => 'neu.pdf' ) );
mkl_assert( 400 === $s, 'Neue Fassung mit falscher Signatur → 400' );
list( $s, $d ) = mkl_req( 'PATCH', '/documents/' . $satzung['id'], array( 'date' => '2026-04-20', 'category_id' => $cat['id'] ) );
mkl_assert( 200 === $s && '2026-04-20' === $d['date'] && $cat['id'] === $d['category_id'] && 'Mkltest Satzung' === $d['title'], 'Stand und Kategorie ändern' );
list( $s, $d ) = mkl_req( 'PATCH', '/documents/' . $satzung['id'], array( 'category_id' => null ) );
mkl_assert( 200 === $s && null === $d['category_id'], 'Kategorie entfernen' );
list( $s ) = mkl_req( 'PATCH', '/documents/' . $satzung['id'], array( 'date' => 'morgen' ) );
mkl_assert( 400 === $s, 'Ungültiger Stand beim Ändern → 400' );
list( $s, $d ) = mkl_req( 'DELETE', '/document-categories/' . $cat['id'] );
mkl_assert( 400 === $s && false !== strpos( $d['message'], 'noch 1 Dokument' ), 'Kategorie mit Dokumenten nicht löschbar' );

$serve = function ( $uid, $id ) {
	wp_set_current_user( $uid );
	ob_start();
	// Auf der Kommandozeile sind schon Ausgaben erfolgt; header() warnt dann nur.
	set_error_handler( '__return_true', E_WARNING );
	MKL_Documents::serve( $id );
	restore_error_handler();
	return ob_get_clean();
};
mkl_assert( $pdf2 === $serve( $m1, $satzung['id'] ), 'Mitglied bekommt die PDF über /app/dokument/<id>' );
mkl_assert( '' === $serve( $doc_ohne, $satzung['id'] ) && '' === $serve( 0, $satzung['id'] ), 'Konto ohne Rolle und nicht Angemeldete bekommen keine Datei' );
mkl_assert( '' === $serve( $m1, $protokoll['id'] ), 'Mitglied bekommt kein Dokument „nur Spieße“' );
mkl_assert( $docx === $serve( $spiess, $protokoll['id'] ), 'Spieß bekommt das Dokument „nur Spieße“' );

wp_set_current_user( $m1 );
list( $s, $d ) = mkl_req( 'GET', '/documents' );
$titles = wp_list_pluck( $d['documents'], 'title' );
mkl_assert( in_array( 'Mkltest Satzung', $titles, true ) && in_array( 'Mkltest Lageplan', $titles, true ) && ! in_array( 'Mkltest Protokoll März', $titles, true ), 'Mitglied sieht nur Dokumente für alle Mitglieder' );
list( $s ) = mkl_req( 'DELETE', '/documents/' . $satzung['id'] );
mkl_assert( 403 === $s, 'Mitglied darf nicht löschen → 403' );
wp_set_current_user( $spiess );
list( $s, $d ) = mkl_req( 'GET', '/documents' );
mkl_assert( in_array( 'Mkltest Protokoll März', wp_list_pluck( $d['documents'], 'title' ), true ), 'Spieß sieht auch „nur Spieße“' );

wp_set_current_user( $doc_admin );
foreach ( array( $satzung, $protokoll, $bild ) as $doc ) {
	mkl_req( 'DELETE', '/documents/' . $doc['id'] );
}
mkl_assert( count( glob( $dir . '/dok-*' ) ) === count( $old_files ) - 3, 'Löschen entfernt auch die Dateien' );
list( $s ) = mkl_req( 'DELETE', '/documents/' . $satzung['id'] );
mkl_assert( 404 === $s, 'Erneutes Löschen → 404' );
list( $s ) = mkl_req( 'DELETE', '/document-categories/' . $cat['id'] );
mkl_assert( 200 === $s && ! MKL_Documents::get_category( $cat['id'] ), 'Leere Kategorie löschen' );

echo "Migration der Satzung aus 0.4\n";
wp_mkdir_p( $dir );
file_put_contents( $dir . '/satzung-alt.pdf', $pdf1 );
update_option( 'mkl_doc_satzung', array( 'file' => 'satzung-alt.pdf', 'date' => '2025-05-01', 'size' => strlen( $pdf1 ), 'version' => 'abc123', 'updated_at' => gmdate( 'c' ) ), false );
MKL_Documents::migrate_statute();
$migrated = $wpdb->get_row( 'SELECT * FROM ' . MKL_Documents::table() . " WHERE file = 'satzung-alt.pdf'", ARRAY_A );
mkl_assert( $migrated && 'Satzung' === $migrated['title'] && '2025-05-01' === $migrated['doc_date'] && 'mitglieder' === $migrated['visibility'] && false === get_option( 'mkl_doc_satzung' ), 'Bisherige Satzung wird als Dokument übernommen' );
if ( $migrated ) {
	MKL_Documents::delete( (int) $migrated['id'] );
}
wp_delete_user( $doc_admin );
wp_delete_user( $doc_ohne );

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
