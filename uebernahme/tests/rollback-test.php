<?php
/**
 * Rückfall: Kompanie-App und Übernahme deaktiviert, mk-lank-app wieder aktiv. Die Daten von Lank sind unverändert.
 *   wp eval-file uebernahme/tests/rollback-test.php
 */

if (!defined('ABSPATH')) {
    exit(1);
}
global $wpdb;
$bad = [];
foreach ((array) get_option('mkl_test_checksums', []) as $n => $sum) {
    $now = md5((string) wp_json_encode($wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . 'mkl_' . $n . ' ORDER BY id', ARRAY_A)));
    if ($now !== $sum) {
        $bad[] = $n;
    }
}
$m1 = get_user_by('login', 'mariner1');
wp_set_current_user($m1->ID);
$res = rest_do_request(new WP_REST_Request('GET', '/mk-lank/v1/me/points'));
$okRest = $res->get_status() === 200;
$okLevel = MKL_Roles::get_level($m1->ID) === MKL_Roles::LEVEL_MITGLIED;
echo ($bad === [] ? '  ok   ' : '  FEHL ') . 'Lank-Tabellen unverändert' . ($bad ? ': ' . implode(', ', $bad) : '') . "\n";
echo ($okRest ? '  ok   ' : '  FEHL ') . "Altes Plugin liefert wieder Punkte\n";
echo ($okLevel ? '  ok   ' : '  FEHL ') . "Rolle in Lank unverändert\n";
exit($bad === [] && $okRest && $okLevel ? 0 : 1);
