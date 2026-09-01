<?php
/**
 * Public template API.
 *
 * The only surface themes should touch. Everything here is a thin wrapper over
 * SchemaPress\Render, kept procedural so templates read naturally.
 *
 * @package SchemaPress
 */

use SchemaPress\Render;
use SchemaPress\Binding;
use SchemaPress\Section;

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('sp_sections')) {
    /**
     * the ordered sections placed on a post.
     *
     * @param integer|null $post_id
     *
     * @return Section[]
     */
    function sp_sections($post_id = null)
    {
        return Render::sections($post_id);
    }
}

if (!function_exists('sp_has_sections')) {
    /**
     * whether a post has any renderable sections.
     *
     * @param integer|null $post_id
     *
     * @return boolean
     */
    function sp_has_sections($post_id = null)
    {
        return !empty(Render::sections($post_id));
    }
}

if (!function_exists('sp_section')) {
    /**
     * the first section of a type, or null.
     *
     * @param string       $type
     * @param integer|null $post_id
     *
     * @return Section|null
     */
    function sp_section($type, $post_id = null)
    {
        return Render::section($type, $post_id);
    }
}

if (!function_exists('sp_sections_of')) {
    /**
     * every section of a type.
     *
     * @param string       $type
     * @param integer|null $post_id
     *
     * @return Section[]
     */
    function sp_sections_of($type, $post_id = null)
    {
        return Render::ofType($type, $post_id);
    }
}

if (!function_exists('sp_field')) {
    /**
     * reads a field from the first section of a type, by dot path.
     *
     *   sp_field('hero.heading')
     *   sp_field('hero.cta.url', '#')
     *
     * for pages that place a section type more than once, iterate sp_sections()
     * instead — this always resolves against the first match.
     *
     * @param string       $path
     * @param mixed        $default
     * @param integer|null $post_id
     *
     * @return mixed
     */
    function sp_field($path, $default = null, $post_id = null)
    {
        list($type, $field) = array_pad(explode('.', (string) $path, 2), 2, '');

        $section = Render::section($type, $post_id);

        if (!$section || $field === '') {
            return $default;
        }

        return $section->get($field, $default);
    }
}

if (!function_exists('sp_rows')) {
    /**
     * repeater rows from the first section of a type.
     *
     *   foreach (sp_rows('card_grid.cards') as $card) {
     *     echo esc_html($card->get('title'));
     *   }
     *
     * @param string       $path
     * @param integer|null $post_id
     *
     * @return \SchemaPress\Fields[]
     */
    function sp_rows($path, $post_id = null)
    {
        list($type, $field) = array_pad(explode('.', (string) $path, 2), 2, '');

        $section = Render::section($type, $post_id);

        if (!$section || $field === '') {
            return [];
        }

        return $section->rows($field);
    }
}

if (!function_exists('sp_render_sections')) {
    /**
     * renders every section through a template part named after its type.
     *
     *   sp_render_sections();          // theme/sections/hero.php, etc.
     *   sp_render_sections('parts/sections');
     *
     * the part receives the Section instance as $args['section'].
     *
     * @param string       $directory template directory, relative to the theme
     * @param integer|null $post_id
     *
     * @return void
     */
    function sp_render_sections($directory = 'sections', $post_id = null)
    {
        foreach (Render::sections($post_id) as $section) {
            get_template_part(
                untrailingslashit($directory) . '/' . $section->type(),
                null,
                ['section' => $section]
            );
        }
    }
}

if (!function_exists('sp_schema_id')) {
    /**
     * the schema governing a post, or 0 when its template is unbound.
     *
     * @param integer|null $post_id
     *
     * @return integer
     */
    function sp_schema_id($post_id = null)
    {
        return Binding::schemaId($post_id ? $post_id : get_the_ID());
    }
}
