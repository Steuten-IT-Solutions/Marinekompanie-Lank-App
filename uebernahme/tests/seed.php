<?php
/**
 * Synthetischer Datenbestand von mk-lank-app (läuft mit aktivem mk-lank-app):
 *   wp eval-file uebernahme/tests/seed.php
 * Merkt sich Prüfsummen der Lank-Tabellen (Option mkl_test_checksums) für den Rückfalltest.
 */

if (!defined('ABSPATH')) {
    exit(1);
}
global $wpdb;
$t = static fn (string $n): string => $wpdb->prefix . 'mkl_' . $n;
$uid = static fn (string $login): int => (int) get_user_by('login', $login)->ID;
$now = gmdate('Y-m-d H:i:s');
$admin = $uid('admin');

// Konten: Spieß, zwei Mitglieder, Anwärter (aus setup-test-wp.sh), dazu ein Jungmariner und ein reines Webseiten-Konto.
foreach (['jung' => 'mkl_mitglied', 'redakteur' => 'editor'] as $login => $role) {
    if (!get_user_by('login', $login)) {
        wp_insert_user(['user_login' => $login, 'user_pass' => 'test1234', 'user_email' => "$login@example.com", 'display_name' => ucfirst($login), 'role' => $role]);
    }
}
update_user_meta($uid('jung'), 'mkl_jungmariner', '1');
[$spiess, $m1, $m2, $anw, $jung] = [$uid('spiess'), $uid('mariner1'), $uid('mariner2'), $uid('anwaerter'), $uid('jung')];

$ins = static function (string $table, array $row) use ($wpdb, $t): int {
    $wpdb->insert($t($table), $row);
    return (int) $wpdb->insert_id;
};

$cat1 = $ins('point_catalog', ['title' => 'Arbeitsdienst', 'points' => 3, 'active' => 1, 'sort' => 1]);
$ins('point_catalog', ['title' => 'Schützenfest', 'points' => 10, 'active' => 1, 'sort' => 2]);
$r1 = $ins('ranks', ['title' => 'Matrose', 'min_points' => 0, 'active' => 1]);
$r3 = $ins('ranks', ['title' => 'Bootsmann', 'min_points' => 20, 'active' => 1]);
$r2 = $ins('ranks', ['title' => 'Obermatrose', 'min_points' => 10, 'active' => 1]);
update_user_meta($m1, 'mkl_rank_id', $r2);
update_user_meta($spiess, 'mkl_rank_id', $r3);
$titles = $wpdb->get_col('SELECT id FROM ' . $t('titles') . ' ORDER BY id');
add_user_meta($m1, 'mkl_title_id', $titles[3]);
add_user_meta($m1, 'mkl_title_id', $titles[0]);
$ins('rank_history', ['user_id' => $m1, 'from_rank_id' => null, 'to_rank_id' => $r1, 'from_title' => '', 'to_title' => 'Matrose', 'kind' => 'befoerderung', 'points_at_change' => 0, 'reason' => 'Ersteinstufung bei Anlage', 'changed_by' => $admin, 'changed_at' => '2026-01-01 10:00:00']);
$ins('rank_history', ['user_id' => $m1, 'from_rank_id' => $r1, 'to_rank_id' => $r2, 'from_title' => 'Matrose', 'to_title' => 'Obermatrose', 'kind' => 'befoerderung', 'points_at_change' => 11, 'reason' => 'Schwelle erreicht', 'changed_by' => $spiess, 'changed_at' => '2026-05-01 10:00:00']);
$ins('rank_history', ['user_id' => $m2, 'from_rank_id' => $r2, 'to_rank_id' => $r1, 'from_title' => 'Obermatrose', 'to_title' => 'Matrose', 'kind' => 'degradierung', 'points_at_change' => 4, 'reason' => 'Fehlverhalten', 'changed_by' => $spiess, 'changed_at' => '2026-06-01 10:00:00']);

