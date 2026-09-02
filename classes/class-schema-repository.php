<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * reads and writes content type definitions.
 *
 * the only place that knows definitions are JSON in post meta. everything else
 * deals in arrays, so the storage medium can change without touching callers.
 */
class SchemaRepository
{
    /**
     * @var array<int, array>
     */
    private static $cache = [];

    /**
     * loads a type's normalized definition. an unknown or malformed type
     * yields an empty definition rather than an error, so read paths can stay
     * branch-free.
     *
     * @param integer $type_id
     *
     * @return array
     */
    public static function definition($type_id)
    {
        $type_id = absint($type_id);

        if (isset(self::$cache[$type_id])) {
            return self::$cache[$type_id];
        }

        $raw = get_post_meta($type_id, Schema::META_DEFINITION, true);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : $raw;

        self::$cache[$type_id] = SchemaModel::normalize($decoded);

        return self::$cache[$type_id];
    }

    /**
     * normalizes and persists a definition, returning what was actually stored
     * so the caller can reconcile its own state.
     *
     * @param integer $type_id
     * @param mixed   $definition
     *
     * @return array
     */
    public static function saveDefinition($type_id, $definition)
    {
        $type_id = absint($type_id);
        $normalized = SchemaModel::normalize($definition);

        update_post_meta(
            $type_id,
            Schema::META_DEFINITION,
            wp_slash(wp_json_encode($normalized))
        );

        self::$cache[$type_id] = $normalized;

        /**
         * fires after a definition is stored.
         *
         * @param integer $type_id
         * @param array   $normalized
         */
        do_action('schemapress/definition_saved', $type_id, $normalized);

        return $normalized;
    }

    /**
     * every content type post.
     *
     * @return \WP_Post[]
     */
    public static function all()
    {
        return get_posts([
            'post_type' => Schema::POST_TYPE,
            'post_status' => ['publish', 'draft'],
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'suppress_filters' => false,
        ]);
    }

    /**
     * clears the in-request definition cache.
     *
     * @param integer|null $type_id
     *
     * @return void
     */
    public static function flush($type_id = null)
    {
        if ($type_id === null) {
            self::$cache = [];
            return;
        }

        unset(self::$cache[absint($type_id)]);
    }
}
