<!-- group: Modeling content -->
<!-- description: Every field a collection can hold, what each one stores, and what it becomes when you read it back. -->

## Field types

Twenty-one types, in five groups. What separates them is not how they look — that is your
theme's business — but **what they store** and **what can be asked of them**.

### Text

| Type | Stores | Notes |
| --- | --- | --- |
| **Text** | A single line | The usual choice for a name, a title, a reference |
| **Textarea** | Several lines, no markup | Line breaks survive; tags do not |
| **Rich Text** | HTML | Filtered through `wp_kses_post`, so it accepts what a post accepts |
| **Email** | A validated address | An address that will not validate is stored as nothing |
| **URL** | A validated address | Schemes WordPress refuses are dropped |
| **Phone** | Digits and punctuation | No format is imposed — there is no universal one |

:::note Why an invalid email is stored as nothing
The alternative is storing text that only *looks* like an address, which fails later,
somewhere else, in a mail queue. The rule is the same for URLs, dates and colors: a value
that is not the thing is not kept as an approximation of it.
:::

### Numbers, dates and choices

| Type | Stores | Notes |
| --- | --- | --- |
| **Number** | A float, or nothing | `min` and `max` are enforced on save; `step` only guides the control |
| **Date** | `2026-09-08` | A wall clock, not an instant — see below |
| **Date and time** | `2026-09-08T19:00:00` | |
| **Time** | `19:00:00` | |
| **Toggle** | `true` or `false` | Off is an answer, not an absence |
| **Dropdown** | One value, or a list | From your own options or a [dataset](#datasets) |
| **Color** | `#3b82f6` | Filterable and sortable |

:::note `step` is the control's, not the value's
`min` and `max` are refused on save, by the server as well as the form — see
**Validation**. `step` is the arrow key's increment on the number input and nothing more: a
value that does not land on it is still stored. If a quantity really must be a multiple of
something, say so with `min` and `max` on a field that counts the multiples.
:::

:::caution Dates are wall-clock, `publishedAt` is an instant
"The event starts at 19:00" means seven in the evening wherever the event is. Nothing is
converted to UTC on the way in, so an editor who types 19:00 sees 19:00 when they come
back. That is a different thing from `publishedAt` and `updatedAt`, which record a moment
and *are* in UTC.
:::

### Media

| Type | Stores | Resolves to |
| --- | --- | --- |
| **Image** | One attachment id | `{ id, url, alt, width, height, sizes, srcset }` |
| **Gallery** | A list of ids, in order | A list of those, in that order |
| **File** | One attachment id | The same shape, without the image parts |

Only the id is stored. Everything else lives in the media library, so replacing an image or
fixing its alt text reaches every entry that points at it without re-saving anything.

:::note Gallery is its own type, not a flag on Image
One image is something you replace; a gallery is a list you add to, take from and reorder,
and **the order is content** — it is the sequence a slideshow plays in. One image can also
be filtered on and a list of them cannot. A `multiple` flag would have made every consumer
branch on a config value to know which shape it was holding.
:::

### Structure

| Type | Stores | Notes |
| --- | --- | --- |
| **Link** | `{ url, label, target }` | Resolves to `null` when no URL is set |
| **Group** | A nested value bag | What a [component](#components) becomes when imported |
| **Repeater** | An ordered list of rows | Each row has a stable id, so reordering never re-keys one |
| **JSON** | Arbitrary decoded data | For shapes this collection should not try to describe |

:::tip When to reach for JSON
A third party's payload, a chart's series, anything whose structure belongs to something
other than this collection. It is stored **decoded**, so it comes back through the API as
JSON rather than as JSON inside a string. Invalid JSON is not stored.
:::

### Relations

**One type**, facing either way. "An author's articles" and "an article's author" are two
views of one relationship, not two kinds of field, so which way a relation faces is a
shape you pick in its settings rather than a type you had to choose before you knew which
collection you meant.

| Shape | Stores | |
| --- | --- | --- |
| **Holds one** | One entry's identifier | Several entries may hold the same one |
| **Holds one, exclusively** | The same, refused to anybody else | What a one-to-one is |
| **Holds several** | An ordered list of identifiers | The order is content |
| **Reads back** | Nothing at all | Lists whatever points at this entry |

The three that hold something store the identifier and nothing else, so correcting a name
on the entry at the other end corrects it everywhere that points at it — the same bargain
**Image** makes with the media library. **Reads back** stores nothing: the answer is a
query against the index, run when the entry is read.

:::note Why only one side stores anything
Writing both sides means two copies of one fact, and this plugin has four places where
copies come apart: a draft ahead of what is published, the trash, an import, and a bulk
action. An inverse that is derived cannot drift. It is also why a relation that reads back
is read-only in the admin — the reference lives on the *other* entry, and saving a draft of
one entry to change another is not something the entry form can promise to do safely.
:::

Every relationship a content API usually names separately is one or two of those shapes:

| What you want | How to say it |
| --- | --- |
| An article has one author | A relation on Article, **holds one** |
| …and an author has many articles | Plus a relation on Author, **reads back**, through that field |
| An article has several tags, a tag has many articles | **Holds several** on Article, **reads back** on Tag |
| A desk has one person and nobody else may have them | **Holds one, exclusively** |
| A link to one collection that nothing needs to walk backwards | Any of the first three, with nothing reading them back |

**Choosing a collection** comes first, then the shape. A relation that reads back asks one
more question — *which of that collection's relations* it is the other end of — because a
collection may point here twice, as an author and as an editor.

#### Reading the diagram

The Schema tab draws the relation rather than only listing its settings, and names the
kind underneath:

```
Article   [many] ←→ [one]   Author
            many-to-one
```

The mark on each end is how many entries sit there, in words rather than in the `1`/`N` a
database diagram uses, and **one arrow or two is the whole of what an inverse looks
like**. A single arrow is a relation you can only walk one way; a double arrow is one
either end can read.

The line is always read **from the field's own collection outwards**, which is what keeps
the marks and the name agreeing. Standing on Article that relationship is a many-to-one;
standing on Author the very same relationship is a one-to-many. Both are its ordinary
names — which one you say depends on where you are standing, and the diagram says where
that is.

Three facts decide the kind:

| | Set where | Shows as |
| --- | --- | --- |
| How many it holds | The shape, on this field | `one` or `many` on the far end |
| Whether anyone else may hold the same one | **Holds one, exclusively** | `one` or `many` on the near end |
| Whether the far collection reads it back | A relation set to **Reads back** *there* | one arrow, or two |

| Near | Far | Inverse | Named |
| --- | --- | --- | --- |
| many | one | no | one-way |
| many | one | yes | many-to-one |
| one | one | yes | one-to-one |
| many | many | no | many-way |
| many | many | yes | many-to-many |
| one | many | — | one-to-many, which is the same relationship seen from the other end |

The third fact belongs to the *other* collection, which is why it is drawn rather than
offered as a fourth shape: a control here that wrote a field into a collection you are not
looking at would be a schema change you did not see. Where the second arrow is missing the
panel says exactly which field to add, and where.

:::caution A reference is not a checked link
An identifier that names nothing is still stored. That is what makes an import
order-independent: a file can write the articles before the authors they point at have
been read out of it, and the links come back the moment the authors arrive. The cost is
that a reference whose entry was deleted stays in the field and resolves to nothing —
which is what the entry form shows it as.
:::

#### What a relation comes back as

A related entry arrives with its identity and then its fields beside them, which is the
same shape the API delivers an entry it was asked for:

```json
{
  "id": "9f2c41ab-…",
  "slug": "on-the-analytical-engine",
  "author": {
    "id": "0b78c8b9-…",
    "slug": "ada-lovelace",
    "title": "Ada Lovelace",
    "bio": "<p>The first programmer.</p>"
  }
}
```

`title` is there when the collection **names its entries** — when its Settings nominate a
title field. Where none is nominated, WordPress still derives a title for its own row, but
that derivation is an accident of field order rather than anything the schema said, so it
is not published. A field of the related collection whose key is `id`, `slug` or `title`
moves aside to `id_field` and so on, rather than replacing the entry's own.

Three rules bound what a read costs and what it says:

| | |
| --- | --- |
| **One hop** | A relation inside a relation resolves to `id`, `slug` and `title` alone. Two collections pointing at each other would otherwise be a read with no end to it. Raise it with the `schemapress/relation_depth` filter, knowing each hop multiplies |
| **The view** | A published read only ever resolves published entries; one pointing at a draft comes back `null`, or drops out of a list. The builder's own screens see drafts, and say which they are |
| **Fifty** | A relation that reads back answers with at most fifty entries. It is a query with no natural end — "every post by this author" is 3 on one site and 9,000 on another. Past that, use the collection's own list endpoint with a filter, which pages |

:::caution What reading back costs on a *list*
It is a query per entry, and then the full resolution of everything that query returns. A
page of 20 authors with 10 posts each is **110 queries and 200 resolved entries** for one
response — measured, on an install with no persistent object cache.

That is not a defect; it is what was asked for. But it is worth knowing before putting a
field that reads back on a collection you serve as a list. Reading **one** entry is the
shape it is for. For the list, leave the field off and ask the other collection instead:

```http
GET /wp-json/schemapress/api/articles?author=<uuid>&pageSize=10
```

One query, paged, and the same entries.
:::

:::caution Relations into a collection that is closed
On the **public API**, a relation into a collection that is not open for **Read one**
delivers `id`, `slug` and `title` and no fields. A published entry may say *which* author
wrote it — the publisher put that reference in a published entry on purpose — but a
collection that has not opted in does not have its fields read out through the back of one
that has. In a template the whole entry is there either way: a template runs on the server
with the database in front of it, and re-fetching what it already holds is the `populate`
step this plugin does not have.
:::

### Starting widths

A new field starts at a width that suits what its control draws. **This is only a
starting point** — the Form tab sets any field to any width, and a width you choose is
kept, full included.

Widths can also be set while dragging, both ways and at every size. A row's spare space
offers every width that fits it, and a **New row** strip every width there is, full
included: the further across you take the field, the wider it comes out. Holding a field
over its own place and moving across it resizes it where it stands — back towards the
start of the row to narrow it, on into any space after it to widen it — which is how a
full-width field is narrowed in a form with no space to spare. The target is drawn at the
width you will get before you let go.

| Starts at | Types |
| --- | --- |
| **A third** | Image, Toggle, Color, Number, Date, Time, Phone |
| **Half** | Text, Email, URL, Dropdown, File, Date and time, JSON, Relation |
| **Full** | Textarea, Rich Text, Link, Gallery, Group, Repeater |

Fields that already exist keep the width they were saved with. A field type you register
yourself can declare its own with a `width` key — `third`, `half`, `two-thirds` or `full` —
and starts full if it does not.

### The sidebar

A collection's entry screen has a sidebar beside the form, holding the entry's status,
its endpoint, when it was edited, and deleting it. A field can go there too: drag its card
into the sidebar on the **Form** tab, or use **Move to the sidebar** under its width badge.
It sits under the status card, the full width of the column.

That is where an image is at its best. An image field shows the whole picture at the full
width of wherever it sits, never cropped — a column's width in the sidebar, where across
the whole form it would be a poster.

Only a collection's own fields can go in the sidebar. A field inside a group or a
repeater is drawn by its group, and a component's fields are drawn wherever the component
is used, so neither has one. Like widths, the sidebar is the admin screen's business: it
is stored as `region` in the field's config, and nothing it does reaches the API.

### What can be filtered and sorted

Filterable: Text, Textarea, Email, URL, Phone, Dropdown, Color, Number, Date, Date and
time, Time, Toggle, Image, File, Relation.

Not filterable: Rich Text, Link, Group, JSON, Gallery, a relation that reads back, and
anything inside a Repeater. See **What can be queried** for why.

A Relation is filtered by the identifier it holds — `?author=9f2c41ab-…` is every article
by that author, and a relation holding several matches if *any* of its references does,
exactly as a multi-valued dropdown behaves. Sorting by one is offered nowhere: it would
order the list by a uuid, which is to say by nothing. A relation that **reads back**
stores no value to index, which is the same reason it cannot be filtered on — it is
*answered from* this index rather than written to it.

### Adding your own

A field type is registered in PHP and drawn in the admin, so adding one touches both:

```php
add_filter('schemapress/field_types', function ($types) {
    $types['rating'] = [
        'label' => 'Rating',
        'default' => null,
        'sanitize' => fn($value) => $value === '' ? null : max(1, min(5, (int) $value)),
        // optional: how its values compare, so it can be filtered and sorted
        'index' => 'NUMERIC',
        // optional: the width its control starts at
        'width' => 'third',
    ];

    return $types;
});
```

That is enough to store, index and deliver the value correctly. It has no control to edit it
with until one is registered in `src/shared/fields/`.

| Key | |
| --- | --- |
| `label` | What the type is called in the field picker |
| `default` | The value an entry holds before anybody fills it in |
| `sanitize` | `fn($value, $field)` — what is actually stored. Return the empty default for anything that is not the thing |
| `index` | `CHAR` or `NUMERIC`, to make it filterable and sortable. Omit for a type nothing sensible can be asked of |
| `width` | `third`, `half`, `two-thirds` or `full`. Defaults to `full` |
| `children` | `true` to hold child fields, the way a Group does |
| `repeatable` | `true` to hold an ordered list of child rows, the way a Repeater does. Implies `children` |

:::note A nesting type is nesting everywhere or nowhere
Sanitizing, validating, resolving, searching, exporting and the GraphQL schema all ask the
registry whether a type nests rather than checking for the names `group` and `repeater`, so
a type that declares `children` or `repeatable` is walked into by all of them. They used to
test for the literal names, which meant a custom nesting type was *stored* as rows and then
validated as though it were a single bag — and required fields inside it passed without
being looked at.
:::
