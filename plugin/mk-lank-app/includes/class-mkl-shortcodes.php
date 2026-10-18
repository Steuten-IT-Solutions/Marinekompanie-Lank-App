<?php
/**
 * Shortcodes für die Webseite.
 *
 * [mkl_termine anzahl="10"]  Kommende öffentliche Termine aus der App.
 * [mkl_galerie]              Galerie aus Google Drive (Jahre → Alben → Bilder).
 */

defined( 'ABSPATH' ) || exit;

class MKL_Shortcodes {

	private static $styles_printed = false;

	public static function init() {
		add_shortcode( 'mkl_termine', array( __CLASS__, 'termine' ) );
		add_shortcode( 'mkl_galerie', array( __CLASS__, 'galerie' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Galerie                                                             */
	/* ------------------------------------------------------------------ */

	public static function galerie() {
		$member = MKL_Roles::is_member();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Navigation
		$album_id = isset( $_GET['album'] ) ? absint( $_GET['album'] ) : 0;
		$html     = self::gallery_assets();

		if ( $album_id ) {
			$album = MKL_Gallery::album( $album_id, $member );
			$back  = esc_url( remove_query_arg( 'album' ) );
			if ( ! $album ) {
				return $html . '<p><a href="' . $back . '">‹ Zur Galerie</a></p><p>Dieses Album gibt es nicht (mehr).</p>';
			}
			$html .= '<div class="mkl-gal">';
			$html .= '<p class="mkl-gal-back"><a href="' . $back . '">‹ Zur Galerie</a></p>';
			$html .= '<h2 class="mkl-gal-title">' . esc_html( $album['title'] ) . ' <span>' . esc_html( $album['year'] ) . '</span></h2>';
			$html .= '<div class="mkl-gal-grid">';
			foreach ( $album['photos'] as $ph ) {
				$html .= sprintf(
					'<a class="mkl-gal-foto%s" href="%s" data-mkl-lightbox><img src="%s" alt="%s" loading="lazy" width="400" height="400" />%s</a>',
					$ph['intern'] ? ' is-intern' : '',
					esc_url( $ph['large'] ),
					esc_url( $ph['thumb'] ),
					esc_attr( $album['title'] ),
					$ph['intern'] ? '<span class="mkl-gal-lock" title="Nur für Mitglieder">🔒</span>' : ''
				);
			}
			$html .= '</div></div>';
			return $html;
		}

		$years = MKL_Gallery::overview( $member );
		if ( ! $years ) {
			return $html . '<p class="mkl-gal-leer">Noch keine Bilder in der Galerie.</p>';
		}
		$html .= '<div class="mkl-gal">';
		foreach ( $years as $year ) {
			$html .= '<h2 class="mkl-gal-jahr">' . esc_html( $year['year'] ) . '</h2><div class="mkl-gal-alben">';
			foreach ( $year['albums'] as $al ) {
				$html .= sprintf(
					'<a class="mkl-gal-album" href="%s">%s<span class="mkl-gal-album-titel">%s</span><span class="mkl-gal-album-anzahl">%s</span></a>',
					esc_url( add_query_arg( 'album', $al['id'] ) ),
					$al['cover'] ? '<img src="' . esc_url( $al['cover'] ) . '" alt="" loading="lazy" />' : '<span class="mkl-gal-platzhalter"></span>',
					esc_html( $al['title'] ),
					esc_html( sprintf( '%d %s', $al['count'], 1 === $al['count'] ? 'Bild' : 'Bilder' ) )
				);
			}
			$html .= '</div>';
		}
		return $html . '</div>';
	}

	/** CSS und eine kleine Vollbild-Ansicht (ohne externe Bibliothek), einmal pro Seite. */
	private static function gallery_assets() {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;
		return '<style id="mkl-galerie-css">
.mkl-gal-jahr{margin:28px 0 12px;font-size:1.4rem;color:#1f4498;border-bottom:2px solid #c9a24a;padding-bottom:4px}
.mkl-gal-alben{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px}
.mkl-gal-album{display:flex;flex-direction:column;text-decoration:none;color:inherit;background:#fffdf8;border:1px solid #e2d8c3;border-radius:10px;overflow:hidden;transition:transform .1s}
.mkl-gal-album:hover{transform:translateY(-2px)}
.mkl-gal-album img,.mkl-gal-platzhalter{display:block;width:100%;aspect-ratio:4/3;object-fit:cover;background:#e2d8c3;border-bottom:3px solid #c9a24a}
.mkl-gal-album-titel{font-weight:700;padding:10px 12px 0;color:#0d2152}
.mkl-gal-album-anzahl{font-size:.85rem;color:#6b7286;padding:0 12px 10px}
.mkl-gal-title span{font-weight:400;color:#6b7286;font-size:.8em}
.mkl-gal-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px}
.mkl-gal-foto{position:relative;display:block;aspect-ratio:1;overflow:hidden;border-radius:6px;background:#e2d8c3}
.mkl-gal-foto img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .2s}
.mkl-gal-foto:hover img{transform:scale(1.04)}
.mkl-gal-lock{position:absolute;right:6px;bottom:6px;background:rgba(13,33,82,.8);border-radius:999px;padding:2px 6px;font-size:.8rem}
.mkl-lb{position:fixed;inset:0;z-index:99999;background:rgba(5,12,30,.94);display:flex;align-items:center;justify-content:center}
.mkl-lb img{max-width:96vw;max-height:88vh;object-fit:contain;box-shadow:0 8px 30px rgba(0,0,0,.5)}
.mkl-lb button{position:absolute;background:rgba(255,255,255,.12);color:#fff;border:0;border-radius:999px;width:48px;height:48px;font-size:1.6rem;cursor:pointer}
.mkl-lb .mkl-lb-close{top:16px;right:16px}.mkl-lb .mkl-lb-prev{left:12px}.mkl-lb .mkl-lb-next{right:12px}
.mkl-lb-count{position:absolute;bottom:14px;left:0;right:0;text-align:center;color:#e6c97a;font-size:.9rem}
</style>
<script>
(function(){if(window.mklLightbox)return;window.mklLightbox=1;
document.addEventListener("click",function(e){var a=e.target.closest("[data-mkl-lightbox]");if(!a)return;e.preventDefault();
var links=[].slice.call(document.querySelectorAll("[data-mkl-lightbox]")),i=links.indexOf(a);
var box=document.createElement("div");box.className="mkl-lb";box.setAttribute("role","dialog");box.setAttribute("aria-modal","true");
box.innerHTML="<img alt=\"\"><button class=\"mkl-lb-close\" aria-label=\"Schließen\">✕</button><button class=\"mkl-lb-prev\" aria-label=\"Vorheriges Bild\">‹</button><button class=\"mkl-lb-next\" aria-label=\"Nächstes Bild\">›</button><div class=\"mkl-lb-count\"></div>";
var img=box.querySelector("img"),cnt=box.querySelector(".mkl-lb-count");
function show(n){i=(n+links.length)%links.length;img.src=links[i].href;cnt.textContent=(i+1)+" / "+links.length;}
function close(){document.removeEventListener("keydown",key);box.remove();a.focus();}
function key(ev){if(ev.key==="Escape")close();if(ev.key==="ArrowLeft")show(i-1);if(ev.key==="ArrowRight")show(i+1);}
box.addEventListener("click",function(ev){if(ev.target===box||ev.target.classList.contains("mkl-lb-close"))close();});
box.querySelector(".mkl-lb-prev").onclick=function(){show(i-1)};box.querySelector(".mkl-lb-next").onclick=function(){show(i+1)};
var x0=null;box.addEventListener("touchstart",function(ev){x0=ev.touches[0].clientX},{passive:true});
box.addEventListener("touchend",function(ev){if(x0===null)return;var dx=ev.changedTouches[0].clientX-x0;if(Math.abs(dx)>40)show(dx<0?i+1:i-1);x0=null;});
document.addEventListener("keydown",key);document.body.appendChild(box);show(i);box.querySelector(".mkl-lb-close").focus();});})();
</script>';
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

	/**
	 * Kleines eigenes CSS, nur einmal pro Seite und nur wenn ein Shortcode vorkommt.
	 * Textfarben sind fest gesetzt, damit sie nicht die (im Dark Mode hellen) Theme-Farben
	 * erben; Astra markiert den Dark Mode mit body.astra-dark-mode-enable.
	 */
	private static function styles() {
		if ( self::$styles_printed ) {
			return '';
		}
		self::$styles_printed = true;
		return '<style id="mkl-termine-css">
.mkl-termine{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:12px}
.mkl-termin{display:flex;gap:16px;align-items:flex-start;padding:14px 16px;background:#fffdf8;color:#1e293b;border:1px solid #e2d8c3;border-left:4px solid #1f4498;border-radius:10px}
.mkl-termin-datum{flex:none;width:56px;border:1.5px solid #c9a24a;border-radius:8px;overflow:hidden;text-align:center;background:#1f4498;color:#fff;display:flex;flex-direction:column}
.mkl-termin-monat{background:#c9a24a;color:#0d2152;font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:2px 0}
.mkl-termin-tag{font-size:1.5rem;font-weight:700;line-height:1;padding:6px 0 8px}
.mkl-termin-text{min-width:0}
.mkl-termin-titel{margin:0 0 2px;font-size:1.1rem;line-height:1.3;color:#0d2152}
.mkl-termin-titel a{color:#1f4498;text-decoration:none}
.mkl-termin-titel a:hover{text-decoration:underline}
.mkl-termin-wann{font-size:.92rem;color:#39435e}
.mkl-termin-ort{font-size:.92rem;color:#6b7286}
.mkl-termin-beschreibung{margin:6px 0 0;font-size:.95rem}
.mkl-termine-leer{font-style:italic}
.astra-dark-mode-enable .mkl-termin{background:#1a2333;border-color:#334155;border-left-color:#4571d9;color:#e7f6ff}
.astra-dark-mode-enable .mkl-termin-titel{color:#fff}
.astra-dark-mode-enable .mkl-termin-titel a{color:#9db8ff}
.astra-dark-mode-enable .mkl-termin-wann{color:#c8d3e6}
.astra-dark-mode-enable .mkl-termin-ort{color:#9aa7bd}
</style>';
	}
}
