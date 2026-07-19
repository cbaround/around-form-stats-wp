<?php

declare(strict_types=1);

namespace AFS;

final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public static function activate(): void
    {
        Queue::create_table();
        Options::ensure_defaults();
        Heartbeat::schedule();
        Queue::schedule();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        Heartbeat::unschedule();
        Queue::unschedule();
    }

    public function boot(): void
    {
        Options::boot();
        Updater::boot();
        Admin::boot();
        Queue::boot();
        Heartbeat::boot();
        Enrollment::boot();
        QuformAdapter::boot();

        if (defined('WP_CLI') && WP_CLI) {
            Cli::boot();
        }
    }
}
