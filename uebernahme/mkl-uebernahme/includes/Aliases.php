<?php

declare(strict_types=1);

namespace MklUebernahme;

use KompanieApp\WordPress\Plugin;
use KompanieApp\WordPress\RestBridge;

/**
 * Bisherige Adressen und Shortcodes von mk-lank-app, damit nach der Umstellung nichts ins Leere läuft:
 * - REST-Namensraum mk-lank/v1 als Alias der Kompanie-App (noch geladene alte App-Fassungen, Push-Abmeldungen),
 * - [mkl_termine] und [mkl_galerie] auf der Webseite.
 * /app/dokument/<id> und /app/foto/<id>/<größe> bedient die Kompanie-App selbst; die IDs bleiben gleich.
 */
final class Aliases
{
    public const NAMESPACE = 'mk-lank/v1';

    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'rest'], 20);
        add_action('init', [self::class, 'shortcodes'], 20);
    }

    public static function rest(): void
    {
        // Der zusätzliche Namensraum braucht eine Kompanie-App, die ihn unterstützt.
        if ((new \ReflectionMethod(RestBridge::class, 'register'))->getNumberOfParameters() >= 2) {
            RestBridge::register(Plugin::app(), self::NAMESPACE);
        }
    }

    public static function shortcodes(): void
    {
        if (!shortcode_exists('mkl_termine')) {
            add_shortcode('mkl_termine', static function ($atts): string {
                $atts = is_array($atts) ? $atts : [];
                // In Lank zeigte [mkl_termine] ohne anzahl alle kommenden Termine; die Kompanie-App zeigt höchstens 100.
                $n = isset($atts['anzahl']) && trim((string) $atts['anzahl']) !== '' ? (int) $atts['anzahl'] : 100;
                return do_shortcode('[kompanie_termine anzahl="' . max(1, min(100, $n)) . '"]');
            });
        }
        if (!shortcode_exists('mkl_galerie')) {
            add_shortcode('mkl_galerie', static fn (): string => do_shortcode('[kompanie_galerie]'));
        }
    }
}
