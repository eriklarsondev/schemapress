<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The filterable mirror of an entry's values.
 *
 * An entry stores everything it holds as one JSON string in one meta row, which
 * is the right shape for reading a whole entry and the wrong shape for asking
 * "which of these has role = Engineer". SQL cannot see inside it: filtering
 * would come down to LIKE '%"role":"Engineer"%', which matches substrings of
 * other fields, cannot tell 5 from 50, and has no idea what a repeater is.
 *
 * So every scalar value is written a second time, one meta row per field. The
 * JSON blob stays the record; this is an index over it, rebuilt on every publish
 * and never edited on its own.
 *
 * A row is written even when the value is empty. WP_Query drops posts that have
 * no row for the meta key it is ordering by, so a collection sorted by a field
 * half its entries had not filled in would quietly lose half its entries.
 *
 * There are TWO indexes because two questions are being asked. The published one
 * answers the public API, which must never see an unpublished edit. The draft
 * one answers the builder's own listing, which is looking at work in progress —
 * sorting that against the published index dropped every draft from the table.
 */
class Index
{
    /**
     * Prefix for every indexed row, so the whole index for an entry can be found
     * and dropped without knowing which fields it used to have.
     */
    public const PREFIX = '_sp_f_';

    /**
     * Prefix for the same values as they currently stand, published or not.
     */
    public const DRAFT_PREFIX = '_sp_d_';

    /**
     * Field types worth mirroring, and how their values compare.
     *
     * Absent deliberately: wysiwyg (a blob of markup nothing sensible can be
     * asked about), link and group (composite), repeater and gallery (many
     * values per row, which is a different index), and json (a shape this
     * collection does not describe).
     *
     * @var array<string, string> type => NUMERIC or CHAR
     */
    public const TYPES = [
        'text' => 'CHAR',
        'textarea' => 'CHAR',
        'email' => 'CHAR',
        'url' => 'CHAR',
        'phone' => 'CHAR',
        'select' => 'CHAR',
        'color' => 'CHAR',
        // CHAR, not DATE: the stored forms are ISO-8601, and comparing those as
        // strings gives the same order as comparing them as dates. MySQL's DATE
        // cast would only add a way for a half-filled value to become
        // 0000-00-00 and sort before everything
        'date' => 'CHAR',
        'datetime' => 'CHAR',
        'time' => 'CHAR',
        'number' => 'NUMERIC',
        'toggle' => 'NUMERIC',
        'image' => 'NUMERIC',
        'file' => 'NUMERIC',
    ];

    /**
     * The meta key one field is indexed under.
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
     * Whether a field can be filtered and sorted on.
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
     * How a field's values compare in SQL — NUMERIC or CHAR.
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
     * Every indexable field of a definition, keyed by field key.
     *
     * Top level only. A field inside a repeater has as many values as the
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
     * Rebuilds one entry's index from the values being published.
     *
     * @param integer $post_id
     * @param array   $values the published value bag
     * @param array   $fields the collection's field definitions
     * @param boolean $draft  whether to write the draft index
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
     * Rebuilds the index for every entry of a collection.
     *
     * Called when a collection's fields change, because the index is keyed by
     * field key: rename `role` to `job` and every row still says `role`. It is
     * also the backfill for entries saved before the index existed.
     *
     * A large collection is queued rather than done here — this used to hold
     * every post object of the collection in memory inside the REST request that
     * saved the schema. See class-batch.php.
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
            // trashed entries included so their rows are cleared rather than
            // left answering questions about content nobody can reach
            'post_status' => ['publish', 'draft', 'trash'],
            'numberposts' => -1,
            'fields' => 'ids',
            'suppress_filters' => false,
        ]);

        self::reindex($type_id, $ids);

        return count($ids);
    }

    /**
     * Rebuilds the index for a specific list of entries — the unit of work a
     * queued rebuild advances by.
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

            // an entry that has never been published has no published values,
            // and writing empty rows would put it in the published index as a
            // row where every field is blank
            if ($status === 'publish') {
                self::write($id, self::stored($id, Entries::META_VALUES), $definition['fields']);
            } else {
                self::clear($id, self::PREFIX);
            }

            if ($status === 'trash') {
                self::clear($id, self::DRAFT_PREFIX);

                continue;
            }

            // every entry that is still here has a draft index — from its draft,
            // or from what is live when it has no draft of its own
            self::write(
                $id,
                self::stored($id, Entries::META_DRAFT) ?: self::stored($id, Entries::META_VALUES),
                $definition['fields'],
                true
            );
        }
    }

    /**
     * One stored value bag, decoded.
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
     * Drops an entry's index.
     *
     * By prefix rather than by field list, because the fields it was written
     * with are not necessarily the fields the collection has now.
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
     * Drops one of an entry's two indexes.
     *
     * @param integer $post_id
     * @param string  $prefix
     *
     * @return void
     */
    private static function clearPrefix($post_id, $prefix)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- there is no API for "every meta key matching a prefix": get_post_meta() answers about a key you can already name, and the keys here are whatever the collection's fields USED to be. Caching a list this method exists to delete would be a cache invalidated by its own caller.
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
     * The rows one value becomes.
     *
     * A multi-select holds several values at once and gets a row each, which is
     * what makes `$in` and `$eq` mean "is one of" without special casing at query
     * time. Everything else is exactly one row — including an empty one, so
     * ordering never drops the entry.
     *
     * @param mixed $value
     * @param array $field
     *
     * @return array<string> the meta values to store
     */
    private static function rowsFor($value, array $field)
    {
        if (is_array($value)) {
            return $value === [] ? [''] : array_map([self::class, 'scalar'], $value);
        }

        return [self::scalar($value)];
    }

    /**
     * One value as it is stored for comparison.
     *
     * Booleans become 1 and 0 rather than '' and 1, which is what PHP casting
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
