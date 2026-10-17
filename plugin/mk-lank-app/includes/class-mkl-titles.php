<?php
/**
 * Titel: Ehrentitel und Ämter wie Kapitän, Ehrenkapitän, Ehrenspieß oder Ehrenmitglied.
 *
 * Grundsätze:
 * - Titel sind unabhängig vom Rang (Dienstgrad) und von Punkten. Sie werden zusätzlich
 *   zum Rang angezeigt.
 * - Die Titelliste pflegen und Titel zuweisen dürfen Spieße und Admins (mkl_manage).
 * - Ein Mitglied kann mehrere Titel haben. Zugewiesene Titel werden als User-Meta
 *   gespeichert (ein Eintrag je Titel).
 * - Zugewiesene Titel lassen sich nicht löschen, nur umbenennen.
 */

defined( 'ABSPATH' ) || exit;

class MKL_Titles {

	/** User-Meta mit der ID eines zugewiesenen Titels; mehrfach vorhanden bei mehreren Titeln. */
	const META = 'mkl_title_id';

	/** Werden beim ersten Anlegen der Tabelle eingetragen. */
	const DEFAULTS = array( 'Kapitän', 'Ehrenkapitän', 'Ehrenspieß', 'Ehrenmitglied' );

	public static function table() {
		return MKL_DB::table( 'titles' );
	}

	/** Trägt die Standardtitel ein, solange die Liste leer ist. */
	public static function seed_defaults() {
		global $wpdb;
		$t = self::table();
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ) > 0 ) { // phpcs:ignore WordPress.DB.PreparedSQL
			return;
		}
		foreach ( self::DEFAULTS as $i => $title ) {
			$wpdb->insert( $t, array( 'title' => $title, 'sort' => $i + 1 ), array( '%s', '%d' ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Titelliste                                                          */
	/* ------------------------------------------------------------------ */

	public static function all() {
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_results( "SELECT * FROM $t ORDER BY sort ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_map( array( __CLASS__, 'format' ), $rows );
	}

	public static function get( $id ) {
		global $wpdb;
		$t   = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $row ? self::format( $row ) : null;
	}

	/** @return int|WP_Error ID des neuen Titels. */
	public static function insert( $title ) {
		global $wpdb;
		$valid = self::validate( $title, 0 );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$t    = self::table();
		$sort = (int) $wpdb->get_var( "SELECT COALESCE(MAX(sort), 0) FROM $t" ) + 1; // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->insert( $t, array( 'title' => $title, 'sort' => $sort ), array( '%s', '%d' ) );
		return (int) $wpdb->insert_id;
	}

	/** @return true|WP_Error */
	public static function rename( $id, $title ) {
		global $wpdb;
		if ( ! self::get( $id ) ) {
			return self::error( 'Titel nicht gefunden.', 404 );
		}
		$valid = self::validate( $title, $id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$wpdb->update( self::table(), array( 'title' => $title ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		return true;
	}

	/** @return true|WP_Error */
	public static function delete( $id ) {
		global $wpdb;
		if ( ! self::get( $id ) ) {
			return self::error( 'Titel nicht gefunden.', 404 );
		}
		$count = self::member_count( $id );
		if ( $count > 0 ) {
			return self::error(
				sprintf(
					'Der Titel ist noch %s zugewiesen. Bitte zuerst dort entfernen.',
					1 === $count ? 'einem Mitglied' : $count . ' Mitgliedern'
				)
			);
		}
		$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
		return true;
	}

	/** Wie vielen Konten der Titel zugewiesen ist. */
	public static function member_count( $id ) {
		$count = 0;
		foreach ( self::title_ids_by_user() as $ids ) {
			if ( in_array( (int) $id, $ids, true ) ) {
				$count++;
			}
		}
		return $count;
	}

	/** @return true|WP_Error */
	private static function validate( $title, $exclude_id ) {
		global $wpdb;
		if ( '' === $title ) {
			return self::error( 'Bitte eine Bezeichnung angeben.' );
		}
		if ( mb_strlen( $title ) > 191 ) {
			return self::error( 'Die Bezeichnung ist zu lang.' );
		}
		$t     = self::table();
		$other = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM $t WHERE title = %s AND id <> %d LIMIT 1", $title, $exclude_id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
		if ( null !== $other ) {
			return self::error( sprintf( 'Den Titel „%s“ gibt es schon.', $title ) );
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Zugewiesene Titel                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Zugewiesene Titel aller Konten in einer Abfrage.
	 *
	 * @return array<int, int[]> user_id => title_ids
	 */
	public static function title_ids_by_user() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT user_id, meta_value FROM $wpdb->usermeta WHERE meta_key = %s", self::META ),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $r ) {
			if ( (int) $r['meta_value'] ) {
				$out[ (int) $r['user_id'] ][] = (int) $r['meta_value'];
			}
		}
		return $out;
	}

	/**
	 * Titel eines Kontos in der Reihenfolge der Titelliste.
	 *
	 * @param int[]|null $ids   Zugewiesene IDs; null = aus der Datenbank lesen.
	 * @param array|null $all   Titelliste; null = aus der Datenbank lesen.
	 * @return array<int, array{id:int, title:string}>
	 */
	public static function for_user( $user_id, $ids = null, $all = null ) {
		if ( null === $ids ) {
			$ids = array_map( 'intval', get_user_meta( $user_id, self::META, false ) );
		}
		if ( ! $ids ) {
			return array();
		}
		$out = array();
		foreach ( null === $all ? self::all() : $all as $t ) {
			if ( in_array( $t['id'], $ids, true ) ) {
				$out[] = self::ref( $t );
			}
		}
		return $out;
	}

	/**
	 * Ersetzt die Titel eines Kontos.
	 *
	 * @param int[] $title_ids
	 * @return true|WP_Error
	 */
	public static function set_for_user( $user_id, array $title_ids ) {
		$ids = array_values( array_unique( array_map( 'absint', $title_ids ) ) );
		foreach ( $ids as $id ) {
			if ( ! $id || ! self::get( $id ) ) {
				return self::error( 'Titel nicht gefunden.' );
			}
		}
		delete_user_meta( $user_id, self::META );
		foreach ( $ids as $id ) {
			add_user_meta( $user_id, self::META, $id );
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Hilfsfunktionen                                                     */
	/* ------------------------------------------------------------------ */

	public static function ref( $title ) {
		return array(
			'id'    => $title['id'],
			'title' => $title['title'],
		);
	}

	public static function format( array $r ) {
		return array(
			'id'    => (int) $r['id'],
			'title' => $r['title'],
			'sort'  => (int) $r['sort'],
		);
	}

	private static function error( $message, $status = 400 ) {
		return new WP_Error( 'mkl_invalid', $message, array( 'status' => $status ) );
	}
}
