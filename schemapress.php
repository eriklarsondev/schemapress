<?php

/**
 * Plugin Name:       SchemaPress
 * Plugin URI:        https://github.com/eriklarsondev/schemapress
 * Description:       Define a collection type — a named shape with typed fields — and get an admin for filling it in and a REST API for reading it back.
 * Version:           0.2.0
 * Author:            Erik Larson
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       schemapress
 * Domain Path:       /languages
 * Requires PHP:      8.2
 * Requires at least: 6.2
 *
 * The version is in two places and has to be: WordPress reads the header with a
 * regular expression before any PHP runs, so it cannot be a constant. The test
 * suite asserts the two agree.
 *
 * @package SchemaPress
 */

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

define('SCHEMAPRESS_VERSION', '0.2.0');
define('SCHEMAPRESS_FILE', __FILE__);
define('SCHEMAPRESS_PATH', plugin_dir_path(__FILE__));
define('SCHEMAPRESS_URL', plugin_dir_url(__FILE__));

/**
 * Maps a class in this namespace to its file: SchemaPress\SchemaModel ->
 * classes/class-schema-model.php
 *
 * @param string $class
 *
 * @return void
 */
spl_autoload_register(function ($class) {
    // the reading API is reachable by a bare name from a theme. aliasing here
    // rather than at load time keeps the class unloaded until something asks
    if ($class === 'SchemaPress') {
        class_alias(Content::class, $class);

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

// Unconditionally, even where a theme has already loaded one. Composer's
// autoloader is additive — a class already declared is never asked for again —
// so registering this alongside another cannot replace what is loaded.
if (file_exists(SCHEMAPRESS_PATH . 'vendor/autoload.php')) {
    require_once SCHEMAPRESS_PATH . 'vendor/autoload.php';
}

require_once SCHEMAPRESS_PATH . 'includes/helpers.php';

add_action('plugins_loaded', [Plugin::class, 'boot']);

// not in Plugin's service list: nothing on a web request should pay for it, and
// WP-CLI is loaded long before this point
add_action('plugins_loaded', [Cli::class, 'register']);

/**
 * Loads the translations.
 *
 * On `init` rather than `plugins_loaded`, which is where WordPress 6.7 began
 * warning about it: a textdomain loaded before `init` cannot see a translation
 * a theme or another plugin registers on `init`.
 *
 * @return void
 */
add_action('init', function () {
    load_plugin_textdomain('schemapress', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

/**
 * Prepares a fresh install.
 *
 * The capability grant is what makes the plugin usable at all: until a role
 * holds one, nobody can open the menu. An upgrade that never deactivates does
 * not reach this, which is why class-upgrade.php grants them again.
 *
 * @return void
 */
register_activation_hook(__FILE__, function () {
    require_once SCHEMAPRESS_PATH . 'classes/class-schema.php';
    require_once SCHEMAPRESS_PATH . 'classes/class-capabilities.php';

    (new Schema())->registerPostType();
    Capabilities::grant();
    flush_rewrite_rules();
});

/**
 * Puts the site back as it was, keeping every trace of the content.
 *
 * Queued jobs go, because a cron event pointing at a hook nothing listens on
 * would sit in the schedule failing quietly. The capabilities stay until
 * uninstall — revoking them on deactivation would lose the site's own grants,
 * including ones added by hand.
 *
 * @return void
 */
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('schemapress/run_jobs');
    flush_rewrite_rules();
});
