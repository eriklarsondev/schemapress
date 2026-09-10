<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * the content API.
 *
 * modeled on Strapi, down to the URLs and the parameter names, so a client
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
    public const NAMESPACE = 'schemapress/api';

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
     * THE MASTER SWITCH IS THE EXCEPTION. off, nothing here is registered at
     * all, so `schemapress/api` is absent from the /wp-json/ index and every
     * address under it answers WordPress's own rest_no_route 404. that is a
     * stronger thing than a 403 from a handler: the namespace is gone rather
     * than answering to say it will not help, so nothing advertises that this
     * site has a content API or which collections it holds.
     *
     * the collection-level switches stay handler-side, and deliberately — those
     * are per collection, and a route list that changed shape as collections
     * were published would be a different API from one request to the next.
     *
     * @return void
     */
    public function register()
    {
        if (!Settings::restEnabled()) {
            return;
        }

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
        $type = $this->open($request['collection'], 'list');

        if (is_wp_error($type)) {
            return $type;
        }

        $spec = Query::parse($request->get_query_params());

        $result = Entries::all($type['id'], [
            'view' => Entries::PUBLISHED,
            'spec' => $spec,
        ]);

        $data = array_map(function ($entry) {
            return $this->shape($entry);
        }, $result['entries']);

        return $this->cached($request, [
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
        $type = $this->open($request['collection'], 'single');

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

        return $this->cached($request, [
            'data' => $this->shape($entry),
            'meta' => new \stdClass(),
        ]);
    }

    // --- internals -----------------------------------------------------------

    /**
     * a response a client can hold on to, and ask about cheaply next time.
     *
     * every response carried no cache headers at all, so a build step reading a
     * collection every minute got a full WordPress bootstrap, a WP_Query and the
     * whole resolver each time to be handed bytes it already had.
     *
     * so there is an ETAG, always. it is a hash of the body, which is exactly
     * the right thing to key on here: the body is a pure function of the
     * published content and the query, so it changes when and only when the
     * answer does. a client that sends back `If-None-Match` gets a 304 with no
     * body — the query still runs, but nothing is serialized or transferred, and
     * that is the bulk of a listing's cost.
     *
     * MAX-AGE IS ZERO UNLESS THE SITE SAYS OTHERWISE, and the two are different
     * promises. an ETag says "ask me and I will tell you cheaply"; a max-age
     * says "do not ask me for an hour", which means an editor publishing a
     * correction cannot get it onto a CDN-fronted site until that hour is up.
     * that is a real trade some sites want and not one to make on their behalf —
     * see Settings::cacheMaxAge.
     *
     * @param \WP_REST_Request $request
     * @param array            $payload
     *
     * @return \WP_REST_Response
     */
    private function cached($request, array $payload)
    {
        $etag = '"' . md5((string) wp_json_encode($payload)) . '"';
        $maxAge = Settings::cacheMaxAge();

        // a listing is public, so `public` is honest and lets a shared cache
        // hold it. `must-revalidate` is what stops a proxy serving a stale copy
        // past its age rather than asking — which is the failure mode that makes
        // people distrust caching and turn it off everywhere
        $control = $maxAge > 0
            ? sprintf('public, max-age=%d, must-revalidate', $maxAge)
            : 'public, max-age=0, must-revalidate';

        if (trim((string) $request->get_header('if_none_match')) === $etag) {
            $response = new \WP_REST_Response(null, 304);
            $response->header('ETag', $etag);
            $response->header('Cache-Control', $control);

            return $response;
        }

        $response = rest_ensure_response($payload);
        $response->header('ETag', $etag);
        $response->header('Cache-Control', $control);
        // the query string is part of the answer and the header is not, but a
        // cache keyed on the URL alone would hand one client another's page of
        // results if anything ever varied by header. saying so costs nothing
        $response->header('Vary', 'Accept-Encoding, Origin');

        return $response;
    }

    /**
     * the collection behind a path segment, if it is open for this shape of read.
     *
     * only the collection's own pair is consulted here. the site's master
     * switch is not: with it off these routes were never registered, so
     * nothing reaches this method to be refused.
     *
     * the refusals say different things on purpose. a collection nobody named
     * is a 404; one that exists but is closed is a 403 that says where the
     * switch is — this is a plugin someone is building against, and "not found"
     * for a collection they are looking at in the admin would send them hunting
     * for a typo that is not there.
     *
     * @param string $name  singular or plural machine name
     * @param string $route list or single
     *
     * @return array|\WP_Error
     */
    private function open($name, $route)
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

        if (empty($definition['settings']['publicApi'][$route])) {
            return new \WP_Error(
                'schemapress_api_disabled',
                $route === 'list'
                    ? __(
                        'This collection does not answer as a list. Turn on Read many in its Settings.',
                        'schemapress'
                    )
                    : __(
                        'This collection does not serve single entries. Turn on Read one in its Settings.',
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
    private function shape(array $entry)
    {
        $data = is_array($entry['data'] ?? null) ? $entry['data'] : [];

        // `title` is not here. WordPress needs a post_title for every row, so
        // one is invented when the collection declared no field to name its
        // entries by — from whichever text field happens to come first, which
        // means reordering the schema would silently rename every entry. that
        // is an artifact of storage rather than content.
        //
        // `slug` IS here, always. it is a stated setting rather than an
        // accident — built from the field the collection chose, or the uuid
        // when it chose none — and it is how a front end addresses an entry,
        // so an entry that sometimes had one would be a routing bug.
        $shaped = ['id' => $entry['id'], 'slug' => $entry['slug']];

        return array_merge($shaped, $data, [
            'updatedAt' => $entry['modified'],
            'publishedAt' => $entry['publishedAt'],
        ]);
    }
}
