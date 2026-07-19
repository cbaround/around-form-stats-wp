<?php

declare(strict_types=1);

namespace AFS;

final class Admin
{
    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_init', [self::class, 'handle_actions']);
        add_filter('plugin_action_links_' . AFS_PLUGIN_BASENAME, [self::class, 'action_links']);
    }

    /**
     * @param array<int, string> $links
     * @return array<int, string>
     */
    public static function action_links(array $links): array
    {
        $url = admin_url('options-general.php?page=around-form-stats');
        array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'around-form-stats') . '</a>');

        return $links;
    }

    public static function register_menu(): void
    {
        add_options_page(
            __('Around Form Stats', 'around-form-stats'),
            __('Around Form Stats', 'around-form-stats'),
            'manage_options',
            'around-form-stats',
            [self::class, 'render_page']
        );
    }

    public static function handle_actions(): void
    {
        if (! is_admin() || ! current_user_can('manage_options')) {
            return;
        }

        if (! isset($_POST['afs_action'])) {
            return;
        }

        check_admin_referer('afs_admin_action');

        $action = sanitize_text_field(wp_unslash((string) $_POST['afs_action']));

        if ($action === 'save_api_url') {
            $api = isset($_POST['api_base_url'])
                ? esc_url_raw(wp_unslash((string) $_POST['api_base_url']))
                : '';
            Options::update(['api_base_url' => rtrim($api, '/')]);
            self::redirect_with_notice('settings_saved');
        }

        if ($action === 'connect') {
            $api = isset($_POST['api_base_url'])
                ? esc_url_raw(wp_unslash((string) $_POST['api_base_url']))
                : Options::api_base_url();
            $key = isset($_POST['enrollment_key'])
                ? sanitize_text_field(wp_unslash((string) $_POST['enrollment_key']))
                : Options::enrollment_key_from_constant();

            $result = Enrollment::enroll($key, $api);
            self::redirect_with_notice($result['ok'] ? 'connected' : 'connect_failed', $result['message']);
        }

        if ($action === 'disconnect') {
            Enrollment::disconnect();
            self::redirect_with_notice('disconnected');
        }

        if ($action === 'flush_queue') {
            Queue::process();
            self::redirect_with_notice('queue_flushed');
        }

        if ($action === 'heartbeat') {
            Heartbeat::send();
            self::redirect_with_notice('heartbeat_sent');
        }
    }

    private static function redirect_with_notice(string $code, string $message = ''): void
    {
        $args = [
            'page' => 'around-form-stats',
            'afs_notice' => $code,
        ];
        if ($message !== '') {
            $args['afs_message'] = rawurlencode($message);
        }

        wp_safe_redirect(add_query_arg($args, admin_url('options-general.php')));
        exit;
    }

    public static function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $settings = Options::all();
        $connected = Options::is_connected();
        $queued = Queue::count();
        $quform_active = QuformAdapter::is_quform_active();
        $constant_key = Options::enrollment_key_from_constant() !== '';
        $constant_api = Options::api_base_url_from_constant() !== '';

        $notice = isset($_GET['afs_notice']) ? sanitize_text_field(wp_unslash((string) $_GET['afs_notice'])) : '';
        $message = isset($_GET['afs_message']) ? sanitize_text_field(rawurldecode(wp_unslash((string) $_GET['afs_message']))) : '';

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Around Form Stats', 'around-form-stats'); ?></h1>

            <?php if ($notice !== '') : ?>
                <div class="notice notice-<?php echo esc_attr(in_array($notice, ['connect_failed'], true) ? 'error' : 'success'); ?> is-dismissible">
                    <p><?php echo esc_html(self::notice_text($notice, $message)); ?></p>
                </div>
            <?php endif; ?>

            <div class="card" style="max-width: 720px; padding: 12px 16px; margin-top: 16px;">
                <h2><?php echo esc_html__('Connection status', 'around-form-stats'); ?></h2>
                <table class="widefat striped" style="margin-top: 8px;">
                    <tbody>
                        <tr>
                            <th><?php echo esc_html__('Status', 'around-form-stats'); ?></th>
                            <td>
                                <?php if ($connected) : ?>
                                    <strong style="color:#008a20;"><?php echo esc_html__('Connected', 'around-form-stats'); ?></strong>
                                    (<?php echo esc_html((string) $settings['site_status']); ?>)
                                <?php else : ?>
                                    <strong><?php echo esc_html__('Not connected', 'around-form-stats'); ?></strong>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php echo esc_html__('Site UUID', 'around-form-stats'); ?></th>
                            <td><code><?php echo esc_html((string) ($settings['site_uuid'] ?: '—')); ?></code></td>
                        </tr>
                        <tr>
                            <th><?php echo esc_html__('Queued events', 'around-form-stats'); ?></th>
                            <td><?php echo esc_html((string) $queued); ?></td>
                        </tr>
                        <tr>
                            <th><?php echo esc_html__('Last successful API request', 'around-form-stats'); ?></th>
                            <td><?php echo esc_html(self::format_time((string) $settings['last_success_at'])); ?></td>
                        </tr>
                        <tr>
                            <th><?php echo esc_html__('Last heartbeat', 'around-form-stats'); ?></th>
                            <td><?php echo esc_html(self::format_time((string) $settings['last_heartbeat_at'])); ?></td>
                        </tr>
                        <tr>
                            <th><?php echo esc_html__('Last API error', 'around-form-stats'); ?></th>
                            <td>
                                <?php if ((string) $settings['last_error'] !== '') : ?>
                                    <span style="color:#b32d2e;"><?php echo esc_html((string) $settings['last_error']); ?></span>
                                    <br><small><?php echo esc_html(self::format_time((string) $settings['last_error_at'])); ?></small>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php echo esc_html__('Quform', 'around-form-stats'); ?></th>
                            <td>
                                <?php
                                echo $quform_active
                                    ? esc_html(sprintf(
                                        /* translators: %s: Quform version */
                                        __('Detected (%s)', 'around-form-stats'),
                                        QuformAdapter::quform_version() ?: __('unknown version', 'around-form-stats')
                                    ))
                                    : esc_html__('Not detected', 'around-form-stats');
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php echo esc_html__('Plugin version', 'around-form-stats'); ?></th>
                            <td><?php echo esc_html(AFS_VERSION); ?></td>
                        </tr>
                    </tbody>
                </table>

                <?php if ($connected) : ?>
                    <form method="post" style="margin-top: 12px; display:inline-block; margin-right: 8px;">
                        <?php wp_nonce_field('afs_admin_action'); ?>
                        <input type="hidden" name="afs_action" value="flush_queue" />
                        <?php submit_button(__('Flush queue now', 'around-form-stats'), 'secondary', 'submit', false); ?>
                    </form>
                    <form method="post" style="display:inline-block; margin-right: 8px;">
                        <?php wp_nonce_field('afs_admin_action'); ?>
                        <input type="hidden" name="afs_action" value="heartbeat" />
                        <?php submit_button(__('Send heartbeat', 'around-form-stats'), 'secondary', 'submit', false); ?>
                    </form>
                    <form method="post" style="display:inline-block;">
                        <?php wp_nonce_field('afs_admin_action'); ?>
                        <input type="hidden" name="afs_action" value="disconnect" />
                        <?php submit_button(__('Disconnect', 'around-form-stats'), 'delete', 'submit', false); ?>
                    </form>
                <?php endif; ?>
            </div>

            <div class="card" style="max-width: 720px; padding: 12px 16px; margin-top: 16px;">
                <h2><?php echo esc_html__('Connect', 'around-form-stats'); ?></h2>
                <p>
                    <?php echo esc_html__('Paste the organisation enrollment key from Around Form Stats. The plugin exchanges it for a per-site API token and then discards the key.', 'around-form-stats'); ?>
                </p>

                <?php if ($constant_key || $constant_api) : ?>
                    <p>
                        <em>
                            <?php
                            echo esc_html__(
                                'wp-config constants detected: AROUND_FORM_STATS_ENROLLMENT_KEY and/or AROUND_FORM_STATS_API_URL.',
                                'around-form-stats'
                            );
                            ?>
                        </em>
                    </p>
                <?php endif; ?>

                <form method="post">
                    <?php wp_nonce_field('afs_admin_action'); ?>
                    <input type="hidden" name="afs_action" value="connect" />
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="afs_api_base_url"><?php echo esc_html__('API URL', 'around-form-stats'); ?></label>
                            </th>
                            <td>
                                <input
                                    name="api_base_url"
                                    id="afs_api_base_url"
                                    type="url"
                                    class="regular-text"
                                    value="<?php echo esc_attr(Options::api_base_url_from_constant() ?: (string) $settings['api_base_url']); ?>"
                                    placeholder="https://stats.example.com"
                                    <?php disabled($constant_api); ?>
                                    required
                                />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="afs_enrollment_key"><?php echo esc_html__('Enrollment key', 'around-form-stats'); ?></label>
                            </th>
                            <td>
                                <input
                                    name="enrollment_key"
                                    id="afs_enrollment_key"
                                    type="text"
                                    class="regular-text"
                                    value=""
                                    autocomplete="off"
                                    placeholder="<?php echo esc_attr($constant_key ? __('Using constant AROUND_FORM_STATS_ENROLLMENT_KEY', 'around-form-stats') : 'afs_...'); ?>"
                                    <?php echo $constant_key ? '' : 'required'; ?>
                                />
                            </td>
                        </tr>
                    </table>
                    <?php submit_button($connected ? __('Reconnect', 'around-form-stats') : __('Connect', 'around-form-stats')); ?>
                </form>
            </div>
        </div>
        <?php
    }

    private static function notice_text(string $code, string $message): string
    {
        if ($message !== '') {
            return $message;
        }

        switch ($code) {
            case 'settings_saved':
                return __('Settings saved.', 'around-form-stats');
            case 'connected':
                return __('Connected.', 'around-form-stats');
            case 'connect_failed':
                return __('Connection failed.', 'around-form-stats');
            case 'disconnected':
                return __('Disconnected.', 'around-form-stats');
            case 'queue_flushed':
                return __('Queue flush attempted.', 'around-form-stats');
            case 'heartbeat_sent':
                return __('Heartbeat sent.', 'around-form-stats');
            default:
                return $code;
        }
    }

    private static function format_time(string $iso): string
    {
        if ($iso === '') {
            return '—';
        }

        $timestamp = strtotime($iso);
        if ($timestamp === false) {
            return $iso;
        }

        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }
}
