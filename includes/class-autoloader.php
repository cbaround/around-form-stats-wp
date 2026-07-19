<?php

declare(strict_types=1);

namespace AFS;

final class Autoloader
{
    public static function register(): void
    {
        spl_autoload_register(static function (string $class): void {
            if (strpos($class, __NAMESPACE__ . '\\') !== 0) {
                return;
            }

            $relative = substr($class, strlen(__NAMESPACE__ . '\\'));
            $relative = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $relative) ?? $relative);
            $relative = str_replace('\\', '/', $relative);
            $path = AFS_PLUGIN_DIR . 'includes/class-' . $relative . '.php';

            if (is_readable($path)) {
                require_once $path;
            }
        });
    }
}
