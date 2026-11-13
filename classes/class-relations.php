<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Entries pointing at other entries.
 *
 * One field type, facing either way. Pointing OUTWARDS it stores the identifier
 * of the entry it points at — one of them, or an ordered list — and nothing
 * else; the entry on the other end knows nothing about it. Pointing BACK it
 * stores nothing at all: it is answered at read time by asking the index which
 * entries point here.
 *
 * That asymmetry is the whole design. The alternative — writing both sides —
 * means two copies of one fact, and this plugin already has four places where
 * the copies could come apart: a draft that is ahead of what is published, the
 * trash, an import, and a bulk action. A derived inverse cannot drift, needs no
 * reindex when a collection changes, and is exactly as true as the index is.
 *
 * What is stored is a REFERENCE, not a verified link. An identifier that names
 * nothing resolves to nothing, the way an attachment id that has been deleted
 * drops out of a gallery. That is what makes an import order-independent: a post
 * can be written before the author it points at exists, and starts resolving the
 * moment the author arrives.
 */
class Relations
{
    /**
     * How many hops a read follows.
     *
     * One. A post resolves its author, and the author's own relations resolve to
     * identity alone — id, slug and the name — rather than to another entry with
     * another author inside it. Two collections pointing at each other would
     * otherwise be a read with no end to it.
     *
     * The ceiling is what keeps a list of twenty-five entries a bounded amount of
     * work. Raise it with `schemapress/relation_depth` knowing that each hop
     * multiplies: a page of 25 entries, each with 5 references, is 125 reads at
     * one hop and 625 at two.
     */
    public const DEPTH = 1;

    /**
     * The most entries one reverse field answers with.
     *
     * A reverse relation is a query, so it has no natural end — "every post by
     * this author" is 3 on one site and 9,000 on another, and the second one is a
     * page of JSON nobody asked for. Past this, use the collection's own list
     * endpoint with a filter, which pages.
     */
    public const INVERSE_LIMIT = 50;

    /**
     * The keys a resolved reference carries whatever else it does. See shape().
     *
     * @var string[]
     */
    public const IDENTITY = ['id', 'slug', 'title'];

    /**
     * How many hops a read follows.
     *
     * @return integer
     */
    public static function depth()
    {
        /**
         * Filters how many hops a relation is followed for.
         *
         * @param integer $depth
         */
        return max(0, (int) apply_filters('schemapress/relation_depth', self::DEPTH));
    }

    /**
     * The collection a relation field points at, or null when it names one this
     * site does not have.
     *
     * Stored as the collection's machine KEY rather than its post id, for the
     * reason Portability gives: a key is the one name that survives the journey
     * to another installation, and a post id is a row number in the database the
     * export left behind.
     *
     * @param array $field
     *
     * @return array|null the collection, from ContentType::all()
     */
    public static function target(array $field)
    {
        $key = isset($field['config']['collection']) ? (string) $field['config']['collection'] : '';

        return $key === '' ? null : ContentType::find($key);
    }

    /**
     * Which way a relation field faces.
     *
     * One type rather than two, because "an author's articles" and "an
     * article's author" are two views of ONE relationship — nobody thinks of
     * them as different kinds of field, and making them different types meant
     * choosing the direction before you had chosen the collection.
     *
     * @param array $field
     *
     * @return string owns or inverse
     */
    public static function direction(array $field)
    {
        return ($field['config']['direction'] ?? '') === 'inverse' ? 'inverse' : 'owns';
    }

    /**
     * Whether a field is a relation at all.
     *
     * @param array $field
     *
     * @return boolean
     */
    public static function isRelation(array $field)
    {
        return ($field['type'] ?? '') === 'relation';
    }

    /**
     * Whether a field STORES references, as opposed to reading back the ones
     * pointing at it.
     *
     * @param array $field
     *
     * @return boolean
     */
    public static function isOwned(array $field)
    {
        return self::isRelation($field) && self::direction($field) === 'owns';
    }

    /**
     * Whether a field is worked out when the entry is read rather than filled
     * in.
     *
     * It is a question about the FIELD and not about its type, which is the
     * price of there being one relation type: the same type stores something
     * facing one way and nothing facing the other. Everything that used to ask
     * the registry — what may be required, what may be unique, what can be
     * indexed, what the form offers — asks this instead.
     *
     * @param array $field
     *
     * @return boolean
     */
    public static function isInverse(array $field)
    {
        return self::isRelation($field) && self::direction($field) === 'inverse';
    }

    // --- what is stored ------------------------------------------------------

