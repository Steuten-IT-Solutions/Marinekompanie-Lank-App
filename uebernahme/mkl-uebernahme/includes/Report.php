<?php

declare(strict_types=1);

namespace MklUebernahme;

/** Bericht eines Laufs als Text (WP-CLI) oder HTML (Admin-Seite). */
final class Report
{
    /** @param array<string, mixed> $r */
    public static function text(array $r): string
    {
        $out = [$r['dry_run'] ? '== Probelauf (nichts geschrieben) ==' : '== Übernahme =='];
        foreach ($r['errors'] as $e) {
            $out[] = 'FEHLER: ' . $e;
        }
        $out[] = sprintf('%-28s %6s %6s %6s %6s', 'Bereich', 'Lank', 'neu', 'schon', 'übersp.');
        foreach ($r['tables'] as $t) {
            $out[] = sprintf('%-28s %6d %6d %6d %6d', $t['label'], $t['source'], $t['new'], $t['existing'], $t['skipped']);
        }
        foreach ($r['roles'] as $role => $n) {
            $out[] = sprintf('Rolle %s: %d Konten', $role, $n);
        }
        foreach ($r['warnings'] as $w) {
            $out[] = 'Hinweis: ' . $w;
        }
        foreach ($r['checks'] as $c) {
            $out[] = ($c['ok'] ? 'ok    ' : 'FALSCH ') . $c['label'] . ' – ' . $c['detail'];
        }
        $out[] = $r['ok'] ? 'Ergebnis: in Ordnung' : 'Ergebnis: bitte die Fehler oben beheben';
        return implode("\n", $out);
    }

    /** @param array<string, mixed> $r */
    public static function html(array $r): string
    {
        $h = '<h2>' . esc_html($r['dry_run'] ? 'Probelauf (nichts geschrieben)' : 'Übernahme') . '</h2>';
        foreach ($r['errors'] as $e) {
            $h .= '<div class="notice notice-error inline"><p>' . esc_html($e) . '</p></div>';
        }
        $h .= '<table class="widefat striped" style="max-width:720px"><thead><tr><th>Bereich</th><th>in Lank</th><th>neu</th><th>schon übernommen</th><th>übersprungen</th></tr></thead><tbody>';
        foreach ($r['tables'] as $t) {
            $h .= sprintf('<tr><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td></tr>', esc_html($t['label']), $t['source'], $t['new'], $t['existing'], $t['skipped']);
        }
        $h .= '</tbody></table>';
        if ($r['roles']) {
            $h .= '<p>Rollen: ' . esc_html(implode(', ', array_map(static fn ($k, $v) => "$k $v", array_keys($r['roles']), $r['roles']))) . '</p>';
        }
        if ($r['warnings']) {
            $h .= '<h3>Hinweise</h3><ul>';
            foreach ($r['warnings'] as $w) {
                $h .= '<li>' . esc_html($w) . '</li>';
            }
            $h .= '</ul>';
        }
        if ($r['checks']) {
            $h .= '<h3>Prüfung</h3><ul>';
            foreach ($r['checks'] as $c) {
                $h .= '<li>' . ($c['ok'] ? '✔ ' : '<strong style="color:#b32d2e">✘</strong> ') . esc_html($c['label'] . ' – ' . $c['detail']) . '</li>';
            }
            $h .= '</ul>';
        }
        return $h;
    }
}
