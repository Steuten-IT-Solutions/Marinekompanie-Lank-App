<?php
/**
 * „Angemeldet bleiben“ praktisch ohne Ablauf.
 *
 * WordPress hält eine Anmeldung mit „Angemeldet bleiben“ nur 14 Tage. Für die App
 * wird daraus:
 * - eine Laufzeit von 400 Tagen (mehr lassen Chrome/Edge für Cookies nicht zu) und
 * - eine automatische Verlängerung, sobald die App geöffnet wird und die
 *   Anmeldung älter als RENEW_AFTER ist.
 * Wer die App mindestens einmal im Jahr öffnet, bleibt also dauerhaft angemeldet.
 *
 * Abmelden geht weiterhin über „Abmelden“ in der App, eine Passwortänderung meldet
 * alle anderen Geräte ab, und im WP-Profil gibt es „Überall sonst abmelden“.
 */

defined( 'ABSPATH' ) || exit;

class MKL_Session {

	const LIFETIME    = 400 * DAY_IN_SECONDS;
	const RENEW_AFTER = 30 * DAY_IN_SECONDS;

	public static function init() {
		add_filter( 'auth_cookie_expiration', array( __CLASS__, 'expiration' ), 10, 3 );
		add_action( 'login_footer', array( __CLASS__, 'check_remember_me' ) );
	}

	/** Nur Anmeldungen mit „Angemeldet bleiben“ werden verlängert, sonst bleibt es bei 2 Tagen. */
	public static function expiration( $length, $user_id, $remember ) {
		return $remember ? self::LIFETIME : $length;
	}

	/**
	 * Verlängert die Anmeldung beim Öffnen der App (vor jeder Ausgabe aufrufen).
	 *
	 * @return bool true, wenn ein neues Cookie gesetzt wurde.
	 */
	public static function maybe_renew( $now = null ) {
		$now = $now ? $now : time();
		if ( ! is_user_logged_in() || headers_sent() ) {
			return false;
		}
		$cookie = wp_parse_auth_cookie( '', 'logged_in' );
		if ( ! $cookie ) {
			return false;
		}
		$remaining = (int) $cookie['expiration'] - $now;
		// Ohne „Angemeldet bleiben“ gilt die Anmeldung nur 2 Tage – die bleibt unverändert.
		if ( $remaining <= 3 * DAY_IN_SECONDS || $remaining > self::LIFETIME - self::RENEW_AFTER ) {
			return false;
		}

		$user_id   = get_current_user_id();
		$old_token = $cookie['token'];
		// Neues Cookie auch für den Rest dieser Anfrage gültig machen (Nonce hängt am Sitzungs-Token).
		$sync = static function ( $logged_in_cookie ) {
			$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in_cookie;
		};
		add_action( 'set_logged_in_cookie', $sync );
		wp_set_auth_cookie( $user_id, true, is_ssl() );
		remove_action( 'set_logged_in_cookie', $sync );

		if ( $old_token ) {
			WP_Session_Tokens::get_instance( $user_id )->destroy( $old_token );
		}
		return true;
	}

	/** Auf der Anmeldeseite der App ist „Angemeldet bleiben“ schon angehakt. */
	public static function check_remember_me() {
		$redirect = isset( $_REQUEST['redirect_to'] ) ? (string) wp_unslash( $_REQUEST['redirect_to'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( false === strpos( $redirect, '/app/' ) ) {
			return;
		}
		echo "<script>(function(){var r=document.getElementById('rememberme');if(r){r.checked=true;}})();</script>\n";
	}
}