    /**
     * Coerces whatever was posted into the reference, or references, a relation
     * field stores.
     *
     * Deliberately NOT a check that the entry exists. Three things depend on
     * that: an import can write a post before the author it points at has been
     * read out of the same file, a script can link entries in whatever order it
     * finds them, and an entry that was deleted and restored from the trash is
     * pointed at again by everything that always was. Existence is a question for
     * read time, where the answer can change without anything being re-saved.
     *
     * @param mixed $value
     * @param array $field
     *
     * @return string|array<string> one reference, or a list of them
     */
    public static function references($value, array $field)
    {
        $config = isset($field['config']) && is_array($field['config']) ? $field['config'] : [];

        if (empty($config['multiple'])) {
            // a list arriving at a single-valued field is the first of them: the
            // field was changed from many to one, and keeping the first is the
            // only answer that is not a guess
            return self::reference(is_array($value) ? reset($value) : $value);
        }

        $max = isset($config['max']) ? (int) $config['max'] : 0;
        $references = [];

        foreach (is_array($value) ? $value : [$value] as $one) {
            if ($max > 0 && count($references) >= $max) {
                break;
            }

            $reference = self::reference($one);

            // the same entry twice is a slip rather than a choice, the rule a
            // gallery already follows — this is a list of what is related, not
            // a count of anything
            if ($reference !== '' && !in_array($reference, $references, true)) {
                $references[] = $reference;
            }
        }

        return $references;
    }

    /**
     * One identifier, as it is stored.
     *
     * A resolved reference is accepted as well as a bare string, so a client can
     * post back the shape it was given — the same courtesy the image sanitizer
     * extends to `{ id: 12 }`.
     *
     * @param mixed $value
     *
     * @return string '' for anything that is not an identifier
     */
    private static function reference($value)
    {
        if (is_array($value)) {
            $value = $value['id'] ?? '';
        }

        if (!is_scalar($value)) {
            return '';
        }

        // a uuid, or a slug — the two things Entries::resolve() answers to. The
        // per-cent is for a slug in an alphabet WordPress percent-encodes, which
        // is most of them
        return substr(preg_replace('/[^A-Za-z0-9_\-%]/', '', (string) $value), 0, 200);
    }

    // --- what is delivered ---------------------------------------------------

    /**
     * Resolves a relation field's value into the entries it points at.
     *
     * @param mixed   $value
     * @param array   $field
     * @param integer $depth how many hops have already been followed
     * @param string  $view  published or draft
     *
     * @return array|null one entry or null, or a list for a multiple field
     */
    public static function resolve($value, array $field, $depth = 0, $view = Entries::PUBLISHED)
    {
        $many = !empty($field['config']['multiple']);
        $target = self::target($field);

        if (!$target) {
            // the collection it pointed at has been deleted. the shape still
            // answers, so a template reading it does not have to test for a
            // field that used to be a relation
            return $many ? [] : null;
        }

        $references = is_array($value) ? $value : ($value === '' || $value === null ? [] : [$value]);
        $entries = [];

        foreach ($references as $reference) {
            $entry = self::entry((string) $reference, $target, $depth, $view);

            if (!$entry) {
                continue;
            }

            if (!$many) {
                return $entry;
            }

            $entries[] = $entry;
        }

        return $many ? $entries : null;
    }

    /**
     * One referenced entry, resolved as deeply as the depth allows.
     *
     * The last hop reads identity alone and never resolves the entry's values,
     * which is what stops the recursion rather than merely bounding it: nothing
     * below this line can reach another relation.
     *
     * @param string  $reference
     * @param array   $target
     * @param integer $depth
     * @param string  $view
     *
     * @return array|null
     */
    private static function entry($reference, array $target, $depth, $view)
    {
        if ($reference === '') {
            return null;
        }

        if ($depth + 1 > self::depth()) {
            return Entries::identity($target['id'], $reference, $view);
        }

        $entry = Entries::get($target['id'], $reference, $depth + 1, $view);

        return $entry ? self::shape($entry, $target) : null;
    }

