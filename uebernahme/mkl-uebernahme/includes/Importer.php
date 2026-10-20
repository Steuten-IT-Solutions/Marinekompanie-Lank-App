<?php

declare(strict_types=1);

namespace MklUebernahme;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\DocumentService;
use KompanieApp\Core\Domain\GalleryService;
use KompanieApp\Core\Storage\GalleryRepository;
use KompanieApp\WordPress\WpUsers;

/**
 * Kopiert die Daten des Plugins mk-lank-app in die Tabellen und Einstellungen der Kompanie-App (Issue #25).
 *
 * - Nicht zerstörend: Tabellen wp_mkl_*, Optionen mkl_* und User-Meta bleiben unverändert (Rückfall möglich).
 * - IDs bleiben gleich. Was übernommen wurde, merkt sich die Option STATE; ein weiterer Lauf überspringt es
 *   und legt nur Neues an. Eine Zeile, die es im Kern mit derselben ID schon gibt, ohne dass sie übernommen
 *   wurde, ist ein Konflikt: Dann wird nichts geschrieben.
 * - Probelauf: plant alles, prüft Dateien und Konflikte, schreibt nichts.
 */
final class Importer
{
    public const STATE = 'mkl_uebernahme_state';
    public const LANK_DB_VERSION = '11';

    public const ROLE_MANAGER = 'Spieß';
    public const ROLE_YOUNG = 'Jungmariner';
    /** Rechte des Spießes in Lank (mkl_manage): Punkte, Strafen, Anwesenheit, Dienstgrade, Titel, Katalog. */
    public const MANAGER_CAPS = ['member', 'calendar', 'manage_points', 'manage_penalties', 'manage_ranks', 'manage_titles', 'manage_attendance'];

    private const PUSH_TYPES = ['strafen' => 'penalties', 'punkte' => 'points', 'dienstgrad' => 'ranks', 'termine' => 'events', 'erinnerung' => 'reminder', 'news' => 'news'];
    private const ATTENDANCE_STATUS = ['anwesend' => 'present', 'entschuldigt' => 'excused', 'fehlt' => 'absent'];
    private const INITIAL_REASON = 'Ersteinstufung bei Anlage';

    /** Einstellungen: Option in Lank => Einstellung im Kern. */
    private const SETTINGS = [
        'mkl_calendar_ids' => 'calendar_ids',
        'mkl_google_api_key' => 'google_api_key',
        'mkl_ics_url' => 'calendar_ics_url',
        'mkl_calendar_cache_minutes' => 'calendar_cache_minutes',
        'mkl_gallery_root' => 'gallery_root',
        'mkl_gallery_status' => 'gallery_status',
        'mkl_push_reminder_date' => 'push_reminder_date',
    ];

    /** @var list<string> */
    private array $errors = [];
    /** @var list<string> */
    private array $warnings = [];
    /** @var array<string, array{label: string, source: int, new: int, existing: int, skipped: int}> */
    private array $tables = [];
    /** @var array<string, array<string, true>> übernommene Schlüssel je Tabelle (aus STATE) */
    private array $imported = [];
    /** @var array<int, string> geplante Rolle je Konto */
    private array $roles = [];
    /** @var array<int, true> */
    private array $users = [];
    /** @var list<array{from: string, rel: string, public: bool, temporary?: bool}> zu kopierende Dateien */
    private array $copies = [];

    public function __construct(private readonly App $app, private readonly \wpdb $wpdb)
    {
    }

