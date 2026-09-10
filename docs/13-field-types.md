<!-- group: Modeling content -->
<!-- description: Every field a collection can hold, what each one stores, and what it becomes when you read it back. -->

## Field types

Twenty types, in four groups. What separates them is not how they look — that is your
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
| **Number** | A float, or nothing | `min`, `max` and `step` are enforced on save |
| **Date** | `2026-09-08` | A wall clock, not an instant — see below |
| **Date and time** | `2026-09-08T19:00:00` | |
| **Time** | `19:00:00` | |
| **Toggle** | `true` or `false` | Off is an answer, not an absence |
| **Dropdown** | One value, or a list | From your own options or a [dataset](#datasets) |
| **Color** | `#3b82f6` | Filterable and sortable |

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

### Starting widths

A new field starts at a width that suits what its control draws. **This is only a
starting point** — the Layout tab sets any field to any width, and a width you choose is
kept, full included.

| Starts at | Types |
| --- | --- |
| **A third** | Image, Toggle, Color, Number, Date, Time, Phone |
| **Half** | Text, Email, URL, Dropdown, File, Date and time, JSON |
| **Full** | Textarea, Rich Text, Link, Gallery, Group, Repeater |

Fields that already exist keep the width they were saved with. A field type you register
yourself can declare its own with a `width` key — `third`, `half`, `two-thirds` or `full` —
and starts full if it does not.

### What can be filtered and sorted

Filterable: Text, Textarea, Email, URL, Phone, Dropdown, Color, Number, Date, Date and
time, Time, Toggle, Image, File.

Not filterable: Rich Text, Link, Group, JSON, Gallery, and anything inside a Repeater. See
**Indexing** for why.

### Adding your own

A field type is registered in PHP and drawn in the admin, so adding one touches both:

```php
add_filter('schemapress/field_types', function ($types) {
    $types['rating'] = [
        'label' => 'Rating',
        'default' => null,
        'sanitize' => fn($value) => $value === '' ? null : max(1, min(5, (int) $value)),
    ];

    return $types;
});
```

That is enough to store and deliver the value correctly. It has no control to edit it with
until one is registered in `src/shared/fields/`, and it cannot be filtered on until it is
added to `Index::TYPES`.