$l1 = $ins('attendance_lists', ['source' => 'kalender', 'event_key' => md5('cal|e1'), 'title' => 'Kompanieabend', 'event_date' => '2026-09-01', 'created_by' => $spiess, 'created_at' => $now, 'updated_by' => $spiess, 'updated_at' => $now]);
$l2 = $ins('attendance_lists', ['source' => 'frei', 'event_key' => str_replace('-', '', wp_generate_uuid4()), 'title' => 'Arbeitsdienst', 'event_date' => '2026-09-05', 'created_by' => $spiess, 'created_at' => $now, 'updated_by' => $admin, 'updated_at' => $now]);
$ins('attendance', ['list_id' => $l1, 'user_id' => $m1, 'status' => 'anwesend', 'updated_by' => $spiess]);
$ins('attendance', ['list_id' => $l1, 'user_id' => $m2, 'status' => 'entschuldigt', 'updated_by' => null]);
$ins('attendance', ['list_id' => $l2, 'user_id' => $m2, 'status' => 'fehlt', 'updated_by' => $spiess]);

$p = static fn (int $user, int $points, int $halved = 0, ?int $att = null, ?string $deleted = null, ?int $cat = null): int => $ins('points', [
    'user_id' => $user, 'points' => $points, 'halved' => $halved, 'reason' => 'Test', 'catalog_id' => $cat, 'attendance_id' => $att,
    'entry_date' => '2026-09-01', 'created_by' => $spiess, 'created_at' => $now, 'deleted_at' => $deleted, 'deleted_by' => $deleted ? $spiess : null,
]);
$p($m1, 5, 0, null, null, $cat1);
$p($m1, 3, 0, $l1);
$p($m1, 7, 0, null, $now); // storniert
$p($jung, 5, 1);
$p($m2, -2);
$p(99999, 4); // Konto gibt es nicht mehr
$pen = static fn (int $user, int $cents, string $status, ?string $deleted = null): int => $ins('penalties', [
    'user_id' => $user, 'amount_cents' => $cents, 'reason' => 'Zu spät', 'entry_date' => '2026-09-01', 'status' => $status,
    'paid_at' => $status === 'paid' ? $now : null, 'paid_marked_by' => $status === 'paid' ? $spiess : null,
    'created_by' => $spiess, 'created_at' => $now, 'deleted_at' => $deleted, 'deleted_by' => $deleted ? $spiess : null,
]);
$pen($m1, 250, 'open');
$pen($m1, 100, 'paid');
$pen($m2, 500, 'open', $now);

$ins('events', ['title' => 'Maifest', 'start_date' => '2027-05-01', 'start_time' => null, 'end_date' => null, 'end_time' => null, 'location' => 'Schulhof', 'description' => '', 'url' => '', 'created_by' => $admin, 'created_at' => $now, 'updated_at' => $now]);
$ins('events', ['title' => 'Kompanieabend', 'start_date' => '2027-03-01', 'start_time' => '19:00:00', 'end_date' => null, 'end_time' => '23:00:00', 'location' => 'Vereinslokal', 'description' => 'Bitte pünktlich', 'url' => '', 'created_by' => $admin, 'created_at' => $now, 'updated_at' => $now]);

// Dokumente (eines ohne Datei) und Galerie (eines nicht fertig, eines ohne Dateien).
$up = wp_upload_dir()['basedir'];
@mkdir("$up/mkl-private", 0775, true);
$c1 = $ins('doc_categories', ['title' => 'Satzung', 'sort' => 1]);
$pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
file_put_contents("$up/mkl-private/dok-AbCdEfGhIj0123456789.pdf", $pdf);
$ins('documents', ['title' => 'Satzung', 'category_id' => $c1, 'doc_date' => '2025-01-01', 'visibility' => 'mitglieder', 'file' => 'dok-AbCdEfGhIj0123456789.pdf', 'ext' => 'pdf', 'size' => strlen($pdf), 'version' => substr(hash('sha256', $pdf), 0, 16), 'uploaded_by' => 0, 'created_at' => $now, 'updated_at' => $now]);
file_put_contents("$up/mkl-private/dok-KassenberichtXYZ1234.PDF", $pdf);
$ins('documents', ['title' => 'Kassenbericht', 'category_id' => null, 'doc_date' => '2026-03-01', 'visibility' => 'spiess', 'file' => 'dok-KassenberichtXYZ1234.PDF', 'ext' => 'PDF', 'size' => strlen($pdf), 'version' => 'abc', 'uploaded_by' => $admin, 'created_at' => $now, 'updated_at' => $now]);
$ins('documents', ['title' => 'Verloren', 'category_id' => $c1, 'doc_date' => '2026-03-01', 'visibility' => 'mitglieder', 'file' => 'dok-gibtesnicht00000000.pdf', 'ext' => 'pdf', 'size' => 1, 'version' => 'x', 'uploaded_by' => $admin, 'created_at' => $now, 'updated_at' => $now]);

