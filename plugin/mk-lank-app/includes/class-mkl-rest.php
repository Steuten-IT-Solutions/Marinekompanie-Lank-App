<?php
/**
 * REST-API unter /wp-json/mk-lank/v1/.
 *
 * Grundsätze:
 * - Öffentlich (auch Gäste ohne Anmeldung): /events (lesen), /news, /gallery
 *   (Gäste nur öffentliche Bilder).
 * - Den internen Kalender (/calendar) sehen Anwärter und Mitglieder (mkl_calendar).
 * - Mitglieder (mkl_member) sehen ausschließlich ihre eigenen Daten. Die /me-Routen
 *   lesen die Benutzer-ID immer aus der Sitzung und nehmen keinen user_id-Parameter entgegen.
 * - Punkte/Strafen/Dienstgrade verwalten erfordert mkl_manage (Spieß), Termine pflegen
 *   mkl_manage_events (Admin), Dokumente pflegen mkl_manage_documents (Admin),
 *   Benutzer anlegen/Rollen ändern create_users/promote_users. Anwärter zum Mitglied
 *   befördern darf auch der Spieß (mkl_manage).
 * - Mitglieder sehen nur den eigenen Dienstgrad und Verlauf (/me…), nie den anderer.
 * - Anwesenheitslisten (Mitglieder und Anwärter) führt der Spieß (mkl_manage);
 *   Mitglieder sehen nur die eigene Anwesenheit (/me/attendance).
 */

defined( 'ABSPATH' ) || exit;

class MKL_REST {

	const NS = 'mk-lank/v1';

	const MAX_POINTS = 10000;
	/** Höchster Anfangsbestand beim Anlegen eines Mitglieds. */
	const MAX_INITIAL_POINTS = 1000000;
	/** Höchstzahl der Konten, die in einem Rutsch angelegt werden können. */
	const MAX_BULK_USERS = 50;
	/** Höchstbetrag einer einzelnen Strafe: 1.000 € */
	const MAX_CENTS = 100000;

	public static function register_routes() {
		$public    = '__return_true';
		$logged_in = array( __CLASS__, 'can_read' );
		$member    = array( __CLASS__, 'can_member' );
		$calendar  = array( __CLASS__, 'can_calendar' );
		$manage    = array( __CLASS__, 'can_manage' );
		$events    = array( __CLASS__, 'can_manage_events' );
		$docs      = array( __CLASS__, 'can_manage_docs' );
		$id_arg    = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);

