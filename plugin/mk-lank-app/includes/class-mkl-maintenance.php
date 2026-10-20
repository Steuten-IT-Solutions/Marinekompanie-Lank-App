<?php
/**
 * Wartungsmodus der App.
 *
 * - Nur von Hand ein- und auszuschalten (Admins mit manage_options), in der App oder unter
 *   Einstellungen → Kompanie-App. Optional mit einer Begründung (reiner Text, max. 500 Zeichen).
 * - Solange er aktiv ist, können nur Admins die App nutzen: Alle REST-Routen unter mk-lank/v1
 *   antworten allen anderen mit 503 (Code mkl_maintenance, Begründung in data.reason). Ausnahme:
 *   GET /me für Angemeldete, damit die App den Zustand erfährt; Gäste erfahren ihn über
 *   window.MKL_CONFIG oder die 503-Antwort. Fotos und Dokumente unter /app/ sind ebenfalls gesperrt.
 * - Die Webseite bleibt unberührt: Die Shortcodes [mkl_termine] und [mkl_galerie] arbeiten normal
 *   (auch Bilder der Website-Galerie, die über /app/foto/ ausgeliefert werden).
 * - Galerie-Abgleich und Push-Versand/-Abgleich pausieren; die Warteschlange bleibt erhalten und
 *   wird nach dem Ende sofort verschickt.
 * - Was die App schon offline gespeichert hat, bleibt auf dem Gerät.
 */

defined( 'ABSPATH' ) || exit;

class MKL_Maintenance {

	const OPTION     = 'mkl_maintenance';
	const LOG_OPTION = 'mkl_maintenance_log';
	const CODE       = 'mkl_maintenance';
	const CAP        = 'manage_options';
	/** Höchstlänge der Begründung (Zeichen). */
	const MAX_REASON = 500;
	/** So viele Änderungen bleiben im Verlauf. */
	const LOG_SIZE = 20;
	/** Fester Text der Wartungsseite. */
	const MESSAGE = 'Die App wird gerade gewartet. Bitte versuche es später noch einmal.';

