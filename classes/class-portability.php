<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Collections, as a file — so a schema can go into version control, move from
 * staging to production, or seed a fresh install.
 *
 * The file is an export rather than the source of truth: a definition here is
 * edited through a UI by people who do not have the repository, so the database
 * stays authoritative and this is how a copy of it travels.
 *
 * The key is the identity. An import matches an existing collection by its
 * machine key, never by title or post id — the key is the one thing stable
 * across two installations. A title is what somebody typed and a post id is a row
 * number in a database this file has left.
 *
 * An import touches only this plugin's own post types. The one thing it shares
 * with the rest of the site is the media library, which is why attachment ids are
 * matched by file rather than trusted: on a site with its own uploads, id 482
 * already exists and is somebody else's photograph.
 */
class Portability
{
    /**
     * the format's own version, so an importer can refuse a file from a future
     * it does not understand rather than half-reading it.
     */
    public const FORMAT = 1;

    /**
     * How many entries one export carries before it is refused. An export is one
     * JSON document held in memory; a collection with a hundred thousand rows in
     * it wants a database backup instead.
     */
    public const MAX_ENTRIES = 5000;

    /**
     * WordPress's own ceiling on a post type name, which the entry post type —
     * `spc_` and the collection's key — has to fit under.
     */
    public const POST_TYPE_LIMIT = 20;

    /**
     * One or more collections as a portable document.
     *
     * @param integer[] $type_ids  empty for every collection
     * @param array     $options   entries: include the content too
     *
     * @return array|\WP_Error
     */
    public static function export(array $type_ids = [], array $options = [])
    {
        $withEntries = !empty($options['entries']);
        $collections = [];
        $counted = 0;
        $media = [];

        foreach (ContentType::all() as $type) {
            if ($type_ids && !in_array($type['id'], $type_ids, true)) {
                continue;
            }

            $definition = SchemaRepository::definition($type['id']);

            $collection = [
                // the machine names travel with the definition, because they
                // are the identity — see the note on this class. an import that
                // derived them again from the label would produce a different
                // key for the same collection and quietly make a second one
                'key' => $type['key'],
                'plural' => $type['plural'],
                'label' => $type['label'],
                'description' => $type['description'],
                'definition' => $definition,
            ];

            if ($withEntries) {
                $counted += Entries::count($type['id']);

                if ($counted > self::MAX_ENTRIES) {
                    return new \WP_Error(
                        'schemapress_export_too_large',
                        sprintf(
                            /* translators: %d: the largest number of entries an export may hold */
                            __(
                                'That is more than %d entries. Export the schema on its own, or use a database backup for the content.',
                                'schemapress'
                            ),
                            self::MAX_ENTRIES
                        ),
                        ['status' => 413]
                    );
                }

                $collection['entries'] = self::entries($type['id'], $definition, $media);
            }

            $collections[] = $collection;
        }

        $payload = [
            'schemapress' => self::FORMAT,
            'version' => SCHEMAPRESS_VERSION,
            'exportedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'site' => home_url(),
            'collections' => $collections,
            'components' => self::components(),
        ];

        if ($withEntries) {
            $payload['media'] = self::manifest(array_keys($media));
        }

        return $payload;
    }

