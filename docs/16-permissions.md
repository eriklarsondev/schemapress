<!-- group: Modelling content -->
<!-- description: Who may fill entries in, who may change the shape of them, and why those are different jobs. -->

## Who can do what

Two capabilities, because filling in a Team Member and deciding what a Team Member **is**
are different jobs at very different blast radii.

| | Capability | Typically |
| --- | --- | --- |
| **Content manager** | `edit_pages` | Editor and above |
| **Builder** | `manage_options` | Administrator |

### Content manager

Opens SchemaPress, reads every collection, and does everything to entries: create, edit,
save, publish, unpublish, discard a draft, trash.

They see the **Entries** tab and nothing that could reshape what is underneath it.

### Builder

Everything above, plus the things that change what content *is*:

- Creating, renaming and deleting collection types
- Editing a collection's fields, and its form layout
- A collection's settings — drafts, the title field, its Public API switches
- Creating, editing and deleting components
- The site's **Settings** screen, including the REST Content API master switch

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

Both are ordinary WordPress capabilities, so a role plugin can grant them however you like
— `manage_options` is a default rather than a requirement.

```php
// let editors build schemas too
add_filter( 'user_has_cap', function ( $caps, $required, $args, $user ) {
    if ( in_array( 'editor', (array) $user->roles, true ) ) {
        $caps['manage_options'] = true;
    }

    return $caps;
}, 10, 4 );
```

:::note The screens follow the transport, not the other way round
Every route checks for itself. What the capability sends to the browser only stops the
admin *offering* what would be refused — it is not the check.
:::
