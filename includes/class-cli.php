<?php

declare(strict_types=1);

namespace AFS;

use WP_CLI;
use WP_CLI_Command;

final class Cli extends WP_CLI_Command
{
    public static function boot(): void
    {
        WP_CLI::add_command('around-form-stats', self::class);
    }

    /**
     * Connect this site using an enrollment key.
     *
     * ## OPTIONS
     *
     * [--key=<key>]
     * : Organisation enrollment key. Falls back to AROUND_FORM_STATS_ENROLLMENT_KEY.
     *
     * [--api-url=<url>]
     * : Laravel API base URL. Falls back to AROUND_FORM_STATS_API_URL or saved setting.
     *
     * ## EXAMPLES
     *
     *     wp around-form-stats connect --key="afs_..." --api-url="https://stats.example.com"
     *
     * @when after_wp_load
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc_args
     */
    public function connect(array $args, array $assoc_args): void
    {
        $key = isset($assoc_args['key'])
            ? (string) $assoc_args['key']
            : Options::enrollment_key_from_constant();

        $api = isset($assoc_args['api-url'])
            ? (string) $assoc_args['api-url']
            : (Options::api_base_url_from_constant() ?: Options::api_base_url());

        if ($key === '' || $api === '') {
            WP_CLI::error('Provide --key and --api-url, or define AROUND_FORM_STATS_ENROLLMENT_KEY and AROUND_FORM_STATS_API_URL.');
        }

        $result = Enrollment::enroll($key, $api);

        if (! $result['ok']) {
            WP_CLI::error($result['message']);
        }

        WP_CLI::success($result['message']);
        WP_CLI::log('Site UUID: ' . Options::get('site_uuid'));
        WP_CLI::log('Status: ' . Options::get('site_status'));
    }

    /**
     * Show connection and queue status.
     *
     * @when after_wp_load
     */
    public function status(): void
    {
        $settings = Options::all();

        WP_CLI::log('Connected: ' . (Options::is_connected() ? 'yes' : 'no'));
        WP_CLI::log('API URL: ' . (Options::api_base_url() ?: '—'));
        WP_CLI::log('Site UUID: ' . ((string) $settings['site_uuid'] ?: '—'));
        WP_CLI::log('Site status: ' . ((string) $settings['site_status'] ?: '—'));
        WP_CLI::log('Queued events: ' . Queue::count());
        WP_CLI::log('Last success: ' . ((string) $settings['last_success_at'] ?: '—'));
        WP_CLI::log('Last heartbeat: ' . ((string) $settings['last_heartbeat_at'] ?: '—'));
        WP_CLI::log('Last error: ' . ((string) $settings['last_error'] ?: '—'));
        WP_CLI::log('History sync: ' . ((string) $settings['history_backfill_completed_at'] ?: 'pending'));
        WP_CLI::log('Quform: ' . (QuformAdapter::quform_version() ?: 'not detected'));
        WP_CLI::log('Plugin: ' . AFS_VERSION);
    }

    /**
     * Sync historical Quform daily counts to the API.
     *
     * @when after_wp_load
     */
    public function backfill(): void
    {
        if (! Options::is_connected()) {
            WP_CLI::error('Site is not connected.');
        }

        // Allow a forced re-run from CLI.
        Options::update(['history_backfill_completed_at' => '']);

        $result = HistoryBackfill::run();
        if (! $result['ok']) {
            WP_CLI::error($result['message']);
        }

        WP_CLI::success($result['message']);
    }

    /**
     * Flush the local event queue to the API.
     *
     * @when after_wp_load
     */
    public function flush(): void
    {
        if (! Options::is_connected()) {
            WP_CLI::error('Site is not connected.');
        }

        $before = Queue::count();
        Queue::process();
        $after = Queue::count();

        WP_CLI::success(sprintf('Queue processed. Before: %d, after: %d.', $before, $after));
    }

    /**
     * Send a heartbeat to the API.
     *
     * @when after_wp_load
     */
    public function heartbeat(): void
    {
        if (! Options::is_connected()) {
            WP_CLI::error('Site is not connected.');
        }

        Heartbeat::send();

        if ((string) Options::get('last_error', '') !== '') {
            WP_CLI::warning('Heartbeat finished with error: ' . Options::get('last_error'));
            return;
        }

        WP_CLI::success('Heartbeat sent.');
    }
}
