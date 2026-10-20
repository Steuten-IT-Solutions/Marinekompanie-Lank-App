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
		add_action( 'admin_post_mkl_gallery_save', array( __CLASS__, 'gallery_save' ) );
		add_action( 'admin_post_mkl_gallery_sync', array( __CLASS__, 'gallery_sync' ) );
		add_action( 'admin_post_mkl_drive_connect', array( __CLASS__, 'drive_connect' ) );
		add_action( 'admin_post_mkl_drive_disconnect', array( __CLASS__, 'drive_disconnect' ) );
		add_action( 'admin_post_mkl_push_test', array( __CLASS__, 'push_test' ) );
		// Rückkehr von Google: admin-post.php ohne „action“ (siehe MKL_Drive::redirect_uri()).
		add_action( 'admin_post', array( __CLASS__, 'drive_callback' ) );
		add_action( 'admin_post_nopriv', array( __CLASS__, 'drive_callback' ) );
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
				Die Rolle in der App (Mitglied, Spieß oder ohne Rolle) legt ein Admin in der App unter Spieß → Mitglieder fest
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

			<?php self::render_gallery(); ?>
			<?php self::render_push(); ?>
			<?php MKL_Maintenance::render_settings(); ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Push-Benachrichtigungen                                             */
	/* ------------------------------------------------------------------ */

	private static function render_push() {
		$missing = MKL_Push::missing_requirements();
		$count   = MKL_Push::count();
		$mine    = count( MKL_Push::for_user( get_current_user_id() ) );
		$msg     = isset( $_GET['mkl_push_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['mkl_push_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$error   = ! empty( $_GET['mkl_push_error'] ); // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<h2 id="push">Push-Benachrichtigungen</h2>
		<?php if ( $msg ) : ?>
			<div class="notice <?php echo $error ? 'notice-error' : 'notice-success'; ?> inline"><p><?php echo esc_html( $msg ); ?></p></div>
		<?php endif; ?>
		<?php if ( $missing ) : ?>
			<div class="notice notice-error inline"><p>
				Auf diesem Server sind keine Push-Benachrichtigungen möglich. Es fehlt: <?php echo esc_html( implode( ', ', $missing ) ); ?>.
				Bitte beim Hoster nachfragen.
			</p></div>
		<?php else : ?>
			<p>
				Server-Voraussetzungen erfüllt (OpenSSL mit P-256 und AES-GCM).<br />
				Aktive Abos: <strong><?php echo esc_html( $count['devices'] ); ?></strong> Geräte von <strong><?php echo esc_html( $count['accounts'] ); ?></strong> Konten.
				In der Warteschlange: <?php echo esc_html( $count['queued'] ); ?>.
			</p>
			<p class="description">
				Angemeldete schalten Benachrichtigungen in der App unter Konto → Benachrichtigungen ein, Gäste unten auf der Seite Termine.
				Auf dem iPhone geht das nur in der über „Zum Home-Bildschirm“ installierten App (ab iOS 16.4). Versand, Kalender-Abgleich
				und die Erinnerung um 18 Uhr laufen über WP-Cron, also nur, wenn jemand die Webseite aufruft.
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mkl_push_test" />
				<?php wp_nonce_field( 'mkl_push_test' ); ?>
				<?php submit_button( sprintf( 'Testbenachrichtigung an meine Geräte (%d)', $mine ), 'secondary', 'submit', false, $mine ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>
		<?php endif; ?>
		<?php
	}

	public static function push_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'mkl_push_test' );
		$ok     = 0;
		$errors = array();
		foreach ( MKL_Push::for_user( get_current_user_id() ) as $sub ) {
			$result = MKL_Push::send_test( $sub );
			if ( 'ok' === $result['status'] ) {
				++$ok;
			} else {
				if ( 'gone' === $result['status'] ) {
					MKL_Push::delete_subscription( (int) $sub['id'] );
				}
				$errors[] = $result['message'] ? $result['message'] : 'Gerät nicht mehr erreichbar (entfernt).';
			}
		}
		$msg = sprintf( '%d Testbenachrichtigung(en) verschickt.', $ok ) . ( $errors ? ' Fehler: ' . implode( ' | ', $errors ) : '' );
		wp_safe_redirect(
			self::page_url(
				array(
					'mkl_push_msg'   => rawurlencode( $msg ),
					'mkl_push_error' => $errors ? 1 : 0,
				)
			) . '#push'
		);
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Galerie                                                             */
	/* ------------------------------------------------------------------ */

	private static function page_url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'options-general.php?page=' . self::PAGE ) );
	}

	private static function render_gallery() {
		$sa_email = MKL_Drive::client_email();
		$oauth    = MKL_Drive::oauth();
		$root     = get_option( MKL_Gallery::ROOT_OPTION, '' );
		$status   = (array) get_option( MKL_Gallery::STATUS_OPTION, array() );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nur Anzeige von Rückmeldungen
		$notice    = isset( $_GET['mkl_gallery_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['mkl_gallery_msg'] ) ) : '';
		$is_error  = ! empty( $_GET['mkl_gallery_error'] );
		$continue  = ! empty( $_GET['mkl_continue'] ) && ! empty( $status['pending'] );
		// phpcs:enable
		?>
		<hr />
		<h2 id="galerie">Galerie (Google Drive)</h2>
		<?php if ( $notice ) : ?>
			<div class="notice <?php echo $is_error ? 'notice-error' : 'notice-success'; ?> inline"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>
		<p>
			Aufbau in Drive: <strong>Galerie-Ordner → Jahr → Album → Bilder</strong>. Bilder im Unterordner
			<strong>„intern“</strong> eines Albums sehen nur angemeldete Mitglieder. Auf der Webseite zeigt der Shortcode
			<code>[mkl_galerie]</code> die Galerie.
		</p>

		<h3>1. Zugang zu Google Drive</h3>
		<?php if ( MKL_Drive::oauth_connected() ) : ?>
			<p>
				✅ Verbunden mit Google<?php echo MKL_Drive::oauth_email() ? ': <strong>' . esc_html( MKL_Drive::oauth_email() ) . '</strong>' : ''; ?>.
				Dieses Konto muss den Galerie-Ordner sehen können (eigener Ordner oder dafür freigegeben).
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mkl_drive_disconnect" />
				<?php wp_nonce_field( 'mkl_drive_disconnect' ); ?>
				<?php submit_button( 'Verbindung trennen', 'secondary', 'submit', false ); ?>
			</form>
		<?php else : ?>
			<p>Lege in der Google Cloud Console einen <strong>OAuth-Client</strong> vom Typ „Webanwendung“ an und trage dort diese
				<strong>autorisierte Weiterleitungs-URI</strong> ein:</p>
			<p><input type="text" readonly class="large-text code" value="<?php echo esc_attr( MKL_Drive::redirect_uri() ); ?>" onclick="this.select()" /></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mkl_drive_connect" />
				<?php wp_nonce_field( 'mkl_drive_connect' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mkl_oauth_client_id">Client-ID</label></th>
						<td><input type="text" class="large-text code" id="mkl_oauth_client_id" name="mkl_oauth_client_id" value="<?php echo esc_attr( isset( $oauth['client_id'] ) ? $oauth['client_id'] : '' ); ?>" placeholder="….apps.googleusercontent.com" autocomplete="off" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="mkl_oauth_client_secret">Clientschlüssel (Client-Secret)</label></th>
						<td><input type="password" class="regular-text code" id="mkl_oauth_client_secret" name="mkl_oauth_client_secret" value="" autocomplete="off" placeholder="<?php echo esc_attr( ! empty( $oauth['client_secret'] ) ? 'gespeichert – leer lassen, um ihn zu behalten' : '' ); ?>" />
						<p class="description">Wird nur auf dem Server gespeichert und hier nicht wieder angezeigt.</p></td>
					</tr>
				</table>
				<?php submit_button( 'Speichern und mit Google verbinden', 'primary', 'submit', false ); ?>
				<p class="description">Danach meldest du dich bei Google mit dem Konto an, das den Galerie-Ordner sehen kann, und erlaubst den Lesezugriff auf Google Drive.</p>
			</form>
		<?php endif; ?>

		<h3>2. Galerie-Ordner</h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mkl_gallery_save" />
			<?php wp_nonce_field( 'mkl_gallery_save' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mkl_gallery_root">Galerie-Ordner</label></th>
					<td>
						<input type="text" class="regular-text" id="mkl_gallery_root" name="mkl_gallery_root" value="<?php echo esc_attr( $root ); ?>" placeholder="Link zum Ordner oder Ordner-ID" />
						<p class="description">Link aus Google Drive, z. B. <code>https://drive.google.com/drive/folders/…</code></p>
					</td>
				</tr>
			</table>
			<details<?php echo $sa_email && ! MKL_Drive::oauth_connected() ? ' open' : ''; ?>>
				<summary>Alternative: Dienstkonto mit JSON-Schlüssel (nur nötig, wenn du nicht „Mit Google verbinden“ nutzt)</summary>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mkl_drive_sa">Dienstkonto (JSON-Schlüssel)</label></th>
						<td>
							<?php if ( $sa_email ) : ?>
								<p>Hinterlegt: <code><?php echo esc_html( $sa_email ); ?></code><br />
								Gib den Galerie-Ordner in Google Drive für <strong>diese Adresse</strong> frei (Rolle „Betrachter“).
								<?php if ( MKL_Drive::oauth_connected() ) : ?><br /><em>Wird nicht genutzt, solange die Verbindung mit Google besteht.</em><?php endif; ?></p>
							<?php endif; ?>
							<textarea id="mkl_drive_sa" name="mkl_drive_sa" rows="4" class="large-text code" placeholder="<?php echo esc_attr( $sa_email ? 'Nur ausfüllen, um den Schlüssel zu ersetzen' : 'Inhalt der heruntergeladenen JSON-Datei hier einfügen' ); ?>"></textarea>
							<p class="description">Der Schlüssel wird nur auf dem Server gespeichert und hier nicht wieder angezeigt.</p>
						</td>
					</tr>
				</table>
			</details>
			<?php submit_button( 'Galerie-Einstellungen speichern', 'secondary' ); ?>
		</form>

		<h3>Status</h3>
		<ul>
			<li>Letzter Abgleich: <?php echo ! empty( $status['last_sync'] ) ? esc_html( wp_date( 'd.m.Y H:i', (int) $status['last_sync'] ) ) : '–'; ?></li>
			<li>Alben: <?php echo esc_html( isset( $status['albums'] ) ? (string) $status['albums'] : '–' ); ?>,
				Bilder: <?php echo esc_html( isset( $status['photos'] ) ? (string) $status['photos'] : '–' ); ?>,
				noch aufzubereiten: <?php echo esc_html( isset( $status['pending'] ) ? (string) $status['pending'] : '–' ); ?></li>
			<?php if ( ! empty( $status['error'] ) ) : ?>
				<li><strong style="color:#b32d2e">Fehler: <?php echo esc_html( $status['error'] ); ?></strong></li>
			<?php endif; ?>
		</ul>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="mkl-gallery-sync">
			<input type="hidden" name="action" value="mkl_gallery_sync" />
			<?php if ( $continue ) : ?>
				<input type="hidden" name="only_process" value="1" />
			<?php endif; ?>
			<?php wp_nonce_field( 'mkl_gallery_sync' ); ?>
			<?php submit_button( 'Jetzt synchronisieren', 'primary', 'submit', false, MKL_Drive::is_configured() && $root ? array() : array( 'disabled' => 'disabled' ) ); ?>
			<p class="description">Neue Bilder werden danach automatisch alle 15 Minuten übernommen. Beim ersten Mal kann das Aufbereiten etwas dauern; die Seite lädt dann selbstständig weiter.</p>
		</form>
		<?php if ( $continue ) : ?>
			<p><em>Bereite weitere Bilder auf …</em></p>
			<script>setTimeout(function(){document.getElementById('mkl-gallery-sync').submit();},800);</script>
		<?php endif; ?>
		<?php
	}

	public static function gallery_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'mkl_gallery_save' );
		$messages = array();
		$error    = false;

		$json = isset( $_POST['mkl_drive_sa'] ) ? trim( (string) wp_unslash( $_POST['mkl_drive_sa'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- JSON wird geprüft und nicht ausgegeben
		if ( '' !== $json ) {
			$saved = MKL_Drive::save_service_account( $json );
			if ( is_wp_error( $saved ) ) {
				$messages[] = $saved->get_error_message();
				$error      = true;
			} else {
				$messages[] = 'Dienstkonto gespeichert.';
			}
		}

		$root_input = isset( $_POST['mkl_gallery_root'] ) ? sanitize_text_field( wp_unslash( $_POST['mkl_gallery_root'] ) ) : '';
		$root       = MKL_Drive::parse_folder_id( $root_input );
		if ( '' !== $root_input && '' === $root ) {
			$messages[] = 'Der Galerie-Ordner wurde nicht erkannt.';
			$error      = true;
		} else {
			update_option( MKL_Gallery::ROOT_OPTION, $root, false );
			MKL_Gallery::schedule();
		}
		if ( ! $messages ) {
			$messages[] = 'Gespeichert.';
		}
		wp_safe_redirect(
			self::page_url(
				array(
					'mkl_gallery_msg'   => rawurlencode( implode( ' ', $messages ) ),
					'mkl_gallery_error' => $error ? 1 : 0,
				)
			) . '#galerie'
		);
		exit;
	}

	public static function gallery_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'mkl_gallery_sync' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		// Beim automatischen Weiterladen nur aufbereiten, nicht erneut abgleichen.
		$stats = empty( $_POST['only_process'] ) ? MKL_Gallery::sync() : (array) get_option( MKL_Gallery::STATUS_OPTION, array() );
		if ( is_wp_error( $stats ) ) {
			$msg   = $stats->get_error_message();
			$error = 1;
		} else {
			$done  = MKL_Gallery::process_pending( 20 );
			$left  = MKL_Gallery::count_pending();
			$msg   = sprintf( '%d Alben, %d Bilder gefunden. %d Bilder aufbereitet, %d ausstehend.', isset( $stats['albums'] ) ? $stats['albums'] : 0, isset( $stats['photos'] ) ? $stats['photos'] : 0, $done, $left );
			$error = 0;
		}
		wp_safe_redirect(
			self::page_url(
				array(
					'mkl_gallery_msg'   => rawurlencode( $msg ),
					'mkl_gallery_error' => $error,
					'mkl_continue'      => ! $error && ! empty( $left ) ? 1 : 0,
				)
			) . '#galerie'
		);
		exit;
	}

	private static function back( $msg, $error ) {
		wp_safe_redirect(
			self::page_url(
				array(
					'mkl_gallery_msg'   => rawurlencode( $msg ),
					'mkl_gallery_error' => $error ? 1 : 0,
				)
			) . '#galerie'
		);
		exit;
	}

	/** Client-ID/-Secret speichern und zu Google weiterleiten. */
	public static function drive_connect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'mkl_drive_connect' );
		$saved = MKL_Drive::save_client(
			isset( $_POST['mkl_oauth_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['mkl_oauth_client_id'] ) ) : '',
			isset( $_POST['mkl_oauth_client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['mkl_oauth_client_secret'] ) ) : ''
		);
		if ( is_wp_error( $saved ) ) {
			self::back( $saved->get_error_message(), true );
		}
		// Externe Adresse: wp_safe_redirect() würde sie verwerfen.
		wp_redirect( MKL_Drive::auth_url( MKL_Drive::new_state() ) ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	/** Google leitet nach der Anmeldung hierher zurück (admin-post.php ohne „action“). */
	public static function drive_callback() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Schutz über „state“
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( 0 !== strpos( $state, MKL_Drive::STATE_PREFIX ) ) {
			return; // Nicht für uns.
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Bitte zuerst als Administrator in WordPress anmelden und die Verbindung dann erneut starten.', 'Keine Berechtigung', array( 'response' => 403 ) );
		}
		if ( ! MKL_Drive::check_state( $state ) ) {
			self::back( 'Die Anmeldung bei Google ist abgelaufen oder ungültig. Bitte erneut „Mit Google verbinden“ klicken.', true );
		}
		if ( isset( $_GET['error'] ) ) {
			$err = sanitize_text_field( wp_unslash( $_GET['error'] ) );
			self::back( 'access_denied' === $err ? 'Die Anmeldung bei Google wurde abgebrochen.' : 'Google meldet einen Fehler: ' . $err, true );
		}
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:enable
		$result = MKL_Drive::exchange_code( $code );
		if ( is_wp_error( $result ) ) {
			self::back( 'Verbindung mit Google fehlgeschlagen: ' . $result->get_error_message(), true );
		}
		MKL_Gallery::schedule();
		self::back( 'Mit Google verbunden' . ( MKL_Drive::oauth_email() ? ' (' . MKL_Drive::oauth_email() . ')' : '' ) . '.', false );
	}

	public static function drive_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'mkl_drive_disconnect' );
		MKL_Drive::disconnect();
		self::back( 'Die Verbindung zu Google wurde getrennt.', false );
	}
}