    /**
     * Every entry of a collection, in the shape import() reads back.
     *
     * Stored values, not resolved ones. An image is its attachment id, which
     * means nothing off this site — so every id is collected into `$media` and
     * the export carries a manifest saying which file each one was.
     *
     * @param integer $type_id
     * @param array   $definition
     * @param array   $media      by reference: attachment ids seen, as keys
     *
     * @return array
     */
    private static function entries($type_id, array $definition, array &$media)
    {
        $type = ContentType::get($type_id);
        $exported = [];

        $ids = get_posts([
            'post_type' => $type['postType'],
            'post_status' => ['publish', 'draft'],
            'numberposts' => self::MAX_ENTRIES,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
            'suppress_filters' => false,
        ]);

        $collect = function ($id) use (&$media) {
            $media[$id] = true;

            return $id;
        };

        foreach ($ids as $id) {
            $post = get_post($id);

            if (!$post) {
                continue;
            }

            $entry = Entries::get($type_id, Entries::uid($id), 0, Entries::DRAFT);

            if (!$entry) {
                continue;
            }

            self::attachments($entry['values'], $definition['fields'], $collect);

            $exported[] = [
                // the uuid travels, so re-importing an export over the
                // collection it came from updates the entries rather than
                // making a second copy of each one
                'id' => $entry['id'],
                'slug' => $entry['slug'],
                'title' => $entry['title'],
                'status' => $post->post_status,
                'values' => $entry['values'],
                'publishedAt' => $entry['publishedAt'],
            ];
        }

        return $exported;
    }

    /**
     * Which file each exported attachment id was. The uploads-relative path is
     * the identity that survives a move. The URL is carried for a person reading
     * the file and is never trusted.
     *
     * @param integer[] $ids
     *
     * @return array<string, array{file: string, filename: string, url: string}>
     */
    private static function manifest(array $ids)
    {
        $manifest = [];

        foreach ($ids as $id) {
            if (get_post_type($id) !== 'attachment') {
                continue;
            }

            $file = (string) get_post_meta($id, '_wp_attached_file', true);

            // keyed by string, because it becomes a JSON object and a JSON
            // object's keys are strings whatever PHP thought they were
            $manifest[(string) $id] = [
                'file' => $file,
                'filename' => self::filenameOf($file),
                'url' => (string) wp_get_attachment_url($id),
            ];
        }

        return $manifest;
    }

    /**
     * Every component, with its fields. Components always travel, whichever
     * collections were asked for — they are small, and a collection whose fields
     * came from one is more useful beside it.
     *
     * @return array
     */
    private static function components()
    {
        $components = [];

        foreach (Component::all() as $listed) {
            $component = Component::get($listed['id']);

            if (!$component) {
                continue;
            }

            $components[] = [
                'label' => $component['label'],
                'description' => $component['description'],
                'fields' => $component['fields'],
            ];
        }

        return $components;
    }

    // --- reading one back ----------------------------------------------------

    /**
     * Restores collections from an exported document.
     *
     * The whole document is checked before anything is written, so every refusal
     * is either up front with nothing changed, or per entry and reported. Writing
     * as it read left a file whose fourth collection was unusable half-imported.
     *
     * @param mixed  $payload the decoded document
     * @param array  $options mode: merge or replace, entries: restore content
     *
     * @return array|\WP_Error a report of what happened
     */
    public static function import($payload, array $options = [])
    {
        $payload = is_array($payload) ? $payload : [];

        $invalid = self::validate($payload);

        if ($invalid) {
            return $invalid;
        }

        // replace means an existing collection's fields become exactly what the
        // file says. merge means a field the file does not mention is left
        // alone, which is what you want when the file is a partial or was
        // written against an older shape — and is the default, because it
        // cannot delete anything
        $replace = ($options['mode'] ?? 'merge') === 'replace';
        $withEntries = !empty($options['entries']);

        $media = [
            'manifest' => isset($payload['media']) && is_array($payload['media']) ? $payload['media'] : null,
            'sameSite' => self::sameSite((string) ($payload['site'] ?? '')),
            'cache' => [],
            'matched' => 0,
            'missing' => 0,
        ];

        $report = [
            'collections' => [],
            'components' => 0,
            'entries' => 0,
            'skipped' => 0,
            'media' => ['matched' => 0, 'missing' => 0],
            'warnings' => [],
        ];

        foreach (self::listOf($payload, 'components') as $component) {
            if (self::importComponent($component, $replace)) {
                $report['components']++;
            }
        }

        foreach (self::listOf($payload, 'collections') as $collection) {
            $result = self::importCollection($collection, $replace, $withEntries, $media);

            $report['entries'] += $result['entries'];
            $report['skipped'] += count($result['skipped']);
            $report['warnings'] = array_merge($report['warnings'], $result['warnings']);
            $report['collections'][] = $result;
        }

        $report['media'] = ['matched' => $media['matched'], 'missing' => $media['missing']];

        if ($media['missing'] > 0) {
            $report['warnings'][] = sprintf(
                /* translators: %d: number of images and files */
                _n(
                    '%d image or file could not be found in this site’s media library, so the entries that used it have none. Upload it and set it again.',
                    '%d images and files could not be found in this site’s media library, so the entries that used them have none. Upload them and set them again.',
                    $media['missing'],
                    'schemapress'
                ),
                $media['missing']
            );
        }

        ContentType::flush();
        SchemaRepository::flush();

        /**
         * Fires after an import has been applied.
         *
         * @param array $report
         * @param array $payload the document that was read
         */
        do_action('schemapress/imported', $report, $payload);

        return $report;
    }

