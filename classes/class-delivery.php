<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * the public delivery API.
 *
 * this is the plugin's product surface: the endpoints a decoupled front-end
 * calls to render pages. published content is readable without authentication;
 * anything unpublished requires a user who could edit it, which is what makes
 * draft previews possible without exposing them.
 */
class Delivery
{
    const NAMESPACE = 'schemapress/v1';

    /**
     * hooks route registration.
     */
    public function __construct()
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * registers the delivery routes.
     *
     * @return void
     */
    public function registerRoutes()
    {
        register_rest_route(self::NAMESPACE, '/page', [
            'methods' => 'GET',
            'callback' => [$this, 'page'],
            'permission_callback' => '__return_true',
            'args' => [
                'id' => ['type' => 'integer'],
                'slug' => ['type' => 'string'],
                'path' => ['type' => 'string'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/pages', [
            'methods' => 'GET',
            'callback' => [$this, 'pages'],
            'permission_callback' => '__return_true',
            'args' => [
                'template' => ['type' => 'string', 'default' => ''],
                'per_page' => ['type' => 'integer', 'default' => 20],
                'page' => ['type' => 'integer', 'default' => 1],
                'sections' => ['type' => 'boolean', 'default' => false],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/contract', [
            'methods' => 'GET',
            'callback' => [$this, 'contract'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * delivers one page, located by id, slug or full path.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function page($request)
    {
        $post = $this->locate($request);

        if (!$post) {
            return new \WP_Error(
                'schemapress_not_found',
                __('No page matched that identifier.', 'schemapress'),
                ['status' => 404]
            );
        }

        if (!$this->isReadable($post)) {
            return new \WP_Error(
                'schemapress_forbidden',
                __('That page is not published.', 'schemapress'),
                ['status' => rest_authorization_required_code()]
            );
        }

        return rest_ensure_response(Resolver::page($post->ID));
    }

    /**
     * lists delivered pages. section content is omitted unless explicitly
     * requested, so an index call stays cheap.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function pages($request)
    {
        $template = sanitize_key((string) $request['template']);

        $per_page = max(1, min(100, (int) $request['per_page']));
        $page = max(1, (int) $request['page']);

        $query = [
            'post_status' => 'publish',
            'numberposts' => $per_page,
            'offset' => ($page - 1) * $per_page,
        ];

        // filtering by template is template-only by definition; without a
        // filter, pages bound directly to a schema belong in the index too
        $posts = $template !== ''
            ? Binding::postsForTemplates([$template], $query)
            : Binding::boundPosts($query);

        $withSections = (bool) $request['sections'];

        $results = array_map(function ($post) use ($withSections) {
            $payload = Resolver::page($post->ID);

            if (!$withSections) {
                unset($payload['sections']);
            }

            return $payload;
        }, $posts);

        return rest_ensure_response($results);
    }

    /**
     * the full structural contract: every template, the schema bound to it and
     * that schema's field definitions.
     *
     * a front-end build can consume this to generate types, so the components
     * and the admin cannot drift apart silently.
     *
     * @return \WP_REST_Response
     */
    public function contract()
    {
        $contract = [];

        foreach (Templates::all() as $slug => $template) {
            $schema_id = Binding::schemaForTemplate($slug);

            $contract[] = [
                'template' => $slug,
                'label' => $template['label'],
                'description' => $template['description'],
                'schema' => $schema_id
                    ? [
                        'id' => $schema_id,
                        'title' => get_the_title($schema_id),
                        'sections' => SchemaRepository::definition($schema_id)['sections'],
                    ]
                    : null,
            ];
        }

        return rest_ensure_response([
            'templates' => $contract,
            // the design tokens the layout vocabulary resolves to, so a
            // front-end can honour the same definitions rather than
            // reimplementing them from a screenshot
            'tokens' => Settings::all(),
        ]);
    }

    /**
     * finds the requested post from id, slug or path.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_Post|null
     */
    private function locate($request)
    {
        if (!empty($request['id'])) {
            return get_post(absint($request['id']));
        }

        $path = (string) ($request['path'] ?? '');

        if ($path !== '') {
            return get_page_by_path(trim($path, '/'), OBJECT, ['page', 'post']);
        }

        $slug = sanitize_title((string) ($request['slug'] ?? ''));

        if ($slug === '') {
            return null;
        }

        $matches = get_posts([
            'name' => $slug,
            'post_type' => 'any',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'numberposts' => 1,
            'suppress_filters' => false,
        ]);

        return isset($matches[0]) ? $matches[0] : null;
    }

    /**
     * whether the requester may read a post. published content is public;
     * anything else needs edit rights, which is what enables draft previews.
     *
     * @param \WP_Post $post
     *
     * @return boolean
     */
    private function isReadable($post)
    {
        return $post->post_status === 'publish' || current_user_can('edit_post', $post->ID);
    }
}
