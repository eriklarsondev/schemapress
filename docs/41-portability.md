<!-- group: Extending -->
<!-- description: Moving collections between installations — the export format, what merge and replace do, and what an import will never do. -->

## Export and import

A collection's definition lives in one meta row of one database. That is a fine place to
keep it, and until there was a way to get it out it was the *only* place — so a schema could
not be committed, moved from staging to production, or used to seed a fresh checkout.

**Settings → Data** is that way out, and `wp schemapress export` is the same thing for a
script.

:::note The file is an export, not the source of truth
Strapi keeps content types as files in the repository. This does not, for a reason: these
definitions are edited through a UI, by people who do not have the repository. The database
stays authoritative and this is how a copy of it travels.
:::

### What is in the file

```json
{
  "schemapress": 1,
  "version": "0.2.0",
  "exportedAt": "2026-09-10T09:15:00Z",
  "site": "https://staging.example.com",
  "collections": [
    {
      "key": "team_member",
      "plural": "team_members",
      "label": "Team Member",
      "description": "The people we list on the about page.",
      "definition": { "version": 1, "settings": {}, "fields": [] },
      "entries": []
    }
  ],
  "components": []
}
```

`entries` is only present when **Include the entries** was ticked.

### The key is the identity

An import matches an existing collection by its **machine key** — never by its title, and
never by a post id. The key is the one thing stable across two installations: it is what the
post type is named after and what your templates refer to. A title is what somebody typed,
and a post id is a row number in a database the file has left.

The same is true of entries: an entry's uuid travels with it, so re-importing a file updates
the entries it made rather than making a second copy of each one — and a link somebody
already published still resolves.

### Merge and replace

| | A field the file does not mention |
| --- | --- |
| **Merge** (default) | Left alone |
| **Replace** | Deleted, and the values stored under it orphaned |

Merge is safe to run against a production collection: nothing it does can orphan stored
values. Replace is what you want when the file *is* the intended shape — a deploy from a
repository, say — and you have accepted that removing a field removes its content from
reach.

:::caution An import never turns on a public API
Whatever the file says, a collection's `publicApi` switches are left as this site had them —
and a collection the import creates arrives with both **off**. A staging export whose
collections were open would otherwise publish them here. Publishing is a decision about
*this* site.
:::

### What an import touches

**Only SchemaPress's own content.** The collection and component definitions, and each
collection's entries. Your pages, posts, menus, and every other plugin's content are never
read, written or deleted — by an import or an export. An entry's id is new on the
destination, and its slug can only collide with other entries of the same collection, never
with a page.

Before anything is written, the whole file is checked. A file that would do something unsafe
is refused with **nothing changed** — not the first three collections imported and the fourth
refused:

| Refused when | Because |
| --- | --- |
| A collection's key is too long | WordPress cannot store entries under a post type that long |
| The file names one collection twice | There is no telling which one was meant |
| A new collection's key is an address another collection already answers on | The API would answer with whichever it found first |
| Another plugin or the theme already uses the post type | Entries would be mixed into that content |

### What a file does not get to decide

Two settings describe **this site** rather than the collection's shape, and are always kept
as the destination has them:

- **Public API.** An import is not a decision to publish. A collection the import creates
  arrives with both switches off.
- **Who can edit these.** A file cannot open a restricted collection to every editor, or lock
  out the team that owns it.

Merge also keeps whether the collection has **drafts**. Replace takes the file's answer.

Components are matched by name, and merge is honored for them too: your own "Address"
component keeps any field the file does not mention.

### Entries

Restoring entries is a separate tick, offered only when the file carries them.

An entry the destination refuses — a unique value one of this site's own entries already
holds, a required field the schema gained since the export — is **skipped** rather than
aborting the import. So is an entry that is in this site's **trash**: importing does not
undo a deletion somebody made here on purpose. The report lists each one and why.

An entry that already exists here (same id) is **overwritten** with the file's values. That
is what restoring means, but it is not a merge — edits made on this site since the export are
replaced.

### Images and files

The media library is the one thing an import shares with the rest of your site, and an
attachment id is only a row number. On a site with its own uploads, id 482 already exists —
it is some other photograph.

So ids are **never trusted across sites**. An export with entries carries a manifest of the
file behind each id, and an import finds that file in this site's media library:

1. By its uploads path, `2026/09/photo.jpg` — which matches on the same site, and on one whose
   uploads were copied or synced.
2. Failing that, by its filename, **only if exactly one** attachment has it. Two files called
   `logo.png` is a guess, not a match.
3. Otherwise it is dropped. The image is empty and the report says how many were missing.

A missing image is visible and fixable; a wrong one is neither. Upload the files first and
the import finds them.

:::note Links and rich text are not rewritten
A Link field or a Rich Text field that points at `https://staging.example.com/...` still
points there after the import. Those are addresses somebody typed, and rewriting them would
mean guessing which of them were meant to move.
:::

### On the command line

```bash
# the whole schema, to a file you can commit
wp schemapress export > schema.json

# one collection, with its content
wp schemapress export --collection=team_member --entries > team.json

# read one back
wp schemapress import schema.json
wp schemapress import schema.json --mode=replace --entries

# or from a pipe
curl -s https://example.com/schema.json | wp schemapress import -
```

Exports go to **stdout** rather than to a file, so they compose — a shell redirect, a pipe
into `jq`, a commit in CI.

### Size

An export is one JSON document held in memory and handed over in one piece. That is the
right shape for a schema and for seed data, and the wrong shape for a backup of a collection
with a hundred thousand rows in it — so an export carrying more than **5,000 entries** is
refused rather than attempted. Use a database backup for that; it is what one is for.
