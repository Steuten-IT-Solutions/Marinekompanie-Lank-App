<?php
/**
 * Shortcodes für die Webseite.
 *
 * [mkl_termine anzahl="10"]  Kommende öffentliche Termine aus der App.
 */

defined( 'ABSPATH' ) || exit;

class MKL_Shortcodes {

	private static $styles_printed = false;

	public static function init() {
		add_shortcode( 'mkl_termine', array( __CLASS__, 'termine' ) );
	}

	public static function termine( $atts ) {
		$atts   = shortcode_atts( array( 'anzahl' => 10 ), $atts, 'mkl_termine' );
		$limit  = max( 1, min( 100, (int) $atts['anzahl'] ) );
		$events = MKL_Events::upcoming( $limit );

		$html = self::styles();
		if ( ! $events ) {
			return $html . '<p class="mkl-termine-leer">Derzeit sind keine Termine eingetragen.</p>';
		}

		$html .= '<ol class="mkl-termine">';
		foreach ( $events as $ev ) {
			$start = new DateTimeImmutable( $ev['start_date'], wp_timezone() );
			$title = esc_html( $ev['title'] );
			if ( $ev['url'] ) {
				$external = 0 !== strpos( $ev['url'], home_url() );
				$title    = sprintf(
					'<a href="%s"%s>%s</a>',
					esc_url( $ev['url'] ),
					$external ? ' target="_blank" rel="noopener"' : '',
					$title
				);
			}
			$html .= '<li class="mkl-termin">';
			$html .= sprintf(
				'<div class="mkl-termin-datum" aria-hidden="true"><span class="mkl-termin-monat">%s</span><span class="mkl-termin-tag">%s</span></div>',
				esc_html( wp_date( 'M', $start->getTimestamp() ) ),
				esc_html( $start->format( 'j' ) )
			);
			$html .= '<div class="mkl-termin-text">';
			$html .= '<h4 class="mkl-termin-titel">' . $title . '</h4>';
			$html .= '<div class="mkl-termin-wann">' . esc_html( self::when( $ev ) ) . '</div>';
			if ( $ev['location'] ) {
				$html .= '<div class="mkl-termin-ort">⚓ ' . esc_html( $ev['location'] ) . '</div>';
			}
			if ( $ev['description'] ) {
				$html .= '<p class="mkl-termin-beschreibung">' . nl2br( esc_html( $ev['description'] ) ) . '</p>';
			}
			$html .= '</div></li>';
		}
		return $html . '</ol>';
	}

	/** „Sa., 27. Juni 2027 · 19:00–23:00 Uhr“ bzw. „25.–28. Juni 2027“. */
	public static function when( array $ev ) {
		$tz    = wp_timezone();
		$start = ( new DateTimeImmutable( $ev['start_date'], $tz ) )->getTimestamp();
		$text  = wp_date( 'D., j. F Y', $start );
		if ( $ev['end_date'] ) {
			$end   = ( new DateTimeImmutable( $ev['end_date'], $tz ) )->getTimestamp();
			$same  = wp_date( 'Y-m', $start ) === wp_date( 'Y-m', $end );
			$text  = ( $same ? wp_date( 'j.', $start ) : wp_date( 'j. F', $start ) ) . '–' . wp_date( 'j. F Y', $end );
		}
		if ( $ev['start_time'] ) {
			$text .= ' · ' . $ev['start_time'] . ( $ev['end_time'] ? '–' . $ev['end_time'] : '' ) . ' Uhr';
		}
		return $text;
	}

	/** Kleines eigenes CSS, nur einmal pro Seite und nur wenn ein Shortcode vorkommt. */
	private static function styles() {
		if ( self::$styles_printed ) {
			return '';
		}
		self::$styles_printed = true;
		return '<style id="mkl-termine-css">
.mkl-termine{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:12px}
.mkl-termin{display:flex;gap:16px;align-items:flex-start;padding:14px 16px;background:#fffdf8;border:1px solid #e2d8c3;border-left:4px solid #1f4498;border-radius:10px}
.mkl-termin-datum{flex:none;width:56px;border:1.5px solid #c9a24a;border-radius:8px;overflow:hidden;text-align:center;background:#1f4498;color:#fff;display:flex;flex-direction:column}
.mkl-termin-monat{background:#c9a24a;color:#0d2152;font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:2px 0}
.mkl-termin-tag{font-size:1.5rem;font-weight:700;line-height:1;padding:6px 0 8px}
.mkl-termin-text{min-width:0}
.mkl-termin-titel{margin:0 0 2px;font-size:1.1rem;line-height:1.3}
.mkl-termin-titel a{color:#1f4498;text-decoration:none}
.mkl-termin-titel a:hover{text-decoration:underline}
.mkl-termin-wann{font-size:.92rem;color:#39435e}
.mkl-termin-ort{font-size:.92rem;color:#6b7286}
.mkl-termin-beschreibung{margin:6px 0 0;font-size:.95rem}
.mkl-termine-leer{font-style:italic}
</style>';
	}
}
