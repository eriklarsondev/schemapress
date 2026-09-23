<!-- group: Extending -->
<!-- description: The actions fired as entries change, as jobs and imports run, and the filters that add field types, datasets and lists. -->

## Hooks

### Entry lifecycle

Seven actions, fired as content moves. They are what a cache purge, a search index, a
static rebuild or an outbound webhook hangs on.

| Action | Arguments | Fires when |
| --- | --- | --- |
| `schemapress/entry_saved` | `$id, $values, $type_id, $created` | Any save, published or not |
| `schemapress/entry_published` | `$id, $values, $type_id` | Values become what the site serves |
| `schemapress/entry_unpublished` | `$id, $type_id` | An entry comes off the front end |
| `schemapress/entry_discarded` | `$id, $type_id` | A draft is thrown away |
| `schemapress/entry_deleted` | `$id, $type_id` | An entry is trashed |
| `schemapress/entry_restored` | `$id, $type_id` | An entry comes back out of the trash |
| `schemapress/entry_purged` | `$id, $type_id` | An entry is about to be erased for good |

`$id` is the **post id**, not the entry's public uuid. `$values` is the stored value bag,
before resolution — an image is still an attachment id there.

**`entry_published` is the one you usually want.** It fires whether publishing happened
through the Publish action or through a plain save on a collection that keeps no drafts,
and it fires only when something actually moved onto the front end. `entry_saved` fires on
every keystroke's worth of work being written, most of which the public never sees.

```php
add_action('schemapress/entry_published', function ($id, $values, $type_id) {
    wp_remote_post('https://example.test/api/revalidate', [
        'blocking' => false,
        'body' => ['collection' => SchemaPress\ContentType::key($type_id)],
    ]);
}, 10, 3);
```

:::caution Trashed, not erased
`entry_deleted` fires after the entry is moved to the trash, so the post and its meta are
still readable. If you need to know what the entry held, read it inside the handler — do
not defer.
:::

**`entry_deleted` and `entry_purged` are two different moments.** Trashing is reversible and
fires `entry_deleted`; the entry is still there, and `entry_restored` says it came back.
Erasing is not, and fires `entry_purged` — **before** the row goes, which is deliberate: it
is the last moment the values can be read at all. Emptying a collection's trash fires it
once per entry.

A cache that keys on entries wants both ends: `entry_published` to warm, `entry_deleted`
and `entry_purged` to drop.

### Definitions

| Action | Arguments | Fires when |
| --- | --- | --- |
| `schemapress/definition_saved` | `$type_id, $definition` | A collection's shape changes |

The definition passed is the normalized one — what was actually stored, not what was sent.

### The site itself

Five more, for the things that happen to a site rather than to one entry.

| Action | Arguments | Fires when |
| --- | --- | --- |
| `schemapress/imported` | `$report, $payload` | An import finishes — see **Export and import** |
| `schemapress/job_queued` | `$id, $job, $args` | Work too big for a request is queued |
| `schemapress/job_finished` | `$id, $job` | That work drains — see **Running it** |
| `schemapress/settings_saved` | `$settings` | The Settings screen is saved |
| `schemapress/upgraded` | `$version, $minted, $from` | The plugin's stored version moves |

`$report` is the same summary the admin shows — what was created, updated and skipped —
and `$payload` is the document it was read from, so a handler can see what was asked for as
well as what happened.

`$from` on `schemapress/upgraded` is the version that was stored before, or `false` on a
fresh install, which is how a handler tells an upgrade from a first activation. `$minted`
is how many identifiers were backfilled on the spot; a large collection is queued instead,
so a site watching for that finishing wants `job_finished` rather than this.

```php
// rebuild a static front end whenever a queued reindex or delete completes
add_action('schemapress/job_finished', function ($id, $job) {
    if (($job['job'] ?? '') === 'reindex') {
        wp_remote_post('https://example.test/api/rebuild', ['blocking' => false]);
    }
}, 10, 2);
```

### Filters

| Filter | Adds |
| --- | --- |
| `schemapress/field_types` | A field type: how a value is defaulted and sanitized |
| `schemapress/datasets` | A ready-made option list a Dropdown can draw from |
| `schemapress/docs/files` | A Markdown page on this screen |

A site with its own closed vocabulary registers it once and every Dropdown can use it, with
the guarantee the built-in lists have: stored once, corrected in one place.

```php
add_filter('schemapress/datasets', function ($sets) {
    $sets['departments'] = [
        'label' => 'Departments',
        'options' => [
            ['value' => 'eng', 'label' => 'Engineering'],
            ['value' => 'design', 'label' => 'Design'],
        ],
    ];

    return $sets;
});
```

:::note Adding a field type touches four places
The registry here, the resolver if it stores something other than what it renders, the
control in `src/shared/fields/`, and the index if it should be filterable. A type
registered in PHP alone will store and deliver correctly but has no control to edit it
with.
:::

`schemapress/docs/files` takes the list of Markdown paths behind this screen, so a plugin
built on top of this one can put its own page in the sidebar beside these. Ordering is by
filename, and a page declares its own group and description the way these do:

```php
add_filter('schemapress/docs/files', function ($paths) {
    $paths[] = __DIR__ . '/docs/60-our-conventions.md';

    return $paths;
});
```

What a filtered page contributes is rendered through the same allow-list as everything
else here, so it can add a topic but not a script.
