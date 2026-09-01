<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * builds the front-end view of a post's sections.
 *
 * read-side reconciliation happens here: stored content is matched against the
 * *current* schema, so sections whose type was removed disappear and fields
 * added since the last save resolve to their type default. a template can
 * therefore assume every declared field exists, even for content saved against
 * an older definition.
 */
class Render
{
    /**
     * @var array<int, Section[]>
     */
    private static $cache = [];

    /**
     * the ordered sections placed on a post.
     *
     * @param integer|null $post_id defaults to the post in the loop
     *
     * @return Section[]
     */
    public static function sections($post_id = null)
    {
        $post_id = $post_id ? absint($post_id) : get_the_ID();

        if (!$post_id) {
            return [];
        }

        if (isset(self::$cache[$post_id])) {
            return self::$cache[$post_id];
        }

        $definition = Binding::definition($post_id);
        $content = Content::get($post_id);

        $sections = [];
        $index = 0;

        foreach ($content['sections'] as $placed) {
            $type = SchemaModel::section($definition, $placed['type']);

            if (!$type) {
                continue;
            }

            $sections[] = new Section(
                $placed['id'],
                $type,
                // reconcile against the current definition, not the saved shape
                ContentSanitizer::values($placed['values'], $type['fields']),
                Layout::sanitize($placed['layout'] ?? [], $type['layout']),
                $index
            );

            $index++;
        }

        /**
         * filters the sections rendered for a post.
         *
         * @param Section[] $sections
         * @param integer   $post_id
         */
        self::$cache[$post_id] = apply_filters('schemapress/sections', $sections, $post_id);

        return self::$cache[$post_id];
    }

    /**
     * the first section of a given type, or null.
     *
     * @param string       $type
     * @param integer|null $post_id
     *
     * @return Section|null
     */
    public static function section($type, $post_id = null)
    {
        foreach (self::sections($post_id) as $section) {
            if ($section->type() === $type) {
                return $section;
            }
        }

        return null;
    }

    /**
     * every section of a given type.
     *
     * @param string       $type
     * @param integer|null $post_id
     *
     * @return Section[]
     */
    public static function ofType($type, $post_id = null)
    {
        return array_values(array_filter(self::sections($post_id), function (Section $section) use ($type) {
            return $section->type() === $type;
        }));
    }

    /**
     * clears the rendered-section cache.
     *
     * @param integer|null $post_id
     *
     * @return void
     */
    public static function flush($post_id = null)
    {
        if ($post_id === null) {
            self::$cache = [];
            return;
        }

        unset(self::$cache[absint($post_id)]);
    }
}
