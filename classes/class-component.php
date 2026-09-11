<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A named group of fields — an address, a call to action — defined once and
 * imported into as many collections as you like.
 *
 * Stored exactly like a content type, because it is the same thing minus the
 * entries. Nothing is ever saved *as* a component, only inside something else.
 *
 * Importing copies the fields rather than pointing at them: a shared definition
 * would mean editing a component silently reshapes content that already exists
 * elsewhere, and there is no migration story for that. A copy can drift, which
 * is the lesser problem.
 */
class Component
{
    public const POST_TYPE = 'sp_component';

    /**
     * Hooks post type registration.
     */
    public function __construct()
    {
        add_action('init', [$this, 'registerPostType']);
    }

    /**
     * Registers the component post type as private storage.
     *
     * @return void
     */
    public function registerPostType()
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('Components', 'schemapress'),
                'singular_name' => __('Component', 'schemapress'),
            ],
            'public' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'show_in_rest' => false,
            'hierarchical' => false,
            // title only: a definition is post meta, which WordPress does not
            // revision — see ContentType::registerPostType
            'supports' => ['title'],
            'capability_type' => 'page',
            'map_meta_cap' => true,
            'rewrite' => false,
            'query_var' => false,
        ]);
    }

    /**
     * Every component, as the sidebar and the field picker list them.
     *
     * @return array
     */
    public static function all()
    {
        $components = [];

        foreach (self::posts() as $post) {
            $definition = SchemaRepository::definition($post->ID);

            $components[] = [
                'id' => (int) $post->ID,
                'label' => get_the_title($post),
                'description' => (string) $post->post_excerpt,
                'fields' => count($definition['fields']),
            ];
        }

        return $components;
    }

    /**
     * One component, with the fields it holds.
     *
     * @param integer $id
     *
     * @return array|null
     */
    public static function get($id)
    {
        $post = get_post(absint($id));

        if (!$post || $post->post_type !== self::POST_TYPE) {
            return null;
        }

        $definition = SchemaRepository::definition($post->ID);

        return [
            'id' => (int) $post->ID,
            'label' => get_the_title($post),
            'description' => (string) $post->post_excerpt,
            'fields' => $definition['fields'],
            // the version a save is made against: a component save replaces the
            // whole field list, so the editor sends this back and one that moved
            // underneath it is refused — see Rest::staleDefinition
            'modified' => Dates::iso($post->post_modified_gmt),
        ];
    }

    /**
     * Every component post.
     *
     * @return \WP_Post[]
     */
    private static function posts()
    {
        return get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => ['publish', 'draft'],
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'suppress_filters' => false,
        ]);
    }
}
