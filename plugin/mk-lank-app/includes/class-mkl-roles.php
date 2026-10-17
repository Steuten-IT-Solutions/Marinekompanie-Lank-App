<?php
/**
 * Rollen der App: Admin, Spieß, Mitglied, Gast.
 *
 * - Admin   = WordPress-Administrator, darf alles.
 * - Spieß   = Rolle mkl_spiess (mkl_manage + mkl_member).
 * - Mitglied = Rolle mkl_mitglied (mkl_member).
 * - Gast    = nicht angemeldet oder angemeldet ohne mkl_member.
 *
 * Spieß und Mitglied werden zusätzlich zur bestehenden WordPress-Rolle vergeben
 * (z. B. Redakteur + Mitglied), damit niemand seine Webseiten-Rechte verliert.
 */

defined( 'ABSPATH' ) || exit;

class MKL_Roles {

	const LEVEL_ADMIN    = 'admin';
	const LEVEL_SPIESS   = 'spiess';
	const LEVEL_MITGLIED = 'mitglied';
	const LEVEL_GAST     = 'gast';

	public static function init() {
		add_action( 'show_user_profile', array( __CLASS__, 'render_profile_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_profile_field' ) );
		// Erst nach wp_update_user() speichern: edit_user() ruft set_role() auf
		// und würde eine vorher gesetzte Zusatzrolle wieder entfernen.
		add_action( 'profile_update', array( __CLASS__, 'save_profile_field' ), 20 );
	}

