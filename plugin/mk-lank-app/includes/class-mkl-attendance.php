<?php
/**
 * Anwesenheitslisten je Termin.
 *
 * Eine Liste gehört entweder zu einem Termin aus dem internen Kalender
 * (source = kalender, event_key = ID aus MKL_Calendar) oder zu einem frei
 * angelegten Termin (source = frei, event_key zufällig). Titel und Datum werden
 * als Momentaufnahme gespeichert, damit die Liste erhalten bleibt, auch wenn der
 * Termin später aus dem Kalender verschwindet oder verschoben wird.
 *
 * Punkte, die über den Vorschlag aus einer Liste gebucht werden, tragen deren ID
 * in mkl_points.attendance_id. Daran erkennt die App, wer schon Punkte hat.
 */

defined( 'ABSPATH' ) || exit;

class MKL_Attendance {

	const SOURCE_KALENDER = 'kalender';
	const SOURCE_FREI     = 'frei';

	const STATUS_ANWESEND     = 'anwesend';
	const STATUS_ENTSCHULDIGT = 'entschuldigt';
	const STATUS_FEHLT        = 'fehlt';

	const STATUSES = array( self::STATUS_ANWESEND, self::STATUS_ENTSCHULDIGT, self::STATUS_FEHLT );

	public static function lists_table() {
		return MKL_DB::table( 'attendance_lists' );
	}

	public static function entries_table() {
		return MKL_DB::table( 'attendance' );
	}

