<?php
declare(strict_types=1);

/**
 * Plugin Name: LeanAutoLinks
 * Description: Lean, API-first automated internal linking for high-volume WordPress sites.
 * Version:     0.3.1
 * Requires PHP: 8.1
 * Author:      Cristian Tala Sánchez
 * Author URI:  https://cristiantala.com/
 * License:     GPLv2 or later
 * Text Domain: leanautolinks
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LEANAUTOLINKS_VERSION', '0.3.1');
define('LEANAUTOLINKS_FILE', __FILE__);
define('LEANAUTOLINKS_DIR', plugin_dir_path(__FILE__));
define('LEANAUTOLINKS_URL', plugin_dir_url(__FILE__));

/**
 * Load dependencies: Composer autoloader or manual requires.
 */
if (file_exists(LEANAUTOLINKS_DIR . 'vendor/autoload.php')) {
    require_once LEANAUTOLINKS_DIR . 'vendor/autoload.php';
} else {
    // Manual autoload fallback when Composer is not available.
    spl_autoload_register(static function (string $class): void {
        $prefix = 'LeanAutoLinks\\';
        $base_dir = LEANAUTOLINKS_DIR . 'src/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        if (file_exists($file)) {
            require_once $file;
        }
    });
}

/**
 * Load Action Scheduler if bundled.
 */
if (file_exists(LEANAUTOLINKS_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php')) {
    require_once LEANAUTOLINKS_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

/**
 * Activation hook.
 */
register_activation_hook(__FILE__, static function (): void {
    $installer = new \LeanAutoLinks\Installer();
    $installer->activate();
});

/**
 * Deactivation hook.
 */
register_deactivation_hook(__FILE__, static function (): void {
    $installer = new \LeanAutoLinks\Installer();
    $installer->deactivate();
});

/**
 * Bootstrap the plugin on plugins_loaded to ensure all dependencies are available.
 */
add_action('plugins_loaded', static function (): void {
    \LeanAutoLinks\Plugin::get_instance()->init();
}, 10);
