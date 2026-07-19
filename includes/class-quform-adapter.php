<?php

declare(strict_types=1);

namespace AFS;

/**
 * Isolates Quform-specific hooks so the rest of the plugin stays provider-agnostic.
 *
 * Quform 2.x runs `quform_post_process` as a filter:
 *   add_filter('quform_post_process', fn (array $result, Quform_Form $form) => $result, 10, 2);
 */
final class QuformAdapter
{
    /** @var array<string, true> */
    private static array $captured_in_request = [];

    public static function boot(): void
    {
        // Quform 2.x: filter after a form is successfully processed.
        add_filter('quform_post_process', [self::class, 'on_post_process'], 10, 2);

        // Optional fallback for older/alternate Quform builds.
        if (apply_filters('afs_enable_quform_form_submitted_hook', false)) {
            add_action('quform_form_submitted', [self::class, 'on_form_submitted'], 10, 2);
        }
    }

    /**
     * @param array<string, mixed> $result
     * @param mixed                $form
     * @return array<string, mixed>
     */
    public static function on_post_process($result, $form = null)
    {
        // Defensive: some callers may pass only the form.
        if ($form === null && (is_object($result) || (is_array($result) && isset($result['id'])))) {
            self::capture($result);

            return is_array($result) ? $result : [];
        }

        self::capture($form);

        return is_array($result) ? $result : [];
    }

    /**
     * @param mixed $form
     */
    public static function on_form_submitted($form, $unused = null): void
    {
        self::capture($form);
    }

    /**
     * @param mixed $form
     */
    private static function capture($form): void
    {
        if ($form === null) {
            return;
        }

        $form_id = self::resolve_form_id($form);
        if ($form_id === '') {
            return;
        }

        // Prevent double-counting if multiple hooks fire for one submission.
        if (isset(self::$captured_in_request[$form_id])) {
            return;
        }
        self::$captured_in_request[$form_id] = true;

        $meta = self::extract_metadata($form);
        if ($meta === null) {
            return;
        }

        /**
         * Filter metadata-only submission events before they enter the local queue.
         *
         * @param array<string, mixed>|null $meta
         * @param mixed                     $form
         */
        $meta = apply_filters('afs_submission_event', $meta, $form);
        if (! is_array($meta) || $meta === []) {
            return;
        }

        Queue::enqueue($meta);
    }

    /**
     * @param mixed $form
     * @return array<string, mixed>|null
     */
    private static function extract_metadata($form): ?array
    {
        $form_id = self::resolve_form_id($form);
        if ($form_id === '') {
            return null;
        }

        return [
            'event_id' => Uuid::v4(),
            'form_id' => $form_id,
            'form_name' => self::resolve_form_name($form, $form_id),
            'submitted_at' => gmdate('c'),
            'plugin_version' => AFS_VERSION,
            'quform_version' => self::quform_version(),
            'provider' => 'quform',
            'provider_version' => self::quform_version(),
        ];
    }

    /**
     * @param mixed $form
     */
    private static function resolve_form_id($form): string
    {
        if (is_object($form)) {
            if (method_exists($form, 'getId')) {
                return (string) $form->getId();
            }
            if (method_exists($form, 'get_id')) {
                return (string) $form->get_id();
            }
            if (isset($form->id)) {
                return (string) $form->id;
            }
            if (isset($form->config['id'])) {
                return (string) $form->config['id'];
            }
        }

        if (is_array($form) && isset($form['id'])) {
            return (string) $form['id'];
        }

        if (is_numeric($form)) {
            return (string) $form;
        }

        return '';
    }

    /**
     * @param mixed $form
     */
    private static function resolve_form_name($form, string $fallback_id): string
    {
        if (is_object($form)) {
            if (method_exists($form, 'getName')) {
                $name = (string) $form->getName();
                if ($name !== '') {
                    return $name;
                }
            }
            if (method_exists($form, 'config') ) {
                // Quform_Form::config('name')
                try {
                    $name = (string) $form->config('name');
                    if ($name !== '') {
                        return $name;
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }
            if (isset($form->name) && is_string($form->name) && $form->name !== '') {
                return $form->name;
            }
            if (isset($form->config['name']) && is_string($form->config['name'])) {
                return $form->config['name'];
            }
        }

        if (is_array($form) && isset($form['name']) && is_string($form['name'])) {
            return $form['name'];
        }

        return 'Form ' . $fallback_id;
    }

    public static function quform_version(): string
    {
        if (defined('QUFORM_VERSION')) {
            return (string) QUFORM_VERSION;
        }

        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        foreach ($plugins as $file => $data) {
            if (stripos($file, 'quform') !== false || stripos((string) ($data['Name'] ?? ''), 'quform') !== false) {
                return (string) ($data['Version'] ?? '');
            }
        }

        return '';
    }

    public static function is_quform_active(): bool
    {
        return self::quform_version() !== '' || class_exists('Quform') || defined('QUFORM_VERSION');
    }
}
