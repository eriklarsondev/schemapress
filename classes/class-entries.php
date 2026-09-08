<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * entries: the rows of a collection type.
 *
 * one entry is one post of the collection's own post type. it holds TWO copies
 * of its values, and the distinction between them is the whole point:
 *
 *   published  what the front end is serving right now
 *   draft      what someone is working on
 *
 * saving writes the draft. the published copy does not move until you publish,
 * so editing a live entry never takes it off the site half-finished — the draft
 * branches off the published version and how far it has diverged is countable:
 * "3 changes ahead of published".
 *
 * publishing fast-forwards: the draft becomes the published copy and the count
 * resets. discarding does the opposite, throwing the branch away.
 *
 * a never-published entry has only a draft, and the front end cannot see it at
 * all.
 */
class Entries
{
    /**
     * what the front end serves.
     */
    const META_VALUES = '_schemapress_values';

    /**
     * what is being worked on.
     */
    const META_DRAFT = '_schemapress_draft';

    /**
     * how many saves the draft is ahead of the published copy.
     */
    const META_AHEAD = '_schemapress_ahead';

    /**
     * when the published copy was last moved forward.
     */
    const META_PUBLISHED_AT = '_schemapress_published_at';

    /**
     * the draft's name, while it differs from the published one.
     *
     * the post row's title belongs to the published copy, so an entry with
     * unpublished edits needs somewhere else to keep what it is currently
     * called. absent means the two agree.
     */
    const META_DRAFT_TITLE = '_schemapress_draft_title';

    /**
     * the entry's public identifier.
     *
     * a generated uuid rather than the post id, because the post id is a row
     * number: it leaks how many entries exist and in what order they were made,
     * it is guessable, and it ties every url and every API response to this
     * particular database. the post id stays, internally, as the primary key it
     * is — nothing outside this class needs to know it.
     */
    const META_UID = '_schemapress_uid';

    /**
     * the field key a collection uses to name its entries, when it declares one.
     */
    /**
     * the field a collection names its entries by, or '' when it names none.
     *
     * @param integer $type_id
     *
     * @return string
     */
    public static function titleField($type_id)
    {
        $settings = SchemaRepository::definition($type_id)['settings'];

        return isset($settings['titleField']) ? (string) $settings['titleField'] : '';
    }

    /**
     * how many entries a listing returns when nothing says otherwise.
     *
     * ten, because a page you can see all of at once is a page you can compare
     * across — and because the pager below it then means something.
     */
    const PER_PAGE = 10;

    /**
     * the two ways an entry can be read.
     */
    const PUBLISHED = 'published';
    const DRAFT = 'draft';

