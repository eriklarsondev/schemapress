<?php
/**
 * Procedural aliases for the reading API.
 *
 * The API proper is the Content facade — `Content::collection('team_members')`.
 * These exist because a WordPress template is a procedural place, and a
 * function reads more naturally inside one.
 *
 * @package SchemaPress
 */

use SchemaPress\Content;

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('sp_collection')) {
    /**
     * a collection, by its machine key.
     *
     *   foreach (sp_collection('team_members') as $person) {
     *     echo esc_html($person->name);
     *   }
     *
     * @param string $key
     *
     * @return \SchemaPress\Collection
     */
    function sp_collection($key)
    {
        return Content::collection($key);
    }
}

if (!function_exists('sp_entry')) {
    /**
     * one entry of a collection, by id.
     *
     * @param string  $key
     * @param integer $id
     *
     * @return \SchemaPress\Entry|null
     */
    function sp_entry($key, $id)
    {
        return Content::collection($key)->find($id);
    }
}

if (!function_exists('sp_collections')) {
    /**
     * every collection's key.
     *
     * @return string[]
     */
    function sp_collections()
    {
        return Content::collections();
    }
}

if (!function_exists('sp_has_collection')) {
    /**
     * whether a collection exists.
     *
     * @param string $key
     *
     * @return boolean
     */
    function sp_has_collection($key)
    {
        return Content::has($key);
    }
}