    /**
     * @return array{ok: bool, dry_run: bool, errors: list<string>, warnings: list<string>,
     *   tables: array<string, array{label: string, source: int, new: int, existing: int, skipped: int}>,
     *   roles: array<string, int>, checks: list<array{label: string, ok: bool, detail: string}>}
     */
    public function run(bool $dryRun): array
    {
        $this->preconditions($dryRun);
        $plan = [];
        $settings = [];
        if ($this->errors === []) {
            $state = get_option(self::STATE, []);
            foreach ((array) ($state['ids'] ?? []) as $table => $keys) {
                $this->imported[(string) $table] = array_fill_keys(array_map('strval', (array) $keys), true);
            }
            foreach (get_users(['fields' => 'ID']) as $id) {
                $this->users[(int) $id] = true;
            }
            $this->planRoles();
            $plan = $this->planRows();
            $settings = $this->planSettings();
        }
        $checks = [];
        if (!$dryRun && $this->errors === []) {
            // Viele Bilder zu kopieren dauert; über WP-CLI gibt es ohnehin keine Grenze.
            @set_time_limit(0);
            try {
                $this->writeRows($plan);
            } catch (\Throwable $e) {
                $this->errors[] = 'Übernahme abgebrochen, nichts in die Tabellen geschrieben: ' . $e->getMessage();
            }
            if ($this->errors === []) {
                try {
                    $this->writeConfiguration($settings);
                } catch (\Throwable $e) {
                    $this->errors[] = 'Die Daten sind übernommen, Bereiche, Rollen oder Einstellungen aber nicht vollständig: ' . $e->getMessage() . ' Nach dem Beheben die Übernahme wiederholen.';
                }
                $checks = $this->verify();
            }
        }
        $roleCounts = [];
        foreach ($this->roles as $role) {
            $label = $this->app->roles()->find($role)['label'] ?? $role;
            $roleCounts[$label] = ($roleCounts[$label] ?? 0) + 1;
        }
        return [
            'ok' => $this->errors === [] && !in_array(false, array_column($checks, 'ok'), true),
            'dry_run' => $dryRun,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'tables' => $this->tables,
            'roles' => $roleCounts,
            'checks' => $checks,
        ];
    }

    /* ---------------------------------------------------------------- */
    /* Voraussetzungen                                                  */
    /* ---------------------------------------------------------------- */

    private function preconditions(bool $dryRun): void
    {
        if (!$this->lankTableExists('points')) {
            $this->errors[] = 'Die Tabellen des Plugins mk-lank-app fehlen (' . $this->lank('points') . ').';
            return;
        }
        $version = (string) get_option('mkl_db_version', '');
        if ($version !== self::LANK_DB_VERSION) {
            $this->warnings[] = sprintf('Datenbankstand von mk-lank-app ist „%s“, geprüft wurde mit „%s“.', $version, self::LANK_DB_VERSION);
        }
        if (function_exists('is_plugin_active') && is_plugin_active('mk-lank-app/mk-lank-app.php')) {
            $msg = 'Das Plugin mk-lank-app ist noch aktiv. Vor der Übernahme deaktivieren (nicht löschen).';
            $dryRun ? $this->warnings[] = $msg : $this->errors[] = $msg;
        }
        foreach (['ranks', 'titles', 'attendance_lists', 'documents', 'push_subscriptions'] as $t) {
            try {
                $this->app->db->scalar('SELECT COUNT(*) FROM {' . $t . '}');
            } catch (\Throwable) {
                $this->errors[] = 'Die Tabelle ' . $t . ' der Kompanie-App fehlt. Ist das Plugin auf dem neuesten Stand?';
            }
        }
    }

    /* ---------------------------------------------------------------- */
    /* Planung                                                          */
    /* ---------------------------------------------------------------- */

    /** Rolle in der App je Konto: Spieß, Jungmariner, Mitglied, Anwärter; Administratoren bleiben Admins. */
    private function planRoles(): void
    {
        foreach (array_keys($this->users) as $id) {
            $u = get_userdata($id);
            if (!$u || in_array('administrator', (array) $u->roles, true)) {
                continue;
            }
            $young = (string) get_user_meta($id, 'mkl_jungmariner', true) === '1';
            $role = match (true) {
                user_can($u, 'mkl_manage') => self::ROLE_MANAGER,
                user_can($u, 'mkl_member') => $young ? self::ROLE_YOUNG : Roles::MEMBER,
                user_can($u, 'mkl_calendar') => Roles::CANDIDATE,
                default => null,
            };
            if ($role === null) {
                continue;
            }
            if ($young && $role !== self::ROLE_YOUNG) {
                $this->warnings[] = sprintf('%s ist als Jungmariner markiert, wird aber %s; Punkte zählen künftig voll.', $u->display_name, $role);
            }
            $this->roles[$id] = $role;
        }
    }

