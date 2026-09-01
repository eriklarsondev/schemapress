<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * reads and writes a post's section content.
 *
 * content is one JSON blob in a single meta row:
 *
 *   [
 *     'version'  => 1,
 *     'sections' => [
 *       [ 'id' => 's_a1b2', 'type' => 'hero', 'values' => [ 'heading' => 'Hi' ] ],
 *     ],
 *   ]
 *
 * the blob is the source of truth. it is never trusted on read — Render
 * reconciles it against the current schema so a definition change cannot
 * produce undefined access in a template.
 */
class Content
{
    const META_KEY = '_schemapress_content';

    /**
     * how deep containers may nest.
     *
     * a bound is not optional: containers can hold containers, and without one
     * a malformed payload could recurse until it exhausts memory. three levels
     * covers a section holding a row holding a block, which is past the point
     * where a page stops being legible anyway.
     */
    const MAX_DEPTH = 3;

    /**
     * @var array<int, array>
     */
    private static $cache = [];

    /**
     * the raw stored content for a post, shape-checked but not reconciled
     * against the schema.
     *
     * @param integer $post_id
     *
     * @return array
     */
    public static function get($post_id)
    {
        $post_id = absint($post_id);

        if (isset(self::$cache[$post_id])) {
            return self::$cache[$post_id];
        }

        $raw = get_post_meta($post_id, self::META_KEY, true);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : $raw;

        self::$cache[$post_id] = self::shape($decoded);

        return self::$cache[$post_id];
    }

    /**
     * sanitizes incoming content against the post's schema and stores it.
     *
     * @param integer $post_id
     * @param mixed   $content
     *
     * @return array
     */
    public static function save($post_id, $content)
    {
        $post_id = absint($post_id);
        $definition = Binding::definition($post_id);

        $clean = ContentSanitizer::sanitize(self::shape($content), $definition);

        update_post_meta($post_id, self::META_KEY, wp_slash(wp_json_encode($clean)));
        self::$cache[$post_id] = $clean;

        return $clean;
    }

    /**
     * removes all schema content from a post.
     *
     * @param integer $post_id
     *
     * @return void
     */
    public static function delete($post_id)
    {
        delete_post_meta(absint($post_id), self::META_KEY);
        unset(self::$cache[absint($post_id)]);
    }

    /**
     * coerces a decoded payload into the expected envelope. entries missing an
     * id are given one so React keys and row identity survive a round trip.
     *
     * @param mixed $content
     *
     * @return array
     */
    public static function shape($content)
    {
        $content = is_array($content) ? $content : [];
        $sections = isset($content['sections']) && is_array($content['sections'])
            ? $content['sections']
            : [];

        return [
            'version' => SchemaModel::VERSION,
            'sections' => self::shapeSections($sections),
        ];
    }

    /**
     * shapes a list of placed sections, recursing into nested children.
     *
     * @param mixed   $sections
     * @param integer $depth
     *
     * @return array
     */
    public static function shapeSections($sections, $depth = 0)
    {
        if (!is_array($sections) || $depth > self::MAX_DEPTH) {
            return [];
        }

        $shaped = [];

        foreach ($sections as $section) {
            if (!is_array($section) || empty($section['type'])) {
                continue;
            }

            $shaped[] = [
                'id' => !empty($section['id']) ? sanitize_key($section['id']) : self::id('s'),
                'type' => sanitize_key($section['type']),
                'layout' => isset($section['layout']) && is_array($section['layout'])
                    ? $section['layout']
                    : [],
                'values' => isset($section['values']) && is_array($section['values'])
                    ? $section['values']
                    : [],
                'children' => isset($section['children'])
                    ? self::shapeSections($section['children'], $depth + 1)
                    : [],
            ];
        }

        return $shaped;
    }

    /**
     * generates a short, collision-resistant node id.
     *
     * @param string $prefix
     *
     * @return string
     */
    public static function id($prefix = 'n')
    {
        return $prefix . '_' . substr(md5(uniqid((string) wp_rand(), true)), 0, 10);
    }

    /**
     * clears the in-request content cache.
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
