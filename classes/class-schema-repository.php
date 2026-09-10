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

        // the index is keyed by field key, so renaming or removing a field
        // leaves rows behind that answer filters about a field the collection
        // no longer has. compared before the write, rebuilt after it
        $before = self::definition($type_id);
        $changed = ($before['fields'] ?? []) !== $normalized['fields'];
        $moved = $before !== $normalized;

        // a collection taking up a slug field has said what its entries should
        // be called, and every entry it already has was addressed under the old
        // answer. the setting used to change and nothing act on it
        $reslug = ($before['settings']['slugField'] ?? '') !== ($normalized['settings']['slugField'] ?? '');

        update_post_meta(
            $type_id,
            Schema::META_DEFINITION,
            wp_slash(wp_json_encode($normalized))
        );

        // THE POST'S MODIFIED STAMP IS THE DEFINITION'S VERSION, and until this
        // line nothing ever moved it. a definition is post META, and writing
        // meta does not touch the post row — so the conflict check that reads
        // that stamp (Rest::staleDefinition) compared a version that could only
        // change when somebody renamed the collection. it was inert: two people
        // with the same Schema tab open still overwrote each other silently,
        // which is the exact loss it was written to stop.
        //
        // only when the definition actually MOVED. a save that stores what was
        // already stored is not a version somebody else has to reload past
        if ($moved) {
            wp_update_post(['ID' => $type_id]);
        }

        self::$cache[$type_id] = $normalized;

        /**
         * fires after a definition is stored.
         *
         * @param integer $type_id
         * @param array   $normalized
         */
        do_action('schemapress/definition_saved', $type_id, $normalized);

        if ($changed) {
            Index::rebuild($type_id);
        }

        // after the index, and after the cache is filled: this reads the
        // definition back to work out what each entry should now be called
        if ($reslug) {
            Entries::reslugAll($type_id);
        }

        return $normalized;
    }

    /**
     * changes some of a type's settings, leaving its fields alone.
     *
     * saveDefinition normalizes whatever it is handed, so a caller that only
     * wanted to flip one switch and sent only that would have every field in
     * the collection normalized out of existence. this reads the stored
     * definition first and merges into it, which is the difference between
     * editing a setting and replacing a schema.
     *
     * @param integer $type_id
     * @param array   $settings the keys to change
     *
     * @return array the stored definition
     */
    public static function saveSettings($type_id, array $settings)
    {
        $definition = self::definition($type_id);
        $definition['settings'] = array_merge($definition['settings'], $settings);

        return self::saveDefinition($type_id, $definition);
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
