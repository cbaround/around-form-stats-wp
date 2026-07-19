<?php

declare(strict_types=1);

namespace AFS;

/**
 * Aggregates historical Quform entry counts and pushes them to the API once
 * the site is connected and approved.
 */
final class HistoryBackfill
{
    public const CRON_HOOK = 'afs_history_backfill';

    private const CHUNK_SIZE = 200;

    public static function boot(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'run']);
    }

    public static function schedule(): void
    {
        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 30, self::CRON_HOOK);
        }
    }

    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
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
     * @return array{ok: bool, message: string, imported?: int, skipped?: int}
     */
    public static function run(): array
    {
        if (! Options::is_connected()) {
            return [
                'ok' => false,
                'message' => 'Site is not connected.',
            ];
        }

        if ((string) Options::get('history_backfill_completed_at', '') !== '') {
            return [
                'ok' => true,
                'message' => 'History backfill already completed.',
                'imported' => 0,
                'skipped' => 0,
            ];
        }

        $status = (string) Options::get('site_status', '');
        if ($status !== '' && $status !== 'active') {
            // Wait until the site is approved; heartbeat will reschedule.
            return [
                'ok' => false,
                'message' => 'Site is not active yet. History will sync after approval.',
            ];
        }

        $rows = self::aggregate_daily_counts();
        if ($rows === null) {
            Options::update([
                'history_backfill_completed_at' => gmdate('c'),
            ]);

            return [
                'ok' => true,
                'message' => 'Quform history unavailable; marked complete.',
                'imported' => 0,
                'skipped' => 0,
            ];
        }

        if ($rows === []) {
            Options::update([
                'history_backfill_completed_at' => gmdate('c'),
            ]);

            return [
                'ok' => true,
                'message' => 'No historical Quform entries found.',
                'imported' => 0,
                'skipped' => 0,
            ];
        }

        $client = new ApiClient();
        $imported = 0;
        $skipped = 0;
        $chunks = array_chunk($rows, self::CHUNK_SIZE);

        foreach ($chunks as $chunk) {
            $result = $client->post('/api/v1/submissions/history', [
                'rows' => $chunk,
                'source' => 'quform_entries',
                'mode' => 'fill_missing',
            ], true);

            if (! $result['ok']) {
                // Retry later (e.g. still pending, network blip).
                self::schedule();

                return [
                    'ok' => false,
                    'message' => $result['error'] !== '' ? $result['error'] : 'History sync failed.',
                    'imported' => $imported,
                    'skipped' => $skipped,
                ];
            }

            $body = is_array($result['body']) ? $result['body'] : [];
            $imported += (int) ($body['imported'] ?? 0);
            $skipped += (int) ($body['skipped'] ?? 0);
        }

        Options::update([
            'history_backfill_completed_at' => gmdate('c'),
            'last_success_at' => gmdate('c'),
        ]);

        return [
            'ok' => true,
            'message' => sprintf('Synced historical counts (%d imported, %d skipped).', $imported, $skipped),
            'imported' => $imported,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return list<array{date: string, form_id: string, form_name: string, submission_count: int}>|null
     */
    private static function aggregate_daily_counts(): ?array
    {
        global $wpdb;

        $entries_table = $wpdb->prefix . 'quform_entries';
        $forms_table = $wpdb->prefix . 'quform_forms';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $entries_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $entries_table));
        if ($entries_exists !== $entries_table) {
            return null;
        }

        $form_names = self::resolve_form_names($forms_table);

        // Prefer created_at (Quform 2); fall back to submitted if present.
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

        $status_filter = in_array('status', $columns, true)
            ? "AND (status = 'normal' OR status = '' OR status IS NULL)"
            : '';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results(
            "SELECT form_id, DATE({$date_column}) AS day, COUNT(*) AS submission_count
             FROM {$entries_table}
             WHERE {$date_column} IS NOT NULL {$status_filter}
             GROUP BY form_id, DATE({$date_column})
             ORDER BY day ASC",
            ARRAY_A
        );

        if (! is_array($results)) {
            return [];
        }

        $rows = [];
        foreach ($results as $row) {
            $form_id = (string) ($row['form_id'] ?? '');
            $day = (string) ($row['day'] ?? '');
            if ($form_id === '' || $day === '') {
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
     * @return array<string, string>
     */
    private static function resolve_form_names(string $forms_table): array
    {
        global $wpdb;

        $names = [];

        if (class_exists('Quform_Repository') && function_exists('quform')) {
            try {
                $repo = quform()->getService('repository');
                if (is_object($repo) && method_exists($repo, 'allForms')) {
                    $forms = $repo->allForms();
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
                    $config = maybe_unserialize((string) ($form['config'] ?? ''));
                    if (! is_array($config)) {
                        $decoded = json_decode((string) ($form['config'] ?? ''), true);
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
