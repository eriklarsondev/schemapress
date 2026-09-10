<?php
/**
 * Plugin Name:       SchemaPress
 * Plugin URI:        https://github.com/eriklarson/schemapress
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
 * Requires PHP 8.2 because Timber 2 does. WordPress checks this header before
 * activating, which is the only thing standing between an older site and a
 * fatal error the moment the autoloader reaches Timber.
 *
 * THE VERSION IS IN TWO PLACES and has to be: WordPress reads the header with a
 * regular expression before any PHP runs, so it cannot be a constant, and the
 * plugin's own code cannot read the header without loading WordPress first. the
 * test suite asserts the two agree, which is the cheapest place to catch the one
 * that gets forgotten.
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
 * maps a class name in this namespace to its classes/class-*.php file.
 * SchemaPress\SchemaModel -> classes/class-schema-model.php
 *
 * @param string $class
 *
 * @return void
 */
spl_autoload_register(function ($class) {
    // the reading API is reachable by a bare name from a theme, which needs a
    // global one. aliasing it here rather than at load time keeps the class
    // unloaded until something asks for it.
    //
    // `SchemaPress` is the name to use and reads as what it is — a global class
    // and a namespace can share a name, because a namespace is not an entity
    // PHP resolves separately.
    //
    // `Content` ALSO ANSWERED HERE, and does not any more. It was the original
    // name, kept so a template written against it would not break — but the
    // global namespace is shared with every other plugin on the site, and
    // `Content` is about as generic as a class name gets. Declaring it is a
    // fatal error the moment anything else does, and this plugin cannot be the
    // one that claims it.
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

/**
 * loads Composer's autoloader when the plugin has its own vendor directory.
 *
 * THIS USED TO BE GUARDED ON `Timber\Timber` NOT EXISTING, on the reasoning
 * that a theme which had already loaded Timber did not need a second copy. The
 * reasoning was about Timber and the guard was over the whole autoloader —
 * which also carries league/commonmark, the Markdown parser the Documentation
 * screen is written against.
 *
 * So on any site where a theme loaded Timber first, this plugin skipped its own
 * autoloader entirely, CommonMark was never available, and the documentation
 * rendered as unparsed plain text under a notice advising the reader to run
 * `composer install` — advice that is wrong on a site installed from the plugin
 * directory, where there is no Composer and vendor/ is already right there.
 *
 * And it was worst on precisely the sites most likely to install this: a plugin
 * whose Twig functions need Timber is one a Timber site goes looking for.
 *
 * Composer's autoloader is additive. A class already declared is never asked
 * for again, so registering this one alongside another cannot replace what is
 * loaded; it only answers for what nothing else has.
 */
if (file_exists(SCHEMAPRESS_PATH . 'vendor/autoload.php')) {
    require_once SCHEMAPRESS_PATH . 'vendor/autoload.php';
}

require_once SCHEMAPRESS_PATH . 'includes/helpers.php';

add_action('plugins_loaded', [Plugin::class, 'boot']);

// the command line, which only exists when there is one to register with. this
// is not inside Plugin's service list because it is not a service: nothing on a
// web request should pay for it, and WP-CLI is loaded long before this point
add_action('plugins_loaded', [Cli::class, 'register']);

/**
 * loads the translations.
 *
 * on `init` rather than `plugins_loaded`, which is where WordPress 6.7 began
 * warning about it: a textdomain loaded before `init` cannot see a translation
 * a theme or another plugin registers on `init`, and just-in-time loading
 * handles the ordinary case anyway. this is here for the case it does not — a
 * plugin outside the wordpress.org directory, whose translations live in its own
 * `languages` directory rather than in wp-content/languages/plugins.
 *
 * this was missing entirely, which meant every `__()` in the plugin and the
 * wp_set_script_translations() call in class-assets.php were decorative: the
 * strings were marked up for translation and no translation could ever load.
 *
 * @return void
 */
add_action('init', function () {
    load_plugin_textdomain('schemapress', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

/**
 * prepares a fresh install.
 *
 * the rewrite flush is for the schema post type. the capability grant is what
 * makes the plugin usable at all: its capabilities are its own now rather than
 * borrowed from WordPress, so until a role holds one nobody can open the menu.
 *
 * an upgrade that never deactivates the plugin does not reach this, which is why
 * class-upgrade.php grants them again — see the note there.
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
 * puts the site back as it was, keeping every trace of the content.
 *
 * the queued jobs go, because a job is work this plugin was going to do and it
 * is not running any more — a cron event pointing at a hook nothing listens on
 * would sit in the schedule failing quietly. the capabilities STAY: a
 * deactivated plugin that stripped its capabilities from every role would come
 * back with the site's own grants lost, including ones added by hand. they are
 * removed on uninstall, which is the point at which somebody has said they are
 * finished with it.
 *
 * @return void
 */
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('schemapress/run_jobs');
    flush_rewrite_rules();
});
