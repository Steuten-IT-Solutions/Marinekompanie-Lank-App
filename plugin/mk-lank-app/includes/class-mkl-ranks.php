<?php
/**
 * Dienstgrade: Liste mit Punkteschwellen, zugewiesener Dienstgrad je Mitglied und Verlauf.
 *
 * Grundsätze:
 * - Den Dienstgrad setzen die Spieße (Beförderung/Degradierung). Er wird als User-Meta
 *   gespeichert und ändert sich nie automatisch, auch nicht durch Punkte.
 * - Befördern nur, wenn die Gesamtpunkte die Schwelle des Zieldienstgrads erreichen.
 *   Dienstgrade überspringen ist erlaubt.
 * - Degradieren nur, wenn die Gesamtpunkte unter der Schwelle des aktuellen Dienstgrads liegen.
 * - Die Regeln gelten für alle, auch für Administratoren.
 * - Nächster Dienstgrad, fehlende Punkte und „Schwelle erreicht seit“ werden berechnet,
 *   nicht gespeichert.
 */

defined( 'ABSPATH' ) || exit;

class MKL_Ranks {

	/** User-Meta mit der ID des zugewiesenen Dienstgrads. Fehlt sie, hat das Mitglied keinen Dienstgrad. */
	const META = 'mkl_rank_id';

	const MAX_MIN_POINTS = 1000000;

	const KIND_PROMOTION = 'befoerderung';
	const KIND_DEMOTION  = 'degradierung';

	public static function table() {
		return MKL_DB::table( 'ranks' );
	}

	public static function history_table() {
		return MKL_DB::table( 'rank_history' );
	}

	/* ------------------------------------------------------------------ */
	/* Dienstgrade                                                           */
	/* ------------------------------------------------------------------ */

