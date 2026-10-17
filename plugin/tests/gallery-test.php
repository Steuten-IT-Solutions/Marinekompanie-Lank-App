<?php
/**
 * Integrationstests der Galerie mit simuliertem Google Drive.
 *
 *   wp eval-file plugin/tests/gallery-test.php
 *
 * Alle HTTP-Aufrufe an Google werden über pre_http_request abgefangen:
 * Token-Endpunkt (inkl. Prüfung der JWT-Signatur), files.list mit Paging
 * und der Bild-Download (erzeugtes JPEG).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
require_once ABSPATH . 'wp-admin/includes/user.php';

$GLOBALS['mkl_failures'] = 0;
$GLOBALS['mkl_passes']   = 0;
function mkl_g_assert( $cond, $label ) {
	$GLOBALS[ $cond ? 'mkl_passes' : 'mkl_failures' ]++;
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . $label . "\n";
}

// Aufräumen aus früheren Läufen.
global $wpdb;
foreach ( $wpdb->get_results( 'SELECT * FROM ' . MKL_Gallery::photos_table(), ARRAY_A ) as $old ) {
	MKL_Gallery::delete_files( $old );
}
$wpdb->query( 'DELETE FROM ' . MKL_Gallery::photos_table() );
$wpdb->query( 'DELETE FROM ' . MKL_Gallery::albums_table() );
delete_transient( MKL_Drive::TOKEN_CACHE );

echo "Dienstkonto\n";
$key = openssl_pkey_new(
	array(
		'private_key_bits' => 2048,
		'private_key_type' => OPENSSL_KEYTYPE_RSA,
	)
);
openssl_pkey_export( $key, $private_pem );
$public_pem = openssl_pkey_get_details( $key )['key'];
$sa_json    = wp_json_encode(
	array(
		'type'         => 'service_account',
		'client_email' => 'galerie@mkl-test.iam.gserviceaccount.com',
		'private_key'  => $private_pem,
		'token_uri'    => 'https://oauth2.googleapis.com/token',
	)
);
mkl_g_assert( is_wp_error( MKL_Drive::save_service_account( '{"foo":1}' ) ), 'Ungültiges JSON wird abgelehnt' );
mkl_g_assert( true === MKL_Drive::save_service_account( $sa_json ), 'Dienstkonto gespeichert' );
mkl_g_assert( 'galerie@mkl-test.iam.gserviceaccount.com' === MKL_Drive::client_email(), 'E-Mail des Dienstkontos lesbar' );

mkl_g_assert( 'abc_DEF-123' === MKL_Drive::parse_folder_id( 'https://drive.google.com/drive/folders/abc_DEF-123?usp=sharing' ), 'Ordner-ID aus Link' );
mkl_g_assert( 'abc' === MKL_Drive::parse_folder_id( 'abc' ) && '' === MKL_Drive::parse_folder_id( 'kein link!' ), 'Ordner-ID direkt / ungültig' );

// --- Simuliertes Drive ------------------------------------------------------
$folder = 'application/vnd.google-apps.folder';
$GLOBALS['mkl_tree'] = array(
	'ROOT'  => array(
		array( 'id' => 'Y2026', 'name' => '2026', 'mimeType' => $folder, 'createdTime' => '2026-01-01T00:00:00Z' ),
		array( 'id' => 'Y2025', 'name' => '2025', 'mimeType' => $folder, 'createdTime' => '2025-01-01T00:00:00Z' ),
		array( 'id' => 'STRAY', 'name' => 'lose.jpg', 'mimeType' => 'image/jpeg', 'md5Checksum' => 'x' ),
	),
	'Y2026' => array(
		array( 'id' => 'A1', 'name' => 'Schützenfest', 'mimeType' => $folder, 'createdTime' => '2026-06-28T00:00:00Z' ),
		array( 'id' => 'A2', 'name' => 'Kompanieabend', 'mimeType' => $folder, 'createdTime' => '2026-03-01T00:00:00Z' ),
	),
	'A1'    => array(
		array( 'id' => 'IMG1', 'name' => 'a.jpg', 'mimeType' => 'image/jpeg', 'md5Checksum' => 'm1', 'imageMediaMetadata' => array( 'width' => 1200, 'height' => 900, 'time' => '2026:06:27 18:00:00' ) ),
		array( 'id' => 'IMG2', 'name' => 'b.jpg', 'mimeType' => 'image/jpeg', 'md5Checksum' => 'm2', 'imageMediaMetadata' => array( 'time' => '2026:06:27 19:00:00' ) ),
		array( 'id' => 'I1', 'name' => 'Intern', 'mimeType' => $folder ),
	),
	'I1'    => array(
		array( 'id' => 'IMG3', 'name' => 'c.jpg', 'mimeType' => 'image/jpeg', 'md5Checksum' => 'm3' ),
	),
	'A2'    => array(
		array( 'id' => 'I2', 'name' => 'intern', 'mimeType' => $folder ),
	),
	'I2'    => array(
		array( 'id' => 'IMG4', 'name' => 'd.jpg', 'mimeType' => 'image/jpeg', 'md5Checksum' => 'm4' ),
	),
	'Y2025' => array(
		array( 'id' => 'A3', 'name' => 'Maifest', 'mimeType' => $folder, 'createdTime' => '2025-05-08T00:00:00Z' ),
	),
	'A3'    => array(
		array( 'id' => 'IMG5', 'name' => 'e.png', 'mimeType' => 'image/png', 'md5Checksum' => 'm5' ),
		array( 'id' => 'VID1', 'name' => 'film.mp4', 'mimeType' => 'video/mp4', 'md5Checksum' => 'v1' ),
	),
);
$GLOBALS['mkl_http']      = array(
	'token'     => 0,
	'jwt_ok'    => false,
	'list'      => 0,
	'paged'     => false,
	'downloads' => 0,
	'oauth'     => array(),
	'revoked'   => '',
);
$GLOBALS['mkl_public_pem'] = $public_pem;

function mkl_test_jpeg() {
	$im = imagecreatetruecolor( 1200, 900 );
	imagefill( $im, 0, 0, imagecolorallocate( $im, 31, 68, 152 ) );
	ob_start();
	imagejpeg( $im, null, 80 );
	return ob_get_clean();
}

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		$ok = function ( $body ) {
			return array(
				'headers'  => array(),
				'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		if ( 0 === strpos( $url, 'https://oauth2.googleapis.com/revoke' ) ) {
			$GLOBALS['mkl_http']['revoked'] = $args['body']['token'];
			return $ok( array() );
		}
		if ( 0 === strpos( $url, 'https://www.googleapis.com/drive/v3/about' ) ) {
			return $ok( array( 'user' => array( 'emailAddress' => 'kompanie@example.com' ) ) );
		}
		if ( 0 === strpos( $url, 'https://oauth2.googleapis.com/token' ) && 'urn:ietf:params:oauth:grant-type:jwt-bearer' !== $args['body']['grant_type'] ) {
			$GLOBALS['mkl_http']['oauth'][] = $args['body'];
			if ( ! empty( $GLOBALS['mkl_http']['invalid_grant'] ) ) {
				$res             = $ok( array( 'error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.' ) );
				$res['response'] = array( 'code' => 400, 'message' => 'Bad Request' );
				return $res;
			}
			$tok = array( 'access_token' => 'TOKEN123', 'expires_in' => 3599, 'scope' => MKL_Drive::SCOPE );
			if ( 'authorization_code' === $args['body']['grant_type'] ) {
				$tok['refresh_token'] = 'R1';
			}
			return $ok( $tok );
		}
		if ( 0 === strpos( $url, 'https://oauth2.googleapis.com/token' ) ) {
			$GLOBALS['mkl_http']['token']++;
			$parts = explode( '.', $args['body']['assertion'] );
			$sig   = base64_decode( strtr( $parts[2], '-_', '+/' ) );
			$claim = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true );
			$GLOBALS['mkl_http']['jwt_ok'] = 1 === openssl_verify( $parts[0] . '.' . $parts[1], $sig, $GLOBALS['mkl_public_pem'], OPENSSL_ALGO_SHA256 )
				&& 'galerie@mkl-test.iam.gserviceaccount.com' === $claim['iss']
				&& MKL_Drive::SCOPE === $claim['scope'];
			return $ok( array( 'access_token' => 'TOKEN123', 'expires_in' => 3600 ) );
		}
		if ( 0 !== strpos( $url, 'https://www.googleapis.com/drive/v3/files' ) ) {
			return $pre;
		}
		if ( 'Bearer TOKEN123' !== $args['headers']['Authorization'] ) {
			return new WP_Error( 'test', 'Kein Token' );
		}
		if ( false !== strpos( $url, 'alt=media' ) ) {
			$GLOBALS['mkl_http']['downloads']++;
			return $ok( mkl_test_jpeg() );
		}
		$GLOBALS['mkl_http']['list']++;
		parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $q );
		preg_match( "/'([^']+)' in parents/", $q['q'], $m );
		$files = isset( $GLOBALS['mkl_tree'][ $m[1] ] ) ? $GLOBALS['mkl_tree'][ $m[1] ] : array();
		// Paging für A1 simulieren: erste Seite nur ein Eintrag.
		if ( 'A1' === $m[1] ) {
			if ( empty( $q['pageToken'] ) ) {
				return $ok(
					array(
						'files'         => array_slice( $files, 0, 1 ),
						'nextPageToken' => 'P2',
					)
				);
			}
			$GLOBALS['mkl_http']['paged'] = true;
			return $ok( array( 'files' => array_slice( $files, 1 ) ) );
		}
		return $ok( array( 'files' => $files ) );
	},
	10,
	3
);

echo "Sync\n";
update_option( MKL_Gallery::ROOT_OPTION, 'ROOT' );
$stats = MKL_Gallery::sync();
mkl_g_assert( ! is_wp_error( $stats ), 'Sync ohne Fehler' . ( is_wp_error( $stats ) ? ': ' . $stats->get_error_message() : '' ) );
mkl_g_assert( $GLOBALS['mkl_http']['jwt_ok'] && 1 === $GLOBALS['mkl_http']['token'], 'Token per signiertem JWT geholt (Signatur geprüft)' );
mkl_g_assert( $GLOBALS['mkl_http']['paged'], 'Paging von files.list wird verfolgt' );
mkl_g_assert( 3 === $stats['albums'] && 5 === $stats['photos'] && 5 === $stats['pending'], 'Drei Alben, fünf Bilder (lose Datei und Video ignoriert)' );

echo "Sichtbarkeit\n";
$titles = function ( $years ) {
	$out = array();
	foreach ( $years as $y ) {
		foreach ( $y['albums'] as $a ) {
			$out[ $a['title'] ] = $a;
		}
	}
	return $out;
};
$guest  = $titles( MKL_Gallery::overview( false ) );
$member = $titles( MKL_Gallery::overview( true ) );
mkl_g_assert( isset( $guest['Schützenfest'], $guest['Maifest'] ) && ! isset( $guest['Kompanieabend'] ), 'Gäste sehen keine Alben, die nur interne Bilder haben' );
mkl_g_assert( 2 === $guest['Schützenfest']['count'] && 3 === $member['Schützenfest']['count'] && 1 === $member['Schützenfest']['intern_count'], 'Interne Bilder werden für Mitglieder ins Album gemischt' );
mkl_g_assert( isset( $member['Kompanieabend'] ), 'Mitglieder sehen auch rein interne Alben' );
$years = MKL_Gallery::overview( false );
mkl_g_assert( '2026' === $years[0]['year'] && '2025' === $years[1]['year'], 'Jahre absteigend sortiert' );

$a1_guest  = MKL_Gallery::album( $guest['Schützenfest']['id'], false );
$a1_member = MKL_Gallery::album( $guest['Schützenfest']['id'], true );
mkl_g_assert( 2 === count( $a1_guest['photos'] ) && 3 === count( $a1_member['photos'] ), 'Album-Detail: Gast 2, Mitglied 3 Bilder' );
mkl_g_assert( 'a.jpg' === $a1_guest['photos'][0]['name'] && 'b.jpg' === $a1_guest['photos'][1]['name'], 'Bilder nach Aufnahmezeit sortiert' );
mkl_g_assert( null === MKL_Gallery::album( $member['Kompanieabend']['id'], false ), 'Rein internes Album für Gäste nicht abrufbar' );

echo "Bilder aufbereiten\n";
$done = MKL_Gallery::process_pending( 60 );
mkl_g_assert( 5 === $done && 0 === MKL_Gallery::count_pending() && 5 === $GLOBALS['mkl_http']['downloads'], 'Alle fünf Bilder heruntergeladen und aufbereitet' );
$photos = $wpdb->get_results( 'SELECT * FROM ' . MKL_Gallery::photos_table(), ARRAY_A );
$all_ok = true;
foreach ( $photos as $ph ) {
	$thumb = getimagesize( MKL_Gallery::path( $ph, 'thumb' ) );
	$large = getimagesize( MKL_Gallery::path( $ph, 'large' ) );
	$all_ok = $all_ok && $thumb && max( $thumb[0], $thumb[1] ) === 400 && $large && 1200 === $large[0] && 'image/jpeg' === $thumb['mime'];
}
mkl_g_assert( $all_ok, 'Je Bild 400er-Vorschau und Großansicht (ohne Hochskalieren) als JPEG' );

$a1_member = MKL_Gallery::album( $guest['Schützenfest']['id'], true );
$public    = $a1_member['photos'][0];
$intern    = array_values( array_filter( $a1_member['photos'], function ( $p ) { return $p['intern']; } ) )[0];
$upload    = wp_upload_dir();
mkl_g_assert( 0 === strpos( $public['thumb'], $upload['baseurl'] . '/mkl-gallery/' ), 'Öffentliche Bilder als statische Datei' );
mkl_g_assert( false !== strpos( $intern['thumb'], '/app/foto/' ) && false === strpos( $intern['thumb'], 'uploads' ), 'Interne Bilder nur über /app/foto/' );
$intern_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MKL_Gallery::photos_table() . ' WHERE id = %d', $intern['id'] ), ARRAY_A );
mkl_g_assert( 0 === strpos( MKL_Gallery::path( $intern_row, 'thumb' ), $upload['basedir'] . '/mkl-gallery-intern/' ) && file_exists( $upload['basedir'] . '/mkl-gallery-intern/.htaccess' ), 'Interne Dateien im geschützten Verzeichnis' );

echo "Auslieferung interner Bilder\n";
wp_set_current_user( 0 );
ob_start();
MKL_Gallery::serve( $intern['id'], 'thumb' );
$out = ob_get_clean();
mkl_g_assert( '' === $out, 'Gast bekommt kein internes Bild' );
$mitglied = wp_insert_user( array( 'user_login' => 'mkltest_galerie', 'user_pass' => wp_generate_password(), 'role' => MKL_ROLE_MITGLIED ) );
wp_set_current_user( $mitglied );
ob_start();
@MKL_Gallery::serve( $intern['id'], 'thumb' ); // phpcs:ignore -- header() im CLI ohne Wirkung
$out = ob_get_clean();
mkl_g_assert( "\xFF\xD8" === substr( $out, 0, 2 ), 'Mitglied bekommt das interne Bild (JPEG)' );
wp_set_current_user( 0 );
wp_delete_user( $mitglied );

echo "Shortcode\n";
$html = do_shortcode( '[mkl_galerie]' );
mkl_g_assert( false !== strpos( $html, 'Schützenfest' ) && false === strpos( $html, 'Kompanieabend' ), '[mkl_galerie] zeigt Gästen nur öffentliche Alben' );
$_GET['album'] = (string) $guest['Schützenfest']['id'];
$html          = do_shortcode( '[mkl_galerie]' );
unset( $_GET['album'] );
mkl_g_assert( 2 === substr_count( $html, 'data-mkl-lightbox' ) && false === strpos( $html, '/app/foto/' ), 'Album-Ansicht im Shortcode ohne interne Bilder' );

echo "Änderungen in Drive\n";
$GLOBALS['mkl_tree']['A1'][1]['md5Checksum'] = 'm2-neu'; // b.jpg geändert
unset( $GLOBALS['mkl_tree']['A3'][0] );                   // e.png gelöscht
$gone = $wpdb->get_row( "SELECT * FROM " . MKL_Gallery::photos_table() . " WHERE drive_file_id = 'IMG5'", ARRAY_A );
$stats = MKL_Gallery::sync();
mkl_g_assert( 2 === $stats['albums'] && 4 === $stats['photos'] && ! isset( $titles( MKL_Gallery::overview( true ) )['Maifest'] ), 'Gelöschtes Bild und leeres Album entfernt' );
mkl_g_assert( ! $wpdb->get_var( "SELECT id FROM " . MKL_Gallery::photos_table() . " WHERE drive_file_id = 'IMG5'" ) && ! file_exists( MKL_Gallery::path( $gone, 'thumb' ) ), 'Dateien gelöschter Bilder werden entfernt' );
mkl_g_assert( 'pending' === $wpdb->get_var( "SELECT status FROM " . MKL_Gallery::photos_table() . " WHERE drive_file_id = 'IMG2'" ), 'Geändertes Bild wird neu aufbereitet' );
mkl_g_assert( 1 === $GLOBALS['mkl_http']['token'], 'Zugriffstoken wird zwischengespeichert' );

echo "Mit Google verbinden (OAuth)\n";
$admin = wp_insert_user( array( 'user_login' => 'mkltest_oauth', 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
wp_set_current_user( $admin );
mkl_g_assert( is_wp_error( MKL_Drive::save_client( 'falsch', 'geheim' ) ), 'Ungültige Client-ID wird abgelehnt' );
mkl_g_assert( is_wp_error( MKL_Drive::save_client( '123-abc.apps.googleusercontent.com', '' ) ), 'Ohne Client-Secret wird abgelehnt' );
mkl_g_assert( true === MKL_Drive::save_client( '123-abc.apps.googleusercontent.com', 'GEHEIM' ) && ! MKL_Drive::oauth_connected(), 'Client gespeichert, noch nicht verbunden' );

parse_str( (string) wp_parse_url( MKL_Drive::auth_url( 'mkl_drive_x' ), PHP_URL_QUERY ), $q );
mkl_g_assert(
	'123-abc.apps.googleusercontent.com' === $q['client_id'] && admin_url( 'admin-post.php' ) === $q['redirect_uri'] && MKL_Drive::SCOPE === $q['scope']
		&& 'offline' === $q['access_type'] && 'consent' === $q['prompt'] && 'code' === $q['response_type'] && 'mkl_drive_x' === $q['state'],
	'Anmelde-Link zu Google mit Offline-Zugriff, nur Lesen'
);
$state = MKL_Drive::new_state();
mkl_g_assert( MKL_Drive::check_state( $state ) && ! MKL_Drive::check_state( $state ), 'state gilt genau einmal' );
mkl_g_assert( ! MKL_Drive::check_state( 'mkl_drive_gefaelscht' ), 'Fremdes state wird abgelehnt' );

$_GET['state'] = 'etwas_anderes';
MKL_Settings::drive_callback(); // darf nichts tun (und nicht beenden)
unset( $_GET['state'] );
mkl_g_assert( true, 'Fremde admin-post-Aufrufe ohne action werden ignoriert' );

$res = MKL_Drive::exchange_code( 'CODE1' );
$req = end( $GLOBALS['mkl_http']['oauth'] );
mkl_g_assert( true === $res && MKL_Drive::oauth_connected(), 'Code gegen Refresh-Token getauscht' );
mkl_g_assert( 'authorization_code' === $req['grant_type'] && 'CODE1' === $req['code'] && 'GEHEIM' === $req['client_secret'] && admin_url( 'admin-post.php' ) === $req['redirect_uri'], 'Token-Tausch mit Code, Secret und Weiterleitungs-URI' );
mkl_g_assert( 'kompanie@example.com' === MKL_Drive::oauth_email(), 'Verbundenes Google-Konto wird angezeigt' );

delete_transient( MKL_Drive::TOKEN_CACHE );
$jwt_before = $GLOBALS['mkl_http']['token'];
$stats      = MKL_Gallery::sync();
$req        = end( $GLOBALS['mkl_http']['oauth'] );
mkl_g_assert( ! is_wp_error( $stats ) && 4 === $stats['photos'], 'Sync über die Google-Verbindung' );
mkl_g_assert( 'refresh_token' === $req['grant_type'] && 'R1' === $req['refresh_token'] && $jwt_before === $GLOBALS['mkl_http']['token'], 'Verbindung hat Vorrang vor dem Dienstkonto (Refresh-Token statt JWT)' );

MKL_Drive::save_client( '123-abc.apps.googleusercontent.com', '' );
mkl_g_assert( MKL_Drive::oauth_connected(), 'Leeres Secret behält Secret und Verbindung' );

delete_transient( MKL_Drive::TOKEN_CACHE );
$GLOBALS['mkl_http']['invalid_grant'] = true;
$err = MKL_Drive::access_token();
$GLOBALS['mkl_http']['invalid_grant'] = false;
mkl_g_assert( is_wp_error( $err ) && false !== strpos( $err->get_error_message(), 'neu mit Google verbinden' ), 'Widerrufene Verbindung: verständliche Meldung' );

MKL_Drive::disconnect();
mkl_g_assert( 'R1' === $GLOBALS['mkl_http']['revoked'] && ! MKL_Drive::oauth_connected(), 'Trennen widerruft das Token bei Google' );
mkl_g_assert( MKL_Drive::is_configured() && ! is_wp_error( MKL_Drive::access_token() ) && $jwt_before + 1 === $GLOBALS['mkl_http']['token'], 'Danach greift wieder das Dienstkonto' );

MKL_Drive::exchange_code( 'CODE2' );
MKL_Drive::save_client( '999-neu.apps.googleusercontent.com', 'ANDERS' );
mkl_g_assert( ! MKL_Drive::oauth_connected(), 'Anderer OAuth-Client erfordert neue Verbindung' );
wp_set_current_user( 0 );
wp_delete_user( $admin );

// Aufräumen.
foreach ( $wpdb->get_results( 'SELECT * FROM ' . MKL_Gallery::photos_table(), ARRAY_A ) as $ph ) {
	MKL_Gallery::delete_files( $ph );
}
$wpdb->query( 'DELETE FROM ' . MKL_Gallery::photos_table() );
$wpdb->query( 'DELETE FROM ' . MKL_Gallery::albums_table() );
delete_option( MKL_Gallery::ROOT_OPTION );
delete_option( MKL_Drive::OPTION_KEY );
delete_option( MKL_Drive::OAUTH_OPTION );
delete_option( MKL_Gallery::STATUS_OPTION );
delete_transient( MKL_Drive::TOKEN_CACHE );
MKL_Gallery::unschedule();

printf( "\n%d bestanden, %d fehlgeschlagen\n", $GLOBALS['mkl_passes'], $GLOBALS['mkl_failures'] );
if ( $GLOBALS['mkl_failures'] ) {
	exit( 1 );
}
