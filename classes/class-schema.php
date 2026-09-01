<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * registers the schema post type.
 *
 * a schema is stored as a post so it inherits ids, capabilities, revisions and
 * trash behaviour for free. it has no admin UI of its own — the React app on
 * the SchemaPress menu page is the only way schemas are edited, and it works
 * exclusively through the REST layer.
 */
class Schema
{
    const POST_TYPE = 'sp_schema';
    const META_DEFINITION = '_schemapress_definition';
    const META_TEMPLATES = '_schemapress_templates';

    /**
     * hooks post type registration.
     */
    public function __construct()
    {
        add_action('init', [$this, 'registerPostType']);
    }

    /**
     * registers the schema post type as private storage.
     *
     * @return void
     */
    public function registerPostType()
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('Schemas', 'schemapress'),
                'singular_name' => __('Schema', 'schemapress'),
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
}