	/** Legt Rollen an bzw. ergänzt fehlende Capabilities (idempotent). */
	public static function install() {
		if ( ! get_role( MKL_ROLE_MITGLIED ) ) {
			add_role( MKL_ROLE_MITGLIED, 'Mitglied', array( 'read' => true ) );
		}
		if ( ! get_role( MKL_ROLE_SPIESS ) ) {
			add_role( MKL_ROLE_SPIESS, 'Spieß', array( 'read' => true ) );
		}
		$caps = array(
			MKL_ROLE_MITGLIED => array( MKL_CAP_MEMBER ),
			MKL_ROLE_SPIESS   => array( MKL_CAP_MEMBER, MKL_CAP_MANAGE ),
			'administrator'   => array( MKL_CAP_MEMBER, MKL_CAP_MANAGE, MKL_CAP_EVENTS ),
		);
		foreach ( $caps as $role_name => $role_caps ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $role_caps as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * Bis Version 0.1.x hatten alle angemeldeten Konten Mitglieder-Rechte.
	 * Damit beim Update niemand zum Gast wird, bekommen alle Abonnenten die Rolle Mitglied.
	 */
	public static function migrate_subscribers_to_members() {
		foreach ( get_users( array( 'role' => 'subscriber' ) ) as $user ) {
			$user->add_role( MKL_ROLE_MITGLIED );
		}
	}

	public static function is_spiess( $user_id = null ) {
		return $user_id ? user_can( $user_id, MKL_CAP_MANAGE ) : current_user_can( MKL_CAP_MANAGE );
	}

	public static function is_member( $user_id = null ) {
		return $user_id ? user_can( $user_id, MKL_CAP_MEMBER ) : current_user_can( MKL_CAP_MEMBER );
	}

	/**
	 * Jungmariner ist keine eigene Rolle, sondern ein Merkmal eines Mitglieds
	 * (Benutzer-Meta). Jungmariner erhalten bei der Punktevergabe nur die halbe Punktzahl.
	 */
	public static function is_jungmariner( $user_id ) {
		return (bool) get_user_meta( $user_id, MKL_META_JUNGMARINER, true );
	}

	public static function set_jungmariner( $user_id, $value ) {
		if ( $value ) {
			update_user_meta( $user_id, MKL_META_JUNGMARINER, 1 );
		} else {
			delete_user_meta( $user_id, MKL_META_JUNGMARINER );
		}
	}

	/** @return string admin|spiess|mitglied|gast */
	public static function get_level( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return self::LEVEL_GAST;
		}
		$roles = (array) $user->roles;
		if ( in_array( 'administrator', $roles, true ) ) {
			return self::LEVEL_ADMIN;
		}
		if ( user_can( $user, MKL_CAP_MANAGE ) ) {
			return self::LEVEL_SPIESS;
		}
		if ( user_can( $user, MKL_CAP_MEMBER ) ) {
			return self::LEVEL_MITGLIED;
		}
		return self::LEVEL_GAST;
	}

	/**
	 * Setzt die App-Rolle (gast|mitglied|spiess). Administratoren werden nicht verändert;
	 * Admin-Rechte vergibt man bewusst nur im WordPress-Backend.
	 *
	 * @return true|WP_Error
	 */
	public static function set_level( $user_id, $level ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'mkl_invalid', 'Benutzer nicht gefunden.', array( 'status' => 404 ) );
		}
		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			return new WP_Error( 'mkl_invalid', 'Administratoren werden im WordPress-Backend verwaltet.', array( 'status' => 400 ) );
		}
		if ( ! in_array( $level, array( self::LEVEL_GAST, self::LEVEL_MITGLIED, self::LEVEL_SPIESS ), true ) ) {
			return new WP_Error( 'mkl_invalid', 'Ungültige Rolle.', array( 'status' => 400 ) );
		}

		$user->remove_role( MKL_ROLE_SPIESS );
		$user->remove_role( MKL_ROLE_MITGLIED );
		if ( self::LEVEL_SPIESS === $level ) {
			$user->add_role( MKL_ROLE_SPIESS );
		} elseif ( self::LEVEL_MITGLIED === $level ) {
			$user->add_role( MKL_ROLE_MITGLIED );
		}
		// Ein Konto ohne jede Rolle kann sich nicht sinnvoll anmelden.
		if ( empty( $user->roles ) ) {
			$user->add_role( 'subscriber' );
		}
		return true;
	}

	public static function level_label( $level ) {
		$labels = array(
			self::LEVEL_ADMIN    => 'Admin',
			self::LEVEL_SPIESS   => 'Spieß',
			self::LEVEL_MITGLIED => 'Mitglied',
			self::LEVEL_GAST     => 'Gast',
		);
		return isset( $labels[ $level ] ) ? $labels[ $level ] : $level;
	}

	/* ------------------------------------------------------------------ */
	/* Benutzerprofil im WP-Admin                                          */
	/* ------------------------------------------------------------------ */

	public static function render_profile_field( WP_User $user ) {
		if ( ! current_user_can( 'promote_users' ) ) {
			return;
		}
		$level = self::get_level( $user->ID );
		?>
		<h2>Kompanie-App</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="mkl_level">Rolle in der App</label></th>
				<td>
					<?php if ( self::LEVEL_ADMIN === $level ) : ?>
						<strong>Admin</strong> – Administratoren dürfen in der App alles.
					<?php else : ?>
						<select name="mkl_level" id="mkl_level">
							<option value="gast" <?php selected( $level, self::LEVEL_GAST ); ?>>Gast – nur öffentliche Inhalte</option>
							<option value="mitglied" <?php selected( $level, self::LEVEL_MITGLIED ); ?>>Mitglied – eigene Punkte und Strafen</option>
							<option value="spiess" <?php selected( $level, self::LEVEL_SPIESS ); ?>>Spieß – Punkte und Strafen verwalten</option>
						</select>
						<?php wp_nonce_field( 'mkl_level_' . $user->ID, 'mkl_level_nonce' ); ?>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function save_profile_field( $user_id ) {
		if ( ! current_user_can( 'promote_users' ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		if ( ! isset( $_POST['mkl_level_nonce'], $_POST['mkl_level'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['mkl_level_nonce'] ) ), 'mkl_level_' . $user_id ) ) {
			return;
		}
		self::set_level( $user_id, sanitize_key( wp_unslash( $_POST['mkl_level'] ) ) );
	}
}
