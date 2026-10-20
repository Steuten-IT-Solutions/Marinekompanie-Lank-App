<?php
/**
 * Tests der Übernahme (mk-lank-app deaktiviert, Kompanie-App und Übernahme aktiv):
 *   wp eval-file uebernahme/tests/run-tests.php
 * Exit-Code 1, wenn ein Test fehlschlägt.
 */

if (!defined('ABSPATH')) {
    exit(1);
}

use KompanieApp\Core\Http\Request;
use KompanieApp\WordPress\Plugin;
use MklUebernahme\Importer;

$passed = 0;
$failed = [];
$ok = static function (bool $cond, string $label) use (&$passed, &$failed): void {
    echo ($cond ? '  ok   ' : '  FEHL ') . $label . "\n";
    $cond ? $passed++ : $failed[] = $label;
};
global $wpdb;
$app = Plugin::app();
$db = $app->db;
$uid = static fn (string $login): int => (int) get_user_by('login', $login)->ID;
[$spiess, $m1, $m2, $anw, $jung, $red] = [$uid('spiess'), $uid('mariner1'), $uid('mariner2'), $uid('anwaerter'), $uid('jung'), $uid('redakteur')];
$count = static fn (string $table): int => (int) $db->scalar('SELECT COUNT(*) FROM {' . $table . '}');
$run = static fn (bool $dry): array => (new Importer(Plugin::app(), $wpdb))->run($dry);
$has = static fn (array $list, string $needle): bool => array_filter($list, static fn (string $s): bool => str_contains($s, $needle)) !== [];

echo "== Probelauf ==\n";
$r = $run(true);
$ok($r['ok'] && $r['errors'] === [], 'Probelauf ohne Fehler: ' . implode(' | ', $r['errors']));
$ok($count('points') === 0 && $count('documents') === 0 && get_option(Importer::STATE) === false, 'Probelauf schreibt nichts');
$ok($r['tables']['points']['new'] === 5 && $r['tables']['points']['skipped'] === 1, 'Punkte: 5 neu, 1 übersprungen (Konto fehlt)');
$ok($r['tables']['documents']['new'] === 2 && $has($r['warnings'], 'dok-gibtesnicht'), 'Dokument ohne Datei übersprungen und gemeldet');
$ok($has($r['warnings'], 'wird neu aus Google Drive aufbereitet'), 'Bild ohne Dateien gemeldet');
$roles = $r['roles'];
$want = ['Spieß' => 1, 'Jungmariner' => 1, $app->roles()->find('candidate')['label'] => 1, $app->roles()->find('member')['label'] => 2];
ksort($roles);
ksort($want);
$ok($roles === $want, 'Rollen geplant (mit Bezeichnungen): ' . wp_json_encode($r['roles'], JSON_UNESCAPED_UNICODE));
$ok(!is_dir(wp_upload_dir()['basedir'] . '/kompanie-galerie-intern/dokumente') || glob(wp_upload_dir()['basedir'] . '/kompanie-galerie-intern/dokumente/*') === [], 'Probelauf kopiert keine Dateien');