	/** Dienstgrade aufsteigend nach Schwelle. */
	public static function all( $only_active = true ) {
		global $wpdb;
		$t     = self::table();
		$where = $only_active ? 'WHERE active = 1' : '';
		$rows  = $wpdb->get_results( "SELECT * FROM $t $where ORDER BY min_points ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_map( array( __CLASS__, 'format' ), $rows );
	}

	public static function get( $id ) {
		global $wpdb;
		$t   = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $row ? self::format( $row ) : null;
	}

	/** @return int|WP_Error ID des neuen Dienstgrads. */
	public static function insert( $title, $min_points ) {
		global $wpdb;
		$valid = self::validate( $title, $min_points, true, 0 );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$wpdb->insert(
			self::table(),
			array(
				'title'      => $title,
				'min_points' => $min_points,
				'active'     => 1,
			),
			array( '%s', '%d', '%d' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array $data title, min_points und/oder active.
	 * @return true|WP_Error
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$rank = self::get( $id );
		if ( ! $rank ) {
			return self::error( 'Dienstgrad nicht gefunden.', 404 );
		}
		$merged = array_merge( $rank, $data );
		$valid  = self::validate( $merged['title'], $merged['min_points'], $merged['active'], $id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$wpdb->update(
			self::table(),
			array(
				'title'      => $merged['title'],
				'min_points' => $merged['min_points'],
				'active'     => $merged['active'] ? 1 : 0,
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%d' ),
			array( '%d' )
		);
		return true;
	}

	/**
	 * Löscht einen Dienstgrad, der niemandem zugewiesen ist. Zugewiesene Dienstgrade
	 * lassen sich nur deaktivieren.
	 *
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;
		if ( ! self::get( $id ) ) {
			return self::error( 'Dienstgrad nicht gefunden.', 404 );
		}
		$count = self::member_count( $id );
		if ( $count > 0 ) {
			return self::error(
				sprintf(
					'Der Dienstgrad ist noch %s zugewiesen. Bitte stattdessen deaktivieren.',
					1 === $count ? 'einem Mitglied' : $count . ' Mitgliedern'
				)
			);
		}
		$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
		return true;
	}

	/** Wie vielen Konten der Dienstgrad zugewiesen ist. */
	public static function member_count( $id ) {
		$counts = array_count_values( self::rank_ids_by_user() );
		return isset( $counts[ (int) $id ] ) ? $counts[ (int) $id ] : 0;
	}

	/** @return true|WP_Error */
	private static function validate( $title, $min_points, $active, $exclude_id ) {
		global $wpdb;
		if ( '' === $title ) {
			return self::error( 'Bitte eine Bezeichnung angeben.' );
		}
		if ( mb_strlen( $title ) > 191 ) {
			return self::error( 'Die Bezeichnung ist zu lang.' );
		}
		if ( ! is_int( $min_points ) || $min_points < 0 || $min_points > self::MAX_MIN_POINTS ) {
			return self::error( 'Bitte eine ganze Punktzahl ab 0 als Schwelle angeben.' );
		}
		if ( $active ) {
			$t     = self::table();
			$other = $wpdb->get_var(
				$wpdb->prepare( "SELECT title FROM $t WHERE active = 1 AND min_points = %d AND id <> %d LIMIT 1", $min_points, $exclude_id ) // phpcs:ignore WordPress.DB.PreparedSQL
			);
			if ( null !== $other ) {
				return self::error( sprintf( 'Der Dienstgrad „%s“ hat schon die Schwelle %d Punkte.', $other, $min_points ) );
			}
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Zugewiesener Dienstgrad                                                   */
	/* ------------------------------------------------------------------ */

	/** @return int|null ID des zugewiesenen Dienstgrads. */
	public static function user_rank_id( $user_id ) {
		$id = (int) get_user_meta( $user_id, self::META, true );
		return $id && self::get( $id ) ? $id : null;
	}

	/**
	 * Zugewiesene Dienstgrade aller Konten in einer Abfrage.
	 *
	 * @return array<int, int> user_id => rank_id
	 */
	public static function rank_ids_by_user() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT user_id, meta_value FROM $wpdb->usermeta WHERE meta_key = %s", self::META ),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $r ) {
			if ( (int) $r['meta_value'] ) {
				$out[ (int) $r['user_id'] ] = (int) $r['meta_value'];
			}
		}
		return $out;
	}

	/**
	 * Prüft eine Dienstgradänderung nach den Regeln (gilt auch für Administratoren).
	 *
	 * @param int|null $target_id Neuer Dienstgrad, null = kein Dienstgrad.
	 * @return array{current: array|null, target: array|null, kind: string}|WP_Error
	 */
	public static function validate_change( $user_id, $target_id, $points ) {
		$current_id = self::user_rank_id( $user_id );
		$current    = $current_id ? self::get( $current_id ) : null;
		$target     = null;
		if ( $target_id ) {
			$target = self::get( $target_id );
			if ( ! $target ) {
				return self::error( 'Dienstgrad nicht gefunden.' );
			}
			if ( ! $target['active'] ) {
				return self::error( sprintf( 'Der Dienstgrad „%s“ ist deaktiviert.', $target['title'] ) );
			}
		}
		if ( ( $target ? $target['id'] : null ) === $current_id ) {
			return self::error( $target ? sprintf( 'Das Mitglied hat bereits den Dienstgrad „%s“.', $target['title'] ) : 'Das Mitglied hat bereits keinen Dienstgrad.' );
		}

		$promotion = $target && ( ! $current || $target['min_points'] >= $current['min_points'] );
		if ( $promotion ) {
			if ( $points < $target['min_points'] ) {
				return self::error(
					sprintf(
						'Die Schwelle für „%s“ ist noch nicht erreicht: Es fehlen %s Punkte.',
						$target['title'],
						number_format_i18n( $target['min_points'] - $points )
					)
				);
			}
		} elseif ( $current && $points >= $current['min_points'] ) {
			return self::error(
				sprintf(
					'Degradieren ist nur möglich, wenn die Punkte unter der Schwelle von „%s“ (%s Punkte) liegen.',
					$current['title'],
					number_format_i18n( $current['min_points'] )
				)
			);
		}
		return array(
			'current' => $current,
			'target'  => $target,
			'kind'    => $promotion ? self::KIND_PROMOTION : self::KIND_DEMOTION,
		);
	}

	/**
	 * Setzt den Dienstgrad und schreibt den Verlauf. Vorher validate_change() aufrufen.
	 *
	 * @return bool
	 */
	public static function set_rank( $user_id, $current, $target, $kind, $points, $reason, $by ) {
		global $wpdb;
		if ( $target ) {
			update_user_meta( $user_id, self::META, $target['id'] );
		} else {
			delete_user_meta( $user_id, self::META );
		}
		return (bool) $wpdb->insert(
			self::history_table(),
			array(
				'user_id'          => $user_id,
				'from_rank_id'     => $current ? $current['id'] : null,
				'to_rank_id'       => $target ? $target['id'] : null,
				'from_title'       => $current ? $current['title'] : '',
				'to_title'         => $target ? $target['title'] : '',
				'kind'             => $kind,
				'points_at_change' => $points,
				'reason'           => $reason,
				'changed_by'       => $by,
				'changed_at'       => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s' )
		);
	}

	/** Verlauf eines Mitglieds, neueste Änderung zuerst. */
	public static function history( $user_id ) {
		global $wpdb;
		$t    = self::history_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $t WHERE user_id = %d ORDER BY changed_at DESC, id DESC", $user_id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		return array_map(
			function ( $r ) {
				$from = '' !== $r['from_title'] ? $r['from_title'] : null;
				$to   = '' !== $r['to_title'] ? $r['to_title'] : null;
				$by   = get_userdata( (int) $r['changed_by'] );
				return array(
					'id'              => (int) $r['id'],
					'from_title'      => $from,
					'to_title'        => $to,
					'kind'            => self::KIND_DEMOTION === $r['kind'] ? self::KIND_DEMOTION : self::KIND_PROMOTION,
					'points'          => (int) $r['points_at_change'],
					'reason'          => $r['reason'],
					'changed_at'      => gmdate( 'c', strtotime( $r['changed_at'] . ' UTC' ) ),
					'changed_by_name' => $by ? $by->display_name : null,
				);
			},
			$rows
		);
	}

	/* ------------------------------------------------------------------ */
	/* Auswertung                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Dienstgrad-Stand eines Mitglieds.
	 *
	 * @param int|null   $rank_id   Zugewiesener Dienstgrad.
	 * @param int        $points    Gesamtpunkte.
	 * @param array      $timeline  Buchungen chronologisch (points, date).
	 * @param array      $ranks     Alle Dienstgrade (auch inaktive), aufsteigend nach Schwelle.
	 * @param string|null $joined   Datum der Anlage (Y-m-d), falls eine Schwelle von 0 Punkten gilt.
	 */
	public static function status( $rank_id, $points, array $timeline, array $ranks, $joined = null ) {
		$current   = null;
		$next      = null;
		$reachable = null;
		foreach ( $ranks as $r ) {
			if ( $r['id'] === $rank_id ) {
				$current = $r;
			}
		}
		$floor = $current ? $current['min_points'] : -1;
		foreach ( $ranks as $r ) {
			if ( ! $r['active'] ) {
				continue;
			}
			if ( ! $next && $r['min_points'] > $floor ) {
				$next = $r;
			}
			if ( $r['min_points'] <= $points ) {
				$reachable = $r;
			}
		}
		$to_next = $next ? $next['min_points'] - $points : null;
		$since   = null;
		if ( null !== $to_next && $to_next <= 0 ) {
			$since = self::threshold_reached_since( $timeline, $next['min_points'] );
			if ( null === $since ) {
				$since = $joined;
			}
		}
		return array(
			'rank'                    => self::ref( $current ),
			'next_rank'               => self::ref( $next ),
			'points_to_next'          => $to_next,
			'reachable_rank'          => self::ref( $reachable ),
			'threshold_reached_since' => $since,
			'below_current_threshold' => $current && $points < $current['min_points'],
		);
	}

	public static function status_for_user( $user_id ) {
		$timeline = MKL_DB::get_point_timeline( $user_id );
		$user     = get_userdata( $user_id );
		return self::status(
			self::user_rank_id( $user_id ),
			MKL_DB::get_points_total( $user_id ),
			isset( $timeline[ $user_id ] ) ? $timeline[ $user_id ] : array(),
			self::all( false ),
			$user ? self::joined( $user ) : null
		);
	}

	/**
	 * Tag, ab dem die laufende Summe durchgehend mindestens $threshold beträgt.
	 * Fällt die Summe zwischendurch darunter, zählt das spätere Erreichen.
	 *
	 * @return string|null Y-m-d, oder null, wenn keine Buchung die Schwelle erreicht hat.
	 */
	public static function threshold_reached_since( array $timeline, $threshold ) {
		$sum   = 0;
		$since = null;
		foreach ( $timeline as $entry ) {
			$sum += $entry['points'];
			if ( $sum < $threshold ) {
				$since = null;
			} elseif ( null === $since ) {
				$since = $entry['date'];
			}
		}
		return $since;
	}

	/**
	 * Beförderungsübersicht: zuerst fällige (älteste zuerst), dann nach fehlenden
	 * Punkten, Mitglieder im höchsten Dienstgrad zuletzt.
	 *
	 * @param array $users Liste mit ID und display_name.
	 */
	public static function promotions( array $users ) {
		$ranks    = self::all( false );
		$assigned = self::rank_ids_by_user();
		$totals   = MKL_DB::get_totals_by_user();
		$timeline = MKL_DB::get_point_timeline();
		$out      = array();
		foreach ( $users as $u ) {
			$id     = (int) $u->ID;
			$points = isset( $totals[ $id ]['points'] ) ? $totals[ $id ]['points'] : 0;
			$status = self::status(
				isset( $assigned[ $id ] ) ? $assigned[ $id ] : null,
				$points,
				isset( $timeline[ $id ] ) ? $timeline[ $id ] : array(),
				$ranks,
				self::joined( get_userdata( $id ) )
			);
			$out[]  = array_merge(
				array(
					'user_id' => $id,
					'name'    => $u->display_name,
					'points'  => $points,
				),
				$status
			);
		}
		usort( $out, array( __CLASS__, 'compare_promotions' ) );
		return $out;
	}

	private static function compare_promotions( $a, $b ) {
		$group = function ( $x ) {
			if ( null === $x['points_to_next'] ) {
				return 2;
			}
			return $x['points_to_next'] <= 0 ? 0 : 1;
		};
		$ga = $group( $a );
		$gb = $group( $b );
		if ( $ga !== $gb ) {
			return $ga - $gb;
		}
		if ( 0 === $ga ) {
			$cmp = strcmp( (string) $a['threshold_reached_since'], (string) $b['threshold_reached_since'] );
			if ( 0 !== $cmp ) {
				return $cmp;
			}
		}
		if ( 2 !== $ga && $a['points_to_next'] !== $b['points_to_next'] ) {
			return $a['points_to_next'] - $b['points_to_next'];
		}
		return strcasecmp( $a['name'], $b['name'] );
	}

	/* ------------------------------------------------------------------ */
	/* Hilfsfunktionen                                                     */
	/* ------------------------------------------------------------------ */

	private static function joined( $user ) {
		if ( ! $user || ! $user->user_registered || '0000-00-00 00:00:00' === $user->user_registered ) {
			return null;
		}
		return wp_date( 'Y-m-d', strtotime( $user->user_registered . ' UTC' ) );
	}

	public static function ref( $rank ) {
		return $rank ? array(
			'id'         => $rank['id'],
			'title'      => $rank['title'],
			'min_points' => $rank['min_points'],
		) : null;
	}

	public static function format( array $r ) {
		return array(
			'id'         => (int) $r['id'],
			'title'      => $r['title'],
			'min_points' => (int) $r['min_points'],
			'active'     => (bool) (int) $r['active'],
		);
	}

	private static function error( $message, $status = 400 ) {
		return new WP_Error( 'mkl_invalid', $message, array( 'status' => $status ) );
	}
}