    /**
     * Zeilen je Zieltabelle, schon umgesetzt in das Format des Kerns.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function planRows(): array
    {
        $plan = [];
        $lists = [];
        foreach ($this->lankRows('attendance_lists') as $l) {
            $lists[(int) $l['id']] = $l;
        }
        $rankSort = [];
        $ranks = $this->lankRows('ranks');
        usort($ranks, static fn (array $a, array $b): int => [(int) $a['min_points'], (int) $a['id']] <=> [(int) $b['min_points'], (int) $b['id']]);
        foreach ($ranks as $i => $r) {
            $rankSort[(int) $r['id']] = $i + 1;
        }
        $now = gmdate('Y-m-d H:i:s');

        $plan['point_catalog'] = $this->plan('point_catalog', 'Punktekatalog', $this->lankRows('point_catalog'), static fn (array $r): array => self::pick($r, ['id', 'title', 'points', 'active', 'sort']));
        $plan['ranks'] = $this->plan('ranks', 'Dienstgrade', $ranks, static fn (array $r): array => self::pick($r, ['id', 'title', 'min_points', 'active']) + ['sort' => $rankSort[(int) $r['id']]]);
        $plan['titles'] = $this->plan('titles', 'Titel', $this->lankRows('titles'), static fn (array $r): array => self::pick($r, ['id', 'title', 'sort']) + ['active' => 1]);
        $plan['doc_categories'] = $this->plan('doc_categories', 'Dokument-Kategorien', $this->lankRows('doc_categories'), static fn (array $r): array => self::pick($r, ['id', 'title', 'sort']));
        $plan['events'] = $this->plan('events', 'Öffentliche Termine', $this->lankRows('events'), static fn (array $r): array => self::pick($r, ['id', 'title', 'start_date', 'start_time', 'end_date', 'end_time', 'location', 'description', 'url', 'created_by', 'created_at', 'updated_at']));
        $plan['albums'] = $this->plan('albums', 'Alben', $this->lankRows('albums'), static fn (array $r): array => self::pick($r, ['id', 'drive_id', 'year', 'title', 'sort_date', 'synced_at']));
        $plan['photos'] = $this->plan('photos', 'Bilder', $this->lankRows('photos'), fn (array $r): array => $this->photo($r));
        $plan['attendance_lists'] = $this->plan('attendance_lists', 'Anwesenheitslisten', array_values($lists), static fn (array $r): array => [
            'source' => $r['source'] === 'kalender' ? 'calendar' : 'free',
        ] + self::pick($r, ['id', 'event_key', 'title', 'event_date', 'created_by', 'created_at', 'updated_by', 'updated_at']));
        $plan['attendance_entries'] = $this->plan('attendance_entries', 'Anwesenheit', $this->lankRows('attendance'), fn (array $r): ?array => $this->userRow($r, [
            'id' => (int) $r['id'],
            'list_id' => (int) $r['list_id'],
            'user_id' => (int) $r['user_id'],
            'status' => self::ATTENDANCE_STATUS[$r['status']] ?? throw new SkipRow('unbekannter Status „' . $r['status'] . '“'),
            'updated_by' => (int) ($r['updated_by'] ?? 0) ?: (int) ($lists[(int) $r['list_id']]['updated_by'] ?? 0),
            'updated_at' => (string) ($lists[(int) $r['list_id']]['updated_at'] ?? $now),
        ]));
        $plan['points'] = $this->plan('points', 'Punkte', $this->lankRows('points'), fn (array $r): ?array => $this->userRow($r, self::pick($r, ['id', 'user_id', 'points', 'reason', 'catalog_id', 'entry_date', 'created_by', 'created_at', 'deleted_at', 'deleted_by', 'attendance_id']) + [
            'factor' => (int) $r['halved'] === 1 ? 0.5 : 1.0,
        ]));
        $plan['penalties'] = $this->plan('penalties', 'Strafen', $this->lankRows('penalties'), fn (array $r): ?array => $this->userRow($r, self::pick($r, ['id', 'user_id', 'amount_cents', 'reason', 'entry_date', 'status', 'paid_at', 'paid_marked_by', 'created_by', 'created_at', 'deleted_at', 'deleted_by'])));
        $plan['rank_history'] = $this->plan('rank_history', 'Dienstgrad-Verlauf', $this->lankRows('rank_history'), fn (array $r): ?array => $this->userRow($r, self::pick($r, ['id', 'user_id', 'from_rank_id', 'to_rank_id', 'from_title', 'to_title', 'points_at_change', 'reason', 'changed_by', 'changed_at']) + [
            'kind' => match (true) {
                $r['reason'] === self::INITIAL_REASON && $r['from_rank_id'] === null => 'initial',
                $r['kind'] === 'degradierung' => 'demotion',
                default => 'promotion',
            },
        ]));
        $plan['member_ranks'] = $this->plan('member_ranks', 'Dienstgrade der Mitglieder', $this->memberRanks($now), fn (array $r): ?array => $this->userRow($r, $r), ['user_id']);
        $plan['member_titles'] = $this->plan('member_titles', 'Titel der Mitglieder', $this->memberTitles($now), fn (array $r): ?array => $this->userRow($r, $r), ['user_id', 'title_id']);
        $plan['documents'] = $this->plan('documents', 'Dokumente', $this->lankRows('documents'), fn (array $r): array => $this->document($r));
        $plan['push_subscriptions'] = $this->plan('push_subscriptions', 'Push-Abos', $this->lankRows('push_subscriptions'), fn (array $r): ?array => $this->subscription($r));
        return $plan;
    }

    /**
     * @param list<array<string, mixed>> $rows Zeilen aus Lank
     * @param callable(array<string, mixed>): ?array<string, mixed> $map null oder SkipRow = überspringen
     * @param list<string> $key Schlüsselspalten
     * @return list<array<string, mixed>> neu anzulegende Zeilen
     */
    private function plan(string $table, string $label, array $rows, callable $map, array $key = ['id']): array
    {
        $stats = ['label' => $label, 'source' => count($rows), 'new' => 0, 'existing' => 0, 'skipped' => 0];
        $present = [];
        foreach ($this->app->db->select('SELECT ' . implode(', ', $key) . ' FROM {' . $table . '}') as $row) {
            $present[self::key($row, $key)] = true;
        }
        $new = [];
        $conflicts = [];
        foreach ($rows as $row) {
            $k = self::key($row, $key);
            if (isset($this->imported[$table][$k])) {
                $stats['existing']++;
                continue;
            }
            try {
                $mapped = $map($row);
            } catch (SkipRow $e) {
                $this->warnings[] = sprintf('%s %s übersprungen: %s.', $label, $k, $e->getMessage());
                $mapped = null;
            }
            if ($mapped === null) {
                $stats['skipped']++;
                continue;
            }
            if (isset($present[$k])) {
                $conflicts[] = $k;
                continue;
            }
            $new[] = $mapped;
            $stats['new']++;
        }
        if ($conflicts !== []) {
            $this->errors[] = sprintf(
                '%s: %d Einträge gibt es in der Kompanie-App schon mit derselben ID, ohne dass sie übernommen wurden (%s). Die Tabelle %s der Kompanie-App muss vor der Übernahme leer sein.',
                $label,
                count($conflicts),
                implode(', ', array_slice($conflicts, 0, 5)) . (count($conflicts) > 5 ? ', …' : ''),
                $table,
            );
        }
        $this->tables[$table] = $stats;
        return $new;
    }