    /**
     * A referenced entry as it is delivered: its identity, and then its fields
     * beside them rather than nested under a `data` key.
     *
     *   { "id": "9f2c…", "slug": "ada-lovelace", "title": "Ada Lovelace",
     *     "role": "Engineer", "avatar": { "url": … } }
     *
     * Flat because that is the shape this API already delivers an entry in — see
     * Api::shape — so `author.role` reads the same whether the author arrived as
     * a relation or as the thing you asked for. A field whose key is one of the
     * identity names moves aside rather than replacing it, which is the rule and
     * the reason given there.
     *
     * `title` only when the collection NAMES its entries. Where it does not,
     * WordPress still derives a title for its own row, and that derivation is an
     * accident of field order rather than anything the schema said.
     *
     * @param array $entry  from Entries::shape()
     * @param array $target the collection it belongs to
     *
     * @return array
     */
    private static function shape(array $entry, array $target)
    {
        $identity = [
            'id' => $entry['id'],
            'slug' => $entry['slug'],
        ];

        $names = Entries::titleField($target['id']);

        if ($names !== '') {
            $identity['title'] = $entry['title'];
        }

        $data = isset($entry['data']) && is_array($entry['data']) ? $entry['data'] : [];

        // a collection whose title field is itself called `title` is the common
        // case rather than a collision: the two hold the same string by
        // construction, so the field gives way instead of being moved aside and
        // delivered twice under a name nobody chose
        if ($names === 'title') {
            unset($data['title']);
        }

        foreach ($identity as $reserved => $ignored) {
            if (!array_key_exists($reserved, $data)) {
                continue;
            }

            $moved = $reserved . '_field';

            while (array_key_exists($moved, $data) || isset($identity[$moved])) {
                $moved .= '_field';
            }

            $data[$moved] = $data[$reserved];
            unset($data[$reserved]);
        }

        return array_merge($identity, $data);
    }

    // --- the other direction -------------------------------------------------

    /**
     * The entries pointing at this one through a named relation field.
     *
     * Asked of the index rather than of the stored values, which is the only
     * reason this is affordable: a reference is one meta row, so "every post
     * whose author is this person" is the same query the public API runs for
     * `?filters[author][$eq]=`.
     *
     * Only at the top of a read. A reverse field inside a relation would be a
     * question about a neighbour — "the other posts by the author of this post" —
     * and two collections each holding a reverse of the other would be a read
     * with no end. Deeper down it answers with nothing.
     *
     * @param array   $field
     * @param integer $depth
     * @param string  $view
     * @param string  $entry the uuid of the entry being read
     *
     * @return array
     */
    public static function inverse(array $field, $depth, $view, $entry)
    {
        $target = self::target($field);
        $key = isset($field['config']['field']) ? (string) $field['config']['field'] : '';

        if (!$target || $key === '' || $entry === '' || $depth > 0) {
            return [];
        }

        $pointing = SchemaModel::field(SchemaRepository::definition($target['id'])['fields'], $key);

        // the field it names is gone, was never a relation, or faces the same
        // way this one does. answering with every entry in the collection —
        // which is what an unindexable filter would quietly do — is the one
        // wrong answer available here
        if (!$pointing || !self::isOwned($pointing)) {
            return [];
        }

        $result = Entries::all($target['id'], [
            'view' => $view,
            'perPage' => self::INVERSE_LIMIT,
            'depth' => $depth + 1,
            'spec' => ['filters' => [$key => ['$eq' => $entry]]],
        ]);

        return array_map(function ($one) use ($target) {
            return self::shape($one, $target);
        }, $result['entries']);
    }

    /**
     * Every collection a relation could point at, and every relation already
     * pointing somewhere — what the builder needs to set one up.
     *
     * Top-level relations only, for the same reason the index holds only
     * top-level fields: one inside a repeater has as many values as the repeater
     * has rows, so there is no row to answer the reverse question against. A
     * reverse field offering one would be offering something that can only come
     * back empty.
     *
     * @return array
     */
    public static function catalog()
    {
        $collections = [];

        foreach (ContentType::all() as $type) {
            $relations = [];
            $inverses = [];

            foreach (SchemaRepository::definition($type['id'])['fields'] as $field) {
                if (!self::isRelation($field)) {
                    continue;
                }

                if (self::isOwned($field)) {
                    $relations[] = [
                        'key' => $field['key'],
                        'label' => $field['label'],
                        'collection' => $field['config']['collection'] ?? '',
                        'multiple' => !empty($field['config']['multiple']),
                        // one-to-one is a single relation nobody else may claim,
                        // and the builder draws the two differently
                        'unique' => !empty($field['unique']),
                    ];

                    continue;
                }

                // the ones facing the other way too, which is what lets the
                // builder tell a relation somebody can walk both ways from one
                // that is a dead end — the difference between many-to-many and
                // many-way, and the only part of the kind a field does not
                // decide for itself
                $inverses[] = [
                    'key' => $field['key'],
                    'label' => $field['label'],
                    'collection' => $field['config']['collection'] ?? '',
                    'field' => $field['config']['field'] ?? '',
                ];
            }

            $collections[] = [
                'id' => $type['id'],
                'key' => $type['key'],
                'label' => $type['singularLabel'] ?: $type['label'],
                // the machine plural addresses it; the readable one is what a
                // diagram saying "many Articles" needs
                'plural' => $type['plural'],
                'pluralLabel' => $type['pluralLabel'] ?: $type['label'],
                'entries' => $type['entries'],
                'relations' => $relations,
                'inverses' => $inverses,
            ];
        }

        return $collections;
    }

