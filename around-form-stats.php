<?php
/**
 * Plugin Name:       Around Form Stats
 * Description:       Push-based form submission stats for Quform. Sends metadata-only events to Around Form Stats.
 * Version:           1.0.4
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Around
 * Text Domain:       around-form-stats
 * License:           GPL-2.0-or-later
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('AFS_VERSION', '1.0.4');
define('AFS_PLUGIN_FILE', __FILE__);
define('AFS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AFS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AFS_PLUGIN_BASENAME', plugin_basename(__FILE__));

require_once AFS_PLUGIN_DIR . 'includes/class-autoloader.php';

AFS\Autoloader::register();

register_activation_hook(__FILE__, [AFS\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [AFS\Plugin::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    AFS\Plugin::instance()->boot();
});
