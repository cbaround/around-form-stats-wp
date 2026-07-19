<?php

declare(strict_types=1);

namespace AFS;

final class Heartbeat
{
    public const CRON_HOOK = 'afs_heartbeat';

    public static function boot(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'send']);
    }

    public static function schedule(): void
    {
        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 120, 'hourly', self::CRON_HOOK);
        }
    }

    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    public static function send(): void
    {
        if (! Options::is_connected()) {
            return;
        }

        $client = new ApiClient();
        $result = $client->post('/api/v1/sites/heartbeat', [
            'wordpress_version' => get_bloginfo('version'),
            'quform_version' => QuformAdapter::quform_version(),
            'plugin_version' => AFS_VERSION,
            'queued_events' => Queue::count(),
            'last_api_error' => (string) Options::get('last_error', ''),
        ], true);

        if ($result['ok']) {
            $status = is_array($result['body']) ? (string) ($result['body']['status'] ?? '') : '';
            Options::update(array_filter([
                'last_heartbeat_at' => gmdate('c'),
                'site_status' => $status !== '' ? $status : null,
            ], static fn ($value) => $value !== null));
        }
    }
}
