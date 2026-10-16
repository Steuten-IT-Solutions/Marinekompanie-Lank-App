<?php
/**
 * REST-API unter /wp-json/mk-lank/v1/.
 *
 * Grundsatz: Normale Mitglieder sehen ausschließlich ihre eigenen Daten.
 * Die /me-Routen lesen die Benutzer-ID immer aus der Sitzung und nehmen
 * keinen user_id-Parameter entgegen. Alles andere erfordert mkl_manage.
 */

defined( 'ABSPATH' ) || exit;

class MKL_REST {

	const NS = 'mk-lank/v1';

	const MAX_POINTS = 10000;
	/** Höchstbetrag einer einzelnen Strafe: 1.000 € */
	const MAX_CENTS = 100000;

	public static function register_routes() {
		$logged_in = array( __CLASS__, 'can_read' );
		$manage    = array( __CLASS__, 'can_manage' );
		$id_arg    = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);

		register_rest_route( self::NS, '/me', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_me' ), 'permission_callback' => $logged_in ) );
		register_rest_route( self::NS, '/me/points', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_my_points' ), 'permission_callback' => $logged_in ) );
		register_rest_route( self::NS, '/me/penalties', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_my_penalties' ), 'permission_callback' => $logged_in ) );
		register_rest_route( self::NS, '/calendar', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_calendar' ), 'permission_callback' => $logged_in ) );

		register_rest_route(
			self::NS,
			'/catalog',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_catalog' ), 'permission_callback' => $logged_in ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_catalog_item' ), 'permission_callback' => $manage ),
			)
		);
		register_rest_route( self::NS, '/catalog/(?P<id>\d+)', array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, 'update_catalog_item' ), 'permission_callback' => $manage, 'args' => $id_arg ) );

		register_rest_route( self::NS, '/members', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_members' ), 'permission_callback' => $manage ) );
		register_rest_route( self::NS, '/members/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_member' ), 'permission_callback' => $manage, 'args' => $id_arg ) );

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

	/* ------------------------------------------------------------------ */
	/* Eigene Daten                                                        */
	/* ------------------------------------------------------------------ */

	public static function get_me() {
		$user = wp_get_current_user();
		return rest_ensure_response(
			array(
				'id'         => $user->ID,
				'name'       => $user->display_name,
				'is_spiess'  => MKL_Roles::is_spiess(),
				'points'     => MKL_DB::get_points_total( $user->ID ),
				'open_cents' => MKL_DB::get_open_penalties_cents( $user->ID ),
			)
		);
	}

	public static function get_my_points() {
		return rest_ensure_response( MKL_DB::get_points_for_user( get_current_user_id() ) );
	}

	public static function get_my_penalties() {
		return rest_ensure_response( MKL_DB::get_penalties_for_user( get_current_user_id() ) );
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

	public static function get_members() {
		$totals = MKL_DB::get_totals_by_user();
		$out    = array();
		foreach ( self::member_users() as $u ) {
			$id    = (int) $u->ID;
			$out[] = array(
				'id'         => $id,
				'name'       => $u->display_name,
				'is_spiess'  => MKL_Roles::is_spiess( $id ),
				'points'     => isset( $totals[ $id ]['points'] ) ? $totals[ $id ]['points'] : 0,
				'open_cents' => isset( $totals[ $id ]['open_cents'] ) ? $totals[ $id ]['open_cents'] : 0,
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
				'id'         => $user->ID,
				'name'       => $user->display_name,
				'is_spiess'  => MKL_Roles::is_spiess( $user->ID ),
				'points'     => MKL_DB::get_points_total( $user->ID ),
				'open_cents' => MKL_DB::get_open_penalties_cents( $user->ID ),
				'point_list' => MKL_DB::get_points_for_user( $user->ID ),
				'penalties'  => MKL_DB::get_penalties_for_user( $user->ID ),
			)
		);
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

		$ids = MKL_DB::add_points( $user_ids, $points, $reason, $catalog_id, $date, get_current_user_id() );
		return new WP_REST_Response( array_map( array( 'MKL_DB', 'get_points_row' ), $ids ), 201 );
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
		$ids = MKL_DB::add_penalty( $user_ids, (int) $cents, $reason, $date, get_current_user_id() );
		return new WP_REST_Response( array_map( array( 'MKL_DB', 'get_penalty' ), $ids ), 201 );
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
