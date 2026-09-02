<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * the admin transport.
 *
 * every screen in the builder talks to these routes and nothing else. they are
 * deliberately not the public delivery API — they are namespaced under /admin/
 * and gated on editing capabilities, because they return draft content and
 * accept schema changes.
 */
class Rest
{
    const NAMESPACE = 'schemapress/admin/v1';

    /**
     * hooks route registration.
     */
    public function __construct()
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    /**
     * registers every route.
     *
     * @return void
     */
    public function routes()
    {
        register_rest_route(self::NAMESPACE, '/types', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'types'],
                'permission_callback' => [$this, 'canEdit'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'createType'],
                'permission_callback' => [$this, 'canEdit'],
                'args' => [
                    'title' => ['type' => 'string', 'required' => true],
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/types/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'type'],
                'permission_callback' => [$this, 'canEditType'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'updateType'],
                'permission_callback' => [$this, 'canEditType'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'deleteType'],
                'permission_callback' => [$this, 'canEditType'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/types/(?P<id>\d+)/entries', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'entries'],
                'permission_callback' => [$this, 'canEditType'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'createEntry'],
                'permission_callback' => [$this, 'canEditType'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/types/(?P<id>\d+)/entries/(?P<entry>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'entry'],
                'permission_callback' => [$this, 'canEditType'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'saveEntry'],
                'permission_callback' => [$this, 'canEditType'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'deleteEntry'],
                'permission_callback' => [$this, 'canEditType'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/posts', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'posts'],
                'permission_callback' => [$this, 'canEdit'],
            ],
        ]);
    }

    // --- content types -------------------------------------------------------

    /**
     * every content type, for the sidebar.
     *
     * @return \WP_REST_Response
     */
    public function types()
    {
        ContentType::flush();

        return rest_ensure_response(['types' => ContentType::all()]);
    }

    /**
     * one content type, with its definition.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function type($request)
    {
        return $this->typePayload(absint($request['id']));
    }

    /**
     * creates a content type.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function createType($request)
    {
        $title = sanitize_text_field($request['title']);

        if (trim($title) === '') {
            return new \WP_Error('schemapress_no_title', __('A name is required.', 'schemapress'), [
                'status' => 400,
            ]);
        }

        $id = wp_insert_post([
            'post_type' => Schema::POST_TYPE,
            'post_title' => $title,
            'post_status' => 'publish',
        ], true);

        if (is_wp_error($id)) {
            return $id;
        }

        SchemaRepository::saveDefinition($id, ['fields' => []]);

        // the key names the post type entries are stored against, so it is
        // claimed now and never follows a later rename
        ContentType::key($id);
        ContentType::register($id);

        return $this->typePayload($id);
    }

    /**
     * renames a type or replaces its fields.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function updateType($request)
    {
        $id = absint($request['id']);
        $body = $request->get_json_params();
        $body = is_array($body) ? $body : [];

        if (isset($body['title']) && trim((string) $body['title']) !== '') {
            wp_update_post([
                'ID' => $id,
                'post_title' => sanitize_text_field($body['title']),
            ]);
        }

        if (isset($body['definition'])) {
            SchemaRepository::saveDefinition($id, $body['definition']);
        }

        ContentType::flush();

        return $this->typePayload($id);
    }

    /**
     * deletes a type. its entries go with it, which is why this asks.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function deleteType($request)
    {
        $id = absint($request['id']);
        $type = ContentType::get($id);

        if ($type && $type['postType']) {
            foreach (get_posts([
                'post_type' => $type['postType'],
                'post_status' => 'any',
                'numberposts' => -1,
                'fields' => 'ids',
                'suppress_filters' => false,
            ]) as $entry_id) {
                wp_delete_post($entry_id, true);
            }
        }

        wp_delete_post($id, true);
        ContentType::flush();

        return rest_ensure_response(['deleted' => true, 'types' => ContentType::all()]);
    }

    // --- entries -------------------------------------------------------------

    /**
     * a page of a collection's entries.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function entries($request)
    {
        $id = absint($request['id']);

        return rest_ensure_response(Entries::all($id, [
            'page' => $request->get_param('page'),
            'perPage' => $request->get_param('perPage'),
            'search' => $request->get_param('search'),
            'orderby' => $request->get_param('orderby'),
            'order' => $request->get_param('order'),
        ]) + ['definition' => SchemaRepository::definition($id)]);
    }

    /**
     * one entry.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function entry($request)
    {
        $entry = Entries::get($request['id'], $request['entry']);

        if (!$entry) {
            return new \WP_Error('schemapress_no_entry', __('Entry not found.', 'schemapress'), [
                'status' => 404,
            ]);
        }

        return rest_ensure_response([
            'entry' => $entry,
            'definition' => SchemaRepository::definition(absint($request['id'])),
        ]);
    }

    /**
     * creates an entry.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function createEntry($request)
    {
        return $this->storeEntry($request['id'], null, $request->get_json_params());
    }

    /**
     * updates an entry.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function saveEntry($request)
    {
        return $this->storeEntry($request['id'], $request['entry'], $request->get_json_params());
    }

    /**
     * trashes an entry.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function deleteEntry($request)
    {
        return rest_ensure_response([
            'deleted' => Entries::delete($request['id'], $request['entry']),
        ]);
    }

    /**
     * shared write path for create and update.
     *
     * @param integer      $type_id
     * @param integer|null $entry_id
     * @param mixed        $body
     *
     * @return \WP_REST_Response|\WP_Error
     */
    private function storeEntry($type_id, $entry_id, $body)
    {
        $entry = Entries::save($type_id, $entry_id, is_array($body) ? $body : []);

        if (!$entry) {
            return new \WP_Error(
                'schemapress_entry_failed',
                __('Could not save that entry.', 'schemapress'),
                ['status' => 400]
            );
        }

        return rest_ensure_response(['entry' => $entry]);
    }

    // --- support -------------------------------------------------------------

    /**
     * published posts, for the post relationship field's picker.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function posts($request)
    {
        $types = $request->get_param('types');
        $types = $types ? array_map('sanitize_key', explode(',', $types)) : ['page'];

        $posts = get_posts([
            'post_type' => $types,
            'post_status' => 'publish',
            'numberposts' => 20,
            's' => sanitize_text_field((string) $request->get_param('search')),
            'suppress_filters' => false,
        ]);

        return rest_ensure_response(array_map(function ($post) {
            return [
                'id' => (int) $post->ID,
                'title' => get_the_title($post),
                'type' => $post->post_type,
            ];
        }, $posts));
    }

    /**
     * the response shape every type-returning route uses.
     *
     * @param integer $id
     *
     * @return \WP_REST_Response|\WP_Error
     */
    private function typePayload($id)
    {
        ContentType::flush();

        $type = ContentType::get($id);

        if (!$type) {
            return new \WP_Error('schemapress_no_type', __('Content type not found.', 'schemapress'), [
                'status' => 404,
            ]);
        }

        return rest_ensure_response([
            'type' => $type,
            'definition' => SchemaRepository::definition($id),
            'types' => ContentType::all(),
        ]);
    }

    // --- permissions ---------------------------------------------------------

    /**
     * whether the current user may use the builder at all.
     *
     * @return boolean
     */
    public function canEdit()
    {
        return current_user_can(Admin::CAPABILITY);
    }

    /**
     * whether the current user may edit a specific content type.
     *
     * @param \WP_REST_Request $request
     *
     * @return boolean
     */
    public function canEditType($request)
    {
        $id = absint($request['id']);

        return get_post_type($id) === Schema::POST_TYPE && current_user_can('edit_post', $id);
    }
}
