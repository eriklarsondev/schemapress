<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * resolves which schema governs a given post.
 *
 * a page is assigned a template slug; a schema declares the slugs it applies
 * to. that indirection is what lets many pages share one structure, and it
 * gives the front-end a stable layout key to switch components on.
 *
 * the assignment is stored under the plugin's own meta key so it never
 * collides with the theme template WordPress writes for classic themes,
 * though that key is honoured as a fallback.
 */
class Binding
{
    const META_TEMPLATE = '_schemapress_template';
    const META_SCHEMA = '_schemapress_schema';

    /**
     * @var array<int, int>
     */
    private static $resolved = [];

    /**
     * clears the resolution cache when a post's template assignment changes.
     */
    public function __construct()
    {
        foreach (['updated_post_meta', 'added_post_meta', 'deleted_post_meta'] as $hook) {
            add_action($hook, [$this, 'onTemplateChanged'], 10, 3);
        }
    }

    /**
     * drops a post's cached resolution when its template meta changes.
     *
     * @param integer $meta_id
     * @param integer $post_id
     * @param string  $meta_key
     *
     * @return void
     */
    public function onTemplateChanged($meta_id, $post_id, $meta_key)
    {
        $keys = [self::META_TEMPLATE, self::META_SCHEMA, '_wp_page_template'];

        if (in_array($meta_key, $keys, true)) {
            unset(self::$resolved[absint($post_id)]);
        }
    }

    /**
     * every template a schema may be bound to.
     *
     * @return array<string, string> slug => label
     */
    public static function templateNames()
    {
        return Templates::names();
    }

    /**
     * the template slug assigned to a post, or an empty string.
     *
     * @param integer $post_id
     *
     * @return string
     */
    public static function template($post_id)
    {
        $slug = get_post_meta(absint($post_id), self::META_TEMPLATE, true);

        if (!$slug) {
            // classic themes keep the assignment in WordPress's own key
            $slug = get_page_template_slug($post_id);
        }

        return $slug === 'default' ? '' : (string) $slug;
    }

    /**
     * assigns a template to a post. an unknown or empty slug clears it.
     *
     * @param integer $post_id
     * @param string  $slug
     *
     * @return string the stored slug
     */
    public static function setTemplate($post_id, $slug)
    {
        $post_id = absint($post_id);
        $slug = sanitize_key($slug);

        if ($slug === '' || !Templates::exists($slug)) {
            delete_post_meta($post_id, self::META_TEMPLATE);
            unset(self::$resolved[$post_id]);

            return '';
        }

        update_post_meta($post_id, self::META_TEMPLATE, $slug);
        unset(self::$resolved[$post_id]);

        return $slug;
    }

    /**
     * the schema id governing a post, or 0 when nothing is bound.
     *
     * a schema assigned directly to the post wins over the one its template
     * would supply. that ordering makes the direct assignment usable as an
     * escape hatch — a one-off page that needs its own structure, or an
     * exception to an otherwise shared template — without having to invent a
     * template for a single page.
     *
     * @param integer $post_id
     *
     * @return integer
     */
    public static function schemaId($post_id)
    {
        $post_id = absint($post_id);

        if (isset(self::$resolved[$post_id])) {
            return self::$resolved[$post_id];
        }

        self::$resolved[$post_id] = self::resolve($post_id);

        return self::$resolved[$post_id];
    }

    /**
     * resolves a post's schema, direct assignment first.
     *
     * @param integer $post_id
     *
     * @return integer
     */
    private static function resolve($post_id)
    {
        $direct = absint(get_post_meta($post_id, self::META_SCHEMA, true));

        if ($direct && get_post_type($direct) === Schema::POST_TYPE) {
            return $direct;
        }

        $template = self::template($post_id);

        return $template === '' ? 0 : self::schemaForTemplate($template);
    }

    /**
     * how a post arrived at its schema: directly, through its template, or
     * not at all.
     *
     * @param integer $post_id
     *
     * @return string one of 'page', 'template', 'none'
     */
    public static function source($post_id)
    {
        $post_id = absint($post_id);
        $direct = absint(get_post_meta($post_id, self::META_SCHEMA, true));

        if ($direct && get_post_type($direct) === Schema::POST_TYPE) {
            return 'page';
        }

        return self::schemaId($post_id) ? 'template' : 'none';
    }

    /**
     * assigns a schema directly to a post. passing 0 clears the assignment and
     * lets the template's schema apply again.
     *
     * @param integer $post_id
     * @param integer $schema_id
     *
     * @return integer the stored schema id
     */
    public static function setSchema($post_id, $schema_id)
    {
        $post_id = absint($post_id);
        $schema_id = absint($schema_id);

        if (!$schema_id || get_post_type($schema_id) !== Schema::POST_TYPE) {
            delete_post_meta($post_id, self::META_SCHEMA);
            unset(self::$resolved[$post_id]);

            return 0;
        }

        update_post_meta($post_id, self::META_SCHEMA, $schema_id);
        unset(self::$resolved[$post_id]);

        return $schema_id;
    }

    /**
     * the schema bound to a template slug, or 0.
     *
     * @param string $template
     *
     * @return integer
     */
    public static function schemaForTemplate($template)
    {
        foreach (SchemaRepository::all() as $schema) {
            if (in_array($template, SchemaRepository::templates($schema->ID), true)) {
                return (int) $schema->ID;
            }
        }

        return 0;
    }

    /**
     * the resolved definition for a post. empty when nothing is bound.
     *
     * @param integer $post_id
     *
     * @return array
     */
    public static function definition($post_id)
    {
        $schema_id = self::schemaId($post_id);

        return $schema_id ? SchemaRepository::definition($schema_id) : SchemaModel::normalize([]);
    }

    /**
     * whether a post is governed by a schema.
     *
     * @param integer $post_id
     *
     * @return boolean
     */
    public static function isBound($post_id)
    {
        return self::schemaId($post_id) > 0;
    }

    /**
     * every post that resolves to a schema, by either route.
     *
     * a page bound directly has no template, so a template-only query would
     * miss it — and it would silently vanish from the delivery index while
     * still being individually fetchable.
     *
     * @param array $args extra WP_Query arguments
     *
     * @return \WP_Post[]
     */
    public static function boundPosts(array $args = [])
    {
        $templates = array_keys(Templates::all());
        $meta = ['relation' => 'OR'];

        if (!empty($templates)) {
            $meta[] = ['key' => self::META_TEMPLATE, 'value' => $templates, 'compare' => 'IN'];
            $meta[] = ['key' => '_wp_page_template', 'value' => $templates, 'compare' => 'IN'];
        }

        $meta[] = ['key' => self::META_SCHEMA, 'compare' => 'EXISTS'];

        return get_posts(array_merge([
            'post_type' => 'any',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'meta_query' => $meta,
            'suppress_filters' => false,
        ], $args));
    }

    /**
     * every post assigned to one of the given template slugs.
     *
     * @param string[] $templates
     * @param array    $args extra WP_Query arguments
     *
     * @return \WP_Post[]
     */
    public static function postsForTemplates(array $templates, array $args = [])
    {
        if (empty($templates)) {
            return [];
        }

        return get_posts(array_merge([
            'post_type' => 'any',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => self::META_TEMPLATE,
                    'value' => $templates,
                    'compare' => 'IN',
                ],
                [
                    'key' => '_wp_page_template',
                    'value' => $templates,
                    'compare' => 'IN',
                ],
            ],
            'suppress_filters' => false,
        ], $args));
    }
}