    /**
     * Every reason a document cannot be imported, found before anything is
     * written.
     *
     * @param array $payload
     *
     * @return \WP_Error|null
     */
    private static function validate(array $payload)
    {
        $format = (int) ($payload['schemapress'] ?? 0);

        if (!$format) {
            return self::refuse('schemapress_not_an_export', __('That file is not a SchemaPress export.', 'schemapress'));
        }

        if ($format > self::FORMAT) {
            return self::refuse(
                'schemapress_export_too_new',
                __(
                    'That export was written by a newer version of SchemaPress than this one. Update the plugin first.',
                    'schemapress'
                )
            );
        }

        $seen = [];

        // what the collections already here answer to over the API. a new
        // collection whose key is one of these would make an address mean two
        // collections, and the API would answer with whichever it found first
        $taken = [];

        foreach (ContentType::all() as $type) {
            $taken[$type['plural']] = $type['key'];
        }

        foreach (self::listOf($payload, 'collections') as $collection) {
            $collection = is_array($collection) ? $collection : [];
            $key = sanitize_key((string) ($collection['key'] ?? ''));
            $label = sanitize_text_field((string) ($collection['label'] ?? ''));

            if ($key === '' || $label === '') {
                return self::refuse(
                    'schemapress_import_incomplete',
                    __('A collection in that file has no name. The file may be truncated.', 'schemapress')
                );
            }

            if (isset($seen[$key])) {
                return self::refuse(
                    'schemapress_import_duplicate',
                    sprintf(
                        /* translators: %s: a collection's machine key */
                        __('That file describes the collection “%s” twice. Nothing was imported.', 'schemapress'),
                        $key
                    )
                );
            }

            $seen[$key] = true;

            if (strlen(ContentType::POST_TYPE_PREFIX . $key) > self::POST_TYPE_LIMIT) {
                return self::refuse(
                    'schemapress_import_key_too_long',
                    sprintf(
                        /* translators: %s: a collection's machine key */
                        __(
                            'The collection key “%s” is too long for WordPress to store entries under. Nothing was imported.',
                            'schemapress'
                        ),
                        $key
                    )
                );
            }

            // the rest only applies to a collection this import would CREATE.
            // one that already exists is matched by its key and updated, and
            // whatever it answers to is already settled
            if (self::findByKey($key)) {
                continue;
            }

            if (isset($taken[$key])) {
                return self::refuse(
                    'schemapress_import_ambiguous',
                    sprintf(
                        /* translators: 1: a collection's machine key, 2: an existing collection's key */
                        __(
                            'The collection “%1$s” would share its address with “%2$s”, which is already on this site. Nothing was imported.',
                            'schemapress'
                        ),
                        $key,
                        $taken[$key]
                    )
                );
            }

            // somebody else's post type — another plugin's, or the theme's —
            // already has the name this collection's entries would be stored
            // under. storing them there would mix them into that content
            if (post_type_exists(ContentType::POST_TYPE_PREFIX . $key)) {
                return self::refuse(
                    'schemapress_import_post_type_taken',
                    sprintf(
                        /* translators: %s: a post type name */
                        __(
                            'Something else on this site already uses the post type “%s”, so that collection cannot be created. Nothing was imported.',
                            'schemapress'
                        ),
                        ContentType::POST_TYPE_PREFIX . $key
                    )
                );
            }
        }

        return null;
    }

