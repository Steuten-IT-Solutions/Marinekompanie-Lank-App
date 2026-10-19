<?php
/**
 * Termine aus dem Google Kalender der Webseite.
 *
 * Quellen (in dieser Reihenfolge), beide unter Einstellungen → Kompanie-App:
 *  1. Eigener Google-API-Key mit Kalender-IDs, für öffentliche Kalender.
 *  2. iCal-Adresse (ICS), z. B. die „geheime Adresse im iCal-Format“ aus Google.
 *
 * Das Plugin „Private Google Calendars“ wird nicht mehr genutzt, auch wenn es aktiv ist.
 *
 * Das Ergebnis wird als Transient zwischengespeichert; API-Keys verlassen nie den Server.
 */

defined( 'ABSPATH' ) || exit;

class MKL_Calendar {

	const CACHE_KEY = 'mkl_calendar_events';
	const PAST_CACHE_KEY = 'mkl_calendar_past_events';
	/** So weit zurück lassen sich Anwesenheitslisten zu Kalenderterminen anlegen. */
	const PAST_DAYS = 90;
	const GOOGLE_EVENTS_URL = 'https://www.googleapis.com/calendar/v3/calendars/%s/events';

	/**
	 * @return array|WP_Error Liste normalisierter Termine, nach Beginn sortiert.
	 */
	public static function get_events() {
		return self::cached( self::CACHE_KEY, self::range() );
	}

