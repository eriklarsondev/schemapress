<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * components: a named group of fields, defined once and reused.
 *
 * An address is a street, a city and a postcode. Once you have typed that into
 * three collections you have three chances to have typed it differently, and a
 * template that reads `address.city` from one and `city` from another. A
 * component is that shape, named, so it is described in one place.
 *
 * It is stored exactly like a content type — a post with a JSON definition in
 * meta — because it IS the same thing minus the entries. What it does not have
 * is a post type of its own for content: nothing is ever saved *as* a
 * component, only inside something else.
 *
 * Importing one copies its fields into the collection rather than pointing at
 * it. That is deliberate: a shared definition means editing a component
 * silently reshapes content that already exists somewhere else, and this plugin
 * has no migration story for that yet. A copy can drift, which is the lesser
 * problem — and it is honest about what it is at the moment you import it.
 */
class Component
{
    const POST_TYPE = 'sp_component';

    /**
     * hooks post type registration.
     */
    public function __construct()
    {
        add_action('init', [$this, 'registerPostType']);
    }

    /**
     * registers the component post type as private storage.
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
            'supports' => ['title', 'revisions'],
            'capability_type' => 'page',
            'map_meta_cap' => true,
            'rewrite' => false,
            'query_var' => false,
        ]);
    }

    /**
     * every component, as the sidebar and the field picker list them.
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
     * one component, with the fields it holds.
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
        ];
    }

    /**
     * every component post.
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
