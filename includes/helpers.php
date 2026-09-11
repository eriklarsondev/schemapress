<?php

/**
 * Procedural aliases for the reading API, because a WordPress template is a
 * procedural place. The API proper is `SchemaPress::collection('team_members')`.
 *
 * Prefixed `schemapress_` rather than `sp_`: SportsPress is on the plugin
 * directory with tens of thousands of installations and uses `sp_` throughout.
 * The Twig functions in class-timber.php can stay `sp_collection()` because
 * those names live in a Twig environment this plugin owns.
 *
 * @package SchemaPress
 */

use SchemaPress\Content;

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('schemapress_collection')) {
    /**
     * a collection, by its machine key.
     *
     *   foreach (schemapress_collection('team_members') as $person) {
     *     echo esc_html($person->name);
     *   }
     *
     * @param string $key
     *
     * @return \SchemaPress\Collection
     */
    function schemapress_collection($key)
    {
        return Content::collection($key);
    }
}

if (!function_exists('schemapress_entry')) {
    /**
     * one entry of a collection, by id.
     *
     * @param string  $key
     * @param integer $id
     *
     * @return \SchemaPress\Entry|null
     */
    function schemapress_entry($key, $id)
    {
        return Content::collection($key)->find($id);
    }
}

if (!function_exists('schemapress_collections')) {
    /**
     * every collection's key.
     *
     * @return string[]
     */
    function schemapress_collections()
    {
        return Content::collections();
    }
}

if (!function_exists('schemapress_has_collection')) {
    /**
     * whether a collection exists.
     *
     * @param string $key
     *
     * @return boolean
     */
    function schemapress_has_collection($key)
    {
        return Content::has($key);
    }
}
