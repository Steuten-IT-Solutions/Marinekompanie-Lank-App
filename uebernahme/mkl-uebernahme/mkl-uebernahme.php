<?php
/**
 * Plugin Name: Marinekompanie – Übernahme in die Kompanie-App
 * Description: Übernimmt die Daten von mk-lank-app in die Kompanie-App (Probelauf, Bericht) und hält die bisherigen Adressen und Shortcodes erreichbar. Nach der Aufräumfrist löschen.
 * Version: 1.0.0
 * Requires PHP: 8.1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'MklUebernahme\\')) {
        $file = __DIR__ . '/includes/' . substr($class, strlen('MklUebernahme\\')) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

add_action('plugins_loaded', static function (): void {
    if (!class_exists(\KompanieApp\WordPress\Plugin::class)) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>Die Übernahme braucht das aktive Plugin der Kompanie-App.</p></div>';
        });
        return;
    }
    MklUebernahme\Aliases::register();
    MklUebernahme\AdminPage::register();
    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('mkl-uebernahme', MklUebernahme\Cli::class);
    }
}, 20);
