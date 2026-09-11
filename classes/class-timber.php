<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The Timber integration: exposing the reading API to Twig, so a template can
 * ask for a collection without the PHP file above it fetching and passing one
 * down.
 *
 *   {% for person in sp_collection('team_members') %}
 *     {{ person.name }}
 *   {% endfor %}
 *
 * Timber is optional and not bundled. Without it the PHP API is unaffected —
 * only these Twig functions are missing.
 */
class Timber
{
    /**
     * The Timber major version these functions are registered against.
     */
    public const REQUIRES = 2;

    /**
     * Hooks the Twig function registration.
     */
    public function __construct()
    {
        add_filter('timber/twig/functions', [$this, 'functions']);
    }

    /**
     * Whether Timber is loaded and a version this plugin can register with.
     *
     * @return boolean
     */
    public static function available()
    {
        return class_exists('Timber\\Timber') && self::major() >= self::REQUIRES;
    }

    /**
     * The loaded Timber major version, or 0 when Timber is absent.
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
     * Exposes the reading API to Twig.
     *
     * @param array $functions
     *
     * @return array
     */
    public function functions($functions)
    {
        $exposed = [
            'sp_collection' => [Content::class, 'collection'],
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
     * Mirrors schemapress_entry() in includes/helpers.php.
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