    /**
     * Zeile eines Kontos: Gibt es das Konto nicht mehr, wird sie übersprungen (Lank zeigt sie auch nicht).
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $mapped
     * @return array<string, mixed>
     */
    private function userRow(array $source, array $mapped): array
    {
        if (!isset($this->users[(int) $source['user_id']])) {
            throw new SkipRow('Konto ' . (int) $source['user_id'] . ' gibt es nicht mehr');
        }
        return $mapped;
    }

    /** @return array<string, mixed> */
    private function photo(array $r): array
    {
        $row = self::pick($r, ['id', 'album_id', 'drive_file_id', 'name', 'is_intern', 'width', 'height', 'taken_at', 'md5', 'file_key', 'status', 'error']) + ['attempts' => 0];
        if ($row['status'] !== GalleryRepository::READY) {
            // Wird im Hintergrund aus Google Drive neu aufbereitet.
            return ['status' => GalleryRepository::PENDING, 'error' => ''] + $row;
        }
        $dir = wp_upload_dir(null, false)['basedir'];
        $copies = [];
        foreach (array_keys(GalleryService::SIZES) as $size) {
            $from = (int) $r['is_intern']
                ? $dir . '/mkl-gallery-intern/' . $r['file_key'] . '-' . $size . '.jpg'
                : $dir . '/mkl-gallery/' . (int) $r['album_id'] . '/' . $r['file_key'] . '-' . $size . '.jpg';
            if (!is_file($from)) {
                $this->warnings[] = sprintf('Bild %d: Datei %s fehlt, wird neu aus Google Drive aufbereitet.', (int) $r['id'], basename($from));
                return ['status' => GalleryRepository::PENDING, 'error' => ''] + $row;
            }
            $copies[] = ['from' => $from, 'rel' => GalleryService::relativePath($row, $size), 'public' => !(int) $row['is_intern']];
        }
        array_push($this->copies, ...$copies);
        return $row;
    }

