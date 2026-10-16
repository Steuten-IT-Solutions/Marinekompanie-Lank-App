<?php
/**
 * Einstellungen → Kompanie-App
 */

defined( 'ABSPATH' ) || exit;

class MKL_Settings {

	const PAGE = 'mkl-settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		foreach ( array( 'mkl_calendar_ids', 'mkl_google_api_key', 'mkl_ics_url', 'mkl_calendar_cache_minutes' ) as $opt ) {
			add_action( 'update_option_' . $opt, array( 'MKL_Calendar', 'clear_cache' ) );
		}
	}

	public static function menu() {
		add_options_page( 'Kompanie-App', 'Kompanie-App', 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function register() {
		register_setting( self::PAGE, 'mkl_calendar_ids', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( self::PAGE, 'mkl_google_api_key', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( self::PAGE, 'mkl_ics_url', array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ) );
		register_setting(
			self::PAGE,
			'mkl_calendar_cache_minutes',
			array(
				'type'              => 'integer',
				'default'           => 15,
				'sanitize_callback' => function ( $v ) {
					return max( 1, absint( $v ) );
				},
			)
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$test = null;
		if ( isset( $_GET['mkl_test'] ) && check_admin_referer( 'mkl_test_calendar' ) ) {
			MKL_Calendar::clear_cache();
			$test = MKL_Calendar::get_events();
		}
		$pgc_ids = MKL_Calendar::get_calendar_ids();
		?>
		<div class="wrap">
			<h1>Kompanie-App</h1>
			<p>
				Die App ist erreichbar unter
				<a href="<?php echo esc_url( MKL_App_Page::app_url() ); ?>" target="_blank"><?php echo esc_html( MKL_App_Page::app_url() ); ?></a>.
				Die Rolle in der App (Gast, Mitglied, Spieß) legt ein Admin in der App unter Spieß → Mitglieder fest
				oder im jeweiligen <a href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>">Benutzerprofil</a>.
				Öffentliche Termine für die Startseite zeigt der Shortcode <code>[mkl_termine]</code>.
			</p>

			<h2>Kalender</h2>
			<p>
				Aktuelle Quelle: <strong><?php echo esc_html( MKL_Calendar::describe_source() ); ?></strong><br />
				Verwendete Kalender-IDs: <code><?php echo esc_html( $pgc_ids ? implode( ', ', $pgc_ids ) : '–' ); ?></code>
			</p>
			<p class="description">
				Sind die Felder leer, nutzt die App automatisch die Kalender und Zugangsdaten aus dem Plugin „Private Google Calendars“.
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( self::PAGE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mkl_calendar_ids">Google-Kalender-IDs</label></th>
						<td><input type="text" class="regular-text" id="mkl_calendar_ids" name="mkl_calendar_ids" value="<?php echo esc_attr( get_option( 'mkl_calendar_ids', '' ) ); ?>" />
						<p class="description">Kommagetrennt, z. B. <code>abc123@group.calendar.google.com</code>. Leer = aus „Private Google Calendars“.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="mkl_google_api_key">Google-API-Key</label></th>
						<td><input type="password" autocomplete="off" class="regular-text" id="mkl_google_api_key" name="mkl_google_api_key" value="<?php echo esc_attr( get_option( 'mkl_google_api_key', '' ) ); ?>" />
						<p class="description">Nur für öffentliche Kalender nötig. Leer = Zugang aus „Private Google Calendars“.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="mkl_ics_url">iCal-Adresse (Alternative)</label></th>
						<td><input type="url" class="regular-text" id="mkl_ics_url" name="mkl_ics_url" value="<?php echo esc_attr( get_option( 'mkl_ics_url', '' ) ); ?>" />
						<p class="description">Wird nur genutzt, wenn keine Google-API verfügbar ist. Serientermine erscheinen hier nur einmal.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="mkl_calendar_cache_minutes">Zwischenspeicher (Minuten)</label></th>
						<td><input type="number" min="1" class="small-text" id="mkl_calendar_cache_minutes" name="mkl_calendar_cache_minutes" value="<?php echo esc_attr( get_option( 'mkl_calendar_cache_minutes', 15 ) ); ?>" /></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=' . self::PAGE . '&mkl_test=1' ), 'mkl_test_calendar' ) ); ?>">Kalender jetzt testen</a>
			</p>
			<?php if ( null !== $test ) : ?>
				<?php if ( is_wp_error( $test ) ) : ?>
					<div class="notice notice-error inline"><p><?php echo esc_html( $test->get_error_message() ); ?></p></div>
				<?php else : ?>
					<div class="notice notice-success inline"><p><?php echo esc_html( sprintf( '%d Termine gefunden.', count( $test ) ) ); ?></p>
					<ul>
						<?php foreach ( array_slice( $test, 0, 5 ) as $ev ) : ?>
							<li><?php echo esc_html( $ev['start'] . ' – ' . $ev['title'] ); ?></li>
						<?php endforeach; ?>
					</ul></div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