// Konflikt: eine eigene Zeile der Kompanie-App mit einer ID aus Lank.
$db->insert('events', ['id' => 1, 'title' => 'Eigener Termin', 'start_date' => '2027-01-01', 'location' => '', 'description' => '', 'url' => '', 'created_by' => 1, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
$r = $run(false);
$ok(!$r['ok'] && $has($r['errors'], 'Öffentliche Termine') && $count('points') === 0, 'Konflikt: nichts übernommen, klare Meldung');
$db->execute('DELETE FROM {events}');

echo "\n== Übernahme ==\n";
$r = $run(false);
$ok($r['ok'], 'Übernahme in Ordnung: ' . implode(' | ', array_merge($r['errors'], array_map(static fn ($c) => $c['ok'] ? '' : $c['label'] . ': ' . $c['detail'], $r['checks']))));
$ok(count($r['checks']) >= 15 && !in_array(false, array_column($r['checks'], 'ok'), true), 'Alle Prüfungen bestanden');

$pts = $db->select('SELECT id, user_id, points, factor, attendance_id, deleted_at FROM {points} ORDER BY id');
$ok(count($pts) === 5 && (float) $pts[3]['factor'] === 0.5 && (float) $pts[0]['factor'] === 1.0, 'Punkte mit Faktor (Jungmariner 0,5)');
$ok((int) $pts[1]['attendance_id'] > 0 && $pts[2]['deleted_at'] !== null, 'Verweis auf Anwesenheitsliste und Storno bleiben');
$ok($db->select("SELECT status FROM {attendance_entries} ORDER BY id") === [['status' => 'present'], ['status' => 'excused'], ['status' => 'absent']], 'Anwesenheit: Status übersetzt');
$ok((int) $db->scalar('SELECT updated_by FROM {attendance_entries} WHERE user_id = ? AND status = ?', [$m2, 'excused']) === $spiess, 'Fehlendes updated_by aus der Liste');
$ok(array_column($db->select('SELECT source FROM {attendance_lists} ORDER BY id'), 'source') === ['calendar', 'free'], 'Listen: Quelle übersetzt, Kalender-ID bleibt');
$ok(array_column($db->select('SELECT kind FROM {rank_history} ORDER BY id'), 'kind') === ['initial', 'promotion', 'demotion'], 'Dienstgrad-Verlauf: Art übersetzt');
$ok(array_column($db->select('SELECT title FROM {ranks} ORDER BY sort'), 'title') === ['Matrose', 'Obermatrose', 'Bootsmann'], 'Dienstgrade nach Punkteschwelle sortiert');
$ok($db->scalar('SELECT assigned_at FROM {member_ranks} WHERE user_id = ?', [$m1]) === '2026-05-01 10:00:00', 'Dienstgrad seit der letzten Beförderung');
$ok($count('member_titles') === 2 && $count('titles') === 4, 'Titel und Zuordnungen');
$docs = $db->select('SELECT id, file, ext, visibility FROM {documents} ORDER BY id');
$ok(count($docs) === 2 && preg_match('/^dok-[0-9a-f]{24}\.pdf$/', $docs[1]['file']) === 1 && $docs[1]['visibility'] === 'managers', 'Dokumente: neuer Dateiname, Endung klein, Sichtbarkeit übersetzt');
$ok(is_file($app->files->path('dokumente/' . $docs[0]['file'], false)), 'Dokumentdatei kopiert');
wp_set_current_user($m1);
$ok($app->documents()->forDelivery($app->users->find($m1), (int) $docs[0]['id'])['status'] === 200, 'Dokument unter derselben ID auslieferbar');
// Die Lanker Testinstanz bringt eine Beispiel-Galerie mit; hier zählen die Bilder F1–F4 aus seed.php.
$photos = $db->select("SELECT * FROM {photos} WHERE drive_file_id IN ('F1', 'F2', 'F3', 'F4') ORDER BY drive_file_id");
$ok(array_column($photos, 'status') === ['ready', 'ready', 'pending', 'pending'], 'Bilder: fertige bleiben fertig, sonst neu aufbereiten');
$ok(is_file($app->gallery()->path($photos[0], 'large')) && is_file($app->gallery()->path($photos[1], 'thumb')), 'Bilddateien an den Pfaden der Kompanie-App (öffentlich und intern)');
$subs = $db->select('SELECT user_id, types, details FROM {push_subscriptions} ORDER BY id');
$ok($subs === [['user_id' => (string) $m1, 'types' => 'penalties,points,ranks,events', 'details' => '1'], ['user_id' => '0', 'types' => 'events,news', 'details' => '0']]
    || $subs === [['user_id' => $m1, 'types' => 'penalties,points,ranks,events', 'details' => 1], ['user_id' => 0, 'types' => 'events,news', 'details' => 0]], 'Push-Abos mit übersetzten Arten, Gast bleibt Gast');
$ok($app->push()->publicKey() === get_option('mkl_push_vapid')['public'], 'Push-Schlüssel übernommen');
$ok($app->settings->get('calendar_ics_url') === 'https://calendar.example.org/basic.ics' && (int) $app->settings->get('calendar_cache_minutes') === 30, 'Kalender-Einstellungen');
$ok($app->gallery()->root() === 'ROOTFOLDER' && $app->drive()->oauthEmail() === 'galerie@example.org', 'Galerie-Ordner und Google-Verbindung');
$lankAreas = ['events', 'calendar', 'news', 'gallery', 'points', 'penalties', 'ranks', 'titles', 'attendance', 'documents'];
$ok(array_intersect_key($app->features()->all(), array_flip($lankAreas)) == array_fill_keys($lankAreas, true), 'Alle Bereiche von Lank eingeschaltet (neue Bereiche des Kerns bleiben aus)');

$role = static fn (int $id): ?string => $app->users->find($id)?->role;
$roles = array_column($app->roles()->all(), null, 'key');
$ok($roles[$role($spiess)]['label'] === 'Spieß' && in_array('manage_points', $roles[$role($spiess)]['capabilities'], true), 'Spieß: eigene Rolle mit Punkten, Strafen, Anwesenheit …');
$ok(in_array('manage_documents', $roles[$role($spiess)]['capabilities'], true) && $app->documents()->forDelivery($app->users->find($spiess), (int) $docs[1]['id'])['status'] === 200 && $app->documents()->forDelivery($app->users->find($m1), (int) $docs[1]['id'])['status'] === 404, 'Spieß sieht Spieß-Dokumente wie in Lank (Recht „Dokumente verwalten“), Mitglied nicht');
$ok($roles[$role($jung)]['label'] === 'Jungmariner' && (float) $roles[$role($jung)]['points_factor'] === 0.5, 'Jungmariner: Rolle mit Faktor 0,5');
$ok($role($m1) === 'member' && $role($anw) === 'candidate' && $role($red) === 'guest' && $role(1) === 'admin', 'Mitglied, Anwärter, Webseiten-Konto ohne Rolle, Admin');

$me = $app->api()->handle(new Request('GET', '/me/points', [], []));
$ok($me->status === 200 && count($me->data) === 2 && abs((float) $app->scores()->pointsTotal($m1) - 8.0) < 0.001, 'Mitglied sieht seine Punkte (2 Einträge ohne Storno, zusammen 8)');
wp_set_current_user(0);

echo "\n== Wiederholung ==\n";
$r = $run(false);
$ok($r['ok'] && array_sum(array_column($r['tables'], 'new')) === 0 && $r['tables']['points']['existing'] === 5, 'Zweiter Lauf legt nichts doppelt an');
$ok(!$has($r['warnings'], 'Einstellung'), 'Zweiter Lauf: übernommene Einstellungen gelten nicht als abweichend');
$wpdb->insert($wpdb->prefix . 'mkl_points', ['user_id' => $m2, 'points' => 6, 'halved' => 0, 'reason' => 'Nachtrag', 'entry_date' => '2026-10-01', 'created_by' => $spiess, 'created_at' => gmdate('Y-m-d H:i:s')]);
$r = $run(false);
$ok($r['ok'] && $r['tables']['points']['new'] === 1 && $count('points') === 6, 'Neuer Eintrag in Lank wird nachgetragen');
$wpdb->delete($wpdb->prefix . 'mkl_points', ['reason' => 'Nachtrag']); // Prüfsummen für den Rückfalltest
$db->execute("DELETE FROM {points} WHERE reason = 'Nachtrag'");

echo "\n== Bisherige Adressen ==\n";
do_action('rest_api_init', rest_get_server());
$res = rest_do_request(new WP_REST_Request('GET', '/mk-lank/v1/config'));
$ok($res->get_status() === 200, 'REST-Namensraum mk-lank/v1 als Alias');
$ok(do_shortcode('[mkl_termine]') === do_shortcode('[kompanie_termine anzahl="100"]') && str_contains(do_shortcode('[mkl_termine anzahl="1"]'), 'Kompanieabend'), '[mkl_termine] zeigt die Termine der Kompanie-App');
$ok(do_shortcode('[mkl_galerie]') === do_shortcode('[kompanie_galerie]'), '[mkl_galerie] zeigt die Galerie der Kompanie-App');

printf("\n%d bestanden, %d fehlgeschlagen\n", $passed, count($failed));
if ($failed) {
    echo "Fehlgeschlagen:\n  - " . implode("\n  - ", $failed) . "\n";
    exit(1);
}