    /**
     * A 400 an import refuses with.
     *
     * @param string $code
     * @param string $message
     *
     * @return \WP_Error
     */
    private static function refuse($code, $message)
    {
        return new \WP_Error($code, $message, ['status' => 400]);
    }

    /**
     * A list from the payload, defended against a document that says a list is
     * something else.
     *
     * @param array  $payload
     * @param string $key
     *
     * @return array
     */
    private static function listOf(array $payload, $key)
    {
        return isset($payload[$key]) && is_array($payload[$key]) ? $payload[$key] : [];
    }

    /**
     * Creates or updates one collection. Nothing here can fail the import —
     * validate() has already refused anything that would. What can go wrong is
     * per entry, and is reported.
     *
     * @param array   $collection
     * @param boolean $replace
     * @param boolean $withEntries
     * @param array   $media       by reference
     *
     * @return array
     */
    private static function importCollection(array $collection, $replace, $withEntries, array &$media)
    {
        $key = sanitize_key((string) $collection['key']);
        $label = sanitize_text_field((string) $collection['label']);
        $raw = isset($collection['definition']) && is_array($collection['definition']) ? $collection['definition'] : [];

        $existing = self::findByKey($key);
        $created = !$existing;

        $id = $existing ?: wp_insert_post([
            'post_type' => Schema::POST_TYPE,
            'post_title' => $label,
            'post_excerpt' => sanitize_textarea_field((string) ($collection['description'] ?? '')),
            'post_status' => 'publish',
        ]);

        if ($created) {
            // claimed from the file rather than derived from the label, so the
            // post type entries are stored against is the one the export named
            update_post_meta($id, ContentType::META_KEY, $key);
            self::claimPlural($id, sanitize_key((string) ($collection['plural'] ?? '')));

            ContentType::flush();
            ContentType::register($id);
        }

        $incoming = SchemaModel::normalize($raw);
        $stored = $created ? null : SchemaRepository::definition($id);

        $warnings = self::unknownTypes($raw, $label);

        if ($stored) {
            $warnings = array_merge($warnings, self::changes($stored['fields'], $incoming['fields'], $replace, $label));
        }

        SchemaRepository::saveDefinition($id, [
            'version' => SchemaModel::VERSION,
            'settings' => self::settings($stored, $incoming['settings'], $replace),
            'fields' => $stored && !$replace
                ? self::mergeFields($stored['fields'], $incoming['fields'])
                : $incoming['fields'],
        ]);

        $written = 0;
        $skipped = [];

        if ($withEntries) {
            list($written, $skipped) = self::importEntries($id, self::listOf($collection, 'entries'), $media);
        }

        return [
            'key' => $key,
            'label' => $label,
            'id' => (int) $id,
            'created' => $created,
            'entries' => $written,
            'skipped' => $skipped,
            'warnings' => $warnings,
        ];
    }

    /**
     * The settings a collection ends up with.
     *
     * The file may set anything about the SHAPE of the collection. Two settings
     * are about this site and a file does not get to decide them:
     *
     *   publicApi   an import is not a decision to publish somebody else's
     *               content; a collection an import creates starts closed
     *   editRoles   or importing a schema could open a restricted collection to
     *               every editor, or lock out the team that owns it
     *
     * Merge also keeps whether the collection has drafts, because turning that
     * off changes what every future save does to a live site.
     *
     * @param array|null $stored   the collection's own definition, or null
     *                             when this import creates it
     * @param array      $incoming the file's settings, normalized
     * @param boolean    $replace
     *
     * @return array
     */
    private static function settings($stored, array $incoming, $replace)
    {
        if (!$stored) {
            // editRoles from the file is kept: a role this site does not have
            // matches nobody, which fails closed, and that is the right way for
            // an imported restriction to fail
            return array_merge($incoming, ['publicApi' => ['list' => false, 'single' => false]]);
        }

        $kept = [
            'publicApi' => $stored['settings']['publicApi'],
            'editRoles' => $stored['settings']['editRoles'],
        ];

        if (!$replace) {
            $kept['draftAndPublish'] = $stored['settings']['draftAndPublish'];
        }

        return array_merge($incoming, $kept);
    }

