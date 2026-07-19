<?php

declare(strict_types=1);

namespace AFS;

final class Options
{
    public const OPTION_KEY = 'afs_settings';

    public static function boot(): void
    {
        // No-op hook point for future upgrades.
    }

    public static function ensure_defaults(): void
    {
        if (get_option(self::OPTION_KEY) !== false) {
            return;
        }

        add_option(self::OPTION_KEY, self::defaults());
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'api_base_url' => '',
            'site_token' => '',
            'site_uuid' => '',
            'site_status' => 'disconnected',
            'last_success_at' => '',
            'last_heartbeat_at' => '',
            'last_error' => '',
            'last_error_at' => '',
            'history_backfill_completed_at' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION_KEY, []);
        if (! is_array($stored)) {
            $stored = [];
        }

        return array_merge(self::defaults(), $stored);
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function update(array $values): void
    {
        $current = self::all();
        update_option(self::OPTION_KEY, array_merge($current, $values));
    }

    public static function get(string $key, $default = null)
    {
        $all = self::all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function api_base_url(): string
    {
        $url = (string) self::get('api_base_url', '');

        return rtrim($url, '/');
    }

    public static function is_connected(): bool
    {
        return self::site_token() !== '' && self::api_base_url() !== '';
    }

    public static function site_token(): string
    {
        return (string) self::get('site_token', '');
    }

    public static function enrollment_key_from_constant(): string
    {
        if (defined('AROUND_FORM_STATS_ENROLLMENT_KEY') && is_string(AROUND_FORM_STATS_ENROLLMENT_KEY)) {
            return AROUND_FORM_STATS_ENROLLMENT_KEY;
        }

        return '';
    }

    public static function api_base_url_from_constant(): string
    {
        if (defined('AROUND_FORM_STATS_API_URL') && is_string(AROUND_FORM_STATS_API_URL)) {
            return rtrim(AROUND_FORM_STATS_API_URL, '/');
        }

        return '';
    }
}
