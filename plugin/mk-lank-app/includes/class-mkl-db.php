<?php
/**
 * Datenbanktabellen und Datenzugriff.
 *
 * Geldbeträge werden ausschließlich als Ganzzahl in Cent gespeichert.
 * Einträge werden nie physisch gelöscht, sondern über deleted_at storniert.
 */

defined( 'ABSPATH' ) || exit;

class MKL_DB {

	const STATUS_OPEN = 'open';
	const STATUS_PAID = 'paid';

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'mkl_' . $name;
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$catalog = self::table( 'point_catalog' );
		$points  = self::table( 'points' );
		$pen     = self::table( 'penalties' );

		dbDelta(
			"CREATE TABLE $catalog (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(191) NOT NULL,
  points int(11) NOT NULL DEFAULT 0,
  active tinyint(1) NOT NULL DEFAULT 1,
  sort int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id)
) $charset;"
		);

		dbDelta(
			"CREATE TABLE $points (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  points int(11) NOT NULL,
  reason varchar(255) NOT NULL DEFAULT '',
  catalog_id bigint(20) unsigned DEFAULT NULL,
  entry_date date NOT NULL,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  deleted_by bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id)
) $charset;"
		);

		dbDelta(
			"CREATE TABLE $pen (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  amount_cents int(11) NOT NULL,
  reason varchar(255) NOT NULL DEFAULT '',
  entry_date date NOT NULL,
  status varchar(10) NOT NULL DEFAULT 'open',
  paid_at datetime DEFAULT NULL,
  paid_marked_by bigint(20) unsigned DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  deleted_by bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id)
) $charset;"
		);

		$events = self::table( 'events' );
		dbDelta(
			"CREATE TABLE $events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(191) NOT NULL,
  start_date date NOT NULL,
  start_time time DEFAULT NULL,
  end_date date DEFAULT NULL,
  end_time time DEFAULT NULL,
  location varchar(191) NOT NULL DEFAULT '',
  description text NOT NULL,
  url varchar(255) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY start_date (start_date)
) $charset;"
		);

		$albums = self::table( 'albums' );
		dbDelta(
			"CREATE TABLE $albums (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  drive_id varchar(64) NOT NULL,
  year varchar(20) NOT NULL DEFAULT '',
  title varchar(191) NOT NULL DEFAULT '',
  sort_date datetime NOT NULL,
  synced_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY drive_id (drive_id)
) $charset;"
		);

		$photos = self::table( 'photos' );
		dbDelta(
			"CREATE TABLE $photos (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  album_id bigint(20) unsigned NOT NULL,
  drive_file_id varchar(64) NOT NULL,
  name varchar(255) NOT NULL DEFAULT '',
  is_intern tinyint(1) NOT NULL DEFAULT 0,
  width int(11) NOT NULL DEFAULT 0,
  height int(11) NOT NULL DEFAULT 0,
  taken_at datetime DEFAULT NULL,
  md5 varchar(64) NOT NULL DEFAULT '',
  file_key char(32) NOT NULL,
  status varchar(10) NOT NULL DEFAULT 'pending',
  error varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY drive_file_id (drive_file_id),
  KEY album_id (album_id),
  KEY status (status)
) $charset;"
		);

		$ranks = self::table( 'ranks' );
		dbDelta(
			"CREATE TABLE $ranks (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(191) NOT NULL,
  min_points int(11) NOT NULL DEFAULT 0,
  active tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id)
) $charset;"
		);

		$history = self::table( 'rank_history' );
		dbDelta(
			"CREATE TABLE $history (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  from_rank_id bigint(20) unsigned DEFAULT NULL,
  to_rank_id bigint(20) unsigned DEFAULT NULL,
  from_title varchar(191) NOT NULL DEFAULT '',
  to_title varchar(191) NOT NULL DEFAULT '',
  kind varchar(15) NOT NULL DEFAULT '',
  points_at_change int(11) NOT NULL DEFAULT 0,
  reason varchar(255) NOT NULL DEFAULT '',
  changed_by bigint(20) unsigned NOT NULL,
  changed_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id)
) $charset;"
		);

		update_option( 'mkl_db_version', MKL_DB_VERSION );
	}

	/**
	 * Läuft bei jedem Seitenaufruf, tut aber nur nach einem Plugin-Update etwas.
	 * Ein Update per ZIP-Upload löst keinen Aktivierungs-Hook aus, deshalb werden
	 * auch Rollen und Migrationen hier nachgezogen.
	 */
	public static function maybe_upgrade() {
		$installed = get_option( 'mkl_db_version' );
		if ( MKL_DB_VERSION === $installed ) {
			return;
		}
		self::install();
		MKL_Roles::install();
		if ( $installed && version_compare( $installed, '2', '<' ) ) {
			MKL_Roles::migrate_subscribers_to_members();
		}
	}

	private static function now() {
		return current_time( 'mysql', true );
	}

	/* ------------------------------------------------------------------ */
	/* Punkte                                                              */
	/* ------------------------------------------------------------------ */

	public static function get_points_for_user( $user_id ) {
		global $wpdb;
		$t    = self::table( 'points' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $t WHERE user_id = %d AND deleted_at IS NULL ORDER BY entry_date DESC, id DESC",
				$user_id
			),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'format_points_row' ), $rows );
	}

	public static function get_points_total( $user_id ) {
		global $wpdb;
		$t = self::table( 'points' );
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(points), 0) FROM $t WHERE user_id = %d AND deleted_at IS NULL", $user_id )
		);
	}

	/**
	 * Alle gültigen Buchungen, chronologisch je Benutzer (für die Rang-Auswertung).
	 *
	 * @return array<int, array<int, array{points:int, date:string}>>
	 */
	public static function get_point_timeline( $user_id = null ) {
		global $wpdb;
		$t     = self::table( 'points' );
		$where = $user_id ? $wpdb->prepare( 'AND user_id = %d', $user_id ) : '';
		$rows  = $wpdb->get_results( "SELECT user_id, points, entry_date FROM $t WHERE deleted_at IS NULL $where ORDER BY user_id ASC, entry_date ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out   = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['user_id'] ][] = array(
				'points' => (int) $r['points'],
				'date'   => $r['entry_date'],
			);
		}
		return $out;
	}

	public static function get_points_row( $id ) {
		global $wpdb;
		$t   = self::table( 'points' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::format_points_row( $row ) : null;
	}

	/**
	 * Legt für jeden Benutzer einen Punkte-Eintrag an.
	 *
	 * @return int[] IDs der angelegten Einträge.
	 */
	public static function add_points( array $user_ids, $points, $reason, $catalog_id, $entry_date, $created_by ) {
		global $wpdb;
		$t   = self::table( 'points' );
		$ids = array();
		foreach ( $user_ids as $user_id ) {
			$wpdb->insert(
				$t,
				array(
					'user_id'    => $user_id,
					'points'     => $points,
					'reason'     => $reason,
					'catalog_id' => $catalog_id,
					'entry_date' => $entry_date,
					'created_by' => $created_by,
					'created_at' => self::now(),
				),
				array( '%d', '%d', '%s', '%d', '%s', '%d', '%s' )
			);
			$ids[] = (int) $wpdb->insert_id;
		}
		return $ids;
	}

	/* ------------------------------------------------------------------ */
	/* Strafen                                                             */
	/* ------------------------------------------------------------------ */

	public static function get_penalties_for_user( $user_id ) {
		global $wpdb;
		$t    = self::table( 'penalties' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $t WHERE user_id = %d AND deleted_at IS NULL ORDER BY entry_date DESC, id DESC",
				$user_id
			),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'format_penalty_row' ), $rows );
	}

	public static function get_open_penalties_cents( $user_id ) {
		global $wpdb;
		$t = self::table( 'penalties' );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount_cents), 0) FROM $t WHERE user_id = %d AND status = %s AND deleted_at IS NULL",
				$user_id,
				self::STATUS_OPEN
			)
		);
	}

	public static function get_penalty( $id ) {
		global $wpdb;
		$t   = self::table( 'penalties' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::format_penalty_row( $row ) : null;
	}

	/** @return int[] IDs der angelegten Strafen. */
	public static function add_penalty( array $user_ids, $amount_cents, $reason, $entry_date, $created_by ) {
		global $wpdb;
		$t   = self::table( 'penalties' );
		$ids = array();
		foreach ( $user_ids as $user_id ) {
			$wpdb->insert(
				$t,
				array(
					'user_id'      => $user_id,
					'amount_cents' => $amount_cents,
					'reason'       => $reason,
					'entry_date'   => $entry_date,
					'status'       => self::STATUS_OPEN,
					'created_by'   => $created_by,
					'created_at'   => self::now(),
				),
				array( '%d', '%d', '%s', '%s', '%s', '%d', '%s' )
			);
			$ids[] = (int) $wpdb->insert_id;
		}
		return $ids;
	}

	public static function set_penalty_status( $id, $status, $by ) {
		global $wpdb;
		$paid = self::STATUS_PAID === $status;
		return false !== $wpdb->update(
			self::table( 'penalties' ),
			array(
				'status'         => $status,
				'paid_at'        => $paid ? self::now() : null,
				'paid_marked_by' => $paid ? $by : null,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);
	}

	/** Storniert einen Eintrag (points|penalties). */
	public static function soft_delete( $table, $id, $by ) {
		global $wpdb;
		return (bool) $wpdb->update(
			self::table( $table ),
			array(
				'deleted_at' => self::now(),
				'deleted_by' => $by,
			),
			array( 'id' => $id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Mitgliederübersicht                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Summen je Benutzer für die Spieß-Übersicht.
	 *
	 * @return array<int, array{points:int, open_cents:int}>
	 */
	public static function get_totals_by_user() {
		global $wpdb;
		$p   = self::table( 'points' );
		$s   = self::table( 'penalties' );
		$out = array();

		$rows = $wpdb->get_results( "SELECT user_id, SUM(points) AS total FROM $p WHERE deleted_at IS NULL GROUP BY user_id", ARRAY_A );
		foreach ( $rows as $r ) {
			$out[ (int) $r['user_id'] ]['points'] = (int) $r['total'];
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, SUM(amount_cents) AS total FROM $s WHERE deleted_at IS NULL AND status = %s GROUP BY user_id",
				self::STATUS_OPEN
			),
			ARRAY_A
		);
		foreach ( $rows as $r ) {
			$out[ (int) $r['user_id'] ]['open_cents'] = (int) $r['total'];
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Punktekatalog                                                       */
	/* ------------------------------------------------------------------ */

	public static function get_catalog( $only_active = true ) {
		global $wpdb;
		$t     = self::table( 'point_catalog' );
		$where = $only_active ? 'WHERE active = 1' : '';
		$rows  = $wpdb->get_results( "SELECT * FROM $t $where ORDER BY sort ASC, title ASC", ARRAY_A );
		return array_map( array( __CLASS__, 'format_catalog_row' ), $rows );
	}

	public static function get_catalog_item( $id ) {
		global $wpdb;
		$t   = self::table( 'point_catalog' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::format_catalog_row( $row ) : null;
	}

	public static function insert_catalog_item( $title, $points, $sort ) {
		global $wpdb;
		$wpdb->insert(
			self::table( 'point_catalog' ),
			array(
				'title'  => $title,
				'points' => $points,
				'active' => 1,
				'sort'   => $sort,
			),
			array( '%s', '%d', '%d', '%d' )
		);
		return (int) $wpdb->insert_id;
	}

	public static function update_catalog_item( $id, array $data ) {
		global $wpdb;
		$formats = array();
		foreach ( $data as $key => $value ) {
			$formats[] = 'title' === $key ? '%s' : '%d';
		}
		return false !== $wpdb->update( self::table( 'point_catalog' ), $data, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Formatierung                                                        */
	/* ------------------------------------------------------------------ */

	private static function user_name( $user_id ) {
		static $cache = array();
		if ( ! $user_id ) {
			return null;
		}
		if ( ! array_key_exists( $user_id, $cache ) ) {
			$u                  = get_userdata( $user_id );
			$cache[ $user_id ] = $u ? $u->display_name : null;
		}
		return $cache[ $user_id ];
	}

	public static function format_points_row( array $r ) {
		return array(
			'id'              => (int) $r['id'],
			'user_id'         => (int) $r['user_id'],
			'points'          => (int) $r['points'],
			'reason'          => $r['reason'],
			'catalog_id'      => $r['catalog_id'] ? (int) $r['catalog_id'] : null,
			'date'            => $r['entry_date'],
			'created_by_name' => self::user_name( (int) $r['created_by'] ),
			'deleted'         => ! empty( $r['deleted_at'] ),
		);
	}

	public static function format_penalty_row( array $r ) {
		return array(
			'id'              => (int) $r['id'],
			'user_id'         => (int) $r['user_id'],
			'amount_cents'    => (int) $r['amount_cents'],
			'reason'          => $r['reason'],
			'date'            => $r['entry_date'],
			'status'          => $r['status'],
			'paid_at'         => $r['paid_at'] ? gmdate( 'c', strtotime( $r['paid_at'] . ' UTC' ) ) : null,
			'created_by_name' => self::user_name( (int) $r['created_by'] ),
			'deleted'         => ! empty( $r['deleted_at'] ),
		);
	}

	public static function format_catalog_row( array $r ) {
		return array(
			'id'     => (int) $r['id'],
			'title'  => $r['title'],
			'points' => (int) $r['points'],
			'active' => (bool) (int) $r['active'],
			'sort'   => (int) $r['sort'],
		);
	}
}
