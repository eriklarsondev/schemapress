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

        $query = new \WP_Query([
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
        ]);

        $definition = SchemaRepository::definition($type_id);
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
     * @return array|null the stored entry, read as a draft
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

        // read before the write: whether this save is a change at all can only
        // be answered against what was there a moment ago
        $before = $existing
            ? self::sanitized($existing->ID, self::META_DRAFT, $definition['fields'])
            : [];

        $live = $existing && $existing->post_status === 'publish';
        $title = self::deriveTitle($values, $definition['fields'], $data['title'] ?? '');

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
        }

        if ($existing) {
            $post['ID'] = $existing->ID;
        }

        $id = $existing ? wp_update_post($post, true) : wp_insert_post($post, true);

        if (is_wp_error($id)) {
            return null;
        }

        self::uid($id);
        self::write($id, self::META_DRAFT, $values);

        if ($publish) {
            self::promote($id, $values, $title);
        } elseif ($live) {
            update_post_meta($id, self::META_DRAFT_TITLE, $title);
            self::retrack($id, $values, $before, $definition['fields']);
        } else {
            // not published: the post row is the draft's own, so nothing is
            // being held back and the second copy would only go stale
            delete_post_meta($id, self::META_DRAFT_TITLE);
        }

        return self::get($type_id, $id, 0, self::DRAFT);
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
            $post->ID,
            self::sanitized($post->ID, self::META_DRAFT, $definition['fields']),
            is_string($stored) && $stored !== '' ? $stored : get_the_title($post)
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
            'post_title' => self::deriveTitle($draft, $definition['fields']),
        ]);

        delete_post_meta($post->ID, self::META_VALUES);
        delete_post_meta($post->ID, self::META_PUBLISHED_AT);
        delete_post_meta($post->ID, self::META_DRAFT_TITLE);
        update_post_meta($post->ID, self::META_AHEAD, 0);

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

        self::write($post->ID, self::META_DRAFT, self::stored($post->ID, self::META_VALUES));
        update_post_meta($post->ID, self::META_AHEAD, 0);

        // the discarded draft's name goes with it, back to the published one
        delete_post_meta($post->ID, self::META_DRAFT_TITLE);

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

        return (bool) wp_trash_post($post->ID);
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
     * finds the post behind a reference.
     *
     * the public surface passes a uid. internal callers that already hold a
     * post id may pass that instead, which is why both are accepted — a numeric
     * reference is unambiguous because a uuid never is one.
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

        if (is_numeric($ref)) {
            $post = get_post(absint($ref));

            return $post && $post->post_type === $type['postType'] ? $post : null;
        }

        $found = get_posts([
            'post_type' => $type['postType'],
            'post_status' => ['publish', 'draft'],
            'numberposts' => 1,
            'meta_key' => self::META_UID,
            'meta_value' => (string) $ref,
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
     * @param integer $id
     * @param array   $values
     * @param string  $title  the name being published, already derived
     *
     * @return void
     */
    private static function promote($id, array $values, $title)
    {
        self::write($id, self::META_VALUES, $values);
        update_post_meta($id, self::META_AHEAD, 0);
        update_post_meta($id, self::META_PUBLISHED_AT, gmdate('Y-m-d H:i:s'));

        wp_update_post(['ID' => $id, 'post_title' => $title]);
        delete_post_meta($id, self::META_DRAFT_TITLE);
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
            'id' => self::uid($post->ID),
            'title' => is_string($draftTitle) && $draftTitle !== ''
                ? $draftTitle
                : get_the_title($post),
            'slug' => $post->post_name,
            // what this entry is, in one word, for a badge
            'state' => !$published ? 'draft' : ($ahead > 0 ? 'modified' : 'published'),
            'isPublished' => $published,
            // how far the draft has diverged from what is live
            'ahead' => $published ? $ahead : 0,
            'modified' => $post->post_modified_gmt,
            'publishedAt' => $published
                ? (string) get_post_meta($post->ID, self::META_PUBLISHED_AT, true)
                : '',
            // stored, for the editor to load back into its controls
            'values' => $values,
            // resolved, for a template or a client to render
            'data' => Resolver::values($values, $definition['fields'], $depth),
        ];
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
    private static function deriveTitle(array $values, array $fields, $given = '')
    {
        $given = sanitize_text_field($given);

        if ($given !== '') {
            return $given;
        }

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