	/** Für MKL_DB::install(). */
	public static function schema( $charset ) {
		$lists   = self::lists_table();
		$entries = self::entries_table();
		return array(
			"CREATE TABLE $lists (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source varchar(10) NOT NULL,
  event_key varchar(64) NOT NULL,
  title varchar(191) NOT NULL,
  event_date date NOT NULL,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  updated_by bigint(20) unsigned NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY event (source,event_key),
  KEY event_date (event_date)
) $charset;",
			"CREATE TABLE $entries (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  list_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  status varchar(15) NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY list_user (list_id,user_id),
  KEY user_id (user_id)
) $charset;",
		);
	}

	private static function now() {
		return current_time( 'mysql', true );
	}

	/* ------------------------------------------------------------------ */
	/* Listen                                                              */
	/* ------------------------------------------------------------------ */

	public static function get( $id ) {
		global $wpdb;
		$t   = self::lists_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::format_list( $row ) : null;
	}

	public static function find( $source, $event_key ) {
		global $wpdb;
		$t   = self::lists_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE source = %s AND event_key = %s", $source, $event_key ), ARRAY_A );
		return $row ? self::format_list( $row ) : null;
	}

	/** @return array<string, int> event_key → list_id für Kalendertermine. */
	public static function list_ids_by_event_key() {
		global $wpdb;
		$t   = self::lists_table();
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, event_key FROM $t WHERE source = %s", self::SOURCE_KALENDER ), ARRAY_A ) as $r ) {
			$out[ $r['event_key'] ] = (int) $r['id'];
		}
		return $out;
	}

	/** Die letzten Listen, neueste Termine zuerst, mit Zählern. */
	public static function recent( $limit = 50 ) {
		global $wpdb;
		$t    = self::lists_table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t ORDER BY event_date DESC, id DESC LIMIT %d", $limit ), ARRAY_A );
		if ( ! $rows ) {
			return array();
		}
		$ids    = implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) );
		$e      = self::entries_table();
		$counts = array();
		foreach ( $wpdb->get_results( "SELECT list_id, status, COUNT(*) AS n FROM $e WHERE list_id IN ($ids) GROUP BY list_id, status", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$counts[ (int) $r['list_id'] ][ $r['status'] ] = (int) $r['n'];
		}
		$out = array();
		foreach ( $rows as $row ) {
			$list           = self::format_list( $row );
			$c              = isset( $counts[ $list['id'] ] ) ? $counts[ $list['id'] ] : array();
			$list['counts'] = array();
			foreach ( self::STATUSES as $status ) {
				$list['counts'][ $status ] = isset( $c[ $status ] ) ? $c[ $status ] : 0;
			}
			$out[] = $list;
		}
		return $out;
	}

	/**
	 * Legt eine Liste an oder aktualisiert sie (Titel, Datum, Stati).
	 *
	 * @param int|null $id       Bestehende Liste oder null.
	 * @param array    $entries  user_id → status (bereits geprüft).
	 * @return int|WP_Error ID der Liste.
	 */
	public static function save( $id, $source, $event_key, $title, $date, array $entries, $by ) {
		global $wpdb;
		$t   = self::lists_table();
		$now = self::now();

		if ( ! $id && self::SOURCE_KALENDER === $source ) {
			$existing = self::find( $source, $event_key );
			$id       = $existing ? $existing['id'] : null;
		}

		if ( $id ) {
			$wpdb->update(
				$t,
				array(
					'title'      => $title,
					'event_date' => $date,
					'updated_by' => $by,
					'updated_at' => $now,
				),
				array( 'id' => $id ),
				array( '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);
		} else {
			$inserted = $wpdb->insert(
				$t,
				array(
					'source'     => $source,
					'event_key'  => self::SOURCE_FREI === $source ? str_replace( '-', '', wp_generate_uuid4() ) : $event_key,
					'title'      => $title,
					'event_date' => $date,
					'created_by' => $by,
					'created_at' => $now,
					'updated_by' => $by,
					'updated_at' => $now,
				),
				array( '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s' )
			);
			if ( ! $inserted ) {
				// Gleichzeitig von jemand anderem angelegt: dessen Liste weiterverwenden.
				$existing = self::find( $source, $event_key );
				if ( ! $existing ) {
					return new WP_Error( 'mkl_invalid', 'Die Liste konnte nicht gespeichert werden.', array( 'status' => 500 ) );
				}
				$id = $existing['id'];
			} else {
				$id = (int) $wpdb->insert_id;
			}
		}

		$e       = self::entries_table();
		$current = self::statuses( $id );
		foreach ( $entries as $user_id => $status ) {
			if ( ! isset( $current[ $user_id ] ) ) {
				$wpdb->insert(
					$e,
					array(
						'list_id' => $id,
						'user_id' => $user_id,
						'status'  => $status,
					),
					array( '%d', '%d', '%s' )
				);
			} elseif ( $current[ $user_id ] !== $status ) {
				$wpdb->update( $e, array( 'status' => $status ), array( 'list_id' => $id, 'user_id' => $user_id ), array( '%s' ), array( '%d', '%d' ) );
			}
		}
		return (int) $id;
	}

	/** Löscht eine Liste. Gebuchte Punkte bleiben, verlieren aber den Bezug. */
	public static function delete( $id ) {
		global $wpdb;
		if ( ! $wpdb->delete( self::lists_table(), array( 'id' => $id ), array( '%d' ) ) ) {
			return false;
		}
		$wpdb->delete( self::entries_table(), array( 'list_id' => $id ), array( '%d' ) );
		$p = MKL_DB::table( 'points' );
		$wpdb->query( $wpdb->prepare( "UPDATE $p SET attendance_id = NULL WHERE attendance_id = %d", $id ) );
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Einträge                                                            */
	/* ------------------------------------------------------------------ */

	/** @return array<int, string> user_id → status */
	public static function statuses( $list_id ) {
		global $wpdb;
		$e   = self::entries_table();
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT user_id, status FROM $e WHERE list_id = %d", $list_id ), ARRAY_A ) as $r ) {
			$out[ (int) $r['user_id'] ] = $r['status'];
		}
		return $out;
	}

	/** @return int[] Benutzer mit nicht stornierten Punkten aus dieser Liste. */
	public static function booked_user_ids( $list_id ) {
		global $wpdb;
		$p = MKL_DB::table( 'points' );
		return array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM $p WHERE attendance_id = %d AND deleted_at IS NULL", $list_id ) )
		);
	}

	/** Eigene Anwesenheit, neueste Termine zuerst. */
	public static function for_user( $user_id ) {
		global $wpdb;
		$t    = self::lists_table();
		$e    = self::entries_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id, l.title, l.event_date, a.status FROM $e a JOIN $t l ON l.id = a.list_id WHERE a.user_id = %d ORDER BY l.event_date DESC, l.id DESC", // phpcs:ignore WordPress.DB.PreparedSQL
				$user_id
			),
			ARRAY_A
		);
		return array_map(
			function ( $r ) {
				return array(
					'list_id' => (int) $r['id'],
					'title'   => $r['title'],
					'date'    => $r['event_date'],
					'status'  => $r['status'],
				);
			},
			$rows
		);
	}

	private static function format_list( array $r ) {
		$by = get_userdata( (int) $r['updated_by'] );
		return array(
			'id'              => (int) $r['id'],
			'source'          => $r['source'],
			'event_key'       => $r['event_key'],
			'title'           => $r['title'],
			'date'            => $r['event_date'],
			'updated_at'      => gmdate( 'c', strtotime( $r['updated_at'] . ' UTC' ) ),
			'updated_by_name' => $by ? $by->display_name : null,
		);
	}
}