		register_rest_route( self::NS, '/me', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_me' ), 'permission_callback' => $logged_in ) );
		register_rest_route( self::NS, '/me/points', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_my_points' ), 'permission_callback' => $member ) );
		register_rest_route( self::NS, '/me/penalties', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_my_penalties' ), 'permission_callback' => $member ) );
		register_rest_route( self::NS, '/me/attendance', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_my_attendance' ), 'permission_callback' => $member ) );
		register_rest_route( self::NS, '/me/rank-history', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_my_rank_history' ), 'permission_callback' => $member ) );
		register_rest_route( self::NS, '/me/password', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'change_my_password' ), 'permission_callback' => $logged_in ) );
		register_rest_route( self::NS, '/calendar', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_calendar' ), 'permission_callback' => $calendar ) );

		register_rest_route(
			self::NS,
			'/events',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_events' ), 'permission_callback' => $public ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_event' ), 'permission_callback' => $events ),
			)
		);
		register_rest_route(
			self::NS,
			'/events/(?P<id>\d+)',
			array(
				array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, 'update_event' ), 'permission_callback' => $events, 'args' => $id_arg ),
				array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_event' ), 'permission_callback' => $events, 'args' => $id_arg ),
			)
		);

		register_rest_route( self::NS, '/gallery', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_gallery' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/gallery/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_gallery_album' ), 'permission_callback' => $public, 'args' => $id_arg ) );

		register_rest_route( self::NS, '/news', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_news' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/news/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_news_item' ), 'permission_callback' => $public, 'args' => $id_arg ) );

		register_rest_route(
			self::NS,
			'/catalog',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_catalog' ), 'permission_callback' => $logged_in ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_catalog_item' ), 'permission_callback' => $manage ),
			)
		);
		register_rest_route(
			self::NS,
			'/catalog/(?P<id>\d+)',
			array(
				array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, 'update_catalog_item' ), 'permission_callback' => $manage, 'args' => $id_arg ),
				array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_catalog_item' ), 'permission_callback' => $manage, 'args' => $id_arg ),
			)
		);

		register_rest_route(
			self::NS,
			'/ranks',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_ranks' ), 'permission_callback' => $member ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_rank' ), 'permission_callback' => $manage ),
			)
		);
		register_rest_route(
			self::NS,
			'/ranks/(?P<id>\d+)',
			array(
				array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, 'update_rank' ), 'permission_callback' => $manage, 'args' => $id_arg ),
				array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_rank' ), 'permission_callback' => $manage, 'args' => $id_arg ),
			)
		);
		register_rest_route(
			self::NS,
			'/titles',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_titles' ), 'permission_callback' => $manage ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_title' ), 'permission_callback' => $manage ),
			)
		);
		register_rest_route(
			self::NS,
			'/titles/(?P<id>\d+)',
			array(
				array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, 'update_title' ), 'permission_callback' => $manage, 'args' => $id_arg ),
				array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_title' ), 'permission_callback' => $manage, 'args' => $id_arg ),
			)
		);
		register_rest_route( self::NS, '/promotions', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_promotions' ), 'permission_callback' => $manage ) );

		register_rest_route(
			self::NS,
			'/documents',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_documents' ), 'permission_callback' => $member ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_document' ), 'permission_callback' => $docs ),
			)
		);
		register_rest_route(
			self::NS,
			'/documents/(?P<id>\d+)',
			array(
				array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, 'update_document' ), 'permission_callback' => $docs, 'args' => $id_arg ),
				array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_document' ), 'permission_callback' => $docs, 'args' => $id_arg ),
			)
		);
		register_rest_route( self::NS, '/documents/(?P<id>\d+)/file', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'replace_document_file' ), 'permission_callback' => $docs, 'args' => $id_arg ) );
		register_rest_route( self::NS, '/document-categories', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_document_category' ), 'permission_callback' => $docs ) );
		register_rest_route(
			self::NS,
			'/document-categories/(?P<id>\d+)',
			array(
				array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, 'update_document_category' ), 'permission_callback' => $docs, 'args' => $id_arg ),
				array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_document_category' ), 'permission_callback' => $docs, 'args' => $id_arg ),
			)
		);

		register_rest_route( self::NS, '/members', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_members' ), 'permission_callback' => $manage ) );
		register_rest_route( self::NS, '/members/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_member' ), 'permission_callback' => $manage, 'args' => $id_arg ) );
		register_rest_route( self::NS, '/members/(?P<id>\d+)/jungmariner', array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, 'set_member_jungmariner' ), 'permission_callback' => $manage, 'args' => $id_arg ) );
		register_rest_route( self::NS, '/members/(?P<id>\d+)/rank', array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'set_member_rank' ), 'permission_callback' => $manage, 'args' => $id_arg ) );
		register_rest_route( self::NS, '/members/(?P<id>\d+)/titles', array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'set_member_titles' ), 'permission_callback' => $manage, 'args' => $id_arg ) );
		register_rest_route( self::NS, '/members/(?P<id>\d+)/promote', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'promote_member' ), 'permission_callback' => $manage, 'args' => $id_arg ) );
		register_rest_route( self::NS, '/members/(?P<id>\d+)/role', array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, 'set_member_role' ), 'permission_callback' => array( __CLASS__, 'can_promote_users' ), 'args' => $id_arg ) );

		register_rest_route( self::NS, '/users', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_user' ), 'permission_callback' => array( __CLASS__, 'can_create_users' ) ) );
		register_rest_route( self::NS, '/users/bulk', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_users_bulk' ), 'permission_callback' => array( __CLASS__, 'can_create_users' ) ) );
		register_rest_route( self::NS, '/accounts', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_accounts' ), 'permission_callback' => array( __CLASS__, 'can_edit_users' ) ) );
		register_rest_route( self::NS, '/accounts/(?P<id>\d+)', array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, 'update_account' ), 'permission_callback' => array( __CLASS__, 'can_edit_users' ), 'args' => $id_arg ) );
		register_rest_route( self::NS, '/accounts/(?P<id>\d+)/password', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'reset_account_password' ), 'permission_callback' => array( __CLASS__, 'can_edit_users' ), 'args' => $id_arg ) );

		register_rest_route(
			self::NS,
			'/attendance',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_attendance_lists' ), 'permission_callback' => $manage ),
				array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'save_attendance' ), 'permission_callback' => $manage ),
			)
		);
		register_rest_route( self::NS, '/attendance/events', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_attendance_events' ), 'permission_callback' => $manage ) );
		register_rest_route(
			self::NS,
			'/attendance/(?P<id>\d+)',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_attendance_list' ), 'permission_callback' => $manage, 'args' => $id_arg ),
				array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_attendance_list' ), 'permission_callback' => $manage, 'args' => $id_arg ),
			)
		);

		register_rest_route( self::NS, '/points', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_points' ), 'permission_callback' => $manage ) );
		register_rest_route( self::NS, '/points/(?P<id>\d+)', array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_points' ), 'permission_callback' => $manage, 'args' => $id_arg ) );

		register_rest_route( self::NS, '/penalties', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_penalties' ), 'permission_callback' => $manage ) );
		register_rest_route(
			self::NS,
			'/penalties/(?P<id>\d+)',
			array(
				array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, 'update_penalty' ), 'permission_callback' => $manage, 'args' => $id_arg ),
				array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_penalty' ), 'permission_callback' => $manage, 'args' => $id_arg ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Berechtigungen                                                      */
	/* ------------------------------------------------------------------ */

	public static function can_read() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'mkl_not_logged_in', 'Bitte melde dich an.', array( 'status' => 401 ) );
		}
		return true;
	}

	/** Mitglied, Spieß oder Admin. */
	public static function can_member() {
		return self::require_cap( MKL_CAP_MEMBER, 'Dieser Bereich ist nur für Mitglieder.' );
	}

	/** Anwärter, Mitglied, Spieß oder Admin. */
	public static function can_calendar() {
		return self::require_cap( MKL_CAP_CALENDAR, 'Die internen Termine sind nur für Anwärter und Mitglieder.' );
	}

	public static function can_manage_events() {
		return self::require_cap( MKL_CAP_EVENTS, 'Nur Administratoren dürfen öffentliche Termine pflegen.' );
	}

	public static function can_manage_docs() {
		return self::require_cap( MKL_CAP_DOCS, 'Nur Administratoren dürfen Dokumente pflegen.' );
	}

	public static function can_promote_users() {
		return self::require_cap( 'promote_users', 'Nur Administratoren dürfen Rollen ändern.' );
	}

	public static function can_edit_users() {
		return self::require_cap( 'edit_users', 'Nur Administratoren dürfen Konten verwalten.' );
	}

	private static function require_cap( $cap, $message ) {
		$read = self::can_read();
		if ( true !== $read ) {
			return $read;
		}
		if ( ! current_user_can( $cap ) ) {
			return new WP_Error( 'mkl_forbidden', $message, array( 'status' => 403 ) );
		}
		return true;
	}

	public static function can_manage() {
		$read = self::can_read();
		if ( true !== $read ) {
			return $read;
		}
		if ( ! current_user_can( MKL_CAP_MANAGE ) ) {
			return new WP_Error( 'mkl_forbidden', 'Nur der Spieß darf das.', array( 'status' => 403 ) );
		}
		return true;
	}

	/** Benutzer anlegen dürfen nur WordPress-Administratoren (Capability create_users). */
	public static function can_create_users() {
		$read = self::can_read();
		if ( true !== $read ) {
			return $read;
		}
		if ( ! current_user_can( 'create_users' ) ) {
			return new WP_Error( 'mkl_forbidden', 'Nur Administratoren dürfen Mitglieder anlegen.', array( 'status' => 403 ) );
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Eigene Daten                                                        */
	/* ------------------------------------------------------------------ */

	public static function get_me() {
		$user = wp_get_current_user();
		return rest_ensure_response(
			array(
				'id'               => $user->ID,
				'name'             => $user->display_name,
				'level'             => MKL_Roles::get_level( $user->ID ),
				'is_member'         => MKL_Roles::is_member(),
				'can_see_calendar'  => MKL_Roles::can_see_calendar(),
				'is_spiess'         => MKL_Roles::is_spiess(),
				'can_create_users'  => current_user_can( 'create_users' ),
				'can_edit_users'    => current_user_can( 'edit_users' ),
				'can_manage_events' => current_user_can( MKL_CAP_EVENTS ),
				'can_manage_docs'   => current_user_can( MKL_CAP_DOCS ),
				'is_jungmariner'    => MKL_Roles::is_jungmariner( $user->ID ),
				'points'           => MKL_DB::get_points_total( $user->ID ),
				'open_cents'       => MKL_DB::get_open_penalties_cents( $user->ID ),
				'titles'           => MKL_Titles::for_user( $user->ID ),
			) + self::own_rank( $user->ID )
		);
	}

	/**
	 * Dienstgrad-Angaben für das Mitglied selbst: zugewiesener Dienstgrad, nächster Dienstgrad und
	 * fehlende Punkte. Gäste haben keinen Dienstgrad.
	 */
	private static function own_rank( $user_id ) {
		if ( ! MKL_Roles::is_member( $user_id ) ) {
			return array(
				'rank'           => null,
				'next_rank'      => null,
				'points_to_next' => null,
			);
		}
		$status = MKL_Ranks::status_for_user( $user_id );
		return array(
			'rank'           => $status['rank'],
			'next_rank'      => $status['next_rank'],
			'points_to_next' => $status['points_to_next'],
		);
	}

	public static function get_my_rank_history() {
		return rest_ensure_response( MKL_Ranks::history( get_current_user_id() ) );
	}

	public static function get_my_attendance() {
		return rest_ensure_response( MKL_Attendance::for_user( get_current_user_id() ) );
	}

	public static function get_my_points() {
		return rest_ensure_response( MKL_DB::get_points_for_user( get_current_user_id() ) );
	}

	public static function get_my_penalties() {
		return rest_ensure_response( MKL_DB::get_penalties_for_user( get_current_user_id() ) );
	}

	/** Mindestlänge für selbst gewählte Passwörter. */
	const MIN_PASSWORD_LENGTH = 8;

	/**
	 * Eigenes Passwort ändern. Erfordert das aktuelle Passwort.
	 *
	 * Ein neues Passwort macht in WordPress alle Anmelde-Cookies ungültig. Andere
	 * Geräte werden dadurch abgemeldet; dieses Gerät bekommt sofort ein neues
	 * Cookie. Die App holt sich danach einen frischen REST-Nonce.
	 */
	public static function change_my_password( WP_REST_Request $req ) {
		$user    = wp_get_current_user();
		$current = (string) $req->get_param( 'current_password' );
		$new     = (string) $req->get_param( 'new_password' );

		if ( '' === $current || ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
			return self::error( 'Das aktuelle Passwort ist falsch.' );
		}
		if ( strlen( $new ) < self::MIN_PASSWORD_LENGTH ) {
			return self::error( sprintf( 'Das neue Passwort muss mindestens %d Zeichen lang sein.', self::MIN_PASSWORD_LENGTH ) );
		}
		if ( $new !== trim( $new ) ) {
			return self::error( 'Das neue Passwort darf nicht mit Leerzeichen beginnen oder enden.' );
		}
		if ( $new === $current ) {
			return self::error( 'Das neue Passwort muss sich vom aktuellen unterscheiden.' );
		}

		wp_set_password( $new, $user->ID );
		WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
		if ( ! headers_sent() ) {
			wp_set_auth_cookie( $user->ID, true, is_ssl() );
		}

		return rest_ensure_response( array( 'changed' => true ) );
	}

	public static function get_calendar() {
		$events = MKL_Calendar::get_events();
		if ( is_wp_error( $events ) ) {
			$events->add_data( array( 'status' => 502 ) );
			return $events;
		}
		return rest_ensure_response( $events );
	}

	/* ------------------------------------------------------------------ */
	/* Öffentliche Termine                                                 */
	/* ------------------------------------------------------------------ */

	public static function get_events() {
		return rest_ensure_response( MKL_Events::upcoming() );
	}

	public static function create_event( WP_REST_Request $req ) {
		$id = MKL_Events::create( self::event_input( $req ), get_current_user_id() );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return new WP_REST_Response( MKL_Events::get( $id ), 201 );
	}

	public static function update_event( WP_REST_Request $req ) {
		$result = MKL_Events::update( (int) $req['id'], self::event_input( $req ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( MKL_Events::get( (int) $req['id'] ) );
	}

	public static function delete_event( WP_REST_Request $req ) {
		if ( ! MKL_Events::delete( (int) $req['id'] ) ) {
			return self::error( 'Termin nicht gefunden.', 404 );
		}
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	private static function event_input( WP_REST_Request $req ) {
		$out = array();
		foreach ( array( 'title', 'start_date', 'start_time', 'end_date', 'end_time', 'location', 'description', 'url' ) as $key ) {
			$value = $req->get_param( $key );
			if ( null !== $value ) {
				$out[ $key ] = is_scalar( $value ) ? (string) $value : '';
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Galerie                                                             */
	/* ------------------------------------------------------------------ */

	/** Gäste sehen nur öffentliche Bilder, Mitglieder zusätzlich die aus „intern“. */
	public static function get_gallery() {
		return rest_ensure_response( array( 'years' => MKL_Gallery::overview( MKL_Roles::is_member() ) ) );
	}

	public static function get_gallery_album( WP_REST_Request $req ) {
		$album = MKL_Gallery::album( (int) $req['id'], MKL_Roles::is_member() );
		if ( ! $album ) {
			return self::error( 'Album nicht gefunden.', 404 );
		}
		return rest_ensure_response( $album );
	}

	/* ------------------------------------------------------------------ */
	/* Neuigkeiten (Blogbeiträge der Webseite)                             */
	/* ------------------------------------------------------------------ */

	const NEWS_PER_PAGE = 10;

	public static function get_news( WP_REST_Request $req ) {
		$page  = max( 1, (int) $req->get_param( 'page' ) );
		$query = new WP_Query(
			array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'has_password'        => false,
				'posts_per_page'      => self::NEWS_PER_PAGE,
				'paged'               => $page,
				'ignore_sticky_posts' => true,
				'no_found_rows'       => false,
			)
		);
		$items = array_map( array( __CLASS__, 'format_news' ), $query->posts );
		$res   = rest_ensure_response( $items );
		$res->header( 'X-WP-TotalPages', (string) (int) $query->max_num_pages );
		return $res;
	}

	public static function get_news_item( WP_REST_Request $req ) {
		$post = get_post( (int) $req['id'] );
		if ( ! $post || 'post' !== $post->post_type || 'publish' !== $post->post_status || post_password_required( $post ) || '' !== $post->post_password ) {
			return self::error( 'Beitrag nicht gefunden.', 404 );
		}
		$item = self::format_news( $post );

		// the_content-Filter erwarten den globalen Beitrag.
		$previous        = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		setup_postdata( $post );
		$item['content'] = apply_filters( 'the_content', $post->post_content );
		$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		wp_reset_postdata();

		return rest_ensure_response( $item );
	}

	private static function format_news( WP_Post $post ) {
		$excerpt = has_excerpt( $post ) ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 40, ' …' );
		$image   = get_the_post_thumbnail_url( $post, 'medium_large' );
		return array(
			'id'      => $post->ID,
			'title'   => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'date'    => get_post_time( 'c', true, $post ),
			'excerpt' => html_entity_decode( wp_strip_all_tags( $excerpt ), ENT_QUOTES, 'UTF-8' ),
			'image'   => $image ? $image : null,
			'link'    => get_permalink( $post ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Punktekatalog                                                       */
	/* ------------------------------------------------------------------ */

	public static function get_catalog( WP_REST_Request $req ) {
		$all = $req->get_param( 'all' ) && current_user_can( MKL_CAP_MANAGE );
		return rest_ensure_response( MKL_DB::get_catalog( ! $all ) );
	}

	public static function create_catalog_item( WP_REST_Request $req ) {
		$title  = sanitize_text_field( (string) $req->get_param( 'title' ) );
		$points = self::parse_points( $req->get_param( 'points' ) );
		if ( '' === $title ) {
			return self::error( 'Bitte einen Titel angeben.' );
		}
		if ( is_wp_error( $points ) ) {
			return $points;
		}
		$id = MKL_DB::insert_catalog_item( $title, $points, (int) $req->get_param( 'sort' ) );
		return new WP_REST_Response( MKL_DB::get_catalog_item( $id ), 201 );
	}

	public static function update_catalog_item( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		if ( ! MKL_DB::get_catalog_item( $id ) ) {
			return self::error( 'Eintrag nicht gefunden.', 404 );
		}
		$data = array();
		if ( null !== $req->get_param( 'title' ) ) {
			$data['title'] = sanitize_text_field( (string) $req->get_param( 'title' ) );
			if ( '' === $data['title'] ) {
				return self::error( 'Bitte einen Titel angeben.' );
			}
		}
		if ( null !== $req->get_param( 'points' ) ) {
			$points = self::parse_points( $req->get_param( 'points' ) );
			if ( is_wp_error( $points ) ) {
				return $points;
			}
			$data['points'] = $points;
		}
		if ( null !== $req->get_param( 'active' ) ) {
			$data['active'] = rest_sanitize_boolean( $req->get_param( 'active' ) ) ? 1 : 0;
		}
		if ( null !== $req->get_param( 'sort' ) ) {
			$data['sort'] = (int) $req->get_param( 'sort' );
		}
		if ( $data ) {
			MKL_DB::update_catalog_item( $id, $data );
		}
		return rest_ensure_response( MKL_DB::get_catalog_item( $id ) );
	}

	/** Bereits vergebene Punkte bleiben erhalten: Punktzahl und Grund stehen im Punkte-Eintrag selbst. */
	public static function delete_catalog_item( WP_REST_Request $req ) {
		if ( ! MKL_DB::delete_catalog_item( (int) $req['id'] ) ) {
			return self::error( 'Eintrag nicht gefunden.', 404 );
		}
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/* ------------------------------------------------------------------ */
	/* Mitglieder (Spieß)                                                  */
	/* ------------------------------------------------------------------ */

	private static function member_users() {
		$args = apply_filters(
			'mkl_members_query_args',
			array(
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'fields'  => array( 'ID', 'display_name' ),
			)
		);
		return get_users( $args );
	}

	/**
	 * Mitglieder (mkl_member) mit Summen. Mit ?anwaerter=1 kommen Anwärter dazu
	 * (damit der Spieß sie befördern kann). Admins können mit ?all=1 auch Konten
	 * ohne Rolle abrufen, um ihnen eine Rolle zu geben.
	 */
	public static function get_members( WP_REST_Request $req ) {
		$all       = $req->get_param( 'all' ) && current_user_can( 'promote_users' );
		$anwaerter = (bool) $req->get_param( 'anwaerter' );
		$totals    = MKL_DB::get_totals_by_user();
		$ranks  = array();
		foreach ( MKL_Ranks::all( false ) as $r ) {
			$ranks[ $r['id'] ] = $r;
		}
		$assigned  = MKL_Ranks::rank_ids_by_user();
		$titles    = MKL_Titles::all();
		$title_ids = MKL_Titles::title_ids_by_user();
		$out       = array();
		foreach ( self::member_users() as $u ) {
			$id = (int) $u->ID;
			if ( ! $all && ! MKL_Roles::is_member( $id ) && ! ( $anwaerter && MKL_Roles::LEVEL_ANWAERTER === MKL_Roles::get_level( $id ) ) ) {
				continue;
			}
			$out[] = array(
				'id'             => $id,
				'name'           => $u->display_name,
				'level'          => MKL_Roles::get_level( $id ),
				'is_spiess'      => MKL_Roles::is_spiess( $id ),
				'is_jungmariner' => MKL_Roles::is_jungmariner( $id ),
				'points'         => isset( $totals[ $id ]['points'] ) ? $totals[ $id ]['points'] : 0,
				'open_cents'     => isset( $totals[ $id ]['open_cents'] ) ? $totals[ $id ]['open_cents'] : 0,
				'rank'           => isset( $assigned[ $id ], $ranks[ $assigned[ $id ] ] ) ? MKL_Ranks::ref( $ranks[ $assigned[ $id ] ] ) : null,
				'titles'         => MKL_Titles::for_user( $id, isset( $title_ids[ $id ] ) ? $title_ids[ $id ] : array(), $titles ),
			);
		}
		return rest_ensure_response( $out );
	}

	public static function get_member( WP_REST_Request $req ) {
		$user = get_userdata( (int) $req['id'] );
		if ( ! $user ) {
			return self::error( 'Mitglied nicht gefunden.', 404 );
		}
		return rest_ensure_response(
			array(
				'id'             => $user->ID,
				'name'           => $user->display_name,
				'level'          => MKL_Roles::get_level( $user->ID ),
				'is_spiess'      => MKL_Roles::is_spiess( $user->ID ),
				'is_jungmariner' => MKL_Roles::is_jungmariner( $user->ID ),
				'points'         => MKL_DB::get_points_total( $user->ID ),
				'open_cents'     => MKL_DB::get_open_penalties_cents( $user->ID ),
				'point_list'     => MKL_DB::get_points_for_user( $user->ID ),
				'penalties'      => MKL_DB::get_penalties_for_user( $user->ID ),
				'rank_status'    => MKL_Ranks::status_for_user( $user->ID ),
				'rank_history'   => MKL_Ranks::history( $user->ID ),
				'titles'         => MKL_Titles::for_user( $user->ID ),
			)
		);
	}

	/**
	 * Markiert ein Mitglied als Jungmariner (oder hebt das auf). Gilt nur für
	 * künftige Punktevergaben; bereits vergebene Punkte bleiben unverändert.
	 */
	public static function set_member_jungmariner( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		if ( ! get_userdata( $id ) ) {
			return self::error( 'Mitglied nicht gefunden.', 404 );
		}
		if ( ! MKL_Roles::is_member( $id ) ) {
			return self::error( 'Nur Mitglieder können Jungmariner sein.' );
		}
		$value = $req->get_param( 'jungmariner' );
		if ( null === $value ) {
			return self::error( 'Bitte angeben, ob das Mitglied Jungmariner ist.' );
		}
		MKL_Roles::set_jungmariner( $id, rest_sanitize_boolean( $value ) );
		return rest_ensure_response( array( 'id' => $id, 'is_jungmariner' => MKL_Roles::is_jungmariner( $id ) ) );
	}

	/**
	 * Ersetzt die Titel eines Mitglieds (Spieße und Admins). title_ids = [] entfernt alle.
	 */
	public static function set_member_titles( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		if ( ! get_userdata( $id ) ) {
			return self::error( 'Mitglied nicht gefunden.', 404 );
		}
		if ( ! MKL_Roles::is_member( $id ) ) {
			return self::error( 'Nur Mitglieder können Titel bekommen.' );
		}
		$raw = $req->get_param( 'title_ids' );
		if ( ! is_array( $raw ) ) {
			return self::error( 'Ungültige Titel.' );
		}
		$result = MKL_Titles::set_for_user( $id, $raw );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'titles' => MKL_Titles::for_user( $id ) ) );
	}

	/**
	 * Befördern/Degradieren. Die Regeln gelten für alle, auch für Administratoren.
	 */
	public static function set_member_rank( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		if ( ! get_userdata( $id ) ) {
			return self::error( 'Mitglied nicht gefunden.', 404 );
		}
		if ( ! MKL_Roles::is_member( $id ) ) {
			return self::error( 'Nur Mitglieder können einen Dienstgrad bekommen.' );
		}
		$raw = $req->get_param( 'rank_id' );
		if ( null !== $raw && '' !== $raw && ( ! is_numeric( $raw ) || (int) $raw <= 0 ) ) {
			return self::error( 'Ungültiger Dienstgrad.' );
		}
		$target_id = $raw ? (int) $raw : null;
		$points    = MKL_DB::get_points_total( $id );
		$change    = MKL_Ranks::validate_change( $id, $target_id, $points );
		if ( is_wp_error( $change ) ) {
			return $change;
		}
		$reason = sanitize_text_field( (string) $req->get_param( 'reason' ) );
		MKL_Ranks::set_rank( $id, $change['current'], $change['target'], $change['kind'], $points, $reason, get_current_user_id() );
		MKL_Push::rank_changed( $id, $change['target'], $change['kind'] );
		return rest_ensure_response(
			array(
				'rank_status'  => MKL_Ranks::status_for_user( $id ),
				'rank_history' => MKL_Ranks::history( $id ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Dienstgrade                                                               */
	/* ------------------------------------------------------------------ */

	/** Aktive Dienstgrade für alle Mitglieder; Spieße bekommen mit ?all=1 auch inaktive und die Anzahl Zugewiesener. */
	public static function get_ranks( WP_REST_Request $req ) {
		$all = $req->get_param( 'all' ) && current_user_can( MKL_CAP_MANAGE );
		$out = MKL_Ranks::all( ! $all );
		if ( $all ) {
			$counts = array_count_values( MKL_Ranks::rank_ids_by_user() );
			foreach ( $out as &$r ) {
				$r['member_count'] = isset( $counts[ $r['id'] ] ) ? $counts[ $r['id'] ] : 0;
			}
			unset( $r );
		}
		return rest_ensure_response( $out );
	}

	public static function create_rank( WP_REST_Request $req ) {
		$min = self::parse_min_points( $req->get_param( 'min_points' ) );
		if ( is_wp_error( $min ) ) {
			return $min;
		}
		$id = MKL_Ranks::insert( sanitize_text_field( (string) $req->get_param( 'title' ) ), $min );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return new WP_REST_Response( MKL_Ranks::get( $id ), 201 );
	}

	public static function update_rank( WP_REST_Request $req ) {
		$data = array();
		if ( null !== $req->get_param( 'title' ) ) {
			$data['title'] = sanitize_text_field( (string) $req->get_param( 'title' ) );
		}
		if ( null !== $req->get_param( 'min_points' ) ) {
			$min = self::parse_min_points( $req->get_param( 'min_points' ) );
			if ( is_wp_error( $min ) ) {
				return $min;
			}
			$data['min_points'] = $min;
		}
		if ( null !== $req->get_param( 'active' ) ) {
			$data['active'] = rest_sanitize_boolean( $req->get_param( 'active' ) );
		}
		$result = MKL_Ranks::update( (int) $req['id'], $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( MKL_Ranks::get( (int) $req['id'] ) );
	}

	public static function delete_rank( WP_REST_Request $req ) {
		$result = MKL_Ranks::delete( (int) $req['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/* ------------------------------------------------------------------ */
	/* Titel                                                               */
	/* ------------------------------------------------------------------ */

	/** Titelliste mit Anzahl der Mitglieder, denen der Titel zugewiesen ist. */
	public static function get_titles() {
		$by_user = MKL_Titles::title_ids_by_user();
		$out     = MKL_Titles::all();
		foreach ( $out as &$t ) {
			$count = 0;
			foreach ( $by_user as $ids ) {
				if ( in_array( $t['id'], $ids, true ) ) {
					$count++;
				}
			}
			$t['member_count'] = $count;
		}
		unset( $t );
		return rest_ensure_response( $out );
	}

	public static function create_title( WP_REST_Request $req ) {
		$id = MKL_Titles::insert( sanitize_text_field( (string) $req->get_param( 'title' ) ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return new WP_REST_Response( MKL_Titles::get( $id ), 201 );
	}

	public static function update_title( WP_REST_Request $req ) {
		$result = MKL_Titles::rename( (int) $req['id'], sanitize_text_field( (string) $req->get_param( 'title' ) ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( MKL_Titles::get( (int) $req['id'] ) );
	}

	public static function delete_title( WP_REST_Request $req ) {
		$result = MKL_Titles::delete( (int) $req['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/** Beförderungsübersicht für die Spieße (Mitglieder, Spieße und Admins, keine Gäste). */
	public static function get_promotions() {
		$users = array_values(
			array_filter(
				self::member_users(),
				function ( $u ) {
					return MKL_Roles::is_member( (int) $u->ID );
				}
			)
		);
		$titles    = MKL_Titles::all();
		$title_ids = MKL_Titles::title_ids_by_user();
		$out       = MKL_Ranks::promotions( $users );
		foreach ( $out as &$p ) {
			$p['titles'] = MKL_Titles::for_user( $p['user_id'], isset( $title_ids[ $p['user_id'] ] ) ? $title_ids[ $p['user_id'] ] : array(), $titles );
		}
		unset( $p );
		return rest_ensure_response( $out );
	}

	private static function parse_min_points( $raw ) {
		if ( ! is_numeric( $raw ) || (int) $raw != $raw || (int) $raw < 0 || (int) $raw > MKL_Ranks::MAX_MIN_POINTS ) { // phpcs:ignore WordPress.PHP.StrictComparisons
			return self::error( 'Bitte eine ganze Punktzahl ab 0 als Schwelle angeben.' );
		}
		return (int) $raw;
	}

	/* ------------------------------------------------------------------ */
	/* Dokumente                                                           */
	/* ------------------------------------------------------------------ */

	/** Dokumente, die das Konto sehen darf, und alle Kategorien. */
	public static function get_documents() {
		return rest_ensure_response(
			array(
				'categories' => MKL_Documents::categories(),
				'documents'  => MKL_Documents::visible(),
			)
		);
	}

	/**
	 * Neues Dokument: Die Datei kommt direkt als Request-Body, die Angaben als
	 * Query-Parameter: filename, title, category_id, date (JJJJ-MM-TT), visibility.
	 */
	public static function create_document( WP_REST_Request $req ) {
		$body = self::upload_body( $req );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$doc = MKL_Documents::create(
			array(
				'title'       => $req->get_param( 'title' ),
				'category_id' => $req->get_param( 'category_id' ),
				'date'        => $req->get_param( 'date' ),
				'visibility'  => $req->get_param( 'visibility' ),
			),
			$body,
			(string) $req->get_param( 'filename' ),
			get_current_user_id()
		);
		return is_wp_error( $doc ) ? $doc : new WP_REST_Response( $doc, 201 );
	}

	/** Neue Fassung: ersetzt die Datei, die Angaben bleiben. */
	public static function replace_document_file( WP_REST_Request $req ) {
		$body = self::upload_body( $req );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$doc = MKL_Documents::replace_file( (int) $req['id'], $body, (string) $req->get_param( 'filename' ), get_current_user_id() );
		return is_wp_error( $doc ) ? $doc : rest_ensure_response( $doc );
	}

	/** Der Request-Body ist die Datei; JSON und Formulare würde WordPress als Parameter lesen. */
	private static function upload_body( WP_REST_Request $req ) {
		$type = $req->get_content_type();
		if ( ! $type || in_array( strtolower( $type['value'] ), array( 'application/json', 'application/x-www-form-urlencoded', 'multipart/form-data' ), true ) ) {
			return self::error( 'Bitte die Datei direkt als Inhalt der Anfrage senden.' );
		}
		return $req->get_body();
	}

	/** Titel, Kategorie, Stand und Sichtbarkeit ändern (nur übergebene Felder). */
	public static function update_document( WP_REST_Request $req ) {
		$meta = array();
		foreach ( array( 'title', 'category_id', 'date', 'visibility' ) as $key ) {
			if ( $req->has_param( $key ) ) {
				$meta[ $key ] = $req->get_param( $key );
			}
		}
		$doc = MKL_Documents::update( (int) $req['id'], $meta );
		return is_wp_error( $doc ) ? $doc : rest_ensure_response( $doc );
	}

	public static function delete_document( WP_REST_Request $req ) {
		if ( ! MKL_Documents::delete( (int) $req['id'] ) ) {
			return self::error( 'Dokument nicht gefunden.', 404 );
		}
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	public static function create_document_category( WP_REST_Request $req ) {
		$cat = MKL_Documents::save_category( 0, $req->get_param( 'title' ), (int) $req->get_param( 'sort' ) );
		return is_wp_error( $cat ) ? $cat : new WP_REST_Response( $cat, 201 );
	}

	public static function update_document_category( WP_REST_Request $req ) {
		$cat = MKL_Documents::save_category( (int) $req['id'], $req->get_param( 'title' ), $req->get_param( 'sort' ) );
		return is_wp_error( $cat ) ? $cat : rest_ensure_response( $cat );
	}

	public static function delete_document_category( WP_REST_Request $req ) {
		$result = MKL_Documents::delete_category( (int) $req['id'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'deleted' => true ) );
	}

	/** Spieß und Admins befördern Anwärter zu vollen Mitgliedern. */
	public static function promote_member( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		if ( ! get_userdata( $id ) ) {
			return self::error( 'Mitglied nicht gefunden.', 404 );
		}
		if ( MKL_Roles::LEVEL_ANWAERTER !== MKL_Roles::get_level( $id ) ) {
			return self::error( 'Nur Anwärter können zum Mitglied befördert werden.' );
		}
		$result = MKL_Roles::set_level( $id, MKL_Roles::LEVEL_MITGLIED );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'id' => $id, 'level' => MKL_Roles::get_level( $id ) ) );
	}

	public static function set_member_role( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		if ( get_current_user_id() === $id ) {
			return self::error( 'Die eigene Rolle kann man nicht selbst ändern.' );
		}
		$result = MKL_Roles::set_level( $id, (string) $req->get_param( 'level' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'id' => $id, 'level' => MKL_Roles::get_level( $id ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Mitglieder anlegen (Admin)                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Legt ein Konto mit der gewählten App-Rolle (level: ohne|anwaerter|mitglied|spiess,
	 * Standard anwaerter, weil neue Leute als Anwärter anfangen) und zufälligem Startpasswort an.
	 * Das Passwort wird nur in dieser Antwort zurückgegeben und nirgends gespeichert
	 * außer als Hash in WordPress; es wird keine E-Mail verschickt.
	 *
	 * Optional für die Übernahme bestehender Mitglieder: initial_points (Anfangsbestand,
	 * wird als normale Buchung angelegt), initial_points_date und rank_id. Der Dienstgrad muss
	 * mit dem Anfangsbestand erreichbar sein; das wird geprüft, bevor das Konto entsteht.
	 * Mit jungmariner=true wird das Mitglied gleich als Jungmariner markiert; der
	 * Anfangsbestand wird dabei nicht halbiert. Anfangsbestand, Dienstgrad und Jungmariner
	 * gibt es nur für Mitglieder und Spieße.
	 */
	public static function create_user( WP_REST_Request $req ) {
		$level = self::parse_new_level( $req->get_param( 'level' ) );
		if ( is_wp_error( $level ) ) {
			return $level;
		}
		$is_member = in_array( $level, array( MKL_Roles::LEVEL_MITGLIED, MKL_Roles::LEVEL_SPIESS ), true );

		$reserved = array();
		$identity = self::validate_identity(
			array(
				'name'     => $req->get_param( 'name' ),
				'username' => $req->get_param( 'username' ),
				'email'    => $req->get_param( 'email' ),
			),
			$reserved
		);
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}
		$initial_date = self::parse_date( $req->get_param( 'initial_points_date' ) );
		if ( is_wp_error( $initial_date ) ) {
			return $initial_date;
		}
		$extras = self::validate_extras(
			array(
				'initial_points' => $req->get_param( 'initial_points' ),
				'rank_id'        => $req->get_param( 'rank_id' ),
				'jungmariner'    => $req->get_param( 'jungmariner' ),
			),
			$is_member
		);
		if ( is_wp_error( $extras ) ) {
			return $extras;
		}

		$account = self::insert_account( $identity, $level );
		if ( is_wp_error( $account ) ) {
			return $account;
		}
		$applied = self::apply_extras( $account['id'], $extras, $initial_date );
		if ( is_wp_error( $applied ) ) {
			self::rollback_user( $account['id'] );
			return self::error( $applied->get_error_message() . ' Das Mitglied wurde nicht angelegt.', 500 );
		}
		return new WP_REST_Response( self::format_created( $account, $extras ), 201 );
	}

	/**
	 * Legt mehrere Konten auf einmal an:
	 * { level?, initial_points_date?, users: [ { name, username?, email?, initial_points?, rank_id?, jungmariner? }, … ] }.
	 * Alle bekommen dieselbe Rolle (Standard Anwärter) und je ein eigenes Startpasswort;
	 * Anfangsbestand, Dienstgrad und Jungmariner gelten je Person (nur für Mitglieder und Spieße),
	 * das Datum des Anfangsbestands für alle.
	 *
	 * Zuerst werden alle Einträge geprüft, auch gegeneinander (z. B. doppelte
	 * E-Mail-Adressen). Ist einer fehlerhaft, wird niemand angelegt und die Meldung
	 * nennt die Person (gezählt ab 1, wie in der App). Scheitert danach noch etwas
	 * beim Speichern, werden alle in diesem Durchgang angelegten Konten wieder entfernt.
	 * Antwort: Liste der angelegten Konten mit Startpasswörtern.
	 */
	public static function create_users_bulk( WP_REST_Request $req ) {
		$level = self::parse_new_level( $req->get_param( 'level' ) );
		if ( is_wp_error( $level ) ) {
			return $level;
		}
		$is_member    = in_array( $level, array( MKL_Roles::LEVEL_MITGLIED, MKL_Roles::LEVEL_SPIESS ), true );
		$initial_date = self::parse_date( $req->get_param( 'initial_points_date' ) );
		if ( is_wp_error( $initial_date ) ) {
			return $initial_date;
		}
		$users = $req->get_param( 'users' );
		if ( ! is_array( $users ) || ! $users ) {
			return self::error( 'Bitte mindestens ein Mitglied angeben.' );
		}
		if ( count( $users ) > self::MAX_BULK_USERS ) {
			return self::error( sprintf( 'Bitte höchstens %d Mitglieder auf einmal anlegen.', self::MAX_BULK_USERS ) );
		}

		$reserved = array();
		$valid    = array();
		foreach ( array_values( $users ) as $i => $entry ) {
			$entry    = is_array( $entry ) ? $entry : array();
			$identity = self::validate_identity( $entry, $reserved );
			$extras   = is_wp_error( $identity ) ? $identity : self::validate_extras( $entry, $is_member );
			if ( is_wp_error( $extras ) ) {
				$name = isset( $entry['name'] ) ? trim( sanitize_text_field( (string) $entry['name'] ) ) : '';
				return self::error( sprintf( 'Person %d%s: %s Es wurde niemand angelegt.', $i + 1, '' !== $name ? ' (' . $name . ')' : '', $extras->get_error_message() ) );
			}
			$valid[] = array( $identity, $extras );
		}

		$created = array();
		foreach ( $valid as $item ) {
			list( $identity, $extras ) = $item;
			$account = self::insert_account( $identity, $level );
			$applied = is_wp_error( $account ) ? $account : self::apply_extras( $account['id'], $extras, $initial_date );
			if ( is_wp_error( $applied ) ) {
				// Sollte nach der Prüfung nicht vorkommen. Alles oder nichts: bereits Angelegte wieder entfernen.
				if ( ! is_wp_error( $account ) ) {
					self::rollback_user( $account['id'] );
				}
				foreach ( $created as $done ) {
					self::rollback_user( $done['id'] );
				}
				return self::error( sprintf( '%s konnte nicht angelegt werden: %s Es wurde niemand angelegt.', $identity['name'], $applied->get_error_message() ), 500 );
			}
			$created[] = self::format_created( $account, $extras );
		}
		return new WP_REST_Response( $created, 201 );
	}

	/**
	 * Prüft Anfangsbestand (initial_points), Dienstgrad (rank_id) und Jungmariner.
	 * Der Dienstgrad muss aktiv und mit dem Anfangsbestand erreichbar sein.
	 *
	 * @return array|WP_Error initial (int), rank (array|null), jungmariner (bool)
	 */
	private static function validate_extras( array $entry, $is_member ) {
		$raw_points = isset( $entry['initial_points'] ) ? $entry['initial_points'] : null;
		$initial    = 0;
		if ( null !== $raw_points && '' !== $raw_points ) {
			if ( ! is_numeric( $raw_points ) || (int) $raw_points != $raw_points || (int) $raw_points < 0 || (int) $raw_points > self::MAX_INITIAL_POINTS ) { // phpcs:ignore WordPress.PHP.StrictComparisons
				return self::error( 'Bitte als Anfangsbestand eine ganze Punktzahl ab 0 angeben.' );
			}
			$initial = (int) $raw_points;
		}
		$raw_rank = isset( $entry['rank_id'] ) ? $entry['rank_id'] : null;
		$rank     = null;
		if ( null !== $raw_rank && '' !== $raw_rank && 0 !== (int) $raw_rank ) {
			$rank = MKL_Ranks::get( (int) $raw_rank );
			if ( ! $rank ) {
				return self::error( 'Dienstgrad nicht gefunden.' );
			}
			if ( ! $rank['active'] ) {
				return self::error( sprintf( 'Der Dienstgrad „%s“ ist deaktiviert.', $rank['title'] ) );
			}
			if ( $initial < $rank['min_points'] ) {
				return self::error( sprintf( 'Für „%s“ sind mindestens %s Punkte nötig.', $rank['title'], number_format_i18n( $rank['min_points'] ) ) );
			}
		}

		$jungmariner = rest_sanitize_boolean( isset( $entry['jungmariner'] ) ? $entry['jungmariner'] : false );
		if ( ! $is_member && ( $initial > 0 || $rank || $jungmariner ) ) {
			return self::error( 'Anfangsbestand, Dienstgrad und Jungmariner gibt es nur für Mitglieder.' );
		}
		return array(
			'initial'     => $initial,
			'rank'        => $rank,
			'jungmariner' => $jungmariner,
		);
	}

	/**
	 * Bucht den Anfangsbestand, setzt den Dienstgrad und markiert Jungmariner.
	 * Bei einem Fehler muss der Aufrufer das Konto mit rollback_user() entfernen.
	 *
	 * @return true|WP_Error
	 */
	private static function apply_extras( $user_id, array $extras, $initial_date ) {
		if ( $extras['initial'] > 0 ) {
			$ids = MKL_DB::add_points( array( $user_id ), $extras['initial'], 'Anfangsbestand bei Übernahme', null, $initial_date, get_current_user_id() );
			if ( ! $ids[0] ) {
				return self::error( 'Der Anfangsbestand konnte nicht gespeichert werden.', 500 );
			}
		}
		if ( $extras['rank'] && ! MKL_Ranks::set_rank( $user_id, null, $extras['rank'], MKL_Ranks::KIND_PROMOTION, $extras['initial'], 'Ersteinstufung bei Anlage', get_current_user_id() ) ) {
			return self::error( 'Der Dienstgrad konnte nicht gespeichert werden.', 500 );
		}
		// Erst nach dem Anfangsbestand: Der ist ein bereits erreichter Stand und wird nicht halbiert.
		MKL_Roles::set_jungmariner( $user_id, $extras['jungmariner'] );
		return true;
	}

	/** Antwort für ein neu angelegtes Konto (inkl. Startpasswort). */
	private static function format_created( array $account, array $extras ) {
		return array(
			'id'             => $account['id'],
			'name'           => $account['name'],
			'username'       => $account['username'],
			'email'          => $account['email'],
			'level'          => MKL_Roles::get_level( $account['id'] ),
			'password'       => $account['password'],
			'points'         => $extras['initial'],
			'rank'           => MKL_Ranks::ref( $extras['rank'] ),
			'is_jungmariner' => MKL_Roles::is_jungmariner( $account['id'] ),
		);
	}

	/**
	 * Rolle für ein neues Konto (ohne|anwaerter|mitglied|spiess), Standard Anwärter.
	 * Admin-Rechte vergibt man bewusst nur im WordPress-Backend.
	 *
	 * @return string|WP_Error
	 */
	private static function parse_new_level( $level ) {
		$level = null === $level || '' === $level ? MKL_Roles::LEVEL_ANWAERTER : $level;
		if ( ! is_string( $level ) || ! isset( self::new_account_roles()[ $level ] ) ) {
			return self::error( 'Ungültige Rolle.' );
		}
		return $level;
	}

	/** App-Rolle → WordPress-Rolle für neue Konten. */
	private static function new_account_roles() {
		return array(
			MKL_Roles::LEVEL_OHNE      => 'subscriber',
			MKL_Roles::LEVEL_ANWAERTER => MKL_ROLE_ANWAERTER,
			MKL_Roles::LEVEL_MITGLIED  => MKL_ROLE_MITGLIED,
			MKL_Roles::LEVEL_SPIESS    => MKL_ROLE_SPIESS,
		);
	}

	/**
	 * Prüft Name, Benutzername und E-Mail für ein neues Konto und bildet fehlende
	 * Benutzernamen aus dem Namen.
	 *
	 * @param array $entry    name, optional username und email.
	 * @param array $reserved Benutzernamen/E-Mails, die schon für andere Einträge
	 *                        derselben Anfrage vorgesehen sind (wird ergänzt).
	 * @return array|WP_Error name, username, email
	 */
	private static function validate_identity( array $entry, array &$reserved ) {
		$name = trim( sanitize_text_field( (string) ( isset( $entry['name'] ) ? $entry['name'] : '' ) ) );
		if ( '' === $name ) {
			return self::error( 'Bitte einen Namen angeben.' );
		}

		$login = trim( (string) ( isset( $entry['username'] ) ? $entry['username'] : '' ) );
		if ( '' === $login ) {
			$login = self::unique_login( $name, $reserved );
		} else {
			if ( sanitize_user( $login, true ) !== $login || strlen( $login ) > 60 ) {
				return self::error( 'Der Benutzername darf nur Buchstaben (a–z), Ziffern sowie . _ - @ enthalten.' );
			}
			if ( username_exists( $login ) || isset( $reserved[ 'login:' . strtolower( $login ) ] ) ) {
				return self::error( 'Dieser Benutzername ist schon vergeben.' );
			}
		}

		$email = trim( (string) ( isset( $entry['email'] ) ? $entry['email'] : '' ) );
		if ( '' !== $email ) {
			if ( ! is_email( $email ) ) {
				return self::error( 'Die E-Mail-Adresse ist ungültig.' );
			}
			if ( email_exists( $email ) || isset( $reserved[ 'email:' . strtolower( $email ) ] ) ) {
				return self::error( 'Diese E-Mail-Adresse wird schon verwendet.' );
			}
			$reserved[ 'email:' . strtolower( $email ) ] = true;
		}
		$reserved[ 'login:' . strtolower( $login ) ] = true;

		return array(
			'name'     => $name,
			'username' => $login,
			'email'    => $email,
		);
	}

	/** @return array|WP_Error Angelegtes Konto (id, name, username, email, password). */
	private static function insert_account( array $identity, $level ) {
		$parts    = preg_split( '/\s+/', $identity['name'], 2 );
		$password = self::readable_password( 12 );
		$user_id  = wp_insert_user(
			array(
				'user_login'   => $identity['username'],
				'user_pass'    => $password,
				'user_email'   => $identity['email'],
				'display_name' => $identity['name'],
				'nickname'     => $identity['name'],
				'first_name'   => $parts[0],
				'last_name'    => isset( $parts[1] ) ? $parts[1] : '',
				'role'         => self::new_account_roles()[ $level ],
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return self::error( $user_id->get_error_message() );
		}
		return array(
			'id'       => $user_id,
			'name'     => $identity['name'],
			'username' => $identity['username'],
			'email'    => $identity['email'],
			'password' => $password,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Konten verwalten (Admin)                                            */
	/* ------------------------------------------------------------------ */

	/** Alle Konten (auch ohne Rolle) mit Benutzername und E-Mail. */
	public static function get_accounts() {
		$users = get_users(
			array(
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'fields'  => array( 'ID', 'display_name', 'user_login', 'user_email' ),
			)
		);
		$out = array();
		foreach ( $users as $u ) {
			$out[] = self::format_account( (int) $u->ID, $u->display_name, $u->user_login, $u->user_email );
		}
		return rest_ensure_response( $out );
	}

	private static function format_account( $id, $name, $login, $email ) {
		return array(
			'id'       => $id,
			'name'     => $name,
			'username' => $login,
			'email'    => $email,
			'level'    => MKL_Roles::get_level( $id ),
			'is_self'  => get_current_user_id() === $id,
			'editable' => current_user_can( 'edit_user', $id ),
		);
	}

	/** @return WP_User|WP_Error */
	private static function editable_account( $id ) {
		$user = get_userdata( $id );
		if ( ! $user ) {
			return self::error( 'Konto nicht gefunden.', 404 );
		}
		if ( ! current_user_can( 'edit_user', $id ) ) {
			return new WP_Error( 'mkl_forbidden', 'Dieses Konto darfst du nicht bearbeiten.', array( 'status' => 403 ) );
		}
		return $user;
	}

	/**
	 * Name, Benutzername und/oder E-Mail eines Kontos ändern.
	 *
	 * WordPress sieht keine Änderung des Benutzernamens vor; er wird deshalb direkt
	 * in der Datenbank geändert. Das Anmelde-Cookie enthält den Benutzernamen, daher
	 * werden dabei alle Sitzungen des Kontos beendet. Den eigenen Benutzernamen kann
	 * man so nicht ändern, um sich nicht selbst abzumelden.
	 */
	public static function update_account( WP_REST_Request $req ) {
		global $wpdb;
		$id   = (int) $req['id'];
		$user = self::editable_account( $id );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$data = array( 'ID' => $id );
		if ( null !== $req->get_param( 'name' ) ) {
			$name = trim( sanitize_text_field( (string) $req->get_param( 'name' ) ) );
			if ( '' === $name ) {
				return self::error( 'Bitte einen Namen angeben.' );
			}
			$parts                = preg_split( '/\s+/', $name, 2 );
			$data['display_name'] = $name;
			$data['nickname']     = $name;
			$data['first_name']   = $parts[0];
			$data['last_name']    = isset( $parts[1] ) ? $parts[1] : '';
		}
		if ( null !== $req->get_param( 'email' ) ) {
			$email = trim( (string) $req->get_param( 'email' ) );
			if ( '' !== $email ) {
				if ( ! is_email( $email ) ) {
					return self::error( 'Die E-Mail-Adresse ist ungültig.' );
				}
				$owner = email_exists( $email );
				if ( $owner && (int) $owner !== $id ) {
					return self::error( 'Diese E-Mail-Adresse wird schon verwendet.' );
				}
			}
			$data['user_email'] = $email;
		}

		$login = null;
		if ( null !== $req->get_param( 'username' ) ) {
			$login = trim( (string) $req->get_param( 'username' ) );
			if ( $login === $user->user_login ) {
				$login = null;
			} elseif ( get_current_user_id() === $id ) {
				return self::error( 'Den eigenen Benutzernamen kann man hier nicht ändern, weil man dabei abgemeldet würde.' );
			} elseif ( '' === $login || sanitize_user( $login, true ) !== $login || strlen( $login ) > 60 ) {
				return self::error( 'Der Benutzername darf nur Buchstaben (a–z), Ziffern sowie . _ - @ enthalten.' );
			} elseif ( username_exists( $login ) ) {
				return self::error( 'Dieser Benutzername ist schon vergeben.' );
			}
		}

		if ( count( $data ) > 1 ) {
			// Wie beim Anlegen: keine automatischen E-Mails an das Mitglied.
			add_filter( 'send_email_change_email', '__return_false' );
			$result = wp_update_user( $data );
			remove_filter( 'send_email_change_email', '__return_false' );
			if ( is_wp_error( $result ) ) {
				return self::error( $result->get_error_message() );
			}
		}
		if ( null !== $login ) {
			$wpdb->update( $wpdb->users, array( 'user_login' => $login ), array( 'ID' => $id ), array( '%s' ), array( '%d' ) );
			clean_user_cache( $id );
			WP_Session_Tokens::get_instance( $id )->destroy_all();
		}

		$fresh = get_userdata( $id );
		return rest_ensure_response( self::format_account( $id, $fresh->display_name, $fresh->user_login, $fresh->user_email ) );
	}

	/**
	 * Setzt ein neues zufälliges Startpasswort und beendet alle Sitzungen des Kontos.
	 * Das Passwort wird nur in dieser Antwort zurückgegeben. Das eigene Passwort
	 * ändert man unter „Konto“.
	 */
	public static function reset_account_password( WP_REST_Request $req ) {
		$id   = (int) $req['id'];
		$user = self::editable_account( $id );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		if ( get_current_user_id() === $id ) {
			return self::error( 'Das eigene Passwort bitte unter „Konto“ ändern.' );
		}
		$password = self::readable_password( 12 );
		wp_set_password( $password, $id );
		WP_Session_Tokens::get_instance( $id )->destroy_all();
		return rest_ensure_response(
			array(
				'id'       => $id,
				'name'     => $user->display_name,
				'username' => $user->user_login,
				'password' => $password,
			)
		);
	}

	/** Entfernt ein gerade angelegtes Konto samt Buchungen wieder (über deleted_user), damit kein halb angelegtes Mitglied bleibt. */
	private static function rollback_user( $user_id ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );
	}

	/**
	 * Zufallspasswort ohne leicht verwechselbare Zeichen (0/O, 1/l/I),
	 * weil es abgeschrieben bzw. persönlich weitergegeben wird.
	 */
	private static function readable_password( $length ) {
		$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
		$max   = strlen( $chars ) - 1;
		$out   = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $chars[ random_int( 0, $max ) ];
		}
		return $out;
	}

	/** „Max Müller“ → „max.mueller“, bei Kollision „max.mueller2“ usw. */
	private static function unique_login( $name, array $reserved = array() ) {
		$base = strtr( mb_strtolower( $name, 'UTF-8' ), array( 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss' ) );
		$base = remove_accents( $base );
		$base = preg_replace( '/[^a-z0-9]+/', '.', $base );
		$base = substr( trim( $base, '.' ), 0, 50 );
		if ( '' === $base ) {
			$base = 'mitglied';
		}
		$login = $base;
		for ( $i = 2; username_exists( $login ) || isset( $reserved[ 'login:' . $login ] ); $i++ ) {
			$login = $base . $i;
		}
		return $login;
	}

	/* ------------------------------------------------------------------ */
	/* Punkte vergeben / stornieren                                        */
	/* ------------------------------------------------------------------ */

	public static function create_points( WP_REST_Request $req ) {
		$user_ids = self::parse_user_ids( $req->get_param( 'user_ids' ) );
		if ( is_wp_error( $user_ids ) ) {
			return $user_ids;
		}
		$date = self::parse_date( $req->get_param( 'date' ) );
		if ( is_wp_error( $date ) ) {
			return $date;
		}

		$catalog_id = $req->get_param( 'catalog_id' ) ? (int) $req->get_param( 'catalog_id' ) : null;
		$item       = $catalog_id ? MKL_DB::get_catalog_item( $catalog_id ) : null;
		if ( $catalog_id && ! $item ) {
			return self::error( 'Katalogeintrag nicht gefunden.' );
		}

		$raw_points = $req->get_param( 'points' );
		$points     = ( null === $raw_points || '' === $raw_points ) && $item ? $item['points'] : self::parse_points( $raw_points );
		if ( is_wp_error( $points ) ) {
			return $points;
		}
		if ( 0 === $points ) {
			return self::error( 'Die Punktzahl darf nicht 0 sein.' );
		}

		$reason = sanitize_text_field( (string) $req->get_param( 'reason' ) );
		if ( '' === $reason && $item ) {
			$reason = $item['title'];
		}
		if ( '' === $reason ) {
			return self::error( 'Bitte einen Grund angeben.' );
		}

		// Aus einer Anwesenheitsliste: wer für diesen Termin schon Punkte hat, wird übersprungen.
		// Die Antwort enthält nur die neu angelegten Einträge.
		$attendance_id = $req->get_param( 'attendance_id' ) ? (int) $req->get_param( 'attendance_id' ) : null;
		if ( $attendance_id ) {
			if ( ! MKL_Attendance::get( $attendance_id ) ) {
				return self::error( 'Anwesenheitsliste nicht gefunden.' );
			}
			$user_ids = array_values( array_diff( $user_ids, MKL_Attendance::booked_user_ids( $attendance_id ) ) );
			if ( ! $user_ids ) {
				return self::error( 'Alle ausgewählten Mitglieder haben für diesen Termin schon Punkte.' );
			}
		}

		$ids  = MKL_DB::add_points( $user_ids, $points, $reason, $catalog_id, $date, get_current_user_id(), $attendance_id );
		$rows = array_map( array( 'MKL_DB', 'get_points_row' ), $ids );
		MKL_Push::points_added( $rows );
		return new WP_REST_Response( $rows, 201 );
	}

	public static function delete_points( WP_REST_Request $req ) {
		$row = MKL_DB::get_points_row( (int) $req['id'] );
		if ( ! $row || $row['deleted'] ) {
			return self::error( 'Eintrag nicht gefunden.', 404 );
		}
		MKL_DB::soft_delete( 'points', $row['id'], get_current_user_id() );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/* ------------------------------------------------------------------ */
	/* Anwesenheit                                                         */
	/* ------------------------------------------------------------------ */

	public static function get_attendance_lists() {
		return rest_ensure_response( MKL_Attendance::recent() );
	}

	/**
	 * Kalendertermine der letzten Tage bis heute (laufende eingeschlossen), neueste
	 * zuerst, jeweils mit der ID einer schon vorhandenen Liste.
	 */
	public static function get_attendance_events() {
		$events = MKL_Calendar::get_past_events();
		if ( is_wp_error( $events ) ) {
			$events->add_data( array( 'status' => 502 ) );
			return $events;
		}
		$today = wp_date( 'Y-m-d' );
		$lists = MKL_Attendance::list_ids_by_event_key();
		$out   = array();
		foreach ( array_reverse( $events ) as $ev ) {
			$date = substr( $ev['start'], 0, 10 );
			if ( $date > $today ) {
				continue;
			}
			$out[] = array(
				'key'      => $ev['id'],
				'title'    => $ev['title'],
				'date'     => $date,
				'start'    => $ev['start'],
				'end'      => $ev['end'],
				'all_day'  => $ev['all_day'],
				'location' => $ev['location'],
				'list_id'  => isset( $lists[ $ev['id'] ] ) ? $lists[ $ev['id'] ] : null,
			);
		}
		return rest_ensure_response( $out );
	}

	public static function get_attendance_list( WP_REST_Request $req ) {
		$list = MKL_Attendance::get( (int) $req['id'] );
		if ( ! $list ) {
			return self::error( 'Anwesenheitsliste nicht gefunden.', 404 );
		}
		return rest_ensure_response( self::attendance_detail( $list ) );
	}

	/** Mitglieder und Anwärter stehen in der Anwesenheitsliste. */
	private static function in_attendance( $user_id ) {
		return MKL_Roles::is_member( $user_id ) || MKL_Roles::LEVEL_ANWAERTER === MKL_Roles::get_level( $user_id );
	}

	/**
	 * Liste mit allen aktuellen Mitgliedern und Anwärtern. Wer noch keinen Eintrag
	 * hat, z. B. weil er erst später dazukam, erscheint als „fehlt“.
	 */
	private static function attendance_detail( array $list ) {
		$statuses = MKL_Attendance::statuses( $list['id'] );
		$booked   = array_flip( MKL_Attendance::booked_user_ids( $list['id'] ) );
		$members  = array();
		foreach ( self::member_users() as $u ) {
			$id = (int) $u->ID;
			if ( ! self::in_attendance( $id ) ) {
				continue;
			}
			$members[] = array(
				'id'             => $id,
				'name'           => $u->display_name,
				'is_anwaerter'   => ! MKL_Roles::is_member( $id ),
				'is_jungmariner' => MKL_Roles::is_jungmariner( $id ),
				'status'         => isset( $statuses[ $id ] ) ? $statuses[ $id ] : MKL_Attendance::STATUS_FEHLT,
				'booked'         => isset( $booked[ $id ] ),
			);
		}
		$list['members'] = $members;
		return $list;
	}

	/**
	 * Legt eine Liste an oder speichert sie. Body: id (bestehende Liste) oder
	 * source (kalender|frei) mit event_key (nur kalender), dazu title, date und
	 * entries: [{user_id, status}].
	 */
	public static function save_attendance( WP_REST_Request $req ) {
		$id = $req->get_param( 'id' ) ? (int) $req->get_param( 'id' ) : null;
		if ( $id ) {
			$list = MKL_Attendance::get( $id );
			if ( ! $list ) {
				return self::error( 'Anwesenheitsliste nicht gefunden.', 404 );
			}
			$source    = $list['source'];
			$event_key = $list['event_key'];
		} else {
			$source    = (string) $req->get_param( 'source' );
			$event_key = '';
			if ( MKL_Attendance::SOURCE_KALENDER === $source ) {
				$event_key = (string) $req->get_param( 'event_key' );
				if ( ! preg_match( '/^[a-f0-9]{32}$/', $event_key ) ) {
					return self::error( 'Ungültiger Termin.' );
				}
			} elseif ( MKL_Attendance::SOURCE_FREI !== $source ) {
				return self::error( 'Ungültige Terminart.' );
			}
		}

		$title = mb_substr( sanitize_text_field( (string) $req->get_param( 'title' ) ), 0, 191 );
		if ( '' === $title ) {
			return self::error( 'Bitte einen Titel angeben.' );
		}
		$raw_date = $req->get_param( 'date' );
		if ( null === $raw_date || '' === $raw_date ) {
			return self::error( 'Bitte ein Datum angeben.' );
		}
		$date = self::parse_date( $raw_date );
		if ( is_wp_error( $date ) ) {
			return $date;
		}

		$raw = $req->get_param( 'entries' );
		if ( null !== $raw && ! is_array( $raw ) ) {
			return self::error( 'Ungültige Einträge.' );
		}
		$entries = array();
		foreach ( (array) $raw as $entry ) {
			$user_id = is_array( $entry ) && isset( $entry['user_id'] ) ? absint( $entry['user_id'] ) : 0;
			$status  = is_array( $entry ) && isset( $entry['status'] ) ? $entry['status'] : null;
			if ( ! $user_id || ! get_userdata( $user_id ) ) {
				return self::error( 'Unbekanntes Mitglied.' );
			}
			if ( ! self::in_attendance( $user_id ) ) {
				return self::error( 'In der Anwesenheitsliste stehen nur Mitglieder und Anwärter.' );
			}
			if ( ! in_array( $status, MKL_Attendance::STATUSES, true ) ) {
				return self::error( 'Ungültiger Status.' );
			}
			$entries[ $user_id ] = $status;
		}

		$saved = MKL_Attendance::save( $id, $source, $event_key, $title, $date, $entries, get_current_user_id() );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return new WP_REST_Response( self::attendance_detail( MKL_Attendance::get( $saved ) ), $id ? 200 : 201 );
	}

	public static function delete_attendance_list( WP_REST_Request $req ) {
		if ( ! MKL_Attendance::delete( (int) $req['id'] ) ) {
			return self::error( 'Anwesenheitsliste nicht gefunden.', 404 );
		}
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/* ------------------------------------------------------------------ */
	/* Strafen                                                             */
	/* ------------------------------------------------------------------ */

	public static function create_penalties( WP_REST_Request $req ) {
		$user_ids = self::parse_user_ids( $req->get_param( 'user_ids' ) );
		if ( is_wp_error( $user_ids ) ) {
			return $user_ids;
		}
		$date = self::parse_date( $req->get_param( 'date' ) );
		if ( is_wp_error( $date ) ) {
			return $date;
		}
		$cents = $req->get_param( 'amount_cents' );
		if ( ! is_numeric( $cents ) || (int) $cents != $cents || (int) $cents <= 0 || (int) $cents > self::MAX_CENTS ) { // phpcs:ignore WordPress.PHP.StrictComparisons
			return self::error( 'Bitte einen Betrag zwischen 0,01 € und 1.000 € angeben.' );
		}
		$reason = sanitize_text_field( (string) $req->get_param( 'reason' ) );
		if ( '' === $reason ) {
			return self::error( 'Bitte einen Grund angeben.' );
		}
		$ids  = MKL_DB::add_penalty( $user_ids, (int) $cents, $reason, $date, get_current_user_id() );
		$rows = array_map( array( 'MKL_DB', 'get_penalty' ), $ids );
		MKL_Push::penalties_added( $rows );
		return new WP_REST_Response( $rows, 201 );
	}

	public static function update_penalty( WP_REST_Request $req ) {
		$row = MKL_DB::get_penalty( (int) $req['id'] );
		if ( ! $row || $row['deleted'] ) {
			return self::error( 'Strafe nicht gefunden.', 404 );
		}
		$status = $req->get_param( 'status' );
		if ( ! in_array( $status, array( MKL_DB::STATUS_OPEN, MKL_DB::STATUS_PAID ), true ) ) {
			return self::error( 'Ungültiger Status.' );
		}
		MKL_DB::set_penalty_status( $row['id'], $status, get_current_user_id() );
		return rest_ensure_response( MKL_DB::get_penalty( $row['id'] ) );
	}

	public static function delete_penalty( WP_REST_Request $req ) {
		$row = MKL_DB::get_penalty( (int) $req['id'] );
		if ( ! $row || $row['deleted'] ) {
			return self::error( 'Strafe nicht gefunden.', 404 );
		}
		MKL_DB::soft_delete( 'penalties', $row['id'], get_current_user_id() );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/* ------------------------------------------------------------------ */
	/* Validierung                                                         */
	/* ------------------------------------------------------------------ */

	private static function error( $message, $status = 400 ) {
		return new WP_Error( 'mkl_invalid', $message, array( 'status' => $status ) );
	}

	private static function parse_user_ids( $raw ) {
		if ( ! is_array( $raw ) || ! $raw ) {
			return self::error( 'Bitte mindestens ein Mitglied auswählen.' );
		}
		$ids = array_values( array_unique( array_map( 'absint', $raw ) ) );
		foreach ( $ids as $id ) {
			if ( ! $id || ! get_userdata( $id ) ) {
				return self::error( 'Unbekanntes Mitglied.' );
			}
			if ( ! MKL_Roles::is_member( $id ) ) {
				return self::error( 'Nur Mitglieder können Punkte oder Strafen bekommen.' );
			}
		}
		return $ids;
	}

	private static function parse_points( $raw ) {
		if ( ! is_numeric( $raw ) || (int) $raw != $raw || abs( (int) $raw ) > self::MAX_POINTS ) { // phpcs:ignore WordPress.PHP.StrictComparisons
			return self::error( 'Bitte eine ganze Punktzahl angeben.' );
		}
		return (int) $raw;
	}

	private static function parse_date( $raw ) {
		if ( null === $raw || '' === $raw ) {
			return wp_date( 'Y-m-d' );
		}
		if ( ! is_string( $raw ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return self::error( 'Ungültiges Datum.' );
		}
		return $raw;
	}
}
