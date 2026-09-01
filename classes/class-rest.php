<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST surface for the admin application.
 *
 * deliberately a separate namespace from Delivery: the public contract at
 * schemapress/v1 is consumed by a front-end build and must stay stable, while
 * these routes exist only to serve this plugin's own UI and are free to change
 * with it. every route here requires an authenticated editor.
 */
class Rest
{
    const NAMESPACE = 'schemapress/admin/v1';

    /**
     * hooks route registration.
     */
    public function __construct()
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * registers every route.
     *
     * @return void
     */
    public function registerRoutes()
    {
        register_rest_route(self::NAMESPACE, '/schemas', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'index'],
                'permission_callback' => [$this, 'canEdit'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'create'],
                'permission_callback' => [$this, 'canEdit'],
                'args' => [
                    'title' => ['type' => 'string', 'required' => true],
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/schemas/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'show'],
                'permission_callback' => [$this, 'canEditSchema'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'update'],
                'permission_callback' => [$this, 'canEditSchema'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'destroy'],
                'permission_callback' => [$this, 'canEditSchema'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/pages', [
            'methods' => 'GET',
            'callback' => [$this, 'pages'],
            'permission_callback' => [$this, 'canEdit'],
            'args' => [
                'search' => ['type' => 'string', 'default' => ''],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/pages/(?P<post>\d+)/schema', [
            'methods' => 'POST',
            'callback' => [$this, 'assignSchema'],
            'permission_callback' => [$this, 'canEditContent'],
            'args' => [
                'schema' => ['type' => 'integer', 'default' => 0],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/settings', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'settings'],
                'permission_callback' => [$this, 'canEdit'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'saveSettings'],
                'permission_callback' => [$this, 'canManage'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/preview/(?P<post>\d+)', [
            'methods' => 'POST',
            'callback' => [$this, 'preview'],
            'permission_callback' => [$this, 'canEditContent'],
        ]);

        register_rest_route(self::NAMESPACE, '/pages/(?P<post>\d+)/workflow', [
            'methods' => 'GET',
            'callback' => [$this, 'workflow'],
            'permission_callback' => [$this, 'canEditContent'],
        ]);

        register_rest_route(self::NAMESPACE, '/content/(?P<post>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'content'],
                'permission_callback' => [$this, 'canEditContent'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'saveContent'],
                'permission_callback' => [$this, 'canEditContent'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/templates', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'templates'],
                'permission_callback' => [$this, 'canEdit'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'saveTemplates'],
                'permission_callback' => [$this, 'canManage'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/pages/(?P<post>\d+)/template', [
            'methods' => 'POST',
            'callback' => [$this, 'assignTemplate'],
            'permission_callback' => [$this, 'canEditContent'],
            'args' => [
                'template' => ['type' => 'string', 'default' => ''],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/posts', [
            'methods' => 'GET',
            'callback' => [$this, 'posts'],
            'permission_callback' => [$this, 'canEdit'],
            'args' => [
                'search' => ['type' => 'string', 'default' => ''],
                'post_type' => ['type' => 'string', 'default' => 'page'],
            ],
        ]);
    }

    /**
     * whether the current user may manage schemas at all.
     *
     * @return boolean
     */
    public function canEdit()
    {
        return current_user_can('edit_pages');
    }

    /**
     * whether the current user may change site-wide configuration such as the
     * template registry. a higher bar than editing an individual schema.
     *
     * @return boolean
     */
    public function canManage()
    {
        return current_user_can('manage_options');
    }

    /**
     * whether the current user may edit a specific schema.
     *
     * @param \WP_REST_Request $request
     *
     * @return boolean
     */
    public function canEditSchema($request)
    {
        $id = absint($request['id']);

        return get_post_type($id) === Schema::POST_TYPE && current_user_can('edit_post', $id);
    }

    /**
     * whether the current user may edit a specific post's content.
     *
     * @param \WP_REST_Request $request
     *
     * @return boolean
     */
    public function canEditContent($request)
    {
        $post_id = absint($request['post']);

        return get_post_status($post_id) !== false && current_user_can('edit_post', $post_id);
    }

    /**
     * every page, whether bound or not.
     *
     * unbound pages are included deliberately — assigning a template is done
     * from this list, so hiding them would make the binding unreachable.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function pages($request)
    {
        $posts = get_posts([
            'post_type' => 'page',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            's' => sanitize_text_field((string) $request['search']),
            'suppress_filters' => false,
        ]);

        $results = array_map(function ($post) {
            $template = Binding::template($post->ID);
            $schema_id = Binding::schemaId($post->ID);

            return [
                'id' => (int) $post->ID,
                'title' => $post->post_title !== '' ? $post->post_title : __('(no title)', 'schemapress'),
                'slug' => $post->post_name,
                'status' => $post->post_status,
                'template' => $template,
                'edit_link' => get_edit_post_link($post->ID, 'raw'),
                'view_link' => get_permalink($post->ID),
                'schema' => $schema_id
                    ? ['id' => $schema_id, 'title' => get_the_title($schema_id)]
                    : null,
                'source' => Binding::source($post->ID),
                'section_count' => count(Content::get($post->ID)['sections']),
            ];
        }, $posts);

        return rest_ensure_response($results);
    }

    /**
     * assigns a schema directly to a page, bypassing the template. passing 0
     * clears it so the template's schema applies again.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function assignSchema($request)
    {
        $post_id = absint($request['post']);
        $body = $request->get_json_params();
        $schema_id = isset($body['schema']) ? $body['schema'] : $request['schema'];

        Binding::setSchema($post_id, $schema_id);

        return rest_ensure_response([
            'id' => $post_id,
            'schema' => Binding::schemaId($post_id),
            'source' => Binding::source($post_id),
        ]);
    }

    /**
     * the site's design tokens.
     *
     * @return \WP_REST_Response
     */
    public function settings()
    {
        return rest_ensure_response(['tokens' => Settings::forClient()]);
    }

    /**
     * stores design tokens, returning what was actually kept - a malformed
     * length or colour falls back to its default rather than being stored.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function saveSettings($request)
    {
        $body = $request->get_json_params();

        Settings::save(isset($body['tokens']) ? $body['tokens'] : []);

        return $this->settings();
    }

    /**
     * renders unsaved content through the reference renderer.
     *
     * the definition is taken from the request rather than storage, so a
     * component invented seconds ago previews correctly. nothing is persisted:
     * this is a pure function of the payload, which is what makes it safe to
     * call on every edit.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function preview($request)
    {
        $body = $request->get_json_params();

        $definition = SchemaModel::normalize(
            isset($body['definition']) ? $body['definition'] : []
        );

        $sections = Resolver::resolve(
            isset($body['content']) ? $body['content'] : [],
            $definition
        );

        // per-section markup as well as the whole page: the build canvas shows
        // each section inside its own card, and slicing the page apart on the
        // client would mean parsing HTML to find the boundaries
        // the builder renders with sample content and clickable field markers;
        // the preview tab renders exactly what a visitor would get
        $options = ['editing' => !empty($body['editing'])];

        $parts = [];

        foreach ($sections as $section) {
            $parts[$section['id']] = Renderer::section($section, $options);
        }

        $stylesheet = SCHEMAPRESS_PATH . 'assets/css/render.css';

        return rest_ensure_response([
            'html' => Renderer::sections($sections, $options),
            'sections' => $parts,
            // the canvas renders each section in a shadow root, which needs the
            // CSS as text rather than as a link - and the tokens with it, since
            // a shadow root does not inherit custom properties declared on :root
            'css' => Settings::cssVariables()
                . (file_exists($stylesheet) ? file_get_contents($stylesheet) : ''),
            'stylesheet' => esc_url_raw(SCHEMAPRESS_URL . 'assets/css/render.css'),
        ]);
    }

    /**
     * everything the guided editor needs for one page, in a single call.
     *
     * deliberately tolerant of an incomplete setup: a page with no template,
     * or a template with no schema, is a normal state part-way through the
     * workflow rather than an error. the `step` it reports is what the client
     * opens on.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function workflow($request)
    {
        $post_id = absint($request['post']);
        $post = get_post($post_id);

        if (!$post) {
            return new \WP_Error(
                'schemapress_not_found',
                __('That page no longer exists.', 'schemapress'),
                ['status' => 404]
            );
        }

        $template = Binding::template($post_id);
        $schema_id = Binding::schemaId($post_id);

        if ($template !== '' && !Templates::exists($template)) {
            // the template was deleted out from under the page
            $template = '';
        }

        return rest_ensure_response([
            'post' => [
                'id' => $post_id,
                'title' => get_the_title($post_id),
                'slug' => $post->post_name,
                'status' => $post->post_status,
                'edit_link' => get_edit_post_link($post_id, 'raw'),
                'view_link' => get_permalink($post_id),
            ],
            'template' => $template !== '' ? Templates::get($template) : null,
            'schema' => $schema_id
                ? [
                    'id' => $schema_id,
                    'title' => get_the_title($schema_id),
                    'definition' => SchemaRepository::definition($schema_id),
                    'templates' => SchemaRepository::templates($schema_id),
                ]
                : null,
            'content' => Content::get($post_id),
            'source' => Binding::source($post_id),
            // a schema resolved by either route means the setup is done, so a
            // directly bound page skips the template step rather than being
            // sent back to a decision it has already opted out of
            'step' => $schema_id ? 'content' : ($template === '' ? 'template' : 'schema'),
        ]);
    }

    /**
     * a post's schema and its current section content.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function content($request)
    {
        $post_id = absint($request['post']);
        $schema_id = Binding::schemaId($post_id);

        if (!$schema_id) {
            return new \WP_Error(
                'schemapress_unbound',
                __('This page is not using a template bound to a schema.', 'schemapress'),
                ['status' => 400]
            );
        }

        return rest_ensure_response([
            'post' => [
                'id' => $post_id,
                'title' => get_the_title($post_id),
                'edit_link' => get_edit_post_link($post_id, 'raw'),
                'view_link' => get_permalink($post_id),
            ],
            'schema' => [
                'id' => $schema_id,
                'title' => get_the_title($schema_id),
            ],
            'definition' => SchemaRepository::definition($schema_id),
            'content' => Content::get($post_id),
        ]);
    }

    /**
     * persists a post's section content, returning the sanitized result so the
     * client adopts whatever the schema actually permitted.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function saveContent($request)
    {
        $post_id = absint($request['post']);
        $body = $request->get_json_params();

        $saved = Content::save($post_id, isset($body['content']) ? $body['content'] : []);

        return rest_ensure_response(['content' => $saved]);
    }

    /**
     * lists every schema with its bindings.
     *
     * @return \WP_REST_Response
     */
    public function index()
    {
        $schemas = array_map(function ($post) {
            $definition = SchemaRepository::definition($post->ID);

            return [
                'id' => (int) $post->ID,
                'title' => $post->post_title,
                'status' => $post->post_status,
                'templates' => SchemaRepository::templates($post->ID),
                'section_count' => count($definition['sections']),
                'modified' => $post->post_modified_gmt,
            ];
        }, SchemaRepository::all());

        return rest_ensure_response($schemas);
    }

    /**
     * creates an empty schema.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function create($request)
    {
        $id = wp_insert_post([
            'post_type' => Schema::POST_TYPE,
            'post_title' => sanitize_text_field($request['title']),
            'post_status' => 'publish',
        ], true);

        if (is_wp_error($id)) {
            return $id;
        }

        SchemaRepository::saveDefinition($id, ['sections' => []]);

        return rest_ensure_response($this->payload($id));
    }

    /**
     * returns one schema with its definition and bindings.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function show($request)
    {
        return rest_ensure_response($this->payload(absint($request['id'])));
    }

    /**
     * persists a schema's title, definition and template bindings. the stored
     * (normalized) result is returned so the client can adopt any key
     * rewriting the server performed.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function update($request)
    {
        $id = absint($request['id']);
        $body = $request->get_json_params();

        if (isset($body['title'])) {
            wp_update_post([
                'ID' => $id,
                'post_title' => sanitize_text_field($body['title']),
            ]);
        }

        if (array_key_exists('definition', $body)) {
            SchemaRepository::saveDefinition($id, $body['definition']);
        }

        if (array_key_exists('templates', $body)) {
            SchemaRepository::saveTemplates($id, $body['templates']);
        }

        return rest_ensure_response($this->payload($id));
    }

    /**
     * trashes a schema. bound pages keep their stored content — rebinding the
     * template restores it — so deletion is non-destructive to page data.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function destroy($request)
    {
        $id = absint($request['id']);
        wp_trash_post($id);

        return rest_ensure_response(['deleted' => true, 'id' => $id]);
    }

    /**
     * every registered template, annotated with the schema that claims it and
     * how many pages use it — enough for the app to warn before a rebinding
     * changes what those pages deliver.
     *
     * @return \WP_REST_Response
     */
    public function templates()
    {
        $bound = [];

        foreach (SchemaRepository::all() as $schema) {
            foreach (SchemaRepository::templates($schema->ID) as $template) {
                $bound[$template] = [
                    'id' => (int) $schema->ID,
                    'title' => $schema->post_title,
                ];
            }
        }

        $templates = [];

        foreach (Templates::all() as $slug => $template) {
            $templates[] = array_merge($template, [
                'schema' => isset($bound[$slug]) ? $bound[$slug] : null,
                'page_count' => count(Binding::postsForTemplates([$slug], ['fields' => 'ids'])),
            ]);
        }

        return rest_ensure_response($templates);
    }

    /**
     * replaces the plugin-defined template list.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function saveTemplates($request)
    {
        $body = $request->get_json_params();

        Templates::save(isset($body['templates']) ? $body['templates'] : []);

        return $this->templates();
    }

    /**
     * assigns a template to a page, which is what binds it to a schema.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function assignTemplate($request)
    {
        $post_id = absint($request['post']);
        $body = $request->get_json_params();
        $slug = isset($body['template']) ? $body['template'] : (string) $request['template'];

        $stored = Binding::setTemplate($post_id, $slug);

        return rest_ensure_response([
            'id' => $post_id,
            'template' => $stored,
            'schema' => Binding::schemaId($post_id),
        ]);
    }

    /**
     * searches posts for the relationship field's picker.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function posts($request)
    {
        $post_types = array_filter(array_map('sanitize_key', explode(',', (string) $request['post_type'])));

        $results = get_posts([
            'post_type' => $post_types ?: ['page'],
            'post_status' => 'publish',
            'numberposts' => 20,
            's' => sanitize_text_field((string) $request['search']),
            'suppress_filters' => false,
        ]);

        return rest_ensure_response(array_map(function ($post) {
            return [
                'id' => (int) $post->ID,
                'title' => $post->post_title !== '' ? $post->post_title : __('(no title)', 'schemapress'),
                'type' => $post->post_type,
            ];
        }, $results));
    }

    /**
     * the wire representation of a schema.
     *
     * @param integer $id
     *
     * @return array
     */
    private function payload($id)
    {
        return [
            'id' => (int) $id,
            'title' => get_the_title($id),
            'definition' => SchemaRepository::definition($id),
            'templates' => SchemaRepository::templates($id),
        ];
    }
}
