<?php
/**
 * Plugin Name:       Marinekompanie Lank – App
 * Description:       Mitglieder-App (PWA) mit Terminen, Punkten, Dienstgraden, Titeln, Strafen, Dokumenten und Push-Benachrichtigungen. Erreichbar unter /app/.
 * Version:           0.9.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Marinekompanie 1973 Lank
 * License:           GPL-2.0-or-later
 * Text Domain:       mk-lank-app
 */

defined( 'ABSPATH' ) || exit;

define( 'MKL_VERSION', '0.9.0' );
define( 'MKL_DB_VERSION', '10' );
define( 'MKL_FILE', __FILE__ );
define( 'MKL_DIR', plugin_dir_path( __FILE__ ) );
define( 'MKL_URL', plugin_dir_url( __FILE__ ) );

/** Capability, die zum Vergeben/Verwalten von Punkten und Strafen berechtigt (Spieß). */
define( 'MKL_CAP_MANAGE', 'mkl_manage' );
/** Capability „Mitglied“: eigene Punkte/Strafen, interne Galerie. */
define( 'MKL_CAP_MEMBER', 'mkl_member' );
/** Capability für den internen Kalender (Google Kalender mit allen Terminen): Anwärter und Mitglieder. */
define( 'MKL_CAP_CALENDAR', 'mkl_calendar' );
/** Capability zum Pflegen der öffentlichen Terminliste (Admin). */
define( 'MKL_CAP_EVENTS', 'mkl_manage_events' );
/** Capability zum Pflegen der Dokumente und ihrer Kategorien (Admin). */
define( 'MKL_CAP_DOCS', 'mkl_manage_documents' );
/** Rolle „Spieß“. Wird Benutzern zusätzlich zu ihrer bisherigen Rolle gegeben. */
define( 'MKL_ROLE_SPIESS', 'mkl_spiess' );
/** Rolle „Mitglied“. Ohne sie (und ohne Anwärter/Spieß/Admin) hat ein Konto in der App keine Rolle. */
define( 'MKL_ROLE_MITGLIED', 'mkl_mitglied' );
/** Rolle „Anwärter“: wie ohne Rolle, sieht aber den internen Kalender statt der öffentlichen Termine. */
define( 'MKL_ROLE_ANWAERTER', 'mkl_anwaerter' );
/** Benutzer-Meta „Jungmariner“: erhält laut Satzung nur die halbe Punktzahl. */
define( 'MKL_META_JUNGMARINER', 'mkl_jungmariner' );

require_once MKL_DIR . 'includes/class-mkl-db.php';
require_once MKL_DIR . 'includes/class-mkl-roles.php';
require_once MKL_DIR . 'includes/class-mkl-ranks.php';
require_once MKL_DIR . 'includes/class-mkl-titles.php';
require_once MKL_DIR . 'includes/class-mkl-documents.php';
require_once MKL_DIR . 'includes/class-mkl-session.php';
require_once MKL_DIR . 'includes/class-mkl-calendar.php';
require_once MKL_DIR . 'includes/class-mkl-events.php';
require_once MKL_DIR . 'includes/class-mkl-attendance.php';
require_once MKL_DIR . 'includes/class-mkl-shortcodes.php';
require_once MKL_DIR . 'includes/class-mkl-drive.php';
require_once MKL_DIR . 'includes/class-mkl-gallery.php';
require_once MKL_DIR . 'includes/class-mkl-rest.php';
require_once MKL_DIR . 'includes/class-mkl-push.php';
require_once MKL_DIR . 'includes/class-mkl-app-page.php';
require_once MKL_DIR . 'includes/class-mkl-settings.php';

register_activation_hook( __FILE__, 'mkl_activate' );
register_deactivation_hook( __FILE__, 'mkl_deactivate' );

function mkl_activate() {
	MKL_DB::maybe_upgrade();
	MKL_Roles::install();
	MKL_App_Page::add_rewrite_rules();
	flush_rewrite_rules();
}

function mkl_deactivate() {
	MKL_Gallery::unschedule();
	MKL_Push::unschedule();
	flush_rewrite_rules();
}

add_action( 'plugins_loaded', array( 'MKL_DB', 'maybe_upgrade' ) );
add_action( 'rest_api_init', array( 'MKL_REST', 'register_routes' ) );
add_action( 'deleted_user', array( 'MKL_DB', 'delete_user_data' ) );
MKL_Roles::init();
MKL_Session::init();
MKL_App_Page::init();
MKL_Settings::init();
MKL_Shortcodes::init();
MKL_Gallery::init();
MKL_Push::init();
