<?php
/**
 * Plugin Name: Lensman
 * Plugin URI: https://github.com/rennerdo30/wp-lensman
 * Description: Auto-generates responsive WebP / AVIF variants of uploaded images, caches them on disk, and rewrites front-end <img> tags into <picture> + srcset so Lighthouse stops shaming you for 4MB hero photos. MIT.
 * Version: 0.2.0
 * Author: renner.dev
 * Author URI: https://renner.dev
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: lensman
 * Requires at least: 6.5
 * Requires PHP: 8.1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('LENSMAN_VERSION', '0.2.0');
define('LENSMAN_FILE', __FILE__);
define('LENSMAN_DIR', plugin_dir_path(__FILE__));
define('LENSMAN_URL', plugin_dir_url(__FILE__));

// Lightweight PSR-4 autoloader for the Lensman namespace.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Lensman\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = LENSMAN_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

add_action('plugins_loaded', static function (): void {
    (new \Lensman\Plugin())->boot();
});

register_activation_hook(__FILE__, static function (): void {
    \Lensman\Plugin::on_activate();
});

register_deactivation_hook(__FILE__, static function (): void {
    \Lensman\Plugin::on_deactivate();
});
