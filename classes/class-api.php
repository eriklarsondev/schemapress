<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * the content API.
 *
 * modelled on Strapi, down to the URLs and the parameter names, so a client
 * written against one reads against the other:
 *
 *   GET /wp-json/schemapress/api/team-members
 *   GET /wp-json/schemapress/api/team-members/9f2c…
 *
 *   ?filters[role][$eq]=Engineer
 *   ?filters[$or][0][role][$eq]=Design&filters[$or][1][lead][$eq]=1
 *   ?sort=name:asc&pagination[page]=2&pagination[pageSize]=50
 *
 * a list answers with `{ data: [...], meta: { pagination } }` and a single
 * entry with `{ data: {...}, meta: {} }`, which is the envelope a Strapi client
 * already unwraps.
 *
 * this is a separate class from Rest on purpose. Rest is the builder's own
 * transport — every route on it requires the capability to edit schemas, and it
 * speaks the editor's shape, values and drafts included. this speaks to the
 * outside world, is readable without an account, and only ever says what has
 * been published. keeping them apart means a change to the editor's transport
 * cannot widen what the public can read.
 *
 * nothing is exposed until a collection's Settings say so. see Public API in
 * SchemaModel::normalizeSettings — the default is off, which is the only safe
 * default for a switch that makes content world-readable.
 */
class Api
{
    /**
     * `schemapress/api`, so a route reads /wp-json/schemapress/api/team-members
     * — as close to Strapi's /api/team-members as a WordPress namespace gets.
     */
    const NAMESPACE = 'schemapress/api';

    /**
     * hooks the routes.
     */
    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register']);
    }

    /**
     * registers the two routes every collection answers on.
     *
     * one pair of routes for all collections rather than a pair per collection:
     * the collection is a path segment, so creating one does not mean
     * re-registering anything, and a collection whose API is switched off is
     * refused by the handler rather than by the route not existing.
     *
     * @return void
     */
    public function register()
    {
        register_rest_route(self::NAMESPACE, '/(?P<collection>[A-Za-z0-9_-]+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'list'],
                'permission_callback' => '__return_true',
            ],
        ]);

        register_rest_route(
            self::NAMESPACE,
            '/(?P<collection>[A-Za-z0-9_-]+)/(?P<id>[A-Za-z0-9-]+)',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'single'],
                    'permission_callback' => '__return_true',
                ],
            ]
        );
    }

    /**
     * a page of entries.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function list($request)
    {
        $type = $this->open($request['collection']);

        if (is_wp_error($type)) {
            return $type;
        }

        $spec = Query::parse($request->get_query_params());

        $result = Entries::all($type['id'], [
            'view' => Entries::PUBLISHED,
            'spec' => $spec,
        ]);

        $named = Entries::titleField($type['id']);

        $data = array_map(function ($entry) use ($named) {
            return $this->shape($entry, $named);
        }, $result['entries']);

        return rest_ensure_response([
            'data' => $data,
            'meta' => Query::meta($spec['pagination'], $result['total']),
        ]);
    }

    /**
     * one entry, by the uuid the rest of the API identifies it with.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function single($request)
    {
        $type = $this->open($request['collection']);

        if (is_wp_error($type)) {
            return $type;
        }

        $entry = Entries::get($type['id'], $request['id']);

        // an unpublished entry is not "yours to see once you know the id" — to
        // this API it does not exist
        if (!$entry || empty($entry['isPublished'])) {
            return new \WP_Error(
                'schemapress_not_found',
                __('No published entry with that id.', 'schemapress'),
                ['status' => 404]
            );
        }

        return rest_ensure_response([
            'data' => $this->shape($entry, Entries::titleField($type['id'])),
            'meta' => new \stdClass(),
        ]);
    }

    // --- internals -----------------------------------------------------------

    /**
     * the collection behind a path segment, if it is open to the public.
     *
     * the two refusals say different things on purpose. a collection nobody
     * named is a 404; one that exists but has its API switched off is a 403
     * that says where the switch is — this is a plugin someone is building
     * against, and "not found" for a collection they are looking at in the
     * admin would send them hunting for a typo that is not there.
     *
     * @param string $name singular or plural machine name
     *
     * @return array|\WP_Error
     */
    private function open($name)
    {
        $id = $this->idFor($name);

        if (!$id) {
            return new \WP_Error(
                'schemapress_unknown_collection',
                __('No collection with that name.', 'schemapress'),
                ['status' => 404]
            );
        }

        $definition = SchemaRepository::definition($id);

        if (empty($definition['settings']['publicApi'])) {
            return new \WP_Error(
                'schemapress_api_disabled',
                __(
                    'This collection is not published to the API. Turn on Public API in its Settings.',
                    'schemapress'
                ),
                ['status' => 403]
            );
        }

        return ContentType::get($id);
    }

    /**
     * resolves a URL segment to a collection id.
     *
     * the segment is matched against both machine names, and hyphens are read
     * as underscores — `team-members` is what a URL wants and `team_members` is
     * what the key is.
     *
     * @param string $name
     *
     * @return integer 0 when nothing matches
     */
    private function idFor($name)
    {
        $name = sanitize_key(str_replace('-', '_', (string) $name));

        foreach (ContentType::collections() as $type) {
            if ($type['key'] === $name || $type['plural'] === $name) {
                return $type['id'];
            }
        }

        return 0;
    }

    /**
     * one entry as the API presents it.
     *
     * flat, the way Strapi v5 returns a document: the fields sit beside the
     * identifiers rather than under an `attributes` envelope. values are the
     * resolved ones — an image is its attachment, a relation is the entries it
     * points at — so a client never holds an id it has to spend another request
     * on.
     *
     * `values`, `state` and `ahead` are not here. they describe the editing of
     * an entry, which is the builder's business and not the public's.
     *
     * @param array $entry from Entries::shape()
     *
     * @return array
     */
    private function shape(array $entry, $named = '')
    {
        $data = is_array($entry['data'] ?? null) ? $entry['data'] : [];

        $shaped = ['id' => $entry['id']];

        // `title` and `slug` are only real when the collection nominated a
        // field to name its entries by. WordPress needs a post_title for every row, so one is invented
        // when none was declared — from whichever text field happens to come
        // first, which means reordering the schema would silently rename every
        // entry and change every slug. that is an artifact of storage, not
        // content, and it has no business in a content API.
        //
        // where one IS nominated it arrives with the rest of the fields below,
        // under its own key and holding exactly what the post title holds, so
        // there is nothing to add here but the slug it produced
        if ($named !== '') {
            $shaped['slug'] = $entry['slug'];
        }

        return array_merge($shaped, $data, [
            'updatedAt' => $entry['modified'],
            'publishedAt' => $entry['publishedAt'],
        ]);
    }
}