    /**
     * Folds incoming fields into stored ones: same key replaces, file-only is
     * appended, database-only stays. That last rule is the difference between
     * merge and replace, and what makes merge safe against a production
     * collection — nothing it does can orphan values.
     *
     * @param array $stored
     * @param array $incoming
     *
     * @return array
     */
    private static function mergeFields(array $stored, array $incoming)
    {
        $fields = [];
        $seen = [];

        foreach ($incoming as $field) {
            $fields[] = $field;
            $seen[$field['key']] = true;
        }

        foreach ($stored as $field) {
            if (!isset($seen[$field['key']])) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * What applying a file will do to fields that already hold values. Not
     * refusals — the person importing may mean both — but not things to find out
     * about later: a changed type is read against the new type from now on, and
     * replace removes a field outright, orphaning whatever it held.
     *
     * @param array   $stored
     * @param array   $incoming
     * @param boolean $replace
     * @param string  $label   the collection's name, for the message
     *
     * @return string[]
     */
    private static function changes(array $stored, array $incoming, $replace, $label)
    {
        $warnings = [];
        $byKey = [];

        foreach ($incoming as $field) {
            $byKey[$field['key']] = $field;
        }

        foreach ($stored as $field) {
            $next = $byKey[$field['key']] ?? null;

            if ($next && $next['type'] !== $field['type']) {
                $warnings[] = sprintf(
                    /* translators: 1: collection name, 2: field name, 3: old type, 4: new type */
                    __(
                        '%1$s: “%2$s” changed from %3$s to %4$s. Values that do not fit the new type will read as empty.',
                        'schemapress'
                    ),
                    $label,
                    $field['label'],
                    self::typeLabel($field['type']),
                    self::typeLabel($next['type'])
                );
            }

            if (!$next && $replace) {
                $warnings[] = sprintf(
                    /* translators: 1: collection name, 2: field name */
                    __('%1$s: “%2$s” was removed, and the values stored under it are no longer reachable.', 'schemapress'),
                    $label,
                    $field['label']
                );
            }
        }

        return $warnings;
    }

    /**
     * Fields the file has whose type this site does not. Normalizing drops them
     * without a word; the word is here.
     *
     * @param array  $raw   the file's definition, before normalizing
     * @param string $label
     *
     * @return string[]
     */
    private static function unknownTypes(array $raw, $label)
    {
        $warnings = [];

        foreach (isset($raw['fields']) && is_array($raw['fields']) ? $raw['fields'] : [] as $field) {
            $type = is_array($field) ? sanitize_key((string) ($field['type'] ?? '')) : '';

            if ($type !== '' && !FieldTypes::exists($type)) {
                $warnings[] = sprintf(
                    /* translators: 1: collection name, 2: field name, 3: a field type slug */
                    __(
                        '%1$s: “%2$s” is a %3$s field, which this site does not have, so it was left out.',
                        'schemapress'
                    ),
                    $label,
                    sanitize_text_field((string) ($field['label'] ?? $field['key'] ?? '')),
                    $type
                );
            }
        }

        return $warnings;
    }

    /**
     * A field type's human name.
     *
     * @param string $type
     *
     * @return string
     */
    private static function typeLabel($type)
    {
        $definition = FieldTypes::get($type);

        return $definition ? $definition['label'] : $type;
    }

    /**
     * Gives a new collection the plural from the file, if nothing else uses it.
     * The plural is an address, so it cannot be one another collection already
     * answers on; when the file's is taken, one is derived instead.
     *
     * @param integer $id
     * @param string  $plural
     *
     * @return void
     */
    private static function claimPlural($id, $plural)
    {
        $taken = [];

        foreach (ContentType::all() as $type) {
            if ($type['id'] === (int) $id) {
                continue;
            }

            $taken[] = $type['key'];
            $taken[] = $type['plural'];
        }

        if ($plural !== '' && !in_array($plural, $taken, true)) {
            update_post_meta($id, ContentType::META_PLURAL, $plural);

            return;
        }

        ContentType::flush();
        ContentType::plural($id);
    }

    /**
     * Restores a collection's entries.
     *
     * @param integer $type_id
     * @param array   $entries
     * @param array   $media   by reference
     *
     * @return array{0: integer, 1: array} how many were written, and what was
     *                                     skipped and why
     */
    private static function importEntries($type_id, array $entries, array &$media)
    {
        $definition = SchemaRepository::definition($type_id);
        $written = 0;
        $skipped = [];

        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['values']) || !is_array($entry['values'])) {
                continue;
            }

            $uid = (string) ($entry['id'] ?? '');
            $title = (string) ($entry['title'] ?? $uid);

            // an entry this site has already thrown away. restoring it from a
            // file would undo a deletion somebody made here on purpose, and
            // re-creating it beside the trashed one would leave two entries
            // with one identifier — the moment the trashed one was restored
            if ($uid !== '' && self::trashed($type_id, $uid)) {
                $skipped[] = [
                    'id' => $uid,
                    'message' => sprintf(
                        /* translators: %s: an entry's title */
                        __('“%s” is in this site’s trash, so it was left there.', 'schemapress'),
                        $title
                    ),
                ];

                continue;
            }

            $existing = $uid !== '' ? Entries::get($type_id, $uid, 0, Entries::DRAFT) : null;

            // BEFORE the save, not after: the image sanitizer accepts any id
            // that is an image on this site, so a foreign id that happens to
            // exist here would be stored as somebody else's photograph
            $values = self::attachments($entry['values'], $definition['fields'], function ($id) use (&$media) {
                return self::localAttachment($id, $media);
            });

            $saved = Entries::save($type_id, $existing ? $uid : null, [
                'values' => $values,
                'title' => (string) ($entry['title'] ?? ''),
                'publish' => ($entry['status'] ?? 'draft') === 'publish',
            ]);

            // an entry the destination refuses — a unique value one of this
            // site's own entries already holds, a required field the schema
            // gained since the export — is skipped rather than aborting the
            // import. one bad row should not cost the other nine hundred
            if (!$saved || is_wp_error($saved)) {
                $skipped[] = [
                    'id' => $uid,
                    'message' => is_wp_error($saved)
                        ? sprintf('%s — %s', $title, $saved->get_error_message())
                        : $title,
                ];

                continue;
            }

            // the identifier is carried over so a re-import updates rather than
            // duplicates, and so a link somebody already published still works
            if ($uid !== '' && !$existing) {
                self::adoptUid($type_id, $saved['id'], $uid);
            }

            $written++;
        }

