<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * the site's own settings, as opposed to a collection's.
 *
 * one option row, because there is one site. a collection's settings live in
 * its definition and describe that collection; these describe the installation.
 *
 * THE MASTER SWITCH. this plugin delivers content three ways — PHP, Twig and
 * the REST content API — and only the last of them leaves the server. the first
 * two are how a theme renders a page and are always available: switching them
 * off would mean switching the site off.
 *
 * so there is one switch, over the one surface where it means anything. off,
 * no collection answers over HTTP whatever its own settings say, and every
 * template on the site carries on exactly as before.
 *
 * IT IS NOT THE ADMIN TRANSPORT. the builder talks to WordPress over REST too,
 * under schemapress/admin/v1, and that is not what this governs — it is how the
 * screen holding this switch loads at all, and taking it down with the content
 * API would leave no way to turn either back on. see class-rest.php on why the
 * two namespaces are separate in the first place.
 *
 * ON BY DEFAULT, which sounds like the wrong direction for something that makes
 * content public until you notice it cannot publish anything by itself. the
 * opt-in is per collection and defaults to off; a master switch that also
 * defaulted to off would mean a collection whose own switch is ON quietly
 * serving nothing, with nothing on its screen explaining why.
 */
class Settings
{
    const OPTION = 'schemapress_settings';

    /**
     * @var array|null
     */
    private static $cache = null;

    /**
     * the site's settings, normalized.
     *
     * @return array
     */
    public static function all()
    {
        if (self::$cache === null) {
            self::$cache = self::normalize(get_option(self::OPTION, []));
        }

        return self::$cache;
    }

    /**
     * the longest a public API response may be cached, in seconds.
     *
     * the ceiling is a day. this is a shared cache directive — a CDN, a reverse
     * proxy — and an hour of an editor wondering why the site still shows the
     * old text is the most this setting should be able to cost somebody who
     * turned it up without reading the note beside it.
     */
    const MAX_CACHE_AGE = 86400;

    /**
     * whether the content API answers at all.
     *
     * @return boolean
     */
    public static function restEnabled()
    {
        return !empty(self::all()['restApi']);
    }

    /**
     * how long a public API response may be considered fresh.
     *
     * @return integer seconds; 0 means revalidate every time
     */
    public static function cacheMaxAge()
    {
        return (int) self::all()['apiCacheMaxAge'];
    }

    /**
     * whether deleting the plugin should take this plugin's content with it.
     *
     * @return boolean
     */
    public static function deletesData()
    {
        return !empty(self::all()['deleteDataOnUninstall']);
    }

    /**
     * coerces an arbitrary payload into the settings shape.
     *
     * absent means default rather than off, so a settings row written by an
     * older version — or no row at all, which is every site until someone
     * visits this screen — reads as the default instead of as the API switched
     * off.
     *
     * @param mixed $settings
     *
     * @return array
     */
    public static function normalize($settings)
    {
        $settings = is_array($settings) ? $settings : [];

        return [
            'restApi' => array_key_exists('restApi', $settings)
                ? (bool) $settings['restApi']
                : true,
            // how long a shared cache may hold a public API response. ZERO BY
            // DEFAULT, which is not the same as uncacheable: every response
            // carries an ETag either way, so a client that asks again gets a
            // 304 and no body. that is the whole win with none of the staleness,
            // and turning this up is a decision about a specific site's content
            // rather than something to inflict on every install
            'apiCacheMaxAge' => min(
                self::MAX_CACHE_AGE,
                max(0, (int) ($settings['apiCacheMaxAge'] ?? 0))
            ),
            // whether deleting the plugin erases its content. OFF, and the one
            // setting in here whose default is not a judgement call: somebody
            // deactivating a plugin to try something is not saying they want
            // their collections gone, and there is no undo for guessing wrong
            'deleteDataOnUninstall' => !empty($settings['deleteDataOnUninstall']),
        ];
    }

    /**
     * stores the settings and returns what was actually stored.
     *
     * @param mixed $settings
     *
     * @return array
     */
    public static function save($settings)
    {
        $normalized = self::normalize($settings);

        update_option(self::OPTION, $normalized);
        self::$cache = $normalized;

        /**
         * fires after the site's settings are stored.
         *
         * @param array $normalized
         */
        do_action('schemapress/settings_saved', $normalized);

        return $normalized;
    }

    /**
     * clears the in-request cache.
     *
     * @return void
     */
    public static function flush()
    {
        self::$cache = null;
    }
}
