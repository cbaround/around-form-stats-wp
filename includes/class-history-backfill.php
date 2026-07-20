<?php

declare(strict_types=1);

namespace AFS;

/**
 * Aggregates historical Quform entry counts and pushes them to the API once
 * the site is connected and approved. Also registers all Quform forms.
 */
final class HistoryBackfill
{
    public const CRON_HOOK = 'afs_history_backfill';

    /** Bump to force already-connected sites to re-sync after plugin upgrades. */
    public const SCHEMA_VERSION = 4;

    private const CHUNK_SIZE = 200;

    public static function boot(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'run']);
        add_action('admin_init', [self::class, 'maybe_migrate_and_schedule'], 30);
    }

    public static function schedule(): void
    {
        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 15, self::CRON_HOOK);
        }

        // Nudge WP-Cron so it does not wait for an unrelated page view.
        if (function_exists('spawn_cron')) {
            spawn_cron(time());
        }
    }

    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * For existing connected sites: upgrade schema clears the completion flag
     * so all forms + historical counts are pushed again (fill_missing).
     */
    public static function maybe_migrate_and_schedule(): void
    {
        if (! Options::is_connected()) {
            return;
        }

        $schema = (int) Options::get('history_backfill_schema', 0);
        if ($schema < self::SCHEMA_VERSION) {
            Options::update([
                'history_backfill_schema' => self::SCHEMA_VERSION,
                'history_backfill_completed_at' => '',
            ]);
        }

        self::maybe_schedule();
    }

    public static function maybe_schedule(): void
    {
        if (! Options::is_connected()) {
            return;
        }

        if ((string) Options::get('history_backfill_completed_at', '') !== '') {
            return;
        }

        self::schedule();
    }

    /**
     * @return array{ok: bool, message: string, imported?: int, skipped?: int, forms?: int, sources_imported?: int, sources_skipped?: int}
     */
    public static function run(bool $force = false): array
    {
        if (! Options::is_connected()) {
            return [
                'ok' => false,
                'message' => 'Site is not connected.',
            ];
        }

        if (! $force && (string) Options::get('history_backfill_completed_at', '') !== '') {
            return [
                'ok' => true,
                'message' => 'History backfill already completed.',
                'imported' => 0,
                'skipped' => 0,
                'forms' => 0,
            ];
        }

        if ($force) {
            Options::update(['history_backfill_completed_at' => '']);
        }

        $status = (string) Options::get('site_status', '');
        if ($status !== '' && $status !== 'active') {
            // Wait until the site is approved; heartbeat / admin will reschedule.
            self::schedule();

            return [
                'ok' => false,
                'message' => 'Site is not active yet. History will sync after approval.',
            ];
        }

        $forms_table = self::forms_table();
        $form_names = self::resolve_form_names($forms_table);
        $rows = self::aggregate_daily_counts($form_names);

        if ($rows === null && $form_names === []) {
            Options::update([
                'history_backfill_completed_at' => gmdate('c'),
                'history_backfill_schema' => self::SCHEMA_VERSION,
            ]);

            return [
                'ok' => true,
                'message' => 'Quform history unavailable; marked complete.',
                'imported' => 0,
                'skipped' => 0,
                'forms' => 0,
            ];
        }

        $rows = $rows ?? [];
        $forms_payload = [];
        foreach ($form_names as $form_id => $form_name) {
            $forms_payload[] = [
                'form_id' => (string) $form_id,
                'form_name' => $form_name !== '' ? $form_name : ('Form ' . $form_id),
            ];
        }

        if ($rows === [] && $forms_payload === []) {
            Options::update([
                'history_backfill_completed_at' => gmdate('c'),
                'history_backfill_schema' => self::SCHEMA_VERSION,
            ]);

            return [
                'ok' => true,
                'message' => 'No Quform forms or entries found.',
                'imported' => 0,
                'skipped' => 0,
                'forms' => 0,
            ];
        }

        $client = new ApiClient();
        $imported = 0;
        $skipped = 0;
        $forms_synced = 0;

        // Never send rows: [] — older API builds reject empty required arrays with
        // "The rows field is required." Attach the form catalog to the first rows
        // chunk, or register forms via zero-count rows when there is no history.
        if ($rows === [] && $forms_payload !== []) {
            $today = gmdate('Y-m-d');
            foreach ($forms_payload as $form) {
                $rows[] = [
                    'date' => $today,
                    'form_id' => $form['form_id'],
                    'form_name' => $form['form_name'],
                    'submission_count' => 0,
                ];
            }
        }

        $row_chunks = array_values(array_chunk($rows, self::CHUNK_SIZE));
        $form_chunks = array_values(array_chunk($forms_payload, self::CHUNK_SIZE));
        $total_chunks = max(count($row_chunks), count($form_chunks), 1);

        for ($index = 0; $index < $total_chunks; $index++) {
            $chunk_rows = $row_chunks[$index] ?? [];
            $chunk_forms = $form_chunks[$index] ?? [];

            // Guarantee a non-empty rows array for APIs that still require it.
            if ($chunk_rows === [] && $chunk_forms !== []) {
                $today = gmdate('Y-m-d');
                foreach ($chunk_forms as $form) {
                    $chunk_rows[] = [
                        'date' => $today,
                        'form_id' => $form['form_id'],
                        'form_name' => $form['form_name'],
                        'submission_count' => 0,
                    ];
                }
            }

            if ($chunk_rows === []) {
                continue;
            }

            $payload = [
                'rows' => $chunk_rows,
                'source' => 'quform_entries',
                'mode' => 'fill_missing',
            ];

            if ($chunk_forms !== []) {
                $payload['forms'] = $chunk_forms;
                $payload['source'] = 'quform_forms_and_entries';
            }

            $result = $client->post('/api/v1/submissions/history', $payload, true);

            if (! $result['ok']) {
                self::schedule();

                return [
                    'ok' => false,
                    'message' => $result['error'] !== '' ? $result['error'] : 'History sync failed.',
                    'imported' => $imported,
                    'skipped' => $skipped,
                    'forms' => $forms_synced,
                ];
            }

            $body = is_array($result['body']) ? $result['body'] : [];
            $imported += (int) ($body['imported'] ?? 0);
            $skipped += (int) ($body['skipped'] ?? 0);
            $forms_synced += (int) ($body['forms'] ?? count($chunk_forms));
        }

        $source_imported = 0;
        $source_skipped = 0;
        $source_rows = self::aggregate_source_counts($form_names) ?? [];

        foreach (array_chunk($source_rows, self::CHUNK_SIZE) as $chunk_sources) {
            if ($chunk_sources === []) {
                continue;
            }

            $compatibility_row = $chunk_sources[0];
            $result = $client->post('/api/v1/submissions/history', [
                // Older API builds require at least one daily row in every request.
                'rows' => [[
                    'date' => $compatibility_row['date'],
                    'form_id' => $compatibility_row['form_id'],
                    'form_name' => $compatibility_row['form_name'],
                    'submission_count' => $compatibility_row['submission_count'],
                ]],
                'source_rows' => $chunk_sources,
                'source' => 'quform_referring_urls',
                'mode' => 'fill_missing',
            ], true);

            if (! $result['ok']) {
                self::schedule();

                return [
                    'ok' => false,
                    'message' => $result['error'] !== ''
                        ? $result['error']
                        : 'History source sync failed.',
                    'imported' => $imported,
                    'skipped' => $skipped,
                    'forms' => $forms_synced,
                    'sources_imported' => $source_imported,
                    'sources_skipped' => $source_skipped,
                ];
            }

            $body = is_array($result['body']) ? $result['body'] : [];
            $source_imported += (int) ($body['sources_imported'] ?? 0);
            $source_skipped += (int) ($body['sources_skipped'] ?? 0);
        }

        Options::update([
            'history_backfill_completed_at' => gmdate('c'),
            'history_backfill_schema' => self::SCHEMA_VERSION,
            'last_success_at' => gmdate('c'),
        ]);

        return [
            'ok' => true,
            'message' => sprintf(
                'Synced %d forms, historical counts (%d imported, %d skipped), and sources (%d imported, %d skipped).',
                $forms_synced,
                $imported,
                $skipped,
                $source_imported,
                $source_skipped
            ),
            'imported' => $imported,
            'skipped' => $skipped,
            'forms' => $forms_synced,
            'sources_imported' => $source_imported,
            'sources_skipped' => $source_skipped,
        ];
    }

    private static function forms_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'quform_forms';
    }

    private static function entries_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'quform_entries';
    }

    /**
     * @param array<string, string> $form_names
     * @return list<array{date: string, form_id: string, form_name: string, submission_count: int}>|null
     */
    private static function aggregate_daily_counts(array $form_names): ?array
    {
        global $wpdb;

        $entries_table = self::entries_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $entries_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $entries_table));
        if ($entries_exists !== $entries_table) {
            return null;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $columns = $wpdb->get_col("DESCRIBE {$entries_table}", 0);
        if (! is_array($columns) || $columns === []) {
            return null;
        }

        $date_column = in_array('created_at', $columns, true)
            ? 'created_at'
            : (in_array('submitted', $columns, true) ? 'submitted' : null);

        if ($date_column === null) {
            return null;
        }

        $has_status = in_array('status', $columns, true);

        // Prefer excluding trash only; fall back to no status filter if that yields nothing.
        $queries = [];
        if ($has_status) {
            $queries[] = "AND (status IS NULL OR status = '' OR LOWER(status) NOT IN ('trash','deleted','spam'))";
            $queries[] = '';
        } else {
            $queries[] = '';
        }

        $results = [];
        foreach ($queries as $status_filter) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $results = $wpdb->get_results(
                "SELECT form_id, DATE({$date_column}) AS day, COUNT(*) AS submission_count
                 FROM {$entries_table}
                 WHERE {$date_column} IS NOT NULL AND {$date_column} != '0000-00-00 00:00:00' {$status_filter}
                 GROUP BY form_id, DATE({$date_column})
                 ORDER BY day ASC",
                ARRAY_A
            );

            if (is_array($results) && $results !== []) {
                break;
            }
        }

        if (! is_array($results)) {
            return [];
        }

        $rows = [];
        foreach ($results as $row) {
            $form_id = (string) ($row['form_id'] ?? '');
            $day = (string) ($row['day'] ?? '');
            if ($form_id === '' || $day === '' || $day === '0000-00-00') {
                continue;
            }

            $name = $form_names[$form_id] ?? '';
            if ($name === '') {
                $name = 'Form ' . $form_id;
            }

            $rows[] = [
                'date' => $day,
                'form_id' => $form_id,
                'form_name' => $name,
                'submission_count' => (int) ($row['submission_count'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Aggregate historical referring hosts from Quform entry metadata.
     *
     * @param array<string, string> $form_names
     * @return list<array{date: string, form_id: string, form_name: string, referrer_host: string, utm_source?: string, submission_count: int}>|null
     */
    private static function aggregate_source_counts(array $form_names): ?array
    {
        global $wpdb;

        $entries_table = self::entries_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $entries_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $entries_table));
        if ($entries_exists !== $entries_table) {
            return null;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $columns = $wpdb->get_col("DESCRIBE {$entries_table}", 0);
        if (! is_array($columns) || $columns === []) {
            return null;
        }

        $date_column = in_array('created_at', $columns, true)
            ? 'created_at'
            : (in_array('submitted', $columns, true) ? 'submitted' : null);

        if ($date_column === null || ! in_array('referring_url', $columns, true)) {
            return [];
        }

        $has_status = in_array('status', $columns, true);
        $queries = $has_status
            ? ["AND (status IS NULL OR status = '' OR LOWER(status) NOT IN ('trash','deleted','spam'))", '']
            : [''];

        $results = [];
        foreach ($queries as $status_filter) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $results = $wpdb->get_results(
                "SELECT form_id, DATE({$date_column}) AS day, referring_url, COUNT(*) AS submission_count
                 FROM {$entries_table}
                 WHERE {$date_column} IS NOT NULL
                   AND {$date_column} != '0000-00-00 00:00:00'
                   {$status_filter}
                 GROUP BY form_id, DATE({$date_column}), referring_url
                 ORDER BY day ASC",
                ARRAY_A
            );

            if (is_array($results) && $results !== []) {
                break;
            }
        }

        if (! is_array($results)) {
            return [];
        }

        $site_host = Attribution::host_from_url(home_url('/'));
        $grouped = [];

        foreach ($results as $row) {
            $form_id = (string) ($row['form_id'] ?? '');
            $day = (string) ($row['day'] ?? '');
            $count = (int) ($row['submission_count'] ?? 0);
            if ($form_id === '' || $day === '' || $day === '0000-00-00' || $count < 1) {
                continue;
            }

            $raw = (string) ($row['referring_url'] ?? '');
            $attribution = Attribution::from_raw_url($raw, $site_host);
            $host = $attribution['referrer_host'];
            $utm = $attribution['utm_source'] ?? null;
            $key = $form_id . '|' . $day . '|' . $host . '|' . (string) $utm;

            if (! isset($grouped[$key])) {
                $name = $form_names[$form_id] ?? '';
                if ($name === '') {
                    $name = 'Form ' . $form_id;
                }

                $grouped[$key] = [
                    'date' => $day,
                    'form_id' => $form_id,
                    'form_name' => $name,
                    'referrer_host' => $host,
                    'submission_count' => 0,
                ];

                if ($utm !== null && $utm !== '') {
                    $grouped[$key]['utm_source'] = $utm;
                }
            }

            $grouped[$key]['submission_count'] += $count;
        }

        return array_values($grouped);
    }

    /**
     * @return array<string, string>
     */
    private static function resolve_form_names(string $forms_table): array
    {
        global $wpdb;

        $names = [];

        if (function_exists('quform')) {
            try {
                $quform = quform();
                if (is_object($quform) && method_exists($quform, 'getService')) {
                    $repo = $quform->getService('repository');
                    if (is_object($repo) && method_exists($repo, 'allForms')) {
                        $forms = $repo->allForms(null);
                        if (is_array($forms)) {
                            foreach ($forms as $form) {
                                if (! is_array($form)) {
                                    continue;
                                }
                                $id = (string) ($form['id'] ?? '');
                                $name = (string) ($form['name'] ?? '');
                                if ($id !== '') {
                                    $names[$id] = $name !== '' ? $name : ('Form ' . $id);
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Fall through to SQL.
            }
        }

        if ($names !== []) {
            return $names;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $forms_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $forms_table));
        if ($forms_exists !== $forms_table) {
            return [];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $columns = $wpdb->get_col("DESCRIBE {$forms_table}", 0);
        if (! is_array($columns)) {
            return [];
        }

        if (in_array('name', $columns, true)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $forms = $wpdb->get_results("SELECT id, name FROM {$forms_table}", ARRAY_A);
            if (is_array($forms)) {
                foreach ($forms as $form) {
                    $names[(string) $form['id']] = (string) ($form['name'] ?? '');
                }
            }

            return $names;
        }

        if (in_array('config', $columns, true)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $forms = $wpdb->get_results("SELECT id, config FROM {$forms_table}", ARRAY_A);
            if (is_array($forms)) {
                foreach ($forms as $form) {
                    $id = (string) ($form['id'] ?? '');
                    $raw = (string) ($form['config'] ?? '');
                    $config = maybe_unserialize($raw);
                    if (! is_array($config)) {
                        $decoded = json_decode($raw, true);
                        $config = is_array($decoded) ? $decoded : [];
                    }
                    $name = is_array($config) ? (string) ($config['name'] ?? '') : '';
                    if ($id !== '') {
                        $names[$id] = $name;
                    }
                }
            }
        }

        return $names;
    }
}
