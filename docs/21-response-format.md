<!-- group: Content API -->
<!-- description: The data and meta envelope, the shape of an entry, and what each field type becomes in JSON. -->

## Response format

### The envelope

Every response is `data` plus `meta`, so a client unwraps the same shape whichever route it
called.

A list:

```json
{
  "data": [
    {
      "id": "86a8d140-…",
      "full_name": "Ada Lovelace"
    }
  ],
  "meta": {
    "pagination": {
      "page": 1,
      "pageSize": 25,
      "pageCount": 1,
      "total": 3
    }
  }
}
```

A single entry — same envelope, `data` is an object, `meta` is empty:

```json
{
  "data": {
    "id": "86a8d140-…",
    "full_name": "Ada Lovelace"
  },
  "meta": {}
}
```

`data` is never `null`. A list with no matches is `[]`; a single entry that does not exist
is a `404`, not a body with `null` in it.

### An entry

Fields sit **beside** the identifiers rather than under an `attributes` envelope:

```json
{
  "id": "86a8d140-4ecb-442f-b9e9-debfc82404a3",
  "title": "Ada Lovelace",
  "slug": "ada-lovelace",
  "full_name": "Ada Lovelace",
  "role": "Engineer",
  "company": "Analytical",
  "avatar": {
    "url": "…",
    "alt": "Ada Lovelace",
    "width": 1200,
    "height": 800,
    "sizes": {}
  },
  "updatedAt": "2026-09-06 19:41:35",
  "publishedAt": "2026-09-06 19:41:35"
}
```

Three keys are always present and always mean the same thing:

| Key | |
| --- | --- |
| `id` | A uuid, stable for the life of the entry |
| `updatedAt` / `publishedAt` | GMT timestamps |

Everything else is one of your fields, under the machine key you gave it.

### Titles and slugs

WordPress needs a title for every row it stores, so one is always derived — but it is **not
in the response** unless the collection says which field names its entries.

**Settings → Names its entries by.** Pick a field and it becomes the entry's name: its
value is written straight to the WordPress title, in full rather than summarised, so the
two cannot disagree. `slug` joins the response because it now derives from something real.

Leave it as **None** and neither `title` nor `slug` appears. That is the right answer for a
collection you would never list by name — a set of settings, a group of link rows.

:::caution Why not just always include it
With no field nominated, the derived title comes from whichever text field happens to come
first in the schema. Reorder the fields and every entry silently gets a new title, and a
new slug with it. That is an artifact of how entries are stored, not something the content
says, so it is kept out of the API.
:::

:::tabs
```json Named by nothing
{
  "id": "86a8d140-…",
  "full_name": "Ada Lovelace",
  "role": "Engineer",
  "updatedAt": "2026-09-06 22:41:15",
  "publishedAt": "2026-09-06 22:41:15"
}
```
```json Named by full_name
{
  "id": "86a8d140-…",
  "slug": "ada-lovelace",
  "full_name": "Ada Lovelace",
  "role": "Engineer",
  "updatedAt": "2026-09-06 22:41:15",
  "publishedAt": "2026-09-06 22:41:15"
}
```
:::

The name arrives under its own field key — `full_name` above — not as a separate `title`.
There is one value, and it is the field you nominated.

Only fields that can be a name are offered: text, textarea, email, URL, phone, number and
dropdown. An image cannot name anything, a repeater is many things, and rich text is a
document rather than a name. A field named `title` is used without being nominated, since
it is already saying so — and deleting the field a collection was named by returns it to
None rather than leaving it pointing at nothing.

### Identifiers

`id` is a generated uuid, not a row number. Putting it in a URL or a data attribute says
nothing about how many entries exist or what order they were created in, and it stays
stable if content is moved between installs.

### Values are resolved

An image is its attachment, not an id. A relation is the entries it points at, not their
ids. A client never holds an identifier it has to spend a second request on, and there is
no `populate` step to remember.

| Field type | JSON |
| --- | --- |
| Text, Textarea, Email, URL, Phone | `"a string"` |
| Rich text | `"<p>HTML</p>"` — shortcodes and paragraphs already applied |
| Number | `42.0`, or `null` |
| Toggle | `true` / `false` |
| Dropdown | `"value"`, or `["a", "b"]` when it allows several |
| Image, File | an object (below), or `null` |
| Link | `{ "url", "label", "target" }`, or `null` |
| Group | a nested object |
| Repeater | an array of `{ "id", "data": {…} }` |

An image or file:

```json
{
  "id": 42,
  "url": "https://…/ada.jpg",
  "alt": "Ada Lovelace",
  "title": "ada",
  "caption": "",
  "mime": "image/jpeg",
  "width": 1200,
  "height": 800,
  "sizes": {
    "thumbnail": {
      "url": "…",
      "width": 150,
      "height": 150
    },
    "medium": {
      "url": "…",
      "width": 300,
      "height": 200
    },
    "large": {
      "url": "…",
      "width": 1024,
      "height": 683
    }
  },
  "srcset": "https://…/ada-300.jpg 300w, https://…/ada-1024.jpg 1024w"
}
```

Every registered image size is in `sizes`, and `srcset` is ready to put straight into an
`<img>`. An empty image field is `null`, so check before reaching into it.

### What is never exposed

:::note Draft and publish
Only **published** content is served, ever.
:::

- A draft does not appear in a list.
- A draft requested by its id returns `404`. Knowing the id is not permission to read it.
- An entry with unpublished edits is served as it was **last published**, not as it is
  being edited.

Three keys the builder uses are deliberately absent: `values` (the unresolved bag), `state`
and `ahead`. They describe the editing of an entry, which is not the public's business.
