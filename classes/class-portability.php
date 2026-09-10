<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * collections, as a file.
 *
 * a collection's definition lived in exactly one place: a JSON string in one
 * meta row of one database. that is a fine place to keep it and the only place
 * it was, which made three ordinary things impossible.
 *
 * you could not put a schema in version control, so "when did this field become
 * required" had no answer. you could not move one from staging to production
 * without rebuilding it by hand in a second admin and hoping the field keys came
 * out the same — and they would not, because a key is derived from a label and
 * the labels get retyped. and you could not seed a fresh install, so every
 * developer on a project started from an empty SchemaPress.
 *
 * Strapi keeps its content types as files in the repository and this does not,
 * for a good reason — a definition here is edited through a UI by people who do
 * not have the repository. so the file is an EXPORT rather than the source of
 * truth: the database stays authoritative, and this is how a copy of it travels.
 *
 * THE KEY IS THE IDENTITY. an import matches an existing collection by its
 * machine key, never by its title or its post id, because the key is the one
 * thing that is stable across two installations — it is what the post type is
 * named after and what a template refers to. a title is what somebody typed and
 * a post id is a row number in a database this file has left.
 *
 * WHAT AN IMPORT MAY TOUCH. only this plugin's own post types — the schema and
 * component posts, and each collection's entry post type. a site's pages, posts
 * and every other plugin's content are never read, written or deleted. the one
 * thing an import shares with the rest of the site is the MEDIA LIBRARY, and
 * that is why attachment ids are matched against it by file rather than trusted:
 * on a site with its own uploads, id 482 already exists and is somebody else's
 * photograph.
 */
class Portability
{
    /**
     * the format's own version, so an importer can refuse a file from a future
     * it does not understand rather than half-reading it.
     */
    const FORMAT = 1;

    /**
     * how many entries one export carries before it is refused.
     *
     * an export is one JSON document held in memory and handed to a browser.
     * that is the right shape for a schema and for the seed data a fresh install
     * wants, and the wrong shape for a backup of a collection with a hundred
     * thousand rows in it — which is what a database backup is for, and what
     * this would silently fail at.
     */
    const MAX_ENTRIES = 5000;

    /**
     * WordPress's own ceiling on a post type name, which the entry post type —
     * `spc_` and the collection's key — has to fit under.
     */
    const POST_TYPE_LIMIT = 20;

    /**
     * one or more collections as a portable document.
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
     * every entry of a collection, in the shape import() reads back.
     *
     * STORED values, not resolved ones. an image is its attachment id here, and
     * the id means nothing anywhere but this site — so every id an entry refers
     * to is also collected into `$media`, and the export carries a manifest
     * saying which FILE each one was. that is what an import matches against.
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
     * which file each exported attachment id was.
     *
     * the uploads-relative path is the identity that survives a move — it is
     * the same on a staging site and a production site whose uploads were
     * synced, and on this site when a file is read back into it. the URL is
     * carried too, for a person reading the file, and is never trusted.
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
     * every component, with its fields.
     *
     * components always travel, whichever collections were asked for. they are
     * small, and a collection whose fields came from one is more useful beside
     * the component it was built from than without it.
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
     * restores collections from an exported document.
     *
     * the whole document is checked BEFORE anything is written. the first
     * version of this wrote as it read, so a file whose fourth collection was
     * unusable left the first three imported and the rest not, with an error
     * that said nothing about the half that had already happened. every refusal
     * here is now either up front, with nothing changed, or per entry and
     * reported.
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
         * fires after an import has been applied.
         *
         * @param array $report
         * @param array $payload the document that was read
         */
        do_action('schemapress/imported', $report, $payload);

        return $report;
    }

    /**
     * every reason a document cannot be imported, found before anything is
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
     * a 400 an import refuses with.
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
     * a list from the payload, defended against a document that says a list is
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
     * creates or updates one collection.
     *
     * nothing in here can fail the import: validate() has already refused
     * anything that would. what CAN go wrong is per entry, and is reported.
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
     * the settings a collection ends up with.
     *
     * the file's settings are about the SHAPE of the collection — which field
     * names an entry, which columns the table shows — and those it may set. two
     * are about THIS SITE, and a file does not get to decide them:
     *
     *   publicApi   what the site publishes to the internet. an import is not a
     *               decision to publish somebody else's content, and a staging
     *               export whose collections were open would otherwise open
     *               them here. a collection the import creates starts closed.
     *
     *   editRoles   who may edit the collection. merging a file used to take
     *               the file's answer, so importing a schema could quietly open
     *               a restricted collection to every editor — or lock out the
     *               team that owns it.
     *
     * merge also keeps whether the collection has drafts, because turning that
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
     * folds incoming fields into stored ones.
     *
     * a field the file names replaces the stored one of the same key; a field
     * only the file has is appended; a field only the database has stays. that
     * last rule is the whole difference between merge and replace, and it is
     * what makes merge safe to run against a production collection: nothing an
     * import does under it can orphan values.
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
     * what applying a file will do to fields that already hold values.
     *
     * not refusals — the person importing may well mean both — but neither is
     * something to find out about later. a field whose type changes has its
     * values read against the new type from now on, so "Engineer" stored in a
     * text field that became a dropdown without that option reads as nothing;
     * and replace removes a field outright, orphaning whatever it held.
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
     * fields the file has whose type this site does not.
     *
     * a site that registered a custom field type exports fields of it; a site
     * without that type cannot store them, and normalizing drops them without a
     * word. the word is here.
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
     * a field type's human name.
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
     * gives a new collection the plural from the file, if nothing else uses it.
     *
     * the plural is an address — the API answers on it — so it cannot be one
     * another collection already answers on, as its plural or its key. when the
     * file's is taken, one is derived here instead, the way a collection created
     * in the admin gets one.
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
     * restores a collection's entries.
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
     * whether an entry identifier is in a collection's trash.
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
     * gives a freshly created entry the identifier it had in the export.
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
     * visits every attachment id in a value bag, replacing each with what the
     * callback returns.
     *
     * one walker for both directions: export collects with it, import remaps
     * with it. an image or file becomes the returned id or null; a gallery keeps
     * the ids that came back and drops the rest, because a gallery with a hole
     * in it is a broken image in the middle of a slideshow. groups and repeater
     * rows are walked into, because that is where most images in a real schema
     * actually live.
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
     * the attachment on THIS site that an exported id referred to.
     *
     * with a manifest, the file is looked for: by its uploads path first, which
     * matches on the same site and on one whose uploads were synced, then by
     * its filename when exactly one attachment has it. two attachments called
     * photo.jpg is not a match, it is a guess, and a guess is the thing this
     * exists to stop.
     *
     * without a manifest — a hand-written file, or one that was edited — an id
     * is only trusted when the file says it came from this very site. from
     * anywhere else it is dropped, because the alternative is exactly the bug
     * this replaced: id 482 is always SOMETHING on a site with its own uploads.
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
     * finds an attachment by the file it holds.
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
     * the last segment of an uploads path.
     *
     * not basename(), which is locale-sensitive and mangles a leading multibyte
     * character — and a filename is exactly where somebody's own alphabet turns
     * up.
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
     * whether a document was exported from this site.
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
     * creates or updates one component, matched by its name.
     *
     * a component has no machine key — nothing stores content against it — so
     * the label is the only identity it has. that makes a match weaker evidence
     * than a collection's, and it is why merge is honored here too: the first
     * version of this replaced a same-named component's fields outright in
     * either mode, so a site's own "Address" was overwritten by a file's.
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
     * the collection holding a machine key, if this site has one.
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
     * a filename for an export, naming the site and the day.
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