    /**
     * a page of entries.
     *
     * @param integer $type_id
     * @param array   $args    page, perPage, search, orderby, order, view
     *
     * @return array{entries: array, total: integer, pages: integer}
     */
    public static function all($type_id, array $args = [])
    {
        $type = ContentType::get($type_id);

        if (!$type) {
            return ['entries' => [], 'total' => 0, 'pages' => 0];
        }

        $view = self::view($args['view'] ?? self::PUBLISHED);
        $page = max(1, (int) ($args['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($args['perPage'] ?? self::PER_PAGE)));

        $definition = SchemaRepository::definition($type_id);

        // filters and sort come in as a Query spec and are translated against
        // the index. they are merged last so a caller asking for them overrides
        // the plain page/orderby arguments the admin listing uses
        $spec = isset($args['spec']) && is_array($args['spec'])
            ? Query::args($args['spec'], $definition['fields'], $view === self::DRAFT)
            : [];

        $query = new \WP_Query(array_merge([
            'post_type' => $type['postType'],
            // reading the published view means published posts only. a template
            // that forgot to say which view it wanted would otherwise publish
            // unfinished work by omission
            'post_status' => $view === self::DRAFT ? ['publish', 'draft'] : ['publish'],
            'posts_per_page' => $perPage,
            'paged' => $page,
            's' => isset($args['search']) ? sanitize_text_field($args['search']) : '',
            'orderby' => in_array($args['orderby'] ?? '', ['title', 'date', 'modified'], true)
                ? $args['orderby']
                : 'modified',
            'order' => strtoupper($args['order'] ?? '') === 'ASC' ? 'ASC' : 'DESC',
            'suppress_filters' => false,
        ], $spec));

        $entries = [];

        foreach ($query->posts as $post) {
            $entries[] = self::shape($post, $definition, 0, $view);
        }

        return [
            'entries' => $entries,
            'total' => (int) $query->found_posts,
            'pages' => (int) $query->max_num_pages,
            // which page this actually is, and how big, so a listing can say
            // "11-20 of 34" without the client guessing at the size it got
            'page' => $page,
            'perPage' => $perPage,
        ];
    }

    /**
     * how many entries a collection holds, counting drafts.
     *
     * @param integer $type_id
     *
     * @return integer
     */
    public static function count($type_id)
    {
        $type = ContentType::get($type_id);

        if (!$type) {
            return 0;
        }

        $counts = wp_count_posts($type['postType']);

        return (int) ($counts->publish ?? 0) + (int) ($counts->draft ?? 0);
    }

    /**
     * one entry.
     *
     * @param integer $type_id
     * @param integer $entry_id
     * @param integer $depth
     * @param string  $view     published or draft
     *
     * @return array|null
     */
    public static function get($type_id, $entry_id, $depth = 0, $view = self::PUBLISHED)
    {
        $post = self::resolve($type_id, $entry_id);

        if (!$post) {
            return null;
        }

        $view = self::view($view);

        // an entry that has never been published has nothing to serve
        if ($view === self::PUBLISHED && $post->post_status !== 'publish') {
            return null;
        }

        return self::shape($post, SchemaRepository::definition($type_id), $depth, $view);
    }

    /**
     * saves the draft, and optionally publishes it.
     *
     * @param integer      $type_id
     * @param integer|null $entry_id null creates
     * @param array        $data     values, publish
     *
     * @return array|\WP_Error|null the stored entry read as a draft, a WP_Error
     *                              naming the rules the values broke, or null
     *                              when there is nothing here to save into
     */
    public static function save($type_id, $entry_id, array $data)
    {
        $type = ContentType::get($type_id);

        if (!$type) {
            return null;
        }

        $definition = SchemaRepository::definition($type_id);
        $values = ContentSanitizer::values($data['values'] ?? [], $definition['fields']);

        // with drafts turned off there is only one copy of an entry and saving
        // is publishing, so the caller's intent is not consulted
        $publish = !empty($data['publish']) || !self::drafts($type_id);

        $existing = $entry_id ? self::resolve($type_id, $entry_id) : null;

        if ($entry_id && !$existing) {
            return null;
        }

        // before the first write, not after it. a rejected save must leave
        // nothing behind — creating the post and then refusing its values would
        // put an empty entry in the collection as the price of a typo
        $problems = Validator::check($values, $definition['fields'], [
            'type_id' => $type_id,
            'entry_id' => $existing ? $existing->ID : 0,
        ]);

        if ($problems) {
            return Validator::error($problems);
        }

        // read before the write: whether this save is a change at all can only
        // be answered against what was there a moment ago
        $before = $existing
            ? self::sanitized($existing->ID, self::META_DRAFT, $definition['fields'])
            : [];

        $live = $existing && $existing->post_status === 'publish';
        $title = self::deriveTitle(
            $values,
            $definition['fields'],
            $data['title'] ?? '',
            $definition['settings']['titleField'] ?? ''
        );

        $post = [
            'post_type' => $type['postType'],
            // once published an entry stays published; only unpublish moves it
            // back, so an ordinary save cannot take a live entry off the site
            'post_status' => $publish || $live ? 'publish' : 'draft',
        ];

        // post_title is the PUBLISHED name. it has to be, because it is the
        // column WordPress searches and the only copy of the name that is not
        // in this plugin's own meta — so writing the draft's name into it would
        // put unpublished text on the live site under a heading nobody chose.
        // a draft-only save of a live entry therefore leaves it alone, and the
        // draft's own name is kept beside it until it is published
        if (!$live) {
            $post['post_title'] = $title;
            // the searchable text goes where the name goes, and for the same
            // reason: both belong to the published copy, and an entry that is
            // not live has no published copy to hold back
            $post['post_content'] = self::searchText($values, $definition['fields']);
        }

        if ($existing) {
            $post['ID'] = $existing->ID;
        }

        $id = $existing ? wp_update_post($post, true) : wp_insert_post($post, true);

        if (is_wp_error($id)) {
            return null;
        }

        $uid = self::uid($id);

        // the slug settles while the entry is unpublished and freezes once it
        // is live. a published address is something somebody has linked to, and
        // renaming an entry should not move it out from under them — which is
        // also how WordPress treats post_name and how Strapi treats a uid field
        if (!$live) {
            self::reslug($id, $values, $definition, $uid);
        }

        self::write($id, self::META_DRAFT, $values);

        // the draft index tracks every save, published or not; the published one
        // is written by promote() and only when something actually goes live
        Index::write($id, $values, $definition['fields'], true);

        if ($publish) {
            self::promote($type_id, $id, $values, $title, $definition['fields']);
        } elseif ($live) {
            update_post_meta($id, self::META_DRAFT_TITLE, $title);
            self::retrack($id, $values, $before, $definition['fields']);
        } else {
            // not published: the post row is the draft's own, so nothing is
            // being held back and the second copy would only go stale
            delete_post_meta($id, self::META_DRAFT_TITLE);
        }

        /**
         * fires after an entry's draft is written.
         *
         * every save reaches this, published or not — a listener wanting only
         * what went live wants schemapress/entry_published instead.
         *
         * @param integer $id      the entry's post id
         * @param array   $values  what was stored
         * @param integer $type_id the collection
         * @param boolean $created whether this save brought the entry into being
         */
        do_action('schemapress/entry_saved', $id, $values, $type_id, $existing === null);

        // by uid, because that is the only reference the reader takes. this is
        // the one place inside the class holding a post id at the moment it
        // needs to read an entry back, and it converts rather than asking the
        // reader to accept both — see resolve()
        return self::get($type_id, self::uid($id), 0, self::DRAFT);
    }

    /**
     * moves the published copy up to the draft.
     *
     * @param integer $type_id
     * @param integer $entry_id
     *
     * @return array|null
     */
    public static function publish($type_id, $entry_id)
    {
        $post = self::resolve($type_id, $entry_id);

        if (!$post || !self::drafts($type_id)) {
            return null;
        }

        $definition = SchemaRepository::definition($type_id);

        // the draft's own name, which is what the admin has been looking at.
        // absent means the two names already agree, so the post row keeps its
        // title — including one a caller supplied rather than derived
        $stored = get_post_meta($post->ID, self::META_DRAFT_TITLE, true);

        wp_update_post(['ID' => $post->ID, 'post_status' => 'publish']);
        self::promote(
            $type_id,
            $post->ID,
            self::sanitized($post->ID, self::META_DRAFT, $definition['fields']),
            is_string($stored) && $stored !== '' ? $stored : get_the_title($post),
            $definition['fields']
        );

        return self::get($type_id, $entry_id, 0, self::DRAFT);
    }

    /**
     * takes an entry off the front end, keeping its work.
     *
     * @param integer $type_id
     * @param integer $entry_id
     *
     * @return array|null
     */
    public static function unpublish($type_id, $entry_id)
    {
        $post = self::resolve($type_id, $entry_id);

        if (!$post || !self::drafts($type_id)) {
            return null;
        }

        $definition = SchemaRepository::definition($type_id);
        $draft = self::sanitized($post->ID, self::META_DRAFT, $definition['fields']);

        wp_update_post([
            'ID' => $post->ID,
            'post_status' => 'draft',
            // nothing is published any more, so the post row's title goes back
            // to describing the only copy left
            'post_title' => self::deriveTitle(
                $draft,
                $definition['fields'],
                '',
                $definition['settings']['titleField'] ?? ''
            ),
            // nothing is published, so the row describes the draft — which is
            // what the builder's own search is looking through
            'post_content' => self::searchText($draft, $definition['fields']),
        ]);

        delete_post_meta($post->ID, self::META_VALUES);
        delete_post_meta($post->ID, self::META_PUBLISHED_AT);
        // nothing is published, so nothing of this entry is live to filter.
        // leaving the published index behind would let a query return an entry
        // the API then refuses to show — the draft index stays, because the
        // entry is still here and the builder still lists it
        Index::clear($post->ID, Index::PREFIX);
        delete_post_meta($post->ID, self::META_DRAFT_TITLE);
        update_post_meta($post->ID, self::META_AHEAD, 0);

        /**
         * fires when an entry comes off the front end, its work kept.
         *
         * the pair to entry_published: anything that was built from this entry
         * being live has to come down.
         *
         * @param integer $id      the entry's post id
         * @param integer $type_id the collection
         */
        do_action('schemapress/entry_unpublished', $post->ID, $type_id);

        return self::get($type_id, $entry_id, 0, self::DRAFT);
    }

    /**
     * throws the draft away, returning to what is published.
     *
     * @param integer $type_id
     * @param integer $entry_id
     *
     * @return array|null
     */
    public static function discard($type_id, $entry_id)
    {
        $post = self::resolve($type_id, $entry_id);

        if (!$post || $post->post_status !== 'publish' || !self::drafts($type_id)) {
            return null;
        }

        $definition = SchemaRepository::definition($type_id);
        $values = self::stored($post->ID, self::META_VALUES);

        self::write($post->ID, self::META_DRAFT, $values);

        // the draft index is derived from the draft, so throwing the draft away
        // has to throw its index away too. without this the builder's own
        // listing went on filtering and sorting a discarded entry by values
        // nothing was storing any more — an entry reverted from "Designer" back
        // to "Engineer" stayed under Designer in the table until its next save
        Index::write($post->ID, ContentSanitizer::values($values, $definition['fields']), $definition['fields'], true);

        update_post_meta($post->ID, self::META_AHEAD, 0);

        // the discarded draft's name goes with it, back to the published one
        delete_post_meta($post->ID, self::META_DRAFT_TITLE);

        /**
         * fires when a draft is thrown away and the entry returns to what is
         * published.
         *
         * @param integer $id      the entry's post id
         * @param integer $type_id the collection
         */
        do_action('schemapress/entry_discarded', $post->ID, $type_id);

        return self::get($type_id, $entry_id, 0, self::DRAFT);
    }

    /**
     * trashes an entry.
     *
     * @param integer $type_id
     * @param integer $entry_id
     *
     * @return boolean
     */
    public static function delete($type_id, $entry_id)
    {
        $post = self::resolve($type_id, $entry_id);

        if (!$post) {
            return false;
        }

        if (!wp_trash_post($post->ID)) {
            return false;
        }

        /**
         * fires after an entry is trashed.
         *
         * trashed, not erased: the post and its meta are still there, so a
         * listener that needs to know what the entry held can still read it.
         *
         * @param integer $id      the entry's post id
         * @param integer $type_id the collection
         */
        do_action('schemapress/entry_deleted', $post->ID, $type_id);

        return true;
    }

    // --- identity ------------------------------------------------------------

    /**
     * an entry's public identifier, minted on first use and stable after.
     *
     * minting lazily rather than only on create means entries saved before this
     * existed get one the first time they are read, instead of needing a
     * migration that could half-run.
     *
     * @param integer $post_id
     *
     * @return string
     */
    public static function uid($post_id)
    {
        $post_id = absint($post_id);
        $stored = get_post_meta($post_id, self::META_UID, true);

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $uid = wp_generate_uuid4();

        update_post_meta($post_id, self::META_UID, $uid);

        return $uid;
    }

    /**
     * an entry's identifier as stored, without minting one.
     *
     * the read-side accessor, so that delivering an entry is a read. uid()
     * mints, which is right when an entry is being created and wrong on the
     * path a public GET takes — see class-upgrade.php.
     *
     * @param integer $post_id
     *
     * @return string '' when the entry has never been given one
     */
    private static function storedUid($post_id)
    {
        $stored = get_post_meta(absint($post_id), self::META_UID, true);

        return is_string($stored) ? $stored : '';
    }

    /**
     * gives every entry of a collection an identifier, for the ones that
     * predate having any.
     *
     * @param integer $type_id
     *
     * @return integer how many were minted
     */
    public static function backfill($type_id)
    {
        $type = ContentType::get($type_id);

        if (!$type) {
            return 0;
        }

        $minted = 0;

        // trashed entries too: they can be restored, and one restored without
        // an identifier would be a read that writes all over again
        foreach (get_posts([
            'post_type' => $type['postType'],
            'post_status' => ['publish', 'draft', 'trash'],
            'numberposts' => -1,
            'fields' => 'ids',
            'suppress_filters' => false,
        ]) as $id) {
            if (self::storedUid($id) !== '') {
                continue;
            }

            self::uid($id);
            $minted++;
        }

        return $minted;
    }

    /**
     * finds the post behind a reference.
     *
     * a uid, and NOTHING else.
     *
     * this used to take a post id as well, for internal callers that already
     * hold one. what it also did was let the content API answer
     * /api/team-members/5, so any published entry could be fetched by counting
     * 1, 2, 3 — precisely the enumeration the uid exists to prevent,
     * reintroduced by the convenience of accepting both.
     *
     * there is exactly one internal caller that holds a post id, and it is
     * save(), which converts to a uid itself. one conversion at one call site
     * is a smaller thing than a reader that cannot tell a public reference from
     * a private one.
     *
     * A SLUG WORKS TOO, and is the point of having one: a front end routing
     * /team/ada-lovelace has the slug and not the uuid, and would otherwise
     * have to list the whole collection to translate between them. a slug is a
     * public identifier in a way a post id never was — it is chosen, it is not
     * sequential, and it says nothing about how many entries exist.
     *
     * the uuid is tried first, because it is the identifier the API reports and
     * so the one most references are. a collection whose slugs ARE uuids
     * matches on the first lookup either way.
     *
     * @param integer $type_id
     * @param mixed   $ref
     *
     * @return \WP_Post|null
     */
    private static function resolve($type_id, $ref)
    {
        $type = ContentType::get($type_id);

        if (!$type) {
            return null;
        }

        $found = get_posts([
            'post_type' => $type['postType'],
            'post_status' => ['publish', 'draft'],
            'numberposts' => 1,
            'meta_key' => self::META_UID,
            'meta_value' => (string) $ref,
            'suppress_filters' => false,
        ]);

        if (isset($found[0])) {
            return $found[0];
        }

        $slug = sanitize_title((string) $ref);

        if ($slug === '') {
            return null;
        }

        $found = get_posts([
            'post_type' => $type['postType'],
            'post_status' => ['publish', 'draft'],
            'numberposts' => 1,
            'name' => $slug,
            'suppress_filters' => false,
        ]);

        return isset($found[0]) ? $found[0] : null;
    }

    // --- internals -----------------------------------------------------------

    /**
     * whether this collection keeps a draft separate from what it publishes.
     *
     * @param integer $type_id
     *
     * @return boolean
     */
    private static function drafts($type_id)
    {
        $definition = SchemaRepository::definition($type_id);

        return !empty($definition['settings']['draftAndPublish']);
    }

    /**
     * updates how far the draft has run ahead of the published copy.
     *
     * the count is of CHANGES, not of saves. pressing save twice on the same
     * text is one change, and pressing it once on text identical to what is
     * published is not a change at all — an entry that reads exactly like the
     * live one is not ahead of it, whatever the counter previously said.
     *
     * that last case is the one that matters in practice: publishing and then
     * saving would otherwise report "1 change ahead of published" about an
     * entry that is character-for-character what the site is serving.
     *
     * @param integer $id
     * @param array   $values the values just written to the draft
     * @param array   $before the draft as it was before this save
     * @param array   $fields
     *
     * @return void
     */
    private static function retrack($id, array $values, array $before, array $fields)
    {
        // both sides come through the same sanitizer against the same field
        // list, so identical content compares identical — key order included
        if ($values === self::sanitized($id, self::META_VALUES, $fields)) {
            update_post_meta($id, self::META_AHEAD, 0);

            return;
        }

        if ($values === $before) {
            return;
        }

        update_post_meta($id, self::META_AHEAD, self::ahead($id) + 1);
    }

    /**
     * one stored value bag, normalized against the current field list.
     *
     * @param integer $id
     * @param string  $key
     * @param array   $fields
     *
     * @return array
     */
    private static function sanitized($id, $key, array $fields)
    {
        return ContentSanitizer::values(self::stored($id, $key), $fields);
    }

    /**
     * copies values into the published slot and resets the divergence count.
     *
     * the post row's title moves here and nowhere else, because that is what
     * makes it mean "the published name" — which is what the front end reads
     * and what WordPress search matches.
     *
     * @param integer $type_id
     * @param integer $id
     * @param array   $values
     * @param string  $title   the name being published, already derived
     *
     * @return void
     */
    private static function promote($type_id, $id, array $values, $title, array $fields = [])
    {
        self::write($id, self::META_VALUES, $values);

        // the index is derived, so it is rebuilt here rather than patched:
        // whatever is going live is exactly what becomes filterable
        Index::write($id, $values, $fields);
        update_post_meta($id, self::META_AHEAD, 0);
        update_post_meta($id, self::META_PUBLISHED_AT, gmdate('Y-m-d\TH:i:s\Z'));

        wp_update_post([
            'ID' => $id,
            'post_title' => $title,
            // what is searchable is what is published, exactly as the title is.
            // written here and nowhere else, so a live entry with unpublished
            // edits cannot be found by words only its draft contains
            'post_content' => self::searchText($values, $fields),
        ]);
        delete_post_meta($id, self::META_DRAFT_TITLE);

        /**
         * fires when an entry's values become what the site is serving.
         *
         * this is the one to hang a cache purge or a rebuild on: it fires
         * whether publishing happened through the publish action or through a
         * save on a collection that keeps no drafts, and it fires only when
         * something actually moved onto the front end.
         *
         * @param integer $id      the entry's post id
         * @param array   $values  what is now live
         * @param integer $type_id the collection
         */
        do_action('schemapress/entry_published', $id, $values, $type_id);
    }

    /**
     * coerces a view name.
     *
     * @param string $view
     *
     * @return string
     */
    private static function view($view)
    {
        return $view === self::DRAFT ? self::DRAFT : self::PUBLISHED;
    }

    /**
     * how far the draft is ahead of the published copy.
     *
     * @param integer $id
     *
     * @return integer
     */
    private static function ahead($id)
    {
        return absint(get_post_meta($id, self::META_AHEAD, true));
    }

    /**
     * stores one value bag.
     *
     * @param integer $id
     * @param string  $key
     * @param array   $values
     *
     * @return void
     */
    private static function write($id, $key, array $values)
    {
        update_post_meta($id, $key, wp_slash(wp_json_encode($values)));
    }

    /**
     * reads one stored value bag.
     *
     * @param integer $id
     * @param string  $key
     *
     * @return array
     */
    private static function stored($id, $key)
    {
        $raw = get_post_meta($id, $key, true);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : $raw;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * the delivered shape of one entry.
     *
     * @param \WP_Post $post
     * @param array    $definition
     * @param integer  $depth
     * @param string   $view
     *
     * @return array
     */
    private static function shape($post, array $definition, $depth = 0, $view = self::PUBLISHED)
    {
        $published = $post->post_status === 'publish';
        $ahead = self::ahead($post->ID);

        // the draft view falls back to the published copy for an entry saved
        // before it had a draft slot at all
        $stored = $view === self::DRAFT
            ? (self::stored($post->ID, self::META_DRAFT) ?: self::stored($post->ID, self::META_VALUES))
            : self::stored($post->ID, self::META_VALUES);

        $values = ContentSanitizer::values($stored, $definition['fields']);

        // the post row's title is the published name; the draft keeps its own
        // beside it while the two disagree. reading one for both views is how
        // draft-only text used to reach the front end
        $draftTitle = $view === self::DRAFT
            ? get_post_meta($post->ID, self::META_DRAFT_TITLE, true)
            : '';

        return [
            // read, not minted. the backfill in class-upgrade.php is what makes
            // the fallback unreachable: without it, delivering an entry over the
            // public API wrote a meta row, which is not something a GET should do
            'id' => self::storedUid($post->ID) ?: self::uid($post->ID),
            'title' => is_string($draftTitle) && $draftTitle !== ''
                ? $draftTitle
                : get_the_title($post),
            'slug' => $post->post_name,
            // what this entry is, in one word, for a badge
            'state' => !$published ? 'draft' : ($ahead > 0 ? 'modified' : 'published'),
            'isPublished' => $published,
            // how far the draft has diverged from what is live
            'ahead' => $published ? $ahead : 0,
            // ISO-8601, so a client can read them. see Dates::iso — these are
            // instants, unlike a date FIELD, which is a wall clock
            'modified' => Dates::iso($post->post_modified_gmt),
            'publishedAt' => $published
                ? Dates::iso(get_post_meta($post->ID, self::META_PUBLISHED_AT, true))
                : '',
            // stored, for the editor to load back into its controls
            'values' => $values,
            // resolved, for a template or a client to render
            'data' => Resolver::values($values, $definition['fields'], $depth),
        ];
    }

    /**
     * writes the entry's address, when it does not already have the right one.
     *
     * WordPress uniquifies a supplied post_name, so two entries called the same
     * thing become `ada-lovelace` and `ada-lovelace-2`. that suffix is why the
     * comparison is a prefix rather than an equality: an entry whose address
     * already begins with what it should be has the right address, and testing
     * for equality would rewrite it on every save forever.
     *
     * @param integer $id
     * @param array   $values
     * @param array   $definition
     * @param string  $uid
     *
     * @return void
     */
    private static function reslug($id, array $values, array $definition, $uid)
    {
        $slug = self::deriveSlug($values, $definition, $uid);
        $post = get_post($id);

        if ($slug === '' || !$post || strpos((string) $post->post_name, $slug) === 0) {
            return;
        }

        wp_update_post(['ID' => $id, 'post_name' => $slug]);
    }

    /**
     * the address an entry should have.
     *
     * built from the field the collection nominated, or from the uuid when it
     * nominated none — see SchemaModel::normalizeSlugField. an entry always has
     * one, because a slug that can be missing is a routing bug waiting for the
     * first entry somebody leaves half filled in.
     *
     * @param array  $values
     * @param array  $definition
     * @param string $uid
     *
     * @return string
     */
    private static function deriveSlug(array $values, array $definition, $uid)
    {
        $key = (string) ($definition['settings']['slugField'] ?? '');

        if ($key === '') {
            return $uid;
        }

        $value = $values[$key] ?? null;

        // a multi-select names itself by whichever choice comes first; nothing
        // sensible can be made of the rest of them in an address
        if (is_array($value)) {
            $value = reset($value);
        }

        $slug = sanitize_title((string) $value);

        // the field is empty, so there is nothing to build an address out of
        // yet. the uuid holds the place until there is
        return $slug !== '' ? $slug : $uid;
    }

    /**
     * everything about an entry that somebody might search for, as plain text.
     *
     * WordPress searches post_title and post_content. an entry's values are a
     * JSON blob in meta, which neither of those is, so search matched the
     * derived title and nothing else — and a collection that names its entries
     * by no field is a list of "Untitled" rows that could not be searched at
     * all.
     *
     * so the searchable text is mirrored into post_content, for the same reason
     * the index mirrors filterable values into meta rows: the record stays the
     * blob, and this is a derived copy in the shape the database can reach.
     *
     * only the types a person would type a word from. a number, a date and a
     * toggle are matched by filtering rather than by searching, and an
     * attachment id is not something anybody searches for.
     *
     * @param array $values
     * @param array $fields
     *
     * @return string
     */
    private static function searchText(array $values, array $fields)
    {
        $parts = [];

        foreach ($fields as $field) {
            $value = $values[$field['key']] ?? null;

            switch ($field['type']) {
                case 'group':
                    $parts[] = self::searchText(is_array($value) ? $value : [], $field['fields']);
                    break;

                case 'repeater':
                    foreach (is_array($value) ? $value : [] as $row) {
                        $parts[] = self::searchText(
                            isset($row['values']) && is_array($row['values']) ? $row['values'] : [],
                            $field['fields']
                        );
                    }
                    break;

                case 'link':
                    // the label is the words; the url is an address, and
                    // matching one on a search for "about" is noise
                    $parts[] = is_array($value) ? (string) ($value['label'] ?? '') : '';
                    break;

                case 'wysiwyg':
                    $parts[] = wp_strip_all_tags((string) $value);
                    break;

                case 'text':
                case 'textarea':
                case 'email':
                case 'url':
                case 'phone':
                case 'select':
                    $parts[] = is_array($value)
                        ? implode(' ', array_map('strval', $value))
                        : (string) $value;
                    break;
            }
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($parts, 'strlen'))));
    }

    /**
     * names an entry from the first text it carries.
     *
     * @param array  $values
     * @param array  $fields
     * @param string $given a title the caller supplied, which wins
     *
     * @return string
     */
    private static function deriveTitle(array $values, array $fields, $given = '', $named = '')
    {
        $given = sanitize_text_field($given);

        if ($given !== '') {
            return $given;
        }

        // a collection that nominated a field has said what its entries are
        // called, and that value IS the post title — not a summary of it. no
        // word trim either: the field is the name, however long it is
        if ($named !== '' && isset($values[$named]) && is_scalar($values[$named])) {
            $title = sanitize_text_field((string) $values[$named]);

            if (trim($title) !== '') {
                return $title;
            }
        }

        // nothing declared, so one is invented for WordPress's benefit. it is
        // the first text a reader would recognise, trimmed to a heading's
        // length — and it is deliberately NOT in the API response, because
        // which field it lands on is an accident of field order rather than
        // anything the schema said. see Api::shape()
        foreach ($fields as $field) {
            if (!in_array($field['type'], ['text', 'textarea'], true)) {
                continue;
            }

            $value = $values[$field['key']] ?? '';

            if (is_string($value) && trim($value) !== '') {
                return wp_trim_words($value, 8, '');
            }
        }

        return __('Untitled', 'schemapress');
    }
}
