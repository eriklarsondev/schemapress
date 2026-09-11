<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The site's own settings, as opposed to a collection's. One option row.
 *
 * The master switch governs the REST content API only. PHP and Twig are how a
 * theme renders a page and are always available; switching them off would mean
 * switching the site off. It is also not the admin transport under
 * schemapress/admin/v1 — taking that down with it would leave no way to turn
 * either back on.
 *
 * It is on by default, which sounds wrong for something that makes content
 * public until you notice it cannot publish anything by itself: the opt-in is
 * per collection and defaults to off. A master switch that also defaulted to off
 * would mean a collection whose own switch is ON quietly serving nothing, with
 * nothing on its screen explaining why.
 */
class Settings
{
    public const OPTION = 'schemapress_settings';

    /**
     * The longest a public API response may be cached, in seconds. A day: this
     * is a shared cache directive, and an hour of an editor wondering why the
     * site still shows the old text is the most it should be able to cost.
     */
    public const MAX_CACHE_AGE = 86400;

    /**
     * @var array|null
     */
    private static $cache = null;

    /**
     * The site's settings, normalized.
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
     * Whether the content API answers at all.
     *
     * @return boolean
     */
    public static function restEnabled()
    {
        return !empty(self::all()['restApi']);
    }

    /**
     * How long a public API response may be considered fresh.
     *
     * @return integer seconds; 0 means revalidate every time
     */
    public static function cacheMaxAge()
    {
        return (int) self::all()['apiCacheMaxAge'];
    }

    /**
     * Coerces an arbitrary payload into the settings shape.
     *
     * Absent means default rather than off, so a row written by an older version
     * — or no row at all, which is every site until someone visits this screen —
     * reads as the default instead of as the API switched off.
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
            // zero is not the same as uncacheable: every response carries an
            // ETag either way, so a client that asks again gets a 304 and no
            // body. That is the whole win with none of the staleness
            'apiCacheMaxAge' => min(
                self::MAX_CACHE_AGE,
                max(0, (int) ($settings['apiCacheMaxAge'] ?? 0))
            ),
            'deleteDataOnUninstall' => !empty($settings['deleteDataOnUninstall']),
        ];
    }

    /**
     * Stores the settings and returns what was actually stored.
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
         * Fires after the site's settings are stored.
         *
         * @param array $normalized
         */
        do_action('schemapress/settings_saved', $normalized);

        return $normalized;
    }

    /**
     * Clears the in-request cache.
     *
     * @return void
     */
    public static function flush()
    {
        self::$cache = null;
    }
}
