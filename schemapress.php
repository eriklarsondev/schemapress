<?php
/**
 * Plugin Name:       SchemaPress
 * Plugin URI:        https://github.com/eriklarson/schemapress
 * Description:       Define reusable page content schemas — ordered sections, typed fields, repeatable items — and bind them to page templates.
 * Version:           0.1.0
 * Author:            Erik Larson
 * License:           GPL-2.0-or-later
 * Text Domain:       schemapress
 * Requires PHP:      8.2
 * Requires at least: 6.2
 *
 * Requires PHP 8.2 because Timber 2 does. WordPress checks this header before
 * activating, which is the only thing standing between an older site and a
 * fatal error the moment the autoloader reaches Timber.
 *
 * @package SchemaPress
 */

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

define('SCHEMAPRESS_VERSION', '0.1.0');
define('SCHEMAPRESS_FILE', __FILE__);
define('SCHEMAPRESS_PATH', plugin_dir_path(__FILE__));
define('SCHEMAPRESS_URL', plugin_dir_url(__FILE__));

/**
 * maps a class name in this namespace to its classes/class-*.php file.
 * SchemaPress\SchemaModel -> classes/class-schema-model.php
 *
 * @param string $class
 *
 * @return void
 */
spl_autoload_register(function ($class) {
    // the reading API is reachable as a bare `Content::` from a theme, which
    // needs a global name. aliasing it here rather than at load time keeps the
    // class unloaded until something asks for it
    if ($class === 'Content') {
        class_alias(Content::class, 'Content');

        return;
    }

    if (strpos($class, __NAMESPACE__ . '\\') !== 0) {
        return;
    }

    $short = substr($class, strlen(__NAMESPACE__) + 1);
    $slug = strtolower(preg_replace('/(?<!^)([A-Z])/', '-$1', $short));
    $path = SCHEMAPRESS_PATH . 'classes/class-' . $slug . '.php';

    if (file_exists($path)) {
        require_once $path;
    }
});

/**
 * loads Composer's autoloader when the plugin has its own vendor directory.
 *
 * guarded because Timber may already be loaded by the theme or another plugin
 * that required it first — in which case its classes are present and loading a
 * second copy would be at best redundant.
 */
if (!class_exists('Timber\\Timber') && file_exists(SCHEMAPRESS_PATH . 'vendor/autoload.php')) {
    require_once SCHEMAPRESS_PATH . 'vendor/autoload.php';
}

require_once SCHEMAPRESS_PATH . 'includes/helpers.php';

add_action('plugins_loaded', [Plugin::class, 'boot']);

/**
 * flushes rewrite rules once on activation so the schema post type resolves.
 *
 * @return void
 */
register_activation_hook(__FILE__, function () {
    require_once SCHEMAPRESS_PATH . 'classes/class-schema.php';
    (new Schema())->registerPostType();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