    /** @return array<string, mixed> */
    private function document(array $r): array
    {
        $ext = strtolower((string) $r['ext']);
        if (!isset(DocumentService::TYPES[$ext])) {
            throw new SkipRow('Dateityp „' . $ext . '“ kennt die Kompanie-App nicht');
        }
        $from = wp_upload_dir(null, false)['basedir'] . '/mkl-private/' . basename((string) $r['file']);
        if (!is_file($from)) {
            throw new SkipRow('Datei ' . basename($from) . ' fehlt');
        }
        // Dateinamen nach dem Muster des Kerns (DocumentService::FILE_PATTERN).
        $file = 'dok-' . bin2hex(random_bytes(12)) . '.' . $ext;
        $this->copies[] = ['from' => $from, 'rel' => 'dokumente/' . $file, 'public' => false, 'temporary' => true];
        return [
            'file' => $file,
            'ext' => $ext,
            'visibility' => $r['visibility'] === 'spiess' ? DocumentService::VISIBLE_MANAGERS : DocumentService::VISIBLE_MEMBERS,
        ] + self::pick($r, ['id', 'title', 'category_id', 'doc_date', 'size', 'version', 'uploaded_by', 'created_at', 'updated_at']);
    }

    /** @return array<string, mixed>|null */
    private function subscription(array $r): ?array
    {
        $uid = (int) $r['user_id'];
        if ($uid > 0 && !isset($this->users[$uid])) {
            throw new SkipRow('Konto ' . $uid . ' gibt es nicht mehr');
        }
        $types = [];
        foreach (array_filter(explode(',', (string) $r['types'])) as $t) {
            if (isset(self::PUSH_TYPES[$t])) {
                $types[] = self::PUSH_TYPES[$t];
            }
        }
        return ['types' => implode(',', $types)] + self::pick($r, ['id', 'user_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'details', 'created_at', 'updated_at', 'last_sent_at']);
    }

    /** @return list<array<string, mixed>> aus User-Meta mkl_rank_id */
    private function memberRanks(string $now): array
    {
        $rows = [];
        $metas = $this->wpdb->get_results($this->wpdb->prepare("SELECT user_id, meta_value FROM {$this->wpdb->usermeta} WHERE meta_key = %s", 'mkl_rank_id'), ARRAY_A);
        foreach ((array) $metas as $m) {
            $uid = (int) $m['user_id'];
            $rank = (int) $m['meta_value'];
            if ($rank <= 0) {
                continue;
            }
            $since = $this->wpdb->get_var($this->wpdb->prepare(
                'SELECT MAX(changed_at) FROM ' . $this->lank('rank_history') . ' WHERE user_id = %d AND to_rank_id = %d',
                $uid,
                $rank,
            ));
            $rows[] = ['user_id' => $uid, 'rank_id' => $rank, 'assigned_at' => is_string($since) && $since !== '' ? $since : $now];
        }
        return $rows;
    }

    /** @return list<array<string, mixed>> aus User-Meta mkl_title_id (mehrere Zeilen je Konto) */
    private function memberTitles(string $now): array
    {
        $rows = [];
        $seen = [];
        $metas = $this->wpdb->get_results($this->wpdb->prepare("SELECT user_id, meta_value FROM {$this->wpdb->usermeta} WHERE meta_key = %s ORDER BY umeta_id", 'mkl_title_id'), ARRAY_A);
        foreach ((array) $metas as $m) {
            $row = ['user_id' => (int) $m['user_id'], 'title_id' => (int) $m['meta_value'], 'assigned_at' => $now];
            $k = $row['user_id'] . '|' . $row['title_id'];
            if ($row['title_id'] > 0 && !isset($seen[$k])) {
                $seen[$k] = true;
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Einstellungen: nur setzen, wo der Kern noch nichts (oder dasselbe) hat; sonst behalten und melden.
     *
     * @return array<string, mixed>
     */
    private function planSettings(): array
    {
        $set = [];
        foreach (self::SETTINGS as $option => $key) {
            $value = get_option($option, null);
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if ($key === 'calendar_cache_minutes') {
                $value = max(1, (int) $value);
            }
            $current = $this->app->settings->get($key);
            $same = is_scalar($current) && is_scalar($value) ? (string) $current === (string) $value : $current == $value;
            if ($current !== null && $current !== '' && !$same && !in_array($key, ['gallery_status', 'push_reminder_date'], true)) {
                $this->warnings[] = sprintf('Einstellung %s ist in der Kompanie-App schon gesetzt und bleibt unverändert.', $key);
                continue;
            }
            $set[$key] = $value;
        }

        // Push: Ohne denselben Schlüssel wären alle übernommenen Abos ungültig.
        $vapid = get_option('mkl_push_vapid');
        if (is_array($vapid) && !empty($vapid['public']) && !empty($vapid['private'])) {
            $own = (int) $this->app->db->scalar('SELECT COUNT(*) FROM {push_subscriptions}') - count($this->imported['push_subscriptions'] ?? []);
            $current = $this->app->settings->get('push_vapid');
            if ($own > 0 && is_array($current) && ($current['public'] ?? '') !== $vapid['public']) {
                $this->errors[] = sprintf('Die Kompanie-App hat schon %d eigene Push-Abos mit einem anderen Schlüssel. Bitte die Push-Abos der Kompanie-App vorher entfernen.', $own);
            } elseif (!openssl_pkey_get_private((string) $vapid['private'])) {
                $this->errors[] = 'Der private Push-Schlüssel (mkl_push_vapid) lässt sich nicht lesen.';
            } else {
                $set['push_vapid'] = ['public' => (string) $vapid['public'], 'private' => (string) $vapid['private']];
            }
        } elseif ((int) ($this->tables['push_subscriptions']['new'] ?? 0) > 0) {
            $this->errors[] = 'Es gibt Push-Abos, aber keinen Push-Schlüssel (mkl_push_vapid).';
        }

        // Google Drive: bestehende OAuth-Verbindung bzw. Dienstkonto.
        $oauth = get_option('mkl_drive_oauth');
        if (is_array($oauth) && !empty($oauth['refresh_token']) && !empty($oauth['client_id']) && !empty($oauth['client_secret'])) {
            if (!method_exists($this->app->drive(), 'saveOAuth')) {
                $this->errors[] = 'Diese Version der Kompanie-App kann die Verbindung zu Google nicht übernehmen. Bitte zuerst aktualisieren.';
            } elseif ($this->app->drive()->oauth() === null) {
                $set['@drive_oauth'] = $oauth;
            }
        }
        $sa = (string) get_option('mkl_drive_service_account', '');
        if ($sa !== '' && $this->app->drive()->serviceAccount() === null) {
            $set['@drive_service_account'] = $sa;
        }
        if (!isset($set['@drive_oauth']) && !isset($set['@drive_service_account']) && !$this->app->drive()->hasCredentials() && ($set['gallery_root'] ?? '') !== '') {
            $this->warnings[] = 'Für die Galerie ist kein Google-Zugang hinterlegt; neue Bilder kommen erst mit einem Dienstkonto.';
        }
        return $set;
    }

    /* ---------------------------------------------------------------- */
    /* Schreiben                                                        */
    /* ---------------------------------------------------------------- */

    /** @param array<string, list<array<string, mixed>>> $plan */
    private function writeRows(array $plan): void
    {
        // Erst die Dateien (umkehrbar), dann alle Zeilen in einer Transaktion.
        $written = [];
        try {
            foreach ($this->copies as $c) {
                $to = $this->app->files->path($c['rel'], $c['public']);
                if (!is_file($to)) {
                    if (!@copy($c['from'], $to)) {
                        throw new \RuntimeException('Datei konnte nicht kopiert werden: ' . basename($c['from']));
                    }
                    if (!empty($c['temporary'])) {
                        $written[] = $to;
                    }
                }
            }
            $this->app->db->transaction(function () use ($plan): void {
                $state = get_option(self::STATE, []);
                foreach ($plan as $table => $rows) {
                    $key = match ($table) {
                        'member_ranks' => ['user_id'],
                        'member_titles' => ['user_id', 'title_id'],
                        default => ['id'],
                    };
                    foreach ($rows as $row) {
                        $this->app->db->insert($table, $row);
                        $state['ids'][$table][] = self::key($row, $key);
                    }
                }
                $state['runs'][] = gmdate('Y-m-d H:i:s');
                update_option(self::STATE, $state, false);
            });
        } catch (\Throwable $e) {
            foreach ($written as $f) {
                @unlink($f);
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $settings */
    private function writeConfiguration(array $settings): void
    {
        // Bereiche: Lank hatte alle.
        $this->app->features()->update(array_fill_keys(['events', 'calendar', 'news', 'gallery', 'points', 'penalties', 'ranks', 'titles', 'attendance', 'documents'], true));
        $this->writeRoles();
        foreach ($settings as $key => $value) {
            match ($key) {
                '@drive_oauth' => $this->app->drive()->saveOAuth($value),
                '@drive_service_account' => $this->app->drive()->saveServiceAccount((string) $value),
                default => $this->app->settings->set($key, $value),
            };
        }
        $this->app->calendar()->clearCache();
    }

    private function writeRoles(): void
    {
        $keys = [Roles::MEMBER => Roles::MEMBER, Roles::CANDIDATE => Roles::CANDIDATE];
        $wanted = [self::ROLE_MANAGER => ['capabilities' => self::MANAGER_CAPS, 'points_factor' => 1.0], self::ROLE_YOUNG => ['capabilities' => ['member', 'calendar'], 'points_factor' => 0.5]];
        foreach ($wanted as $label => $def) {
            if (!in_array($label, $this->roles, true)) {
                continue;
            }
            foreach ($this->app->roles()->all() as $r) {
                if (mb_strtolower($r['label']) === mb_strtolower($label)) {
                    $keys[$label] = $r['key'];
                }
            }
            $keys[$label] ??= $this->app->roles()->create(['label' => $label] + $def)['key'];
        }
        $state = get_option(self::STATE, []);
        $done = array_fill_keys(array_map('intval', (array) ($state['users'] ?? [])), true);
        foreach ($this->roles as $id => $label) {
            if (isset($done[$id])) {
                continue;
            }
            $meta = get_user_meta($id, WpUsers::metaKey(), true);
            if (is_string($meta) && $meta !== '' && $meta !== $keys[$label]) {
                $this->warnings[] = sprintf('%s hat in der Kompanie-App schon eine Rolle; sie bleibt.', get_userdata($id)->display_name);
            } else {
                $this->app->users->setRole($id, $keys[$label]);
            }
            $state['users'][] = $id;
        }
        update_option(self::STATE, $state, false);
    }

    /* ---------------------------------------------------------------- */
    /* Prüfen nach der Übernahme                                        */
    /* ---------------------------------------------------------------- */

    /** @return list<array{label: string, ok: bool, detail: string}> */
    private function verify(): array
    {
        $checks = [];
        foreach ($this->tables as $table => $s) {
            $key = str_starts_with($table, 'member_') ? 'user_id' : 'id';
            $core = (int) $this->app->db->scalar('SELECT COUNT(' . $key . ') FROM {' . $table . '}');
            $expected = $s['source'] - $s['skipped'];
            $checks[] = ['label' => $s['label'], 'ok' => $core >= $expected, 'detail' => sprintf('Lank %d, übersprungen %d, Kompanie-App %d', $s['source'], $s['skipped'], $core)];
        }
        $diff = [];
        foreach (array_keys($this->users) as $id) {
            $lank = [
                (float) $this->wpdb->get_var($this->wpdb->prepare('SELECT COALESCE(SUM(CASE WHEN halved = 1 THEN points / 2.0 ELSE points END), 0) FROM ' . $this->lank('points') . ' WHERE user_id = %d AND deleted_at IS NULL', $id)),
                (int) $this->wpdb->get_var($this->wpdb->prepare('SELECT COALESCE(SUM(amount_cents), 0) FROM ' . $this->lank('penalties') . " WHERE user_id = %d AND deleted_at IS NULL AND status = 'open'", $id)),
                (int) get_user_meta($id, 'mkl_rank_id', true),
            ];
            $core = [
                (float) $this->app->db->scalar('SELECT COALESCE(SUM(points * factor), 0) FROM {points} WHERE user_id = ? AND deleted_at IS NULL', [$id]),
                (int) $this->app->db->scalar("SELECT COALESCE(SUM(amount_cents), 0) FROM {penalties} WHERE user_id = ? AND deleted_at IS NULL AND status = 'open'", [$id]),
                (int) $this->app->db->scalar('SELECT rank_id FROM {member_ranks} WHERE user_id = ?', [$id]),
            ];
            if (abs($lank[0] - $core[0]) > 0.001 || $lank[1] !== $core[1] || $lank[2] !== $core[2]) {
                $diff[] = sprintf('%s: Punkte %s/%s, offen %d/%d ct, Dienstgrad %d/%d', get_userdata($id)->display_name, $lank[0], $core[0], $lank[1], $core[1], $lank[2], $core[2]);
            }
        }
        $checks[] = ['label' => 'Punkte, offene Strafen und Dienstgrad je Mitglied', 'ok' => $diff === [], 'detail' => $diff === [] ? 'bei allen gleich' : implode('; ', $diff)];
        $wrong = [];
        foreach ($this->roles as $id => $label) {
            clean_user_cache($id);
            $role = $this->app->users->find($id)?->role;
            $expected = in_array($label, [Roles::MEMBER, Roles::CANDIDATE], true) ? $label : null;
            if ($role === null || $role === Roles::GUEST || ($expected !== null && $role !== $expected)) {
                $wrong[] = get_userdata($id)->display_name;
            }
        }
        $checks[] = ['label' => 'Rollen in der App', 'ok' => $wrong === [], 'detail' => $wrong === [] ? count($this->roles) . ' Konten' : 'ohne passende Rolle: ' . implode(', ', $wrong)];
        $vapid = get_option('mkl_push_vapid');
        if (is_array($vapid)) {
            $checks[] = ['label' => 'Push-Schlüssel', 'ok' => $this->app->push()->publicKey() === $vapid['public'], 'detail' => 'derselbe öffentliche Schlüssel wie bisher'];
        }
        return $checks;
    }

    /* ---------------------------------------------------------------- */
    /* Hilfen                                                           */
    /* ---------------------------------------------------------------- */

    private function lank(string $table): string
    {
        return $this->wpdb->prefix . 'mkl_' . $table;
    }

    private function lankTableExists(string $table): bool
    {
        return $this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $this->lank($table))) === $this->lank($table);
    }

    /** @return list<array<string, mixed>> */
    private function lankRows(string $table): array
    {
        if (!$this->lankTableExists($table)) {
            return [];
        }
        return (array) $this->wpdb->get_results('SELECT * FROM ' . $this->lank($table) . ' ORDER BY id', ARRAY_A);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $columns
     * @return array<string, mixed>
     */
    private static function pick(array $row, array $columns): array
    {
        $out = [];
        foreach ($columns as $c) {
            $out[$c] = $row[$c] ?? null;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $key
     */
    private static function key(array $row, array $key): string
    {
        return implode('|', array_map(static fn (string $c): string => (string) $row[$c], $key));
    }
}