	public static function init() {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'rest_pre_dispatch' ), 10, 3 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_post_mkl_maintenance', array( __CLASS__, 'admin_save' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Zustand                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array{on: bool, reason: string, since: int, by: int}
	 */
	public static function state() {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'on'     => ! empty( $raw['on'] ),
			'reason' => isset( $raw['reason'] ) ? (string) $raw['reason'] : '',
			'since'  => isset( $raw['since'] ) ? (int) $raw['since'] : 0,
			'by'     => isset( $raw['by'] ) ? (int) $raw['by'] : 0,
		);
	}

	public static function is_active() {
		return self::state()['on'];
	}

	/** Admins nutzen die App auch während der Wartung. */
	public static function can_bypass() {
		return current_user_can( self::CAP );
	}

	/** Ist die App für den aktuellen Benutzer gesperrt? */
	public static function is_locked() {
		return self::is_active() && ! self::can_bypass();
	}

	/** Für die App (window.MKL_CONFIG und /me): nur Schalter und Begründung. */
	public static function public_state() {
		$state = self::state();
		return array(
			'on'     => $state['on'],
			'reason' => $state['on'] ? $state['reason'] : '',
		);
	}

	/**
	 * Prüft und bereinigt die Begründung: reiner Text, Zeilenumbrüche bleiben, max. MAX_REASON Zeichen.
	 *
	 * @return string|WP_Error
	 */
	public static function sanitize_reason( $raw ) {
		if ( null === $raw ) {
			return '';
		}
		if ( ! is_string( $raw ) ) {
			return new WP_Error( 'mkl_invalid', 'Die Begründung muss ein Text sein.', array( 'status' => 400 ) );
		}
		$raw = str_replace( array( "\r\n", "\r" ), "\n", $raw );
		if ( mb_strlen( $raw ) > self::MAX_REASON ) {
			return new WP_Error( 'mkl_invalid', sprintf( 'Die Begründung darf höchstens %d Zeichen lang sein.', self::MAX_REASON ), array( 'status' => 400 ) );
		}
		// sanitize_textarea_field() entfernt Tags, kodiert aber ein einzelnes „<“ als Entität. Gespeichert
		// wird reiner Text (bei der Ausgabe immer maskiert), daher die Entitäten wieder auflösen.
		return wp_specialchars_decode( sanitize_textarea_field( $raw ), ENT_QUOTES );
	}

	/**
	 * Schaltet den Wartungsmodus oder ändert die Begründung.
	 *
	 * @param bool        $on      Ein oder aus.
	 * @param string|null $reason  Bereinigte Begründung; null = unverändert lassen.
	 * @param int         $user_id Wer schaltet.
	 * @return array Neuer Zustand.
	 */
	public static function set( $on, $reason, $user_id ) {
		$before = self::state();
		$on     = (bool) $on;
		if ( $on ) {
			$state = array(
				'on'     => true,
				'reason' => null === $reason ? $before['reason'] : (string) $reason,
				// Beim Ändern der Begründung bleiben Beginn und wer eingeschaltet hat.
				'since'  => $before['on'] ? $before['since'] : time(),
				'by'     => $before['on'] ? $before['by'] : (int) $user_id,
			);
		} else {
			$state = array(
				'on'     => false,
				'reason' => '',
				'since'  => 0,
				'by'     => 0,
			);
		}
		if ( $state === $before ) {
			return $state;
		}
		update_option( self::OPTION, $state, false );

		$action = $on ? ( $before['on'] ? 'reason' : 'on' ) : 'off';
		$log    = get_option( self::LOG_OPTION, array() );
		$log    = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				'time'   => time(),
				'by'     => (int) $user_id,
				'on'     => $on,
				'action' => $action,
				'reason' => $on ? $state['reason'] : $before['reason'],
			)
		);
		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_SIZE ), false );

		if ( ! $on && $before['on'] ) {
			// Während der Wartung gesammelte Benachrichtigungen jetzt verschicken.
			MKL_Push::schedule();
		}
		return $state;
	}

	/** Zustand und Verlauf für Admins. */
	public static function admin_view() {
		$state = self::state();
		$log   = get_option( self::LOG_OPTION, array() );
		$log   = is_array( $log ) ? $log : array();
		return array(
			'on'      => $state['on'],
			'reason'  => $state['reason'],
			'since'   => $state['since'] ? gmdate( 'c', $state['since'] ) : null,
			'by_name' => self::user_name( $state['by'] ),
			'log'     => array_map(
				function ( $entry ) {
					return array(
						'time'    => gmdate( 'c', (int) $entry['time'] ),
						'by_name' => self::user_name( (int) $entry['by'] ),
						'on'      => (bool) $entry['on'],
						'action'  => (string) $entry['action'],
						'reason'  => (string) $entry['reason'],
					);
				},
				$log
			),
		);
	}

	private static function user_name( $user_id ) {
		$user = $user_id ? get_userdata( $user_id ) : false;
		return $user ? $user->display_name : null;
	}

	/* ------------------------------------------------------------------ */
	/* Sperre                                                              */
	/* ------------------------------------------------------------------ */

	public static function error() {
		return new WP_Error(
			self::CODE,
			self::MESSAGE,
			array(
				'status' => 503,
				'reason' => self::state()['reason'],
			)
		);
	}

	/** Sperrt alle Routen des Plugins für Nicht-Admins (GET /me bleibt für Angemeldete offen). */
	public static function rest_pre_dispatch( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof WP_REST_Request ) {
			return $result;
		}
		$route = $request->get_route();
		$ns    = '/' . MKL_REST::NS;
		if ( $route !== $ns && 0 !== strpos( $route, $ns . '/' ) ) {
			return $result;
		}
		if ( ! self::is_locked() ) {
			return $result;
		}
		if ( 'GET' === $request->get_method() && $ns . '/me' === untrailingslashit( $route ) && is_user_logged_in() ) {
			return $result;
		}
		$response = rest_convert_error_to_response( self::error() );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Retry-After', '300' );
		return $response;
	}

	/**
	 * Gesperrte Abrufe unter /app/ (Fotos, Dokumente): 503 ohne Zwischenspeichern.
	 */
	public static function send_unavailable() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		status_header( 503 );
		nocache_headers();
		header( 'Retry-After: 300' );
		header( 'Content-Type: application/json; charset=utf-8' );
		$error = self::error();
		echo wp_json_encode(
			array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'data'    => $error->get_error_data(),
			),
			JSON_UNESCAPED_UNICODE
		);
	}

	/**
	 * Fotos unter /app/foto/ nutzt auch die Website-Galerie ([mkl_galerie]); die bleibt in der
	 * Wartung erreichbar. Gesperrt sind Abrufe aus der App (oder ohne Herkunftsangabe).
	 */
	public static function blocks_photo() {
		if ( ! self::is_locked() ) {
			return false;
		}
		$referer = wp_get_raw_referer();
		if ( ! $referer ) {
			return true;
		}
		$app  = (string) wp_parse_url( MKL_App_Page::app_url(), PHP_URL_PATH );
		$path = (string) wp_parse_url( $referer, PHP_URL_PATH );
		$host = (string) wp_parse_url( $referer, PHP_URL_HOST );
		$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return $host !== $home || 0 === strpos( trailingslashit( $path ), $app );
	}

	/* ------------------------------------------------------------------ */
	/* REST: Schalten (Admins)                                             */
	/* ------------------------------------------------------------------ */

	public static function register_routes() {
		register_rest_route(
			MKL_REST::NS,
			'/admin/maintenance',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'rest_get' ),
					'permission_callback' => array( __CLASS__, 'can_switch' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'rest_set' ),
					'permission_callback' => array( __CLASS__, 'can_switch' ),
					'args'                => array(
						'on'     => array(
							'type'     => 'boolean',
							'required' => true,
						),
						'reason' => array(
							'type' => 'string',
						),
					),
				),
			)
		);
	}

	public static function can_switch() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'mkl_not_logged_in', 'Bitte melde dich an.', array( 'status' => 401 ) );
		}
		if ( ! current_user_can( self::CAP ) ) {
			return new WP_Error( 'mkl_forbidden', 'Nur Administratoren dürfen den Wartungsmodus schalten.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function rest_get() {
		return rest_ensure_response( self::admin_view() );
	}

	public static function rest_set( WP_REST_Request $req ) {
		$reason = null;
		if ( null !== $req->get_param( 'reason' ) ) {
			$reason = self::sanitize_reason( $req->get_param( 'reason' ) );
			if ( is_wp_error( $reason ) ) {
				return $reason;
			}
		}
		self::set( (bool) $req->get_param( 'on' ), $reason, get_current_user_id() );
		return rest_ensure_response( self::admin_view() );
	}

	/* ------------------------------------------------------------------ */
	/* WordPress-Backend (Einstellungen → Kompanie-App)                    */
	/* ------------------------------------------------------------------ */

	private static function page_url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'options-general.php?page=' . MKL_Settings::PAGE ) ) . '#wartung';
	}

	public static function admin_notice() {
		if ( ! self::is_active() || ! current_user_can( self::CAP ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'settings_page_' . MKL_Settings::PAGE === $screen->id ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>Wartungsmodus der Kompanie-App ist aktiv</strong> – nur Admins können die App nutzen. <a href="%s">Wartungsmodus verwalten</a></p></div>',
			esc_url( self::page_url() )
		);
	}

	public static function render_settings() {
		$state = self::admin_view();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nur Anzeige von Rückmeldungen
		$msg   = isset( $_GET['mkl_maintenance_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['mkl_maintenance_msg'] ) ) : '';
		$error = ! empty( $_GET['mkl_maintenance_error'] );
		// phpcs:enable
		$labels = array(
			'on'     => 'eingeschaltet',
			'off'    => 'beendet',
			'reason' => 'Begründung geändert',
		);
		?>
		<hr />
		<h2 id="wartung">Wartungsmodus</h2>
		<?php if ( $msg ) : ?>
			<div class="notice <?php echo $error ? 'notice-error' : 'notice-success'; ?> inline"><p><?php echo esc_html( $msg ); ?></p></div>
		<?php endif; ?>
		<?php if ( $state['on'] ) : ?>
			<div class="notice notice-error inline"><p>
				<strong>Der Wartungsmodus ist aktiv</strong> – nur Admins können die App nutzen<?php
				if ( $state['since'] ) {
					echo esc_html( ' (seit ' . wp_date( 'd.m.Y H:i', strtotime( $state['since'] ) ) . ( $state['by_name'] ? ', ' . $state['by_name'] : '' ) . ')' );
				}
				?>.
			</p></div>
		<?php else : ?>
			<p>
				Im Wartungsmodus können nur Admins die App nutzen. Alle anderen sehen eine Wartungsseite mit der Begründung.
				Die Webseite mit <code>[mkl_termine]</code> und <code>[mkl_galerie]</code> bleibt normal erreichbar.
				Galerie-Abgleich und Benachrichtigungen pausieren und laufen nach dem Ende weiter.
			</p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mkl_maintenance" />
			<?php wp_nonce_field( 'mkl_maintenance' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mkl_maintenance_reason">Begründung (optional)</label></th>
					<td>
						<textarea id="mkl_maintenance_reason" name="mkl_maintenance_reason" rows="3" class="large-text" maxlength="<?php echo esc_attr( (string) self::MAX_REASON ); ?>"><?php echo esc_textarea( $state['reason'] ); ?></textarea>
						<p class="description">Erscheint auf der Wartungsseite unter dem festen Text. Reiner Text, höchstens <?php echo esc_html( (string) self::MAX_REASON ); ?> Zeichen.</p>
					</td>
				</tr>
			</table>
			<?php if ( $state['on'] ) : ?>
				<?php submit_button( 'Begründung speichern', 'secondary', 'mkl_maintenance_do[save]', false ); ?>
				<?php submit_button( 'Wartungsmodus beenden', 'primary', 'mkl_maintenance_do[off]', false ); ?>
			<?php else : ?>
				<?php submit_button( 'Wartungsmodus einschalten', 'primary', 'mkl_maintenance_do[on]', false, array( 'onclick' => "return confirm('Alle außer Admins können die App dann nicht mehr nutzen.');" ) ); ?>
			<?php endif; ?>
		</form>
		<?php if ( $state['log'] ) : ?>
			<h3>Verlauf</h3>
			<ul>
				<?php foreach ( $state['log'] as $entry ) : ?>
					<li>
						<?php echo esc_html( wp_date( 'd.m.Y H:i', strtotime( $entry['time'] ) ) . ' – ' . ( isset( $labels[ $entry['action'] ] ) ? $labels[ $entry['action'] ] : $entry['action'] ) . ( $entry['by_name'] ? ' von ' . $entry['by_name'] : '' ) ); ?>
						<?php if ( '' !== $entry['reason'] ) : ?>
							<br /><span class="description"><?php echo nl2br( esc_html( $entry['reason'] ) ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php
	}

	public static function admin_save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'mkl_maintenance' );
		$do     = isset( $_POST['mkl_maintenance_do'] ) && is_array( $_POST['mkl_maintenance_do'] ) ? array_keys( wp_unslash( $_POST['mkl_maintenance_do'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nur die Schlüssel werden verglichen
		$do     = $do ? (string) $do[0] : '';
		$reason = self::sanitize_reason( isset( $_POST['mkl_maintenance_reason'] ) ? (string) wp_unslash( $_POST['mkl_maintenance_reason'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- wird in sanitize_reason() bereinigt
		if ( is_wp_error( $reason ) ) {
			wp_safe_redirect( self::page_url( array( 'mkl_maintenance_msg' => rawurlencode( $reason->get_error_message() ), 'mkl_maintenance_error' => 1 ) ) );
			exit;
		}
		if ( 'off' === $do ) {
			self::set( false, null, get_current_user_id() );
			$msg = 'Der Wartungsmodus ist beendet.';
		} elseif ( 'save' === $do && self::is_active() ) {
			self::set( true, $reason, get_current_user_id() );
			$msg = 'Begründung gespeichert.';
		} elseif ( 'on' === $do ) {
			self::set( true, $reason, get_current_user_id() );
			$msg = 'Der Wartungsmodus ist eingeschaltet.';
		} else {
			$msg = 'Nichts geändert.';
		}
		wp_safe_redirect( self::page_url( array( 'mkl_maintenance_msg' => rawurlencode( $msg ) ) ) );
		exit;
	}
}
