<?php
/**
 * Liefert die PWA unter /app/ aus.
 *
 * /app/                      → App (Gäste sehen die öffentliche Ansicht)
 * /app/installieren/         → App mit der Installationsanleitung (Link zum Weitergeben)
 * /app/manifest.webmanifest  → Web-App-Manifest
 * /app/sw.js                 → Service Worker (Scope /app/)
 * /app/foto/<id>/<größe>     → Galeriebild (interne nur für Mitglieder)
 *
 * Die gebauten JS/CSS-Dateien liegen in app-dist/ und werden direkt vom
 * Webserver als statische Dateien ausgeliefert.
 */

defined( 'ABSPATH' ) || exit;

class MKL_App_Page {

	const QUERY_VAR = 'mkl_app';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'dispatch' ), 0 );
	}

	public static function add_rewrite_rules() {
		add_rewrite_rule( '^app/?$', 'index.php?' . self::QUERY_VAR . '=index', 'top' );
		add_rewrite_rule( '^app/(.+)$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function app_url() {
		return home_url( '/app/' );
	}

	public static function dist_dir() {
		return MKL_DIR . 'app-dist/';
	}

	public static function dispatch() {
		$path = get_query_var( self::QUERY_VAR );
		if ( '' === $path || null === $path ) {
			return;
		}

		if ( 'manifest.webmanifest' === $path ) {
			self::send_manifest();
		} elseif ( 'sw.js' === $path ) {
			self::send_service_worker();
		} elseif ( preg_match( '#^foto/(\d+)/(thumb|large)$#', $path, $m ) ) {
			MKL_Gallery::serve( (int) $m[1], $m[2] );
		} else {
			self::send_app();
		}
		exit;
	}

	private static function send_manifest() {
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: no-cache' );
		$icons = MKL_URL . 'app-dist/icons/';
		echo wp_json_encode(
			array(
				'name'             => get_option( 'mkl_app_name', 'Marinekompanie Lank' ),
				'short_name'       => get_option( 'mkl_app_short_name', 'Marinekompanie' ),
				'description'      => 'Termine, Punkte und Strafen der Marinekompanie 1973 Lank',
				'lang'             => 'de',
				'start_url'        => self::app_url(),
				'scope'            => self::app_url(),
				'display'          => 'standalone',
				'orientation'      => 'portrait',
				'background_color' => '#f4efe4',
				'theme_color'      => '#1f4498',
				'icons'            => array(
					array( 'src' => $icons . 'icon-192.png', 'sizes' => '192x192', 'type' => 'image/png' ),
					array( 'src' => $icons . 'icon-512.png', 'sizes' => '512x512', 'type' => 'image/png' ),
					array( 'src' => $icons . 'icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' ),
				),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}

	private static function send_service_worker() {
		$file = self::dist_dir() . 'sw.js';
		if ( ! is_readable( $file ) ) {
			status_header( 404 );
			return;
		}
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Cache-Control: no-cache' );
		header( 'Service-Worker-Allowed: ' . wp_parse_url( self::app_url(), PHP_URL_PATH ) );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	private static function send_app() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();


		$file = self::dist_dir() . 'index.html';
		if ( ! is_readable( $file ) ) {
			status_header( 500 );
			echo 'Die App wurde noch nicht gebaut (app-dist/index.html fehlt).';
			return;
		}

		// Wer die App öffnet, bleibt angemeldet: Anmeldung bei Bedarf verlängern.
		MKL_Session::maybe_renew();

		// Gäste (nicht angemeldet) sehen die öffentliche Ansicht; ein Nonce gibt es nur mit Anmeldung.
		$user   = wp_get_current_user();
		$config = array(
			'restUrl'   => esc_url_raw( rest_url( MKL_REST::NS . '/' ) ),
			'nonce'     => $user->exists() ? wp_create_nonce( 'wp_rest' ) : '',
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'loginUrl'  => wp_login_url( self::app_url() ),
			'logoutUrl' => wp_logout_url( self::app_url() ),
			'appUrl'    => self::app_url(),
			'siteUrl'   => home_url( '/' ),
			'swUrl'     => self::app_url() . 'sw.js',
			'user'      => ! $user->exists() ? null : array(
				'id'              => $user->ID,
				'name'            => $user->display_name,
				'login'           => $user->user_login,
				'level'           => MKL_Roles::get_level( $user->ID ),
				'isMember'        => MKL_Roles::is_member(),
				'isSpiess'        => MKL_Roles::is_spiess(),
				'canCreateUsers'  => current_user_can( 'create_users' ),
				'canManageEvents' => current_user_can( MKL_CAP_EVENTS ),
				'canPromoteUsers' => current_user_can( 'promote_users' ),
			),
		);

		$head = sprintf(
			'<link rel="manifest" href="%s"><script>window.MKL_CONFIG=%s;</script>',
			esc_url( self::app_url() . 'manifest.webmanifest' ),
			wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES )
		);

		$html = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		// Relative Pfade aus dem Vite-Build (base: './') auf das Plugin-Verzeichnis umbiegen.
		$html = str_replace( array( '"./', "'./" ), array( '"' . MKL_URL . 'app-dist/', "'" . MKL_URL . 'app-dist/' ), $html );
		// Konfiguration ganz an den Anfang, damit sie vor dem App-Skript verfügbar ist.
		$pos  = strpos( $html, '<head>' );
		$html = false === $pos ? $head . $html : substr_replace( $html, $head, $pos + strlen( '<head>' ), 0 );

		header( 'Content-Type: text/html; charset=utf-8' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