	/**
	 * Termine der letzten PAST_DAYS Tage bis einschließlich morgen, für die
	 * Anwesenheit. Die IDs sind dieselben wie bei get_events().
	 *
	 * @return array|WP_Error
	 */
	public static function get_past_events() {
		return self::cached(
			self::PAST_CACHE_KEY,
			array(
				'timeMin' => gmdate( 'Y-m-d\TH:i:s\Z', time() - self::PAST_DAYS * DAY_IN_SECONDS ),
				'timeMax' => gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS ),
			)
		);
	}

	private static function cached( $key, array $range ) {
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$events = self::fetch_events( $range );
		if ( is_wp_error( $events ) ) {
			return $events;
		}

		usort(
			$events,
			function ( $a, $b ) {
				return strcmp( $a['start'], $b['start'] );
			}
		);

		$minutes = max( 1, (int) get_option( 'mkl_calendar_cache_minutes', 15 ) );
		set_transient( $key, $events, $minutes * MINUTE_IN_SECONDS );
		return $events;
	}

	public static function clear_cache() {
		delete_transient( self::CACHE_KEY );
		delete_transient( self::PAST_CACHE_KEY );
	}

	/** Zeitraum: ab gestern bis ein Jahr in die Zukunft. */
	private static function range() {
		return array(
			'timeMin' => gmdate( 'Y-m-d\TH:i:s\Z', time() - DAY_IN_SECONDS ),
			'timeMax' => gmdate( 'Y-m-d\TH:i:s\Z', time() + YEAR_IN_SECONDS ),
		);
	}

	public static function get_calendar_ids() {
		$own = trim( (string) get_option( 'mkl_calendar_ids', '' ) );
		return array_values( array_filter( array_map( 'trim', explode( ',', $own ) ) ) );
	}

	/** Ist eine Quelle eingetragen? Sonst zeigt die Einstellungsseite einen Hinweis. */
	public static function is_configured() {
		return ( get_option( 'mkl_google_api_key' ) && self::get_calendar_ids() ) || get_option( 'mkl_ics_url' );
	}

	/** Für die Einstellungsseite: welche Quelle wird verwendet? */
	public static function describe_source() {
		if ( get_option( 'mkl_google_api_key' ) && self::get_calendar_ids() ) {
			return 'Google Calendar API (eigener API-Key)';
		}
		if ( get_option( 'mkl_ics_url' ) ) {
			return 'iCal-Adresse (ICS)';
		}
		return 'Nicht konfiguriert';
	}

	private static function fetch_events( array $range ) {
		$ids     = self::get_calendar_ids();
		$api_key = (string) get_option( 'mkl_google_api_key', '' );

		if ( $ids && '' !== $api_key ) {
			return self::fetch_google( $ids, array( 'key' => $api_key ), array(), $range );
		}

		$ics_url = (string) get_option( 'mkl_ics_url', '' );
		if ( '' !== $ics_url ) {
			return self::fetch_ics( $ics_url, $range );
		}

		return new WP_Error( 'mkl_calendar_not_configured', 'Der Kalender ist noch nicht eingerichtet.' );
	}

	private static function fetch_google( array $ids, array $auth_params, array $headers, array $range ) {
		$events = array();
		foreach ( $ids as $cal_id ) {
			$params = array_merge(
				array(
					'singleEvents' => 'true',
					'orderBy'      => 'startTime',
					'maxResults'   => 250,
					'timeMin'      => $range['timeMin'],
					'timeMax'      => $range['timeMax'],
					'timeZone'     => wp_timezone_string(),
				),
				$auth_params
			);
			$url      = add_query_arg( array_map( 'rawurlencode', $params ), sprintf( self::GOOGLE_EVENTS_URL, rawurlencode( $cal_id ) ) );
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 15,
					'headers' => $headers,
				)
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$code = wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 200 !== $code || ! is_array( $body ) ) {
				$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
				return new WP_Error( 'mkl_calendar_google', 'Google-Kalender: ' . $msg );
			}
			foreach ( isset( $body['items'] ) ? $body['items'] : array() as $item ) {
				if ( isset( $item['visibility'] ) && 'private' === $item['visibility'] ) {
					continue;
				}
				if ( isset( $item['status'] ) && 'cancelled' === $item['status'] ) {
					continue;
				}
				$all_day  = ! empty( $item['start']['date'] );
				$events[] = array(
					'id'          => md5( $cal_id . '|' . ( isset( $item['id'] ) ? $item['id'] : wp_json_encode( $item['start'] ) ) ),
					'title'       => isset( $item['summary'] ) ? $item['summary'] : '(ohne Titel)',
					'start'       => self::normalize_time( $all_day ? $item['start']['date'] : $item['start']['dateTime'], $all_day ),
					'end'         => self::normalize_time( $all_day ? $item['end']['date'] : $item['end']['dateTime'], $all_day ),
					'all_day'     => $all_day,
					'location'    => isset( $item['location'] ) ? $item['location'] : '',
					'description' => self::plain_text( isset( $item['description'] ) ? $item['description'] : '' ),
				);
			}
		}
		return $events;
	}

	/**
	 * Ganztägig: 'Y-m-d' (Ende exklusiv, wie bei Google/iCal).
	 * Mit Uhrzeit: ISO 8601 mit Offset in der Zeitzone der Webseite.
	 */
	private static function normalize_time( $value, $all_day ) {
		if ( $all_day ) {
			return substr( $value, 0, 10 );
		}
		try {
			$dt = new DateTimeImmutable( $value );
			return $dt->setTimezone( wp_timezone() )->format( 'c' );
		} catch ( Exception $e ) {
			return $value;
		}
	}

	private static function plain_text( $html ) {
		$text = preg_replace( '#<br\s*/?>|</p>|</li>#i', "\n", (string) $html );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( "/\n{3,}/", "\n\n", $text ) );
	}

	/* ------------------------------------------------------------------ */
	/* iCal (ICS)                                                          */
	/* ------------------------------------------------------------------ */

	private static function fetch_ics( $url, array $range ) {
		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'mkl_calendar_ics', 'iCal-Adresse lieferte HTTP ' . wp_remote_retrieve_response_code( $response ) );
		}
		return self::parse_ics( wp_remote_retrieve_body( $response ), $range );
	}

	/**
	 * Einfacher ICS-Parser für VEVENTs. Wiederkehrende Termine (RRULE) werden
	 * nur mit ihrem ersten Vorkommen angezeigt – dafür lieber die Google-API nutzen.
	 */
	public static function parse_ics( $ics, $range = null ) {
		$ics   = preg_replace( "/\r\n[ \t]|\n[ \t]/", '', str_replace( "\r\n", "\n", (string) $ics ) );
		$lines = explode( "\n", $ics );
		$range = $range ? $range : self::range();
		$min   = strtotime( $range['timeMin'] );
		$max   = strtotime( $range['timeMax'] );

		$events = array();
		$cur    = null;
		foreach ( $lines as $line ) {
			if ( 'BEGIN:VEVENT' === trim( $line ) ) {
				$cur = array();
				continue;
			}
			if ( 'END:VEVENT' === trim( $line ) ) {
				if ( $cur && ! empty( $cur['DTSTART'] ) ) {
					$event = self::ics_event( $cur );
					if ( $event && strtotime( $event['end'] ) >= $min && strtotime( $event['start'] ) <= $max ) {
						$events[] = $event;
					}
				}
				$cur = null;
				continue;
			}
			if ( null === $cur || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $key, $value ) = explode( ':', $line, 2 );
			$parts               = explode( ';', $key );
			$name                = strtoupper( array_shift( $parts ) );
			$params              = array();
			foreach ( $parts as $p ) {
				if ( false !== strpos( $p, '=' ) ) {
					list( $pk, $pv )             = explode( '=', $p, 2 );
					$params[ strtoupper( $pk ) ] = trim( $pv, '"' );
				}
			}
			$cur[ $name ] = array(
				'value'  => $value,
				'params' => $params,
			);
		}
		return $events;
	}

	private static function ics_text( $v ) {
		return str_replace( array( '\\n', '\\N', '\\,', '\\;', '\\\\' ), array( "\n", "\n", ',', ';', '\\' ), $v );
	}

	private static function ics_time( array $prop ) {
		$v       = trim( $prop['value'] );
		$all_day = ( isset( $prop['params']['VALUE'] ) && 'DATE' === $prop['params']['VALUE'] ) || 8 === strlen( $v );
		if ( $all_day ) {
			return array( substr( $v, 0, 4 ) . '-' . substr( $v, 4, 2 ) . '-' . substr( $v, 6, 2 ), true );
		}
		try {
			if ( 'Z' === substr( $v, -1 ) ) {
				$dt = new DateTimeImmutable( rtrim( $v, 'Z' ), new DateTimeZone( 'UTC' ) );
			} elseif ( ! empty( $prop['params']['TZID'] ) ) {
				$dt = new DateTimeImmutable( $v, new DateTimeZone( $prop['params']['TZID'] ) );
			} else {
				$dt = new DateTimeImmutable( $v, wp_timezone() );
			}
			return array( $dt->setTimezone( wp_timezone() )->format( 'c' ), false );
		} catch ( Exception $e ) {
			return array( null, false );
		}
	}

	private static function ics_event( array $e ) {
		list( $start, $all_day ) = self::ics_time( $e['DTSTART'] );
		if ( ! $start ) {
			return null;
		}
		$end = $start;
		if ( ! empty( $e['DTEND'] ) ) {
			list( $end ) = self::ics_time( $e['DTEND'] );
		}
		return array(
			'id'          => md5( isset( $e['UID'] ) ? $e['UID']['value'] . $start : $start ),
			'title'       => isset( $e['SUMMARY'] ) ? self::ics_text( $e['SUMMARY']['value'] ) : '(ohne Titel)',
			'start'       => $start,
			'end'         => $end,
			'all_day'     => $all_day,
			'location'    => isset( $e['LOCATION'] ) ? self::ics_text( $e['LOCATION']['value'] ) : '',
			'description' => isset( $e['DESCRIPTION'] ) ? self::plain_text( self::ics_text( $e['DESCRIPTION']['value'] ) ) : '',
		);
	}
}