        return [$written, $skipped];
    }

    /**
     * Whether an entry identifier is in a collection's trash.
     *
     * @param integer $type_id
     * @param string  $uid
     *
     * @return boolean
     */
    private static function trashed($type_id, $uid)
    {
        $type = ContentType::get($type_id);

        return (bool) get_posts([
            'post_type' => $type['postType'],
            'post_status' => 'trash',
            'numberposts' => 1,
            'fields' => 'ids',
            'meta_key' => Entries::META_UID,
            'meta_value' => $uid,
            'suppress_filters' => false,
        ]);
    }

    /**
     * Gives a freshly created entry the identifier it had in the export.
     *
     * @param integer $type_id
     * @param string  $minted the uuid save() gave it
     * @param string  $uid    the uuid it should have
     *
     * @return void
     */
    private static function adoptUid($type_id, $minted, $uid)
    {
        $type = ContentType::get($type_id);

        $found = get_posts([
            'post_type' => $type['postType'],
            'post_status' => ['publish', 'draft'],
            'numberposts' => 1,
            'meta_key' => Entries::META_UID,
            'meta_value' => $minted,
            'suppress_filters' => false,
        ]);

        if (isset($found[0])) {
            update_post_meta($found[0]->ID, Entries::META_UID, $uid);
        }
    }

    // --- media ---------------------------------------------------------------

    /**
     * Visits every attachment id in a value bag, replacing each with what the
     * callback returns — export collects with it, import remaps with it.
     *
     * A gallery keeps the ids that came back and drops the rest, because a hole
     * in one is a broken image mid-slideshow. Groups and repeater rows are walked
     * into, which is where most images in a real schema live.
     *
     * @param array    $values
     * @param array    $fields
     * @param callable $map    int => int|null
     *
     * @return array
     */
    private static function attachments(array $values, array $fields, callable $map)
    {
        foreach ($fields as $field) {
            $key = $field['key'];

            if (!array_key_exists($key, $values)) {
                continue;
            }

            $value = $values[$key];

            if (FieldTypes::isRepeatable($field['type'])) {
                $rows = [];

                foreach (is_array($value) ? $value : [] as $row) {
                    if (is_array($row) && isset($row['values']) && is_array($row['values'])) {
                        $row['values'] = self::attachments($row['values'], $field['fields'], $map);
                    }

                    $rows[] = $row;
                }

                $values[$key] = $rows;

                continue;
            }

            if (FieldTypes::hasChildren($field['type'])) {
                $values[$key] = self::attachments(is_array($value) ? $value : [], $field['fields'], $map);

                continue;
            }

            switch ($field['type']) {
                case 'image':
                case 'file':
                    $id = absint(is_array($value) ? ($value['id'] ?? 0) : $value);
                    $values[$key] = $id ? $map($id) : null;
                    break;

                case 'gallery':
                    $kept = [];

                    foreach (is_array($value) ? $value : [] as $item) {
                        $id = absint(is_array($item) ? ($item['id'] ?? 0) : $item);
                        $local = $id ? $map($id) : null;

                        if ($local) {
                            $kept[] = $local;
                        }
                    }

                    $values[$key] = $kept;
                    break;
            }
        }

        return $values;
    }

    /**
     * The attachment on this site that an exported id referred to.
     *
     * With a manifest: by uploads path first, then by filename when exactly one
     * attachment has it. Two attachments called photo.jpg is a guess, not a
     * match, and a guess is what this exists to stop.
     *
     * Without a manifest, an id is trusted only when the file says it came from
     * this very site — id 482 is always something on a site with its own uploads.
     *
     * @param integer $id
     * @param array   $media by reference: manifest, sameSite, cache, counts
     *
     * @return integer|null
     */
    private static function localAttachment($id, array &$media)
    {
        $key = (string) absint($id);

        if (array_key_exists($key, $media['cache'])) {
            return $media['cache'][$key];
        }

        $local = null;

        if (is_array($media['manifest'])) {
            $described = isset($media['manifest'][$key]) && is_array($media['manifest'][$key])
                ? $media['manifest'][$key]
                : [];

            $local = self::findAttachment((string) ($described['file'] ?? ''));
        } elseif ($media['sameSite'] && get_post_type((int) $key) === 'attachment') {
            $local = (int) $key;
        }

        // counted per distinct attachment rather than per use, so a
        // placeholder image shared by two hundred entries is one missing file
        // in the report rather than two hundred
        $media['cache'][$key] = $local;
        $local ? $media['matched']++ : $media['missing']++;

        return $local;
    }

    /**
     * Finds an attachment by the file it holds.
     *
     * @param string $file uploads-relative, e.g. 2026/09/photo.jpg
     *
     * @return integer|null
     */
    private static function findAttachment($file)
    {
        if ($file === '') {
            return null;
        }

        $exact = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'any',
            'numberposts' => 1,
            'fields' => 'ids',
            'meta_key' => '_wp_attached_file',
            'meta_value' => $file,
            'suppress_filters' => false,
        ]);

        if ($exact) {
            return (int) $exact[0];
        }

        $name = self::filenameOf($file);

        if ($name === '') {
            return null;
        }

        // LIKE finds candidates — it also finds myphoto.jpg for photo.jpg —
        // and the comparison below decides
        $candidates = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'any',
            'numberposts' => 20,
            'fields' => 'ids',
            'meta_query' => [
                ['key' => '_wp_attached_file', 'value' => $name, 'compare' => 'LIKE'],
            ],
            'suppress_filters' => false,
        ]);

        $matches = array_values(array_filter($candidates, function ($candidate) use ($name) {
            return self::filenameOf((string) get_post_meta($candidate, '_wp_attached_file', true)) === $name;
        }));

        return count($matches) === 1 ? (int) $matches[0] : null;
    }

    /**
     * Not basename(), which is locale-sensitive and mangles a leading multibyte
     * character — and a filename is exactly where somebody's own alphabet shows.
     *
     * @param string $file
     *
     * @return string
     */
    private static function filenameOf($file)
    {
        $file = (string) $file;
        $slash = strrpos($file, '/');

        return $slash === false ? $file : substr($file, $slash + 1);
    }

    /**
     * Whether a document was exported from this site.
     *
     * @param string $site
     *
     * @return boolean
     */
    private static function sameSite($site)
    {
        return $site !== '' && rtrim($site, '/') === rtrim(home_url(), '/');
    }

    // --- components ----------------------------------------------------------

    /**
     * Creates or updates one component, matched by its name — a component has no
     * machine key, so the label is the only identity it has. That makes the match
     * weaker evidence than a collection's, which is why merge is honored here
     * too rather than a file's "Address" overwriting the site's own.
     *
     * @param mixed   $component
     * @param boolean $replace
     *
     * @return boolean
     */
    private static function importComponent($component, $replace)
    {
        $component = is_array($component) ? $component : [];
        $label = sanitize_text_field((string) ($component['label'] ?? ''));

        if ($label === '') {
            return false;
        }

        $existing = 0;

        foreach (Component::all() as $one) {
            if ($one['label'] === $label) {
                $existing = $one['id'];
                break;
            }
        }

        $id = $existing ?: wp_insert_post([
            'post_type' => Component::POST_TYPE,
            'post_title' => $label,
            'post_excerpt' => sanitize_textarea_field((string) ($component['description'] ?? '')),
            'post_status' => 'publish',
        ], true);

        if (is_wp_error($id)) {
            return false;
        }

        $incoming = SchemaModel::normalize([
            'fields' => isset($component['fields']) && is_array($component['fields'])
                ? $component['fields']
                : [],
        ])['fields'];

        SchemaRepository::saveDefinition($id, [
            'fields' => $existing && !$replace
                ? self::mergeFields(SchemaRepository::definition($id)['fields'], $incoming)
                : $incoming,
        ]);

        return true;
    }

    /**
     * The collection holding a machine key, if this site has one.
     *
     * @param string $key
     *
     * @return integer 0 when nothing matches
     */
    private static function findByKey($key)
    {
        foreach (ContentType::all() as $type) {
            if ($type['key'] === $key) {
                return $type['id'];
            }
        }

        return 0;
    }

    /**
     * A filename for an export, naming the site and the day.
     *
     * @param integer[] $type_ids
     *
     * @return string
     */
    public static function filename(array $type_ids = [])
    {
        $host = wp_parse_url(home_url(), PHP_URL_HOST) ?: 'site';
        $what = count($type_ids) === 1 ? 'collection' : 'schema';

        return sprintf('schemapress-%s-%s-%s.json', sanitize_key($host), $what, gmdate('Y-m-d'));
    }
}
