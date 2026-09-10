<?php
/**
 * Procedural aliases for the reading API.
 *
 * The API proper is the SchemaPress facade — `SchemaPress::collection('team_members')`.
 * These exist because a WordPress template is a procedural place, and a
 * function reads more naturally inside one.
 *
 * THEY ARE `schemapress_` RATHER THAN `sp_`, AND HAVE TO BE. Every function a
 * plugin declares in the global namespace shares that namespace with every
 * other plugin on the site, and `sp_` is two letters — SportsPress, which is on
 * the plugin directory with tens of thousands of installations, uses it
 * throughout. Two plugins declaring the same function name is not a warning,
 * it is a fatal error; the `function_exists()` guards below turn that into the
 * quieter and worse failure where a template calls somebody else's function and
 * gets somebody else's answer.
 *
 * The Twig functions in class-timber.php are still `sp_collection()` and
 * friends, and can be: those names are registered into a Twig environment this
 * plugin owns, so they collide with nothing.
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
