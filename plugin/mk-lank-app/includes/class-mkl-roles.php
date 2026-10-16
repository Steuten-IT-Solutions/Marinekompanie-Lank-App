<?php
/**
 * Rolle „Spieß“ und die Capability mkl_manage.
 *
 * Die Rolle wird zusätzlich zur bestehenden WordPress-Rolle vergeben
 * (z. B. Redakteur + Spieß), damit niemand seine Webseiten-Rechte verliert.
 */

defined( 'ABSPATH' ) || exit;

class MKL_Roles {

	public static function init() {
		add_action( 'show_user_profile', array( __CLASS__, 'render_profile_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_profile_field' ) );
		// Erst nach wp_update_user() speichern: edit_user() ruft set_role() auf
		// und würde eine vorher gesetzte Zusatzrolle wieder entfernen.
		add_action( 'profile_update', array( __CLASS__, 'save_profile_field' ), 20 );
	}

	public static function install() {
		if ( ! get_role( MKL_ROLE_SPIESS ) ) {
			add_role(
				MKL_ROLE_SPIESS,
				'Spieß',
				array(
					'read'         => true,
					MKL_CAP_MANAGE => true,
				)
			);
		}
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( MKL_CAP_MANAGE ) ) {
			$admin->add_cap( MKL_CAP_MANAGE );
		}
	}

	public static function is_spiess( $user_id = null ) {
		return $user_id ? user_can( $user_id, MKL_CAP_MANAGE ) : current_user_can( MKL_CAP_MANAGE );
	}

	public static function render_profile_field( WP_User $user ) {
		if ( ! current_user_can( 'promote_users' ) ) {
			return;
		}
		$checked = in_array( MKL_ROLE_SPIESS, (array) $user->roles, true );
		?>
		<h2>Kompanie-App</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Spieß</th>
				<td>
					<label>
						<input type="checkbox" name="mkl_spiess" value="1" <?php checked( $checked ); ?> />
						Darf in der App Punkte und Strafen an Mitglieder vergeben
					</label>
					<?php wp_nonce_field( 'mkl_spiess_' . $user->ID, 'mkl_spiess_nonce' ); ?>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function save_profile_field( $user_id ) {
		if ( ! current_user_can( 'promote_users' ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		if ( ! isset( $_POST['mkl_spiess_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['mkl_spiess_nonce'] ) ), 'mkl_spiess_' . $user_id ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		if ( ! empty( $_POST['mkl_spiess'] ) ) {
			$user->add_role( MKL_ROLE_SPIESS );
		} else {
			$user->remove_role( MKL_ROLE_SPIESS );
		}
	}
}
