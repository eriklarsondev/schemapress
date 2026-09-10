<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * the Timber integration.
 *
 * this plugin renders nothing. it models content and hands it over; what a page
 * looks like is the theme's Twig. so the integration is one thing only —
 * exposing the reading API to Twig, so a template can ask for a collection
 * without the PHP file above it having to fetch and pass one down:
 *
 *   {% for person in sp_collection('team_members') %}
 *     {{ person.name }}
 *   {% endfor %}
 *
 * Timber is optional. Without it the PHP API is unaffected — only these Twig
 * functions are missing, and a theme that is not using Twig would not have
 * called them.
 */
class Timber
{
    /**
     * the Timber major version these functions are registered against.
     */
    public const REQUIRES = 2;

    /**
     * hooks the Twig function registration.
     */
    public function __construct()
    {
        add_filter('timber/twig/functions', [$this, 'functions']);
    }

    /**
     * whether Timber is loaded and a version this plugin can register with.
     *
     * @return boolean
     */
    public static function available()
    {
        return class_exists('Timber\\Timber') && self::major() >= self::REQUIRES;
    }

    /**
     * the loaded Timber major version, or 0 when Timber is absent.
     *
     * @return integer
     */
    public static function major()
    {
        if (!class_exists('Timber\\Timber')) {
            return 0;
        }

        // Timber 2 exposes a version constant; 1.x did not, so its absence is
        // itself the signal that an older copy won the autoloader race
        return defined('Timber\\Timber::VERSION')
            ? (int) constant('Timber\\Timber::VERSION')
            : 1;
    }

    /**
     * exposes the reading API to Twig.
     *
     * @param array $functions
     *
     * @return array
     */
    public function functions($functions)
    {
        $exposed = [
            'sp_collection' => [Content::class, 'collection'],
            // one entry by id. the procedural helper has existed since the
            // start and this did not, so a Twig template asking for
            // sp_entry() got an undefined function while the PHP beside it
            // worked — the two surfaces are meant to be the same API
            'sp_entry' => [self::class, 'entry'],
            'sp_collections' => [Content::class, 'collections'],
            'sp_has_collection' => [Content::class, 'has'],
        ];

        foreach ($exposed as $name => $callable) {
            $functions[$name] = ['callable' => $callable];
        }

        return $functions;
    }

    /**
     * one entry of a collection, by id.
     *
     * mirrors schemapress_entry() in includes/helpers.php — the Twig name stays sp_entry, which is safe because it is registered into this plugin's own Twig environment rather than the global one.
     *
     * @param string $key
     * @param string $id
     *
     * @return Entry|null
     */
    public static function entry($key, $id)
    {
        return Content::collection($key)->find($id);
    }
}