$album = $ins('albums', ['drive_id' => 'ALBUM1', 'year' => '2026', 'title' => 'Sommerfest', 'sort_date' => '2026-07-01 12:00:00', 'synced_at' => $now]);
$photo = static function (string $drive, int $intern, string $status, bool $files) use ($ins, $album, $up): int {
    $key = bin2hex(random_bytes(16));
    $dir = $intern ? "$up/mkl-gallery-intern" : "$up/mkl-gallery/$album";
    @mkdir($dir, 0775, true);
    if ($files) {
        foreach (['thumb', 'large'] as $s) {
            file_put_contents("$dir/$key-$s.jpg", "JPEG-$drive-$s");
        }
    }
    return $ins('photos', ['album_id' => $album, 'drive_file_id' => $drive, 'name' => "$drive.jpg", 'is_intern' => $intern, 'width' => 1600, 'height' => 1200, 'taken_at' => null, 'md5' => md5($drive), 'file_key' => $key, 'status' => $status, 'error' => '']);
};
$photo('F1', 0, 'ready', true);
$photo('F2', 1, 'ready', true);
$photo('F3', 0, 'pending', false);
$photo('F4', 0, 'ready', false);

// Push: Schlüssel wie mk-lank-app (öffentlich roh base64url, privat PEM), ein Mitglied und ein Gast.
$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
openssl_pkey_export($key, $pem);
$d = openssl_pkey_get_details($key)['ec'];
$b64u = static fn (string $b): string => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
update_option('mkl_push_vapid', ['public' => $b64u("\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT)), 'private' => $pem], false);
$sub = static fn (int $user, string $types, int $details) => $ins('push_subscriptions', [
    'user_id' => $user, 'endpoint' => 'https://push.example.org/' . $user . '-' . $types, 'endpoint_hash' => hash('sha256', 'https://push.example.org/' . $user . '-' . $types),
    'p256dh' => $b64u(random_bytes(65)), 'auth' => $b64u(random_bytes(16)), 'types' => $types, 'details' => $details, 'created_at' => $now, 'updated_at' => $now, 'last_sent_at' => null,
]);
$sub($m1, 'strafen,punkte,dienstgrad,termine', 1);
$sub(0, 'termine,news', 0);
$sub(99999, 'termine', 0);

update_option('mkl_ics_url', 'https://calendar.example.org/basic.ics');
update_option('mkl_calendar_cache_minutes', 30);
update_option('mkl_gallery_root', 'ROOTFOLDER');
// Mit den bei Google erlaubten Berechtigungen (leerzeichengetrennt, wie Google sie liefert).
update_option('mkl_drive_oauth', ['client_id' => 'CID', 'client_secret' => 'SECRET', 'refresh_token' => 'REFRESH', 'email' => 'galerie@example.org', 'scope' => 'https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/calendar.readonly'], false);

// Prüfsummen für den Rückfalltest.
$sums = [];
foreach (['point_catalog', 'points', 'penalties', 'events', 'albums', 'photos', 'ranks', 'rank_history', 'documents', 'doc_categories', 'titles', 'attendance_lists', 'attendance', 'push_subscriptions'] as $n) {
    $sums[$n] = md5((string) wp_json_encode($wpdb->get_results('SELECT * FROM ' . $t($n) . ' ORDER BY id', ARRAY_A)));
}
update_option('mkl_test_checksums', $sums, false);
echo "Lank-Testdaten angelegt.\n";
