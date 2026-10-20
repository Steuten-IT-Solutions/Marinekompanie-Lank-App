<?php

declare(strict_types=1);

namespace MklUebernahme;

use KompanieApp\WordPress\Plugin;

/**
 * WP-CLI:
 *   wp mkl-uebernahme probelauf [--format=json]
 *   wp mkl-uebernahme uebernehmen --yes [--format=json]
 */
final class Cli
{
    /**
     * Plant die Übernahme und prüft sie, ohne etwas zu schreiben.
     *
     * [--format=<format>]
     * : text oder json
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function probelauf(array $args, array $assoc): void
    {
        $this->output((new Importer(Plugin::app(), $GLOBALS['wpdb']))->run(true), $assoc['format'] ?? 'text');
    }

    /**
     * Übernimmt die Daten (wiederholbar; schon Übernommenes wird übersprungen).
     *
     * [--yes]
     * : ohne Rückfrage
     *
     * [--format=<format>]
     * : text oder json
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function uebernehmen(array $args, array $assoc): void
    {
        \WP_CLI::confirm('Sicherung von Datenbank und uploads/ erstellt? Jetzt übernehmen?', $assoc);
        $this->output((new Importer(Plugin::app(), $GLOBALS['wpdb']))->run(false), $assoc['format'] ?? 'text');
    }

    /** @param array<string, mixed> $report */
    private function output(array $report, string $format): void
    {
        if ($format === 'json') {
            \WP_CLI::line((string) wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            \WP_CLI::line(Report::text($report));
        }
        if (!$report['ok']) {
            \WP_CLI::halt(1);
        }
    }
}
