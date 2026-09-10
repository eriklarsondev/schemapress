<!-- group: Modeling content -->
<!-- description: Who may fill entries in, who may change the shape of them, and why those are different jobs. -->

## Who can do what

Two capabilities, because filling in a Team Member and deciding what a Team Member **is**
are different jobs at very different blast radii.

| | Capability | Granted on activation to |
| --- | --- | --- |
| **Content manager** | `schemapress_edit_content` | Editors and administrators |
| **Builder** | `schemapress_manage_schema` | Administrators |

### Content manager

Opens SchemaPress, reads every collection, and does everything to entries: create, edit,
save, publish, unpublish, discard a draft, duplicate, trash and restore from the trash.

They cannot erase a trashed entry permanently, or empty a trash. Those are the two actions
in the entries screen with no way back, so they sit with the builder.

They see the **Entries** tab and nothing that could reshape what is underneath it.

### Builder

Everything above, plus the things that change what content *is*:

- Creating, renaming and deleting collection types
- Editing a collection's fields, and its form layout
- A collection's settings — drafts, the title field, who can edit it, its Public API switches
- Creating, editing and deleting components
- Erasing trashed entries permanently, and emptying a trash
- Exporting and importing schemas
- The site's **Settings** screen, including the REST Content API master switch and whether
  uninstalling erases the content

The **Schema** and **Form** tabs, the collection **Settings** button and the sidebar's
Settings entry only appear for a builder.

:::caution Why these are separate
Renaming a field orphans every value stored under it. Deleting a collection deletes every
entry in it, permanently. Turning on the content API publishes to the internet. None of
those is a thing somebody should do by accident on their way to fixing a typo in a bio.

This mirrors Strapi, where the Content-Type Builder and the Content Manager are separate
permissions for the same reason.
:::

### Changing the bar

Both are ordinary WordPress capabilities, so a role plugin can move them wherever you like —
or you can add one directly:

```php
// let editors build schemas too
add_action( 'init', function () {
    get_role( 'editor' )?->add_cap( 'schemapress_manage_schema' );
} );
```

:::note These are the plugin's own, and that matters
They used to be `edit_pages` and `manage_options` borrowed wholesale, and the advice here
used to be to grant editors `manage_options` — which hands over the entire site's settings,
users and plugins in order to let somebody add a field.

Borrowing a capability means you cannot widen it without widening everything else it
governs, and cannot narrow it at all. `schemapress_manage_schema` grants exactly what its
name says. Removing it from an administrator leaves the rest of their administration
intact.
:::

### Per collection

A collection can name the roles allowed to edit **its** entries, under **Who can edit
these** in its settings dialog. A Grants collection can belong to finance while News belongs
to comms.

Naming no roles — which is what every collection starts as — means anyone with
`schemapress_edit_content`. Naming some narrows it to those, plus anyone who can manage
schemas: somebody able to delete the collection outright is not meaningfully kept out of its
entries.

A role that does not exist on this site matches nobody, which fails closed. That is
deliberate, so a collection imported from an install with a `finance` role keeps its
restriction rather than quietly opening to everyone.

:::note The screens follow the transport, not the other way round
Every route checks for itself. What the capability sends to the browser only stops the
admin *offering* what would be refused — it is not the check.
:::
