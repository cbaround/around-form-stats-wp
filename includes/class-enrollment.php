<?php

declare(strict_types=1);

namespace AFS;

final class Enrollment
{
    public static function boot(): void
    {
        add_action('admin_init', [self::class, 'maybe_auto_enroll_from_constant']);
    }

    public static function maybe_auto_enroll_from_constant(): void
    {
        if (Options::is_connected()) {
            return;
        }

        $key = Options::enrollment_key_from_constant();
        $api = Options::api_base_url_from_constant();

        if ($key === '' || $api === '') {
            return;
        }

        // Avoid hammering the API on every admin page load.
        $lock = get_transient('afs_auto_enroll_lock');
        if ($lock) {
            return;
        }
        set_transient('afs_auto_enroll_lock', 1, 5 * MINUTE_IN_SECONDS);

        Options::update(['api_base_url' => $api]);
        self::enroll($key, $api);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function enroll(string $enrollment_key, string $api_base_url): array
    {
        $enrollment_key = trim($enrollment_key);
        $api_base_url = rtrim(trim($api_base_url), '/');

        if ($enrollment_key === '' || $api_base_url === '') {
            return [
                'ok' => false,
                'message' => 'Enrollment key and API URL are required.',
            ];
        }

        Options::update(['api_base_url' => $api_base_url]);

        $client = new ApiClient();
        $result = $client->post('/api/v1/sites/enroll', [
            'enrollment_key' => $enrollment_key,
            'site_url' => home_url('/'),
            'site_name' => get_bloginfo('name'),
            'wordpress_version' => get_bloginfo('version'),
            'quform_version' => QuformAdapter::quform_version(),
            'plugin_version' => AFS_VERSION,
        ], false);

        if (! $result['ok'] || ! is_array($result['body'])) {
            return [
                'ok' => false,
                'message' => $result['error'] !== '' ? $result['error'] : 'Enrollment failed.',
            ];
        }

        $body = $result['body'];

        Options::update([
            'site_token' => (string) ($body['token'] ?? ''),
            'site_uuid' => (string) ($body['site_uuid'] ?? ''),
            'site_status' => (string) ($body['status'] ?? 'pending'),
            'last_error' => '',
            'last_error_at' => '',
            'last_success_at' => gmdate('c'),
        ]);

        // Flush any events queued before connection, then sync Quform history.
        Queue::process();
        Heartbeat::send();
        HistoryBackfill::maybe_schedule();

        $status = (string) ($body['status'] ?? 'pending');
        $message = $status === 'pending'
            ? 'Connected. Waiting for approval in Around Form Stats. History will sync after approval.'
            : 'Connected. Historical form counts will sync automatically.';

        return [
            'ok' => true,
            'message' => $message,
        ];
    }

    public static function disconnect(): void
    {
        Options::update([
            'site_token' => '',
            'site_uuid' => '',
            'site_status' => 'disconnected',
            'history_backfill_completed_at' => '',
        ]);
        HistoryBackfill::unschedule();
    }
}
