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

        // a headless setup may serve the public API read-only. these routes are
        // not that API: they are wp-admin's own transport, gated on editing
        // capabilities, and the builder cannot save without them
        add_filter('wpdev_rest_readonly_exempt', [$this, 'exemptFromReadonly']);
    }

    /**
     * claims this namespace as one a read-only public API does not cover.
     *
     * @param string[] $exempt route prefixes
     *
     * @return string[]
     */
    public function exemptFromReadonly($exempt)
    {
        $exempt[] = '/' . self::NAMESPACE;

        return $exempt;
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
                    'description' => ['type' => 'string'],
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

        register_rest_route(self::NAMESPACE, '/types/(?P<id>\d+)/entries/(?P<entry>[A-Za-z0-9-]+)', [
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

        // moving the published copy is its own act, not a flag on a save
        register_rest_route(
            self::NAMESPACE,
            '/types/(?P<id>\d+)/entries/(?P<entry>[A-Za-z0-9-]+)/(?P<action>publish|unpublish|discard)',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'transition'],
                    'permission_callback' => [$this, 'canEditType'],
                ],
            ]
        );

        $this->componentRoutes();
    }

    /**
     * registers the component routes.
     *
     * @return void
     */
    private function componentRoutes()
    {
        register_rest_route(self::NAMESPACE, '/components', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'components'],
                'permission_callback' => [$this, 'canEdit'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'createComponent'],
                'permission_callback' => [$this, 'canEdit'],
                'args' => [
                    'title' => ['type' => 'string', 'required' => true],
                    'description' => ['type' => 'string'],
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/components/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'component'],
                'permission_callback' => [$this, 'canEditComponent'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'updateComponent'],
                'permission_callback' => [$this, 'canEditComponent'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'deleteComponent'],
                'permission_callback' => [$this, 'canEditComponent'],
            ],
        ]);
    }

    // --- components ----------------------------------------------------------

    /**
     * every component, for the sidebar and the field picker.
     *
     * @return \WP_REST_Response
     */
    public function components()
    {
        return rest_ensure_response(['components' => Component::all()]);
    }

    /**
     * one component, with its fields.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function component($request)
    {
        $component = Component::get($request['id']);

        return $component
            ? rest_ensure_response(['component' => $component])
            : new \WP_Error('schemapress_no_component', __('Component not found.', 'schemapress'), [
                'status' => 404,
            ]);
    }

    /**
     * creates a component.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function createComponent($request)
    {
        $title = sanitize_text_field($request['title']);

        if (trim($title) === '') {
            return new \WP_Error('schemapress_no_title', __('A name is required.', 'schemapress'), [
                'status' => 400,
            ]);
        }

        $id = wp_insert_post([
            'post_type' => Component::POST_TYPE,
            'post_title' => $title,
            'post_excerpt' => sanitize_textarea_field((string) $request['description']),
            'post_status' => 'publish',
        ], true);

        if (is_wp_error($id)) {
            return $id;
        }

        SchemaRepository::saveDefinition($id, ['fields' => []]);

        return rest_ensure_response([
            'component' => Component::get($id),
            'components' => Component::all(),
        ]);
    }

    /**
     * renames a component or replaces its fields.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function updateComponent($request)
    {
        $id = absint($request['id']);
        $body = $request->get_json_params();
        $body = is_array($body) ? $body : [];

        $post = ['ID' => $id];

        if (isset($body['title']) && trim((string) $body['title']) !== '') {
            $post['post_title'] = sanitize_text_field($body['title']);
        }

        if (array_key_exists('description', $body)) {
            $post['post_excerpt'] = sanitize_textarea_field((string) $body['description']);
        }

        if (count($post) > 1) {
            wp_update_post($post);
        }

        if (isset($body['fields'])) {
            SchemaRepository::saveDefinition($id, ['fields' => $body['fields']]);
        }

        return rest_ensure_response([
            'component' => Component::get($id),
            'components' => Component::all(),
        ]);
    }

    /**
     * deletes a component.
     *
     * Collections that imported it keep their copy of the fields, because
     * importing copies rather than references — deleting the original cannot
     * take content down with it.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function deleteComponent($request)
    {
        wp_delete_post(absint($request['id']), true);

        return rest_ensure_response(['deleted' => true, 'components' => Component::all()]);
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
            // what the collection is for, kept where WordPress keeps a summary
            'post_excerpt' => sanitize_textarea_field((string) $request['description']),
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

        $post = ['ID' => $id];

        if (isset($body['title']) && trim((string) $body['title']) !== '') {
            $post['post_title'] = sanitize_text_field($body['title']);
        }

        // present-but-empty clears it, which is the only way to take a
        // description back off once it is written
        if (array_key_exists('description', $body)) {
            $post['post_excerpt'] = sanitize_textarea_field((string) $body['description']);
        }

        if (count($post) > 1) {
            wp_update_post($post);
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
            // the builder works on drafts, so it reads that view
            'view' => Entries::DRAFT,
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
        $entry = Entries::get($request['id'], $request['entry'], 0, Entries::DRAFT);

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
     * publishes, unpublishes or discards the draft.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function transition($request)
    {
        $actions = [
            'publish' => [Entries::class, 'publish'],
            'unpublish' => [Entries::class, 'unpublish'],
            'discard' => [Entries::class, 'discard'],
        ];

        $action = (string) $request['action'];
        $entry = call_user_func($actions[$action], $request['id'], $request['entry']);

        if (!$entry) {
            return new \WP_Error(
                'schemapress_transition_failed',
                __('That could not be done.', 'schemapress'),
                ['status' => 400]
            );
        }

        return rest_ensure_response(['entry' => $entry]);
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

    /**
     * whether the current user may edit a specific component.
     *
     * @param \WP_REST_Request $request
     *
     * @return boolean
     */
    public function canEditComponent($request)
    {
        $id = absint($request['id']);

        return get_post_type($id) === Component::POST_TYPE && current_user_can('edit_post', $id);
    }
}
