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
    public const NAMESPACE = 'schemapress/admin/v1';

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
                // creating a collection is defining one, which is the builder's
                // job rather than the content manager's
                'permission_callback' => [$this, 'canManageSchema'],
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
                // reading a definition is how the entry form is drawn, so
                // anyone who may fill one in may read it
                'permission_callback' => [$this, 'canEditType'],
            ],
            [
                'methods' => 'POST',
                // this route takes the definition, so it can rename a field and
                // orphan every value stored under it
                'callback' => [$this, 'updateType'],
                'permission_callback' => [$this, 'canManageType'],
            ],
            [
                'methods' => 'DELETE',
                // and this one takes every entry in the collection with it
                'callback' => [$this, 'deleteType'],
                'permission_callback' => [$this, 'canManageType'],
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
            '/types/(?P<id>\d+)/entries/(?P<entry>[A-Za-z0-9-]+)/(?P<action>publish|unpublish|discard|duplicate)',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'transition'],
                    'permission_callback' => [$this, 'canEditType'],
                ],
            ]
        );

        $this->trashRoutes();
        $this->bulkRoutes();
        $this->portabilityRoutes();

        register_rest_route(self::NAMESPACE, '/settings', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'updateSettings'],
                // this decides what the site serves to the internet
                'permission_callback' => [$this, 'canManageSchema'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/jobs', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'jobs'],
                'permission_callback' => [$this, 'canEdit'],
            ],
        ]);

        $this->componentRoutes();
    }

    /**
     * the trash: listing it, coming back from it, and emptying it.
     *
     * @return void
     */
    private function trashRoutes()
    {
        register_rest_route(self::NAMESPACE, '/types/(?P<id>\d+)/trash', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'trash'],
                'permission_callback' => [$this, 'canEditType'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'emptyTrash'],
                // emptying a trash erases content permanently and in bulk, which
                // is the blast radius the schema capability exists to gate
                'permission_callback' => [$this, 'canManageType'],
            ],
        ]);

        register_rest_route(
            self::NAMESPACE,
            '/types/(?P<id>\d+)/trash/(?P<entry>[A-Za-z0-9-]+)',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'restoreEntry'],
                    'permission_callback' => [$this, 'canEditType'],
                ],
                [
                    'methods' => 'DELETE',
                    'callback' => [$this, 'purgeEntry'],
                    'permission_callback' => [$this, 'canManageType'],
                ],
            ]
        );
    }

    /**
     * one action over several entries at once.
     *
     * @return void
     */
    private function bulkRoutes()
    {
        // NOT /entries/bulk. WordPress matches routes in registration order and
        // returns the first whose pattern fits, so `bulk` would be read as an
        // entry identifier by the route above — `[A-Za-z0-9-]+` matches it
        // perfectly — and every bulk request would have tried to save an entry
        // whose uuid was the word bulk. its own segment cannot be mistaken for
        // one
        register_rest_route(self::NAMESPACE, '/types/(?P<id>\d+)/bulk', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'bulk'],
                'permission_callback' => [$this, 'canEditType'],
                'args' => [
                    'action' => ['type' => 'string', 'required' => true],
                    'entries' => ['type' => 'array', 'required' => true],
                ],
            ],
        ]);
    }

    /**
     * schemas in and out as files.
     *
     * @return void
     */
    private function portabilityRoutes()
    {
        register_rest_route(self::NAMESPACE, '/export', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'export'],
                // an export of everything is a copy of the whole content model,
                // and with entries it is a copy of the content
                'permission_callback' => [$this, 'canManageSchema'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/import', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'import'],
                'permission_callback' => [$this, 'canManageSchema'],
            ],
        ]);
    }

    // --- settings ------------------------------------------------------------

    /**
     * stores the site's master switch and every collection's own pair.
     *
     * one route, because the Settings screen is one form and saving half of it
     * is not a state anybody asked for — turning the API off while a collection
     * on the same screen was being opened should not be two requests that can
     * land in either order.
     *
     * the collection pairs are the same setting each collection's own dialog
     * writes, stored in the same place. nothing is shared between them; what
     * this adds is doing it in one sitting.
     *
     * the body is { restApi: bool, collections: { "<type id>": { list, single } } }.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function updateSettings($request)
    {
        $body = $request->get_json_params();
        $body = is_array($body) ? $body : [];

        $settings = Settings::save($body);

        $collections = isset($body['collections']) && is_array($body['collections'])
            ? $body['collections']
            : [];

        foreach ($collections as $id => $publicApi) {
            $id = absint($id);

            // checked per collection rather than once for the route, because
            // that is the bar the collection's own dialog sets and this writes
            // the same setting. a body naming something that is not a
            // collection, or one this user may not edit, is skipped rather than
            // trusted — the route says they may edit collections, not that
            // every id they sent is one of them
            if (get_post_type($id) !== Schema::POST_TYPE || !current_user_can('edit_post', $id)) {
                continue;
            }

            SchemaRepository::saveSettings($id, ['publicApi' => $publicApi]);
        }

        ContentType::flush();

        return rest_ensure_response([
            'settings' => $settings,
            'types' => ContentType::all(),
        ]);
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
                'permission_callback' => [$this, 'canManageSchema'],
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
                'permission_callback' => [$this, 'canManageComponent'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'deleteComponent'],
                'permission_callback' => [$this, 'canManageComponent'],
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
     * the same conflict check a collection's definition gets, for the same
     * reason: this route takes the WHOLE field list, so two people with the
     * component open in two tabs meant the second save deleted whatever the
     * first had added. a component is imported by copy, so the loss is not
     * confined to the component — every collection that imports it afterwards
     * gets the version that survived.
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

        $conflict = $this->staleDefinition($id, $body, 'fields');

        if ($conflict) {
            return $conflict;
        }

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

        $conflict = $this->staleDefinition($id, $body);

        if ($conflict) {
            return $conflict;
        }

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
     * refuses a schema save built on a definition that has since moved.
     *
     * the same rule entries get — see Entries::conflict — for the same reason,
     * and here the loss is larger: this route takes the WHOLE field list, so two
     * builders with the collection open in two tabs meant the second save
     * silently deleted whatever the first had added.
     *
     * the schema post's modified stamp is the version, because saving a
     * definition moves it — see SchemaRepository::saveDefinition, which is where
     * that had to be made true. as with an entry it is optional, so a script
     * that has always posted a definition still can.
     *
     * a component is the same shape and gets the same check — see
     * updateComponent, which is why the key naming the field-replacing half of
     * the body is a parameter rather than the literal `definition`.
     *
     * @param integer $id
     * @param array   $body
     * @param string  $key the body key whose presence means the fields are
     *                     being replaced
     *
     * @return \WP_Error|null
     */
    private function staleDefinition($id, array $body, $key = 'definition')
    {
        $expected = isset($body['expectedModified']) ? (string) $body['expectedModified'] : '';

        // only a save that would REPLACE the fields can lose somebody's work. a
        // rename arriving alongside a stale stamp is not worth refusing
        if ($expected === '' || !isset($body[$key])) {
            return null;
        }

        $post = get_post($id);
        $current = $post ? Dates::iso($post->post_modified_gmt) : '';

        if ($current === '' || $current === $expected) {
            return null;
        }

        return new \WP_Error(
            'schemapress_conflict',
            $key === 'fields'
                ? __(
                    'Somebody else changed this component while you were editing it. Reload before saving, or your changes will replace theirs.',
                    'schemapress'
                )
                : __(
                    'Somebody else changed this collection while you were editing it. Reload before saving, or your changes will replace theirs.',
                    'schemapress'
                ),
            ['status' => 409, 'expected' => $expected, 'current' => $current]
        );
    }

    /**
     * deletes a type. its entries go with it, which is why this asks.
     *
     * a collection small enough to erase inside the request is; anything larger
     * is queued, because reading every entry of a large collection to delete
     * them one at a time is precisely the request that does not finish. the
     * definition is deleted by the last chunk rather than here, so an
     * interrupted purge leaves the collection behind to be finished from rather
     * than a post type full of orphans nothing can name.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function deleteType($request)
    {
        $id = absint($request['id']);
        $type = ContentType::get($id);

        if ($type && !Batch::inline($id)) {
            Batch::queue('purge', ['type_id' => $id, 'delete_type' => true]);

            return rest_ensure_response([
                'deleted' => true,
                'queued' => true,
                'types' => array_values(array_filter(ContentType::all(), function ($one) use ($id) {
                    return $one['id'] !== $id;
                })),
            ]);
        }

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
        $orderby = (string) $request->get_param('orderby');
        $order = strtoupper((string) $request->get_param('order')) === 'ASC' ? 'ASC' : 'DESC';

        $args = [
            'page' => $request->get_param('page'),
            'perPage' => $request->get_param('perPage'),
            'search' => $request->get_param('search'),
            'orderby' => $orderby,
            'order' => $order,
            // the builder works on drafts, so it reads that view
            'view' => Entries::DRAFT,
        ];

        // title, date and modified live on the post row and WP_Query orders by
        // them directly. anything else names one of the collection's own
        // fields, which is a sort against the index — the same one the public
        // API sorts by, so a column here and `?sort=` out there agree
        if ($orderby !== '' && !in_array($orderby, ['title', 'date', 'modified'], true)) {
            $args['spec'] = ['sort' => [['field' => $orderby, 'direction' => $order]]];
        }

        return rest_ensure_response(
            Entries::all($id, $args) + ['definition' => SchemaRepository::definition($id)]
        );
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
     * publishes, unpublishes, discards the draft, or copies the entry.
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
            'duplicate' => [Entries::class, 'duplicate'],
        ];

        $action = (string) $request['action'];
        $entry = call_user_func($actions[$action], $request['id'], $request['entry']);

        // a duplicate can fail on a unique field, and that sentence names which
        // one — the same reason storeEntry passes a validation error through
        if (is_wp_error($entry)) {
            return $entry;
        }

        if (!$entry) {
            return new \WP_Error(
                'schemapress_transition_failed',
                __('That could not be done.', 'schemapress'),
                ['status' => 400]
            );
        }

        return rest_ensure_response(['entry' => $entry]);
    }

    // --- the trash -----------------------------------------------------------

    /**
     * a page of a collection's trashed entries.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function trash($request)
    {
        return rest_ensure_response(Entries::trashed(absint($request['id']), [
            'page' => $request->get_param('page'),
            'perPage' => $request->get_param('perPage'),
        ]));
    }

    /**
     * brings an entry back from the trash.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function restoreEntry($request)
    {
        $entry = Entries::restore(absint($request['id']), $request['entry']);

        if (!$entry) {
            return new \WP_Error(
                'schemapress_no_entry',
                __('That entry is not in the trash.', 'schemapress'),
                ['status' => 404]
            );
        }

        return rest_ensure_response(['entry' => $entry]);
    }

    /**
     * erases one trashed entry.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function purgeEntry($request)
    {
        return rest_ensure_response([
            'deleted' => Entries::purge(absint($request['id']), $request['entry']),
        ]);
    }

    /**
     * erases everything in a collection's trash.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function emptyTrash($request)
    {
        return rest_ensure_response(['erased' => Entries::emptyTrash(absint($request['id']))]);
    }

    // --- several at once -----------------------------------------------------

    /**
     * one action applied to a list of entries.
     *
     * the result is per entry rather than one verdict for the request. a bulk
     * publish of forty entries where one has a required field empty should
     * publish the thirty-nine and say which one it could not — reporting the
     * whole thing as a failure would be both untrue and unhelpful.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function bulk($request)
    {
        $type_id = absint($request['id']);
        $action = (string) $request['action'];
        $entries = (array) $request['entries'];

        $handlers = [
            'publish' => [Entries::class, 'publish'],
            'unpublish' => [Entries::class, 'unpublish'],
            'discard' => [Entries::class, 'discard'],
            'duplicate' => [Entries::class, 'duplicate'],
            'delete' => [Entries::class, 'delete'],
            'restore' => [Entries::class, 'restore'],
        ];

        if (!isset($handlers[$action])) {
            return new \WP_Error(
                'schemapress_unknown_action',
                __('That is not something that can be done to several entries at once.', 'schemapress'),
                ['status' => 400]
            );
        }

        // a bulk request is one page of a listing at most, and a page is capped
        // at a hundred. a body naming more than that is not the admin asking
        if (count($entries) > 100) {
            return new \WP_Error(
                'schemapress_too_many',
                __('That is more entries than one request can act on. Do it a page at a time.', 'schemapress'),
                ['status' => 400]
            );
        }

        $succeeded = [];
        $failed = [];

        foreach ($entries as $entry) {
            $result = call_user_func($handlers[$action], $type_id, (string) $entry);

            if (!$result || is_wp_error($result)) {
                $failed[] = [
                    'id' => (string) $entry,
                    'message' => is_wp_error($result)
                        ? $result->get_error_message()
                        : __('That could not be done.', 'schemapress'),
                ];

                continue;
            }

            $succeeded[] = (string) $entry;
        }

        return rest_ensure_response([
            'action' => $action,
            'succeeded' => $succeeded,
            'failed' => $failed,
        ]);
    }

    // --- portability ---------------------------------------------------------

    /**
     * collections as a portable document.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function export($request)
    {
        $types = array_filter(array_map(
            'absint',
            array_filter(explode(',', (string) $request->get_param('types')), 'strlen')
        ));

        $payload = Portability::export($types, [
            'entries' => (bool) $request->get_param('entries'),
        ]);

        if (is_wp_error($payload)) {
            return $payload;
        }

        return rest_ensure_response([
            'filename' => Portability::filename($types),
            'export' => $payload,
        ]);
    }

    /**
     * restores collections from an exported document.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function import($request)
    {
        $body = $request->get_json_params();
        $body = is_array($body) ? $body : [];

        $report = Portability::import($body['export'] ?? null, [
            'mode' => ($body['mode'] ?? '') === 'replace' ? 'replace' : 'merge',
            'entries' => !empty($body['entries']),
        ]);

        if (is_wp_error($report)) {
            return $report;
        }

        return rest_ensure_response([
            'report' => $report,
            'types' => ContentType::all(),
            'components' => Component::all(),
        ]);
    }

    /**
     * what long-running work is queued, so a screen can show its progress.
     *
     * @return \WP_REST_Response
     */
    public function jobs()
    {
        return rest_ensure_response(['jobs' => Batch::status()]);
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

        // a validation failure already says which field and why, and that
        // sentence is the whole point of it — replacing it with this route's
        // generic refusal would throw away the only useful part
        if (is_wp_error($entry)) {
            return $entry;
        }

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
        return Capabilities::canEdit();
    }

    /**
     * whether the current user may change the shape of content.
     *
     * a higher bar than editing entries, and deliberately — see
     * class-capabilities.php. everything gated on this either restructures
     * stored content or decides what the site publishes.
     *
     * @return boolean
     */
    public function canManageSchema()
    {
        return Capabilities::canManage();
    }

    /**
     * whether the current user may change a specific collection's shape.
     *
     * @param \WP_REST_Request $request
     *
     * @return boolean
     */
    public function canManageType($request)
    {
        return get_post_type(absint($request['id'])) === Schema::POST_TYPE
            && $this->canManageSchema();
    }

    /**
     * whether the current user may change a specific component's shape.
     *
     * @param \WP_REST_Request $request
     *
     * @return boolean
     */
    public function canManageComponent($request)
    {
        return get_post_type(absint($request['id'])) === Component::POST_TYPE
            && $this->canManageSchema();
    }

    /**
     * whether the current user may edit a specific content type's entries.
     *
     * this used to be `edit_post` on the SCHEMA post, which mapped to the page
     * capabilities and so meant "may this person edit content at all" — the same
     * answer for every collection on the site. a collection may now name the
     * roles that own it, and this is where that is enforced.
     *
     * @param \WP_REST_Request $request
     *
     * @return boolean
     */
    public function canEditType($request)
    {
        $id = absint($request['id']);

        return get_post_type($id) === Schema::POST_TYPE && Capabilities::canEditCollection($id);
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

        return get_post_type($id) === Component::POST_TYPE && Capabilities::canEdit();
    }
}
