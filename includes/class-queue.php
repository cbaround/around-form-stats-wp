<?php

declare(strict_types=1);

namespace AFS;

final class Queue
{
    public const TABLE = 'afs_event_queue';
    public const CRON_HOOK = 'afs_process_queue';
    public const BATCH_SIZE = 50;

    public static function boot(): void
    {
        add_filter('cron_schedules', [self::class, 'cron_schedules']);
        add_action(self::CRON_HOOK, [self::class, 'process']);
    }

    public static function table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE;
    }

    public static function create_table(): void
    {
        global $wpdb;

        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_uuid varchar(36) NOT NULL,
            form_id varchar(100) NOT NULL,
            form_name varchar(255) NOT NULL DEFAULT '',
            submitted_at varchar(40) NOT NULL,
            payload longtext NOT NULL,
            attempts int unsigned NOT NULL DEFAULT 0,
            last_attempt_at datetime DEFAULT NULL,
            last_error text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_uuid (event_uuid),
            KEY attempts (attempts),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta($sql);
    }

    public static function schedule(): void
    {
        add_filter('cron_schedules', [self::class, 'cron_schedules']);

        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'afs_fifteen_minutes', self::CRON_HOOK);
        }
    }

    /**
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public static function cron_schedules(array $schedules): array
    {
        $schedules['afs_fifteen_minutes'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display' => __('Every 15 minutes (Around Form Stats)', 'around-form-stats'),
        ];

        return $schedules;
    }

    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    public static function enqueue(array $event): bool
    {
        global $wpdb;

        $event_uuid = (string) ($event['event_id'] ?? '');
        if ($event_uuid === '') {
            return false;
        }

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . self::table_name() . ' WHERE event_uuid = %s',
                $event_uuid
            )
        );

        if ($existing) {
            return true;
        }

        $inserted = $wpdb->insert(
            self::table_name(),
            [
                'event_uuid' => $event_uuid,
                'form_id' => (string) ($event['form_id'] ?? ''),
                'form_name' => (string) ($event['form_name'] ?? ''),
                'submitted_at' => (string) ($event['submitted_at'] ?? gmdate('c')),
                'payload' => wp_json_encode($event),
                'attempts' => 0,
                'created_at' => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%d', '%s']
        );

        if ($inserted) {
            // Best-effort immediate flush; failures stay queued for cron.
            self::process();
        }

        return (bool) $inserted;
    }

    public static function count(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table_name());
    }

    public static function process(): void
    {
        if (! Options::is_connected()) {
            return;
        }

        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table_name() . ' ORDER BY id ASC LIMIT %d',
                self::BATCH_SIZE
            ),
            ARRAY_A
        );

        if (! is_array($rows) || $rows === []) {
            return;
        }

        $events = [];
        $id_by_uuid = [];

        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload'], true);
            if (! is_array($payload)) {
                continue;
            }
            $events[] = $payload;
            $id_by_uuid[(string) $row['event_uuid']] = (int) $row['id'];
        }

        if ($events === []) {
            return;
        }

        $client = new ApiClient();
        $result = $client->post('/api/v1/submissions/batch', ['events' => $events], true);

        if (! $result['ok']) {
            foreach ($rows as $row) {
                $wpdb->update(
                    self::table_name(),
                    [
                        'attempts' => ((int) $row['attempts']) + 1,
                        'last_attempt_at' => current_time('mysql', true),
                        'last_error' => $result['error'],
                    ],
                    ['id' => (int) $row['id']],
                    ['%d', '%s', '%s'],
                    ['%d']
                );
            }

            return;
        }

        $accepted = [];
        if (is_array($result['body']) && isset($result['body']['accepted']) && is_array($result['body']['accepted'])) {
            $accepted = $result['body']['accepted'];
        }

        foreach ($accepted as $event_uuid) {
            $event_uuid = (string) $event_uuid;
            if (! isset($id_by_uuid[$event_uuid])) {
                continue;
            }

            $wpdb->delete(
                self::table_name(),
                ['id' => $id_by_uuid[$event_uuid]],
                ['%d']
            );
        }
    }
}
