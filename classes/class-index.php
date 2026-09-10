<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * the filterable mirror of an entry's values.
 *
 * an entry stores everything it holds as one JSON string in one meta row, which
 * is the right shape for reading a whole entry and the wrong shape for asking
 * "which of these has role = Engineer". SQL cannot see inside it: filtering
 * would come down to LIKE '%"role":"Engineer"%', which matches substrings of
 * other fields, cannot tell 5 from 50, and has no idea what a repeater is.
 *
 * so every scalar value is written a second time, one meta row per field, where
 * WP_Query can reach it. the JSON blob stays the record; this is an index over
 * it and is rebuilt from it on every publish, never edited on its own.
 *
 * a row is written even when the value is empty. WP_Query drops posts that have
 * no row for the meta key it is ordering by, so a collection sorted by a field
 * half its entries had not filled in would quietly lose half its entries.
 *
 * there are TWO indexes, under two key prefixes. the published one answers the
 * public API, which must never see an unpublished edit. the draft one answers
 * the builder's own listing, which is looking at work in progress — sorting
 * that against the published index dropped every draft from the table.
 */
class Index
{
    /**
     * prefix for every indexed row, so the whole index for an entry can be
     * found and dropped without knowing which fields it used to have.
     */
    const PREFIX = '_sp_f_';

    /**
     * prefix for the same values as they currently stand, published or not.
     *
     * two indexes, because two questions are being asked. the public API asks
     * "what is live", and must never see an unpublished edit. the builder's own
     * listing asks "what is here", and sorting it against the published index
     * silently dropped every draft — a collection of eight entries showed five
     * the moment a column header was clicked.
     */
    const DRAFT_PREFIX = '_sp_d_';

    /**
     * field types worth mirroring, and how their values compare.
     *
     * absent from this list, and deliberately: wysiwyg (a blob of markup, which
     * nothing sensible can be asked about), link and group (composite), repeater
     * and gallery (many values per row, which is a different index), and json
     * (a shape this collection does not describe, so there is no column to
     * compare it as).
     *
     * @var array<string, string> type => NUMERIC or CHAR
     */
    const TYPES = [
        'text' => 'CHAR',
        'textarea' => 'CHAR',
        'email' => 'CHAR',
        'url' => 'CHAR',
        'phone' => 'CHAR',
        'select' => 'CHAR',
        // a hex string, which compares as one: "give me everything brand red"
        // is a real question and `$eq` answers it
        'color' => 'CHAR',
        // CHAR, not DATE: the stored forms are ISO-8601, and comparing those as
        // strings gives the same order as comparing them as dates. going
        // through MySQL's DATE cast would only add a way for a half-filled
        // value to become 0000-00-00 and sort before everything
        'date' => 'CHAR',
        'datetime' => 'CHAR',
        'time' => 'CHAR',
        'number' => 'NUMERIC',
        'toggle' => 'NUMERIC',
        'image' => 'NUMERIC',
        'file' => 'NUMERIC',
    ];

    /**
     * the meta key one field is indexed under.
     *
     * @param string  $field_key
     * @param boolean $draft whether to address the draft index
     *
     * @return string
     */
    public static function key($field_key, $draft = false)
    {
        return ($draft ? self::DRAFT_PREFIX : self::PREFIX) . $field_key;
    }

    /**
     * whether a field can be filtered and sorted on.
     *
     * @param array $field
     *
     * @return boolean
     */
    public static function indexable(array $field)
    {
        return isset(self::TYPES[$field['type'] ?? '']);
    }

    /**
     * how a field's values compare in SQL — NUMERIC or CHAR.
     *
     * @param array $field
     *
     * @return string
     */
    public static function compareAs(array $field)
    {
        return self::TYPES[$field['type'] ?? ''] ?? 'CHAR';
    }

    /**
     * every indexable field of a definition, keyed by field key.
     *
     * top level only. a field inside a repeater has as many values as the
     * repeater has rows, which one meta row cannot represent.
     *
     * @param array $fields
     *
     * @return array<string, array>
     */
    public static function fields(array $fields)
    {
        $indexable = [];

        foreach ($fields as $field) {
            if (isset($field['key']) && self::indexable($field)) {
                $indexable[$field['key']] = $field;
            }
        }

        return $indexable;
    }

    /**
     * rebuilds one entry's index from the values being published.
     *
     * @param integer $post_id
     * @param array   $values the published value bag
     * @param array   $fields the collection's field definitions
     *
     * @return void
     */
    public static function write($post_id, array $values, array $fields, $draft = false)
    {
        self::clear($post_id, $draft ? self::DRAFT_PREFIX : self::PREFIX);

        foreach (self::fields($fields) as $key => $field) {
            foreach (self::rowsFor($values[$key] ?? null, $field) as $row) {
                add_post_meta($post_id, self::key($key, $draft), $row);
            }
        }
    }

