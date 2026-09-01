<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * reads and writes schema definitions.
 *
 * the only place that knows definitions are JSON in post meta. everything
 * else deals in arrays, so the storage medium can change without touching
 * callers.
 */
class SchemaRepository
{
    /**
     * @var array<int, array>
     */
    private static $cache = [];

    /**
     * loads a schema's normalized definition. an unknown or malformed schema
     * yields an empty definition rather than an error, so render paths can
     * stay branch-free.
     *
     * @param integer $schema_id
     *
     * @return array
     */
    public static function definition($schema_id)
    {
        $schema_id = absint($schema_id);

        if (isset(self::$cache[$schema_id])) {
            return self::$cache[$schema_id];
        }

        $raw = get_post_meta($schema_id, Schema::META_DEFINITION, true);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : $raw;

        self::$cache[$schema_id] = SchemaModel::normalize($decoded);

        return self::$cache[$schema_id];
    }

    /**
     * normalizes and persists a definition, returning what was actually
     * stored so the caller can reconcile its own state.
     *
     * @param integer $schema_id
     * @param mixed   $definition
     *
     * @return array
     */
    public static function saveDefinition($schema_id, $definition)
    {
        $schema_id = absint($schema_id);
        $normalized = SchemaModel::normalize($definition);

        update_post_meta(
            $schema_id,
            Schema::META_DEFINITION,
            wp_slash(wp_json_encode($normalized))
        );

        self::$cache[$schema_id] = $normalized;

        /**
         * fires after a schema definition is stored.
         *
         * @param integer $schema_id
         * @param array   $normalized
         */
        do_action('schemapress/schema_saved', $schema_id, $normalized);

        return $normalized;
    }

    /**
     * the template files a schema is bound to.
     *
     * @param integer $schema_id
     *
     * @return string[]
     */
    public static function templates($schema_id)
    {
        $stored = get_post_meta(absint($schema_id), Schema::META_TEMPLATES, true);

        return is_array($stored) ? array_values(array_filter($stored)) : [];
    }

    /**
     * binds a schema to a set of template files. a template may only be bound
     * to one schema, so this releases the template from any other schema first
     * — otherwise resolution at render time would be ambiguous.
     *
     * @param integer  $schema_id
     * @param string[] $templates
     *
     * @return string[]
     */
    public static function saveTemplates($schema_id, $templates)
    {
        $schema_id = absint($schema_id);
        $available = Binding::templateNames();

        $clean = [];
        foreach ((array) $templates as $template) {
            $template = (string) $template;

            if (isset($available[$template]) && !in_array($template, $clean, true)) {
                $clean[] = $template;
            }
        }

        foreach (self::all() as $other) {
            if ($other->ID === $schema_id) {
                continue;
            }

            $existing = self::templates($other->ID);
            $remaining = array_values(array_diff($existing, $clean));

            if (count($remaining) !== count($existing)) {
                update_post_meta($other->ID, Schema::META_TEMPLATES, $remaining);
            }
        }

        update_post_meta($schema_id, Schema::META_TEMPLATES, $clean);

        return $clean;
    }

    /**
     * every published schema post.
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
     * @param integer|null $schema_id
     *
     * @return void
     */
    public static function flush($schema_id = null)
    {
        if ($schema_id === null) {
            self::$cache = [];
            return;
        }

        unset(self::$cache[absint($schema_id)]);
    }
}