    /**
     * Which collections embed entries of this one.
     *
     * A relation is a dereference, so publishing an entry changes the delivered
     * body of every entry pointing at it: correct an author's name and every
     * article carrying it is a different response, with a different ETag, while
     * nothing about those articles was saved. A site fronted by a CDN has to
     * purge them too, and this is how it knows which — see the Caching page.
     *
     * Nested fields are walked, because a relation inside a group or a repeater
     * row embeds exactly as one at the top level does. The reverse direction is
     * left out on purpose: a field that reads back is answered from the index at
     * read time, so it is the entry being READ that changes, not some other
     * collection's.
     *
     * @param string $collection the machine key of the collection being changed
     *
     * @return string[] the machine keys of the collections that point at it
     */
    public static function pointingAt($collection)
    {
        $collection = sanitize_key((string) $collection);

        if ($collection === '') {
            return [];
        }

        $keys = [];

        foreach (ContentType::all() as $type) {
            $fields = SchemaRepository::definition($type['id'])['fields'];

            if (self::embeds($fields, $collection)) {
                $keys[] = $type['key'];
            }
        }

        return $keys;
    }

    /**
     * Whether any field in a list — at any depth — points at a collection.
     *
     * @param array  $fields
     * @param string $collection
     *
     * @return boolean
     */
    private static function embeds(array $fields, $collection)
    {
        foreach ($fields as $field) {
            if (
                self::isOwned($field)
                && ($field['config']['collection'] ?? '') === $collection
            ) {
                return true;
            }

            if (
                FieldTypes::hasChildren($field['type'] ?? '')
                && self::embeds(isset($field['fields']) ? $field['fields'] : [], $collection)
            ) {
                return true;
            }
        }

        return false;
    }

    // --- what the public is told ---------------------------------------------

    /**
     * Reduces embedded entries to their identity wherever the collection they
     * came from has not opened itself to being read one at a time.
     *
     * A relation is a dereference, and this plugin publishes nothing a collection
     * has not opted into. Those two meet here: a published post may say WHICH
     * author wrote it — the publisher put that reference in a published entry on
     * purpose — but a collection that is closed does not have its fields read out
     * through the back of another collection that is open.
     *
     * Only on the public API. A template runs on the server with the whole
     * database in front of it, and asking it to re-fetch what it already holds is
     * the `populate` step this plugin does not have.
     *
     * @param array $data   resolved values, from Entries::shape()
     * @param array $fields the collection's field definitions
     *
     * @return array
     */
    public static function prune(array $data, array $fields)
    {
        foreach ($fields as $field) {
            $key = $field['key'];

            if (!array_key_exists($key, $data)) {
                continue;
            }

            // by capability and repeatable first, the order every walker in this
            // plugin takes: a repeater has children too, and the other way round
            // reads its list of rows as one row's values
            if (FieldTypes::isRepeatable($field['type'])) {
                $rows = [];

                foreach (is_array($data[$key]) ? $data[$key] : [] as $row) {
                    if (is_array($row) && isset($row['data']) && is_array($row['data'])) {
                        $row['data'] = self::prune($row['data'], $field['fields']);
                    }

                    $rows[] = $row;
                }

                $data[$key] = $rows;

                continue;
            }

            if (FieldTypes::hasChildren($field['type'])) {
                $data[$key] = self::prune(
                    is_array($data[$key]) ? $data[$key] : [],
                    isset($field['fields']) ? $field['fields'] : []
                );

                continue;
            }

            if (!self::isRelation($field) || self::open($field)) {
                continue;
            }

            // which shape it is comes from the FIELD rather than from the value,
            // the rule the GraphQL schema already follows for a dropdown: a
            // multiple relation holding one entry must not read as a single one
            $many = self::isInverse($field) || !empty($field['config']['multiple']);

            $data[$key] = $many
                ? array_map([self::class, 'identityOf'], is_array($data[$key]) ? $data[$key] : [])
                : self::identityOf($data[$key]);
        }

        return $data;
    }

    /**
     * Whether the collection on the other end of a relation answers for a single
     * entry on the public API.
     *
     * @param array $field
     *
     * @return boolean
     */
    private static function open(array $field)
    {
        $target = self::target($field);

        if (!$target) {
            return false;
        }

        $settings = SchemaRepository::definition($target['id'])['settings'];

        return !empty($settings['publicApi']['single']);
    }

    /**
     * One resolved reference with its fields taken back off.
     *
     * @param mixed $entry
     *
     * @return array|null
     */
    private static function identityOf($entry)
    {
        if (!is_array($entry)) {
            return null;
        }

        return array_intersect_key($entry, array_flip(self::IDENTITY));
    }
}
