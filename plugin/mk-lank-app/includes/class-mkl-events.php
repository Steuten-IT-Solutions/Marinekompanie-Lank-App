<?php
/**
 * Öffentliche Termine, die Admins in der App pflegen.
 *
 * Datum und Uhrzeit sind getrennt gespeichert (lokale Zeit der Webseite),
 * weil viele Termine ganztägig oder mehrtägig sind.
 */

defined( 'ABSPATH' ) || exit;

class MKL_Events {

	public static function table() {
		return MKL_DB::table( 'events' );
	}

	/** Kommende Termine (inkl. heute laufender), aufsteigend. */
	public static function upcoming( $limit = 200 ) {
		global $wpdb;
		$t     = self::table();
		$today = wp_date( 'Y-m-d' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $t WHERE COALESCE(end_date, start_date) >= %s ORDER BY start_date ASC, start_time ASC, id ASC LIMIT %d",
				$today,
				$limit
			),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'format' ), $rows );
	}

	public static function get( $id ) {
		global $wpdb;
		$t   = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::format( $row ) : null;
	}

	/** @return int|WP_Error */
	public static function create( array $input, $user_id ) {
		$data = self::validate( $input, null );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		global $wpdb;
		$now                = current_time( 'mysql', true );
		$data['created_by'] = $user_id;
		$data['created_at'] = $now;
		$data['updated_at'] = $now;
		$wpdb->insert( self::table(), $data );
		self::flush();
		return (int) $wpdb->insert_id;
	}

	/** @return true|WP_Error */
	public static function update( $id, array $input ) {
		$current = self::get( $id );
		if ( ! $current ) {
			return new WP_Error( 'mkl_invalid', 'Termin nicht gefunden.', array( 'status' => 404 ) );
		}
		$data = self::validate( $input, $current );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( self::table(), $data, array( 'id' => $id ) );
		self::flush();
		return true;
	}

	public static function delete( $id ) {
		global $wpdb;
		$deleted = (bool) $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
		self::flush();
		return $deleted;
	}

	/** Seiten-Caches (z. B. Startseite mit Shortcode) sollen neue Termine zeigen. */
	private static function flush() {
		do_action( 'mkl_events_changed' );
	}

	/**
	 * Prüft und normalisiert Eingaben. Bei Updates werden fehlende Felder aus $current übernommen.
	 *
	 * @return array|WP_Error Datenbankfelder.
	 */
	private static function validate( array $in, $current ) {
		$get = function ( $key, $fallback = '' ) use ( $in, $current ) {
			if ( array_key_exists( $key, $in ) && null !== $in[ $key ] ) {
				return is_string( $in[ $key ] ) ? trim( $in[ $key ] ) : $in[ $key ];
			}
			return $current && isset( $current[ $key ] ) && null !== $current[ $key ] ? $current[ $key ] : $fallback;
		};
		$err = function ( $msg ) {
			return new WP_Error( 'mkl_invalid', $msg, array( 'status' => 400 ) );
		};

		$title = sanitize_text_field( (string) $get( 'title' ) );
		if ( '' === $title ) {
			return $err( 'Bitte einen Titel angeben.' );
		}
		if ( mb_strlen( $title ) > 191 ) {
			return $err( 'Der Titel ist zu lang.' );
		}

		$start_date = (string) $get( 'start_date' );
		if ( ! self::valid_date( $start_date ) ) {
			return $err( 'Bitte ein gültiges Startdatum angeben.' );
		}
		$end_date = (string) $get( 'end_date' );
		if ( '' !== $end_date && ! self::valid_date( $end_date ) ) {
			return $err( 'Das Enddatum ist ungültig.' );
		}
		if ( '' !== $end_date && $end_date < $start_date ) {
			return $err( 'Das Enddatum liegt vor dem Startdatum.' );
		}

		$start_time = self::norm_time( (string) $get( 'start_time' ) );
		$end_time   = self::norm_time( (string) $get( 'end_time' ) );
		if ( false === $start_time || false === $end_time ) {
			return $err( 'Bitte Uhrzeiten im Format HH:MM angeben.' );
		}
		if ( null === $start_time && null !== $end_time ) {
			return $err( 'Eine Endzeit braucht auch eine Startzeit.' );
		}
		if ( null !== $end_time && ( '' === $end_date || $end_date === $start_date ) && $end_time < $start_time ) {
			return $err( 'Die Endzeit liegt vor der Startzeit.' );
		}

		$url = (string) $get( 'url' );
		if ( '' !== $url ) {
			$url = esc_url_raw( $url, array( 'http', 'https' ) );
			if ( '' === $url ) {
				return $err( 'Der Link muss mit http:// oder https:// beginnen.' );
			}
		}

		return array(
			'title'       => $title,
			'start_date'  => $start_date,
			'start_time'  => $start_time,
			'end_date'    => '' === $end_date || $end_date === $start_date ? null : $end_date,
			'end_time'    => $end_time,
			'location'    => mb_substr( sanitize_text_field( (string) $get( 'location' ) ), 0, 191 ),
			'description' => sanitize_textarea_field( (string) $get( 'description' ) ),
			'url'         => $url,
		);
	}

	private static function valid_date( $d ) {
		return (bool) preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/** '' → null, 'H:MM'/'HH:MM(:SS)' → 'HH:MM:00', sonst false. */
	private static function norm_time( $t ) {
		if ( '' === $t ) {
			return null;
		}
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $t, $m ) || (int) $m[1] > 23 || (int) $m[2] > 59 ) {
			return false;
		}
		return sprintf( '%02d:%02d:00', $m[1], $m[2] );
	}

	/**
	 * API-Format. Enthält zusätzlich start/end/all_day im selben Format wie der
	 * Google-Kalender (MKL_Calendar), damit die App dieselbe Darstellung nutzt.
	 */
	public static function format( array $r ) {
		$all_day = empty( $r['start_time'] );
		$tz      = wp_timezone();
		$hm      = function ( $t ) {
			return $t ? substr( $t, 0, 5 ) : null;
		};
		if ( $all_day ) {
			$last  = $r['end_date'] ? $r['end_date'] : $r['start_date'];
			$start = $r['start_date'];
			// Ende exklusiv wie bei Google/iCal.
			$end = ( new DateTimeImmutable( $last, $tz ) )->modify( '+1 day' )->format( 'Y-m-d' );
		} else {
			$start_dt = new DateTimeImmutable( $r['start_date'] . ' ' . $r['start_time'], $tz );
			$end_day  = $r['end_date'] ? $r['end_date'] : $r['start_date'];
			$end_dt   = $r['end_time'] ? new DateTimeImmutable( $end_day . ' ' . $r['end_time'], $tz ) : $start_dt;
			$start    = $start_dt->format( 'c' );
			$end      = $end_dt->format( 'c' );
		}
		return array(
			'id'          => (int) $r['id'],
			'title'       => $r['title'],
			'start_date'  => $r['start_date'],
			'start_time'  => $hm( $r['start_time'] ),
			'end_date'    => $r['end_date'],
			'end_time'    => $hm( $r['end_time'] ),
			'location'    => $r['location'],
			'description' => $r['description'],
			'url'         => $r['url'],
			'all_day'     => $all_day,
			'start'       => $start,
			'end'         => $end,
		);
	}
}
