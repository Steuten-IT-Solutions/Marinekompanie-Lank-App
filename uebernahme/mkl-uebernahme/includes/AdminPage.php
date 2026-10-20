<?php

declare(strict_types=1);

namespace MklUebernahme;

use KompanieApp\WordPress\Plugin;

/** Werkzeuge → Übernahme Marinekompanie: Probelauf und Übernahme mit Bericht (nur Administratoren). */
final class AdminPage
{
    private const SLUG = 'mkl-uebernahme';

    public static function register(): void
    {
        add_action('admin_menu', static function (): void {
            add_management_page('Übernahme Marinekompanie', 'Übernahme Marinekompanie', 'manage_options', self::SLUG, [self::class, 'render']);
        });
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Keine Berechtigung.');
        }
        $report = null;
        if (isset($_POST['mkl_uebernahme'])) {
            check_admin_referer('mkl_uebernahme');
            $real = $_POST['mkl_uebernahme'] === 'uebernehmen';
            if ($real && empty($_POST['mkl_backup'])) {
                echo '<div class="notice notice-error"><p>Bitte bestätigen, dass eine Sicherung erstellt wurde.</p></div>';
            } else {
                $report = (new Importer(Plugin::app(), $GLOBALS['wpdb']))->run(!$real);
            }
        }
        ?>
        <div class="wrap">
            <h1>Übernahme aus der Marinekompanie-App</h1>
            <p>Kopiert Punkte, Strafen, Dienstgrade, Titel, Anwesenheit, Dokumente, Galerie, öffentliche Termine, Push-Abos,
               Rollen und Einstellungen aus dem Plugin <code>mk-lank-app</code> in die Kompanie-App. Die bisherigen Daten bleiben
               unverändert; ein Zurückschalten auf das alte Plugin ist möglich. Ein weiterer Lauf übernimmt nur Neues.</p>
            <ol>
                <li>Sicherung von Datenbank und <code>wp-content/uploads/</code> erstellen.</li>
                <li>Plugin <code>mk-lank-app</code> deaktivieren (nicht löschen).</li>
                <li>Probelauf, dann übernehmen. Danach die Prüfung unten und die App kontrollieren.</li>
            </ol>
            <form method="post">
                <?php wp_nonce_field('mkl_uebernahme'); ?>
                <p><button class="button" name="mkl_uebernahme" value="probelauf">Probelauf</button></p>
                <p><label><input type="checkbox" name="mkl_backup" value="1"> Sicherung ist erstellt</label></p>
                <p><button class="button button-primary" name="mkl_uebernahme" value="uebernehmen">Jetzt übernehmen</button></p>
            </form>
            <?php
            if ($report !== null) {
                echo Report::html($report); // phpcs:ignore WordPress.Security.EscapeOutput -- in Report::html maskiert
            }
            ?>
        </div>
        <?php
    }
}
