<?php

declare(strict_types=1);

namespace AFS;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

final class Updater
{
    public static function boot(): void
    {
        $autoload = AFS_PLUGIN_DIR . 'vendor/autoload.php';
        if (! is_readable($autoload)) {
            return;
        }

        require_once $autoload;

        $checker = PucFactory::buildUpdateChecker(
            'https://github.com/cbaround/around-form-stats-wp/',
            AFS_PLUGIN_FILE,
            'around-form-stats'
        );

        // Prefer GitHub Releases (tag like v1.0.2) for update metadata.
        $checker->getVcsApi()->enableReleaseAssets();

        $token = self::github_token();
        if ($token !== '') {
            $checker->setAuthentication($token);
        }
    }

    private static function github_token(): string
    {
        if (defined('AROUND_FORM_STATS_GITHUB_TOKEN') && is_string(AROUND_FORM_STATS_GITHUB_TOKEN)) {
            return AROUND_FORM_STATS_GITHUB_TOKEN;
        }

        $stored = Options::get('github_token', '');

        return is_string($stored) ? $stored : '';
    }
}
