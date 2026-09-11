<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The public content API, modeled on Strapi down to the URLs and the parameter
 * names, so a client written against one reads against the other:
 *
 *   GET /wp-json/schemapress/api/team-members
 *   GET /wp-json/schemapress/api/team-members/9f2c…
 *
 *   ?filters[role][$eq]=Engineer
 *   ?sort=name:asc&pagination[page]=2&pagination[pageSize]=50
 *
 * Separate from Rest on purpose. Rest is the builder's own transport and speaks
 * the editor's shape, drafts included; this is readable without an account and
 * only ever says what has been published. Keeping them apart means a change to
 * the editor's transport cannot widen what the public can read.
 *
 * Nothing is exposed until a collection's Settings say so — the default is off.
 */
class Api
{
    /**
     * As close to Strapi's /api/team-members as a WordPress namespace gets.
     */
    public const NAMESPACE = 'schemapress/api';

    /**
     * Hooks the routes.
     */
    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register']);
    }

    /**
     * Registers the two routes every collection answers on.
     *
     * One pair for all collections rather than a pair per collection: the
     * collection is a path segment, so creating one re-registers nothing.
     *
     * The master switch is the exception. Off, nothing here is registered, so
     * the namespace is absent from the /wp-json/ index entirely and every address
     * under it answers rest_no_route — nothing advertises that this site has a
     * content API or which collections it holds. The per-collection switches stay
     * handler-side, since a route list that changed shape as collections were
     * published would be a different API from one request to the next.
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
     * A page of entries.
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
     * One entry, by the uuid the rest of the API identifies it with.
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
     * A response a client can hold on to, and ask about cheaply next time.
     *
     * There is always an ETag, hashed from the body — which is a pure function of
     * the published content and the query, so it changes when and only when the
     * answer does. A client that sends back `If-None-Match` gets a 304 with no
     * body.
     *
     * max-age is zero unless the site says otherwise, because the two are
     * different promises: an ETag says "ask me and I will tell you cheaply", a
     * max-age says "do not ask me for an hour" — which means an editor publishing
     * a correction cannot get it onto a CDN-fronted site until that hour is up.
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

        // `must-revalidate` stops a proxy serving a stale copy past its age
        // rather than asking
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
        $response->header('Vary', 'Accept-Encoding, Origin');

        return $response;
    }

    /**
     * The collection behind a path segment, if it is open for this shape of read.
     *
     * Only the collection's own pair is consulted; with the master switch off
     * these routes were never registered, so nothing reaches here to be refused.
     *
     * The refusals differ on purpose: a collection nobody named is a 404, one
     * that exists but is closed is a 403 saying where the switch is. "Not found"
     * for a collection somebody is looking at in the admin would send them
     * hunting for a typo that is not there.
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
     * Resolves a URL segment to a collection id, matching both machine names.
     * Hyphens read as underscores: `team-members` is what a URL wants and
     * `team_members` is what the key is.
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
     * One entry as the API presents it: flat, the way Strapi v5 returns a
     * document, with resolved values so a client never holds an id it has to
     * spend another request on.
     *
     * `values`, `state` and `ahead` are absent — they describe the editing of an
     * entry, which is the builder's business and not the public's.
     *
     * @param array $entry from Entries::shape()
     *
     * @return array
     */
    private function shape(array $entry)
    {
        $data = is_array($entry['data'] ?? null) ? $entry['data'] : [];

        // No `title`: WordPress needs a post_title for every row, so one is
        // invented from whichever text field comes first when the collection
        // named no field to title by — an artifact of storage, not content.
        // `slug` is always here, because it is how a front end addresses an
        // entry and one that sometimes had one would be a routing bug.
        $shaped = ['id' => $entry['id'], 'slug' => $entry['slug']];

        return array_merge($shaped, $data, [
            'updatedAt' => $entry['modified'],
            'publishedAt' => $entry['publishedAt'],
        ]);
    }
}