    /**
     * rebuilds the index for every entry of a collection.
     *
     * called when a collection's fields change, because the index is keyed by
     * field key: rename `role` to `job` and every row still says `role`, which
     * answers filters about a field that no longer exists and answers nothing
     * about the one that does.
     *
     * it is also the backfill. entries saved before this index existed have no
     * rows, and re-saving each one by hand is not a migration — opening the
     * collection's Schema tab and saving is.
     *
     * A LARGE COLLECTION IS QUEUED rather than done here. this used to hold
     * every post object of the collection in memory inside the REST request
     * that saved the schema, which on a collection of any size did not finish —
     * and left the index rebuilt for the entries it got through and stale for
     * the rest, with nothing recording where it stopped. see class-batch.php.
     *
     * @param integer $type_id
     *
     * @return integer how many entries were reindexed, or 0 when it was queued
     */
    public static function rebuild($type_id)
    {
        $type = ContentType::get($type_id);

        if (!$type) {
            return 0;
        }

        if (!Batch::inline($type_id)) {
            Batch::queue('reindex', ['type_id' => $type_id]);

            return 0;
        }

        $ids = get_posts([
            'post_type' => $type['postType'],
            // trashed entries are included so their rows are cleared rather
            // than left behind answering questions about content nobody can
            // reach
            'post_status' => ['publish', 'draft', 'trash'],
            'numberposts' => -1,
            'fields' => 'ids',
            'suppress_filters' => false,
        ]);

        self::reindex($type_id, $ids);

        return count($ids);
    }

    /**
     * rebuilds the index for a specific list of entries.
     *
     * the unit of work a queued rebuild advances by, and the whole of one when
     * the collection is small enough to do at once.
     *
     * @param integer   $type_id
     * @param integer[] $ids
     *
     * @return void
     */
    public static function reindex($type_id, array $ids)
    {
        $definition = SchemaRepository::definition($type_id);

        foreach ($ids as $id) {
            $status = get_post_status($id);

            // the published index belongs to entries that are actually live. an
            // entry that has never been published has no published values, and
            // writing empty rows for it would put it in the published index as
            // a row where every field is blank
            if ($status === 'publish') {
                self::write($id, self::stored($id, Entries::META_VALUES), $definition['fields']);
            } else {
                self::clear($id, self::PREFIX);
            }

            if ($status === 'trash') {
                self::clear($id, self::DRAFT_PREFIX);

                continue;
            }

            // the draft index is what the builder lists, so every entry that is
            // still here has one — from its draft, or from what is live when it
            // has no draft of its own
            self::write(
                $id,
                self::stored($id, Entries::META_DRAFT) ?: self::stored($id, Entries::META_VALUES),
                $definition['fields'],
                true
            );
        }
    }

    /**
     * one stored value bag, decoded.
     *
     * @param integer $post_id
     * @param string  $key
     *
     * @return array
     */
    private static function stored($post_id, $key)
    {
        $raw = get_post_meta($post_id, $key, true);
        $values = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];

        return is_array($values) ? $values : [];
    }

    /**
     * drops an entry's index.
     *
     * by prefix rather than by field list, because the fields it was written
     * with are not necessarily the fields the collection has now — a renamed or
     * deleted field would otherwise leave a row behind that still answers
     * filters about a field nobody can see.
     *
     * @param integer     $post_id
     * @param string|null $prefix one index, or null for both
     *
     * @return void
     */
    public static function clear($post_id, $prefix = null)
    {
        foreach ($prefix === null ? [self::PREFIX, self::DRAFT_PREFIX] : [$prefix] as $one) {
            self::clearPrefix($post_id, $one);
        }
    }

    /**
     * drops one of an entry's two indexes.
     *
     * @param integer $post_id
     * @param string  $prefix
     *
     * @return void
     */
    private static function clearPrefix($post_id, $prefix)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- there is no API for "every meta key matching a prefix": get_post_meta() answers about a key you can already name, and the whole point here is that the keys are whatever the collection's fields USED to be. Caching a list that this method exists to delete would be a cache invalidated by its own caller.
        $keys = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT meta_key FROM {$wpdb->postmeta}
                 WHERE post_id = %d AND meta_key LIKE %s",
                $post_id,
                $wpdb->esc_like($prefix) . '%'
            )
        );

        foreach ((array) $keys as $key) {
            delete_post_meta($post_id, $key);
        }
    }

    /**
     * the rows one value becomes.
     *
     * a multi-select holds several values at once and gets a row each, which is
     * what makes `$in` and `$eq` mean "is one of" and "is among" without any
     * special casing at query time. everything else is exactly one row —
     * including an empty one, so ordering never drops the entry.
     *
     * @param mixed $value
     * @param array $field
     *
     * @return array<string> the meta values to store
     */
    private static function rowsFor($value, array $field)
    {
        if (is_array($value)) {
            // an empty multi-select still gets its row, for the same reason an
            // empty text field does
            return $value === [] ? [''] : array_map([self::class, 'scalar'], $value);
        }

        return [self::scalar($value)];
    }

    /**
     * one value as it is stored for comparison.
     *
     * booleans become 1 and 0 rather than '' and 1, which is what PHP casting
     * would have given — and an empty string is not false, it is missing, a
     * distinction `$null` depends on.
     *
     * @param mixed $value
     *
     * @return string
     */
    private static function scalar($value)
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value === null) {
            return '';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
