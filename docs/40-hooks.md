<!-- group: Extending -->
<!-- description: The actions fired as entries change, and the filters that add field types, datasets and lists. -->

## Hooks

### Entry lifecycle

Five actions, fired as content moves. They are what a cache purge, a search index, a
static rebuild or an outbound webhook hangs on.

| Action | Arguments | Fires when |
| --- | --- | --- |
| `schemapress/entry_saved` | `$id, $values, $type_id, $created` | Any save, published or not |
| `schemapress/entry_published` | `$id, $values, $type_id` | Values become what the site serves |
| `schemapress/entry_unpublished` | `$id, $type_id` | An entry comes off the front end |
| `schemapress/entry_discarded` | `$id, $type_id` | A draft is thrown away |
| `schemapress/entry_deleted` | `$id, $type_id` | An entry is trashed |

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

### Definitions

| Action | Arguments | Fires when |
| --- | --- | --- |
| `schemapress/definition_saved` | `$type_id, $definition` | A collection's shape changes |

The definition passed is the normalized one — what was actually stored, not what was sent.

### Filters

| Filter | Adds |
| --- | --- |
| `schemapress/field_types` | A field type: how a value is defaulted and sanitized |
| `schemapress/datasets` | A ready-made option list a Dropdown can draw from |
| `schemapress/elements` | An entry in the element palette |

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
