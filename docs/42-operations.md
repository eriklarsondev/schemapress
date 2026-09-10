<!-- group: Extending -->
<!-- description: Background jobs on large collections, the WP-CLI commands, and what happens to your content when the plugin is deleted. -->

## Running it

### Work that does not fit in a request

Three things walk every entry of a collection: rebuilding the filter index after a field
changes, deleting a collection, and minting identifiers for entries that predate having any.

On a collection you are still building, that is instant. On one with fifty thousand rows it
is a request that does not finish — and worse, one that half-finishes, leaving the index
rebuilt for the entries it got through and stale for the rest.

So above **200 entries** the work becomes a **job**: a cursor that survives the request,
picked up by WP-Cron and carried on from where it stopped. A banner in the admin says what
is running and how far along it is, and disappears when the queue drains.

:::caution Filters are stale until a reindex finishes
Rename a field on a large collection and the save returns immediately, but filtering and
sorting by that field give the wrong answer until the job completes. The banner is there to
say so. Reads of whole entries are unaffected — the JSON document is the record, and the
index is only a mirror of it.
:::

If WP-Cron is disabled on your site, nothing drains the queue on its own. Run it yourself:

```bash
wp schemapress jobs          # what is queued
wp schemapress jobs --run    # work through it now
```

### WP-CLI

```bash
wp schemapress list                              # the collections on this site
wp schemapress reindex                           # rebuild every filter index
wp schemapress reindex --collection=team_member
wp schemapress backfill                          # give old entries their identifiers
wp schemapress jobs --run                        # drain the queue
wp schemapress export > schema.json
wp schemapress import schema.json
wp schemapress trash --collection=team_member --yes   # empty one collection's trash
```

`reindex` runs to **completion** rather than queuing, which is what makes it usable in a
deploy step: the command exits when the index is current, so the step after it can rely on
that.

### Capabilities

Two, and they are the plugin's own:

| | Capability | Granted to |
| --- | --- | --- |
| **Content manager** | `schemapress_edit_content` | Editors and administrators |
| **Builder** | `schemapress_manage_schema` | Administrators |

They are ordinary WordPress capabilities, so a role plugin can move them wherever you like:

```php
// let a custom role build schemas, without giving it the site
add_action('init', function () {
    get_role('content_lead')?->add_cap('schemapress_manage_schema');
});
```

:::note These used to be borrowed, and that was the problem
They were `edit_pages` and `manage_options`. Borrowing meant they could not be widened
without widening everything else those capabilities govern — the old advice for letting an
editor build schemas was to grant them `manage_options`, which is the entire site. Now
`schemapress_manage_schema` grants exactly what its name says, and taking it off an
administrator leaves the rest of their administration intact.
:::

A collection can also name the **roles** allowed to edit its entries, in its Settings
dialog. Empty means anyone who can edit content, which is what every collection is until
you say otherwise.

### Deleting the plugin

Deactivating changes nothing about your content. It clears the job queue — there is nothing
listening to run it — and leaves every collection, entry and setting where it is.

**Deleting** the plugin from the Plugins screen is the question, and the answer is a setting:

| `Delete all content when uninstalled` | On delete |
| --- | --- |
| **Off** (default) | Everything stays. Reinstalling picks it back up |
| **On** | Every collection, entry, index row and option is erased |

:::warning There is no undo, and no confirmation at the moment it happens
WordPress does not ask again when it runs an uninstaller. The decision is made in advance,
on the Settings screen, which is why turning it on asks you there instead. Export your
schema before you turn it on.
:::

Off is the default because deleting a plugin is often how somebody reinstalls it, moves it,
or clears a broken update — none of which is a decision to destroy the content.

On a multisite network, the uninstaller runs once per site, and each site's own setting
decides its own answer.
