<!-- group: Displaying content -->
<!-- description: What every field type becomes once you have it — one table covering JSON, PHP and Twig, and the rules about escaping and empties. -->

## Displaying values

The other half of the job. **Querying** is how you ask for entries; this is what you get
back and how to put it on a page.

One rule underneath all of it: **values arrive resolved**. An image is its attachment — url,
dimensions, alt text and every registered size, already expanded — not an id you have to
spend a second request on. There is no `populate` step to remember and no lazy loading to
trip over, on any of the three surfaces.

### Every field type, three ways

| Field type | In JSON | In PHP | In Twig |
| --- | --- | --- | --- |
| Text, Textarea, Email, URL, Phone | `"a string"` | `$e->full_name` | `{{ e.full_name }}` |
| Rich text | `"<p>HTML</p>"` | `$e->bio` | `{{ e.bio\|raw }}` |
| Number | `42.0` or `null` | `$e->headcount` | `{{ e.headcount }}` |
| Date | `"2026-09-08"` or `""` | `$e->starts_on` | `{{ e.starts_on }}` |
| Date and time | `"2026-09-08T19:00:00"` | `$e->starts_at` | `{{ e.starts_at }}` |
| Time | `"19:00:00"` | `$e->doors` | `{{ e.doors }}` |
| Toggle | `true` / `false` | `$e->lead` | `{{ e.lead }}` |
| Dropdown | `"value"`, or a list | `$e->role` | `{{ e.role }}` |
| Image, File | an object, or `null` | `$e->avatar['url']` | `{{ e.avatar.url }}` |
| Link | `{ url, label, target }` | `$e->site['url']` | `{{ e.site.url }}` |
| Group | a nested object | `$e->get('address.city')` | `{{ e.get('address.city') }}` |
| Repeater | `[{ id, data }]` | `$e->rows('links')` | `{{ e.rows('links') }}` |

PHP and Twig are reading the same resolved data; the difference is only that PHP hands you
arrays and Twig lets you walk them with a dot. **Values in JSON**, **Values in PHP** and
**Values in Twig** each go through this in full, with the object shapes written out.

:::note Dates are wall clocks, not instants
A Date, Time or Date and time carries no timezone, and none is applied on the way in or
out: `19:00:00` is seven in the evening, which is what somebody filling in an event form
means. `publishedAt` and `updatedAt` are the opposite — they record a moment rather than a
plan, and are GMT.
:::

### Empty is `null`, everywhere

An image, file or link that has not been filled in is `null` rather than an empty object,
so reaching into it without checking is the one thing that will break a template:

:::tabs
```php In PHP
<?php if ($person->has('avatar')) : ?>
  <img src="<?php echo esc_url($person->avatar['url']); ?>"
       alt="<?php echo esc_attr($person->avatar['alt']); ?>">
<?php endif; ?>
```
```twig In Twig
{% if person.avatar %}
  <img src="{{ person.avatar.url }}" alt="{{ person.avatar.alt }}">
{% endif %}
```
```js From JSON
if (person.avatar) {
  img.src = person.avatar.url
  img.alt = person.avatar.alt
}
```
:::

A text field that is empty is `""` rather than `null`, and a number that is empty is `null`
rather than `0` — a blank is not a zero, and a template that prints a total should be able
to tell the difference.

### Escaping is not the same on all three

This is the one place the three surfaces genuinely differ, and getting it wrong is a
security bug rather than a rendering bug:

| | |
| --- | --- |
| **JSON** | Nothing is escaped, because JSON is data. Whatever puts it in the DOM is responsible — `textContent`, not `innerHTML`. |
| **PHP** | Nothing is escaped. It is the template's job to pick `esc_html`, `esc_attr` or `esc_url` per spot. |
| **Twig** | Escaped on output, automatically. Rich text is the exception and needs `\|raw`. |

:::warning `|raw` is only ever for rich text
Rich text is HTML by definition, so printing it escaped shows the tags. On any other field
`|raw` disables the escaping that makes the value safe to print — and a text field holds
whatever an editor typed into it.
:::

### The entry itself

Five names belong to the entry rather than to your fields, and they win over a field of the
same name: `id`, `title`, `slug`, `state`, `rows`. If your collection has its own field
called `title`, reach it explicitly with `get('title')`.

`id` is a generated uuid, not a row number. Putting it in a URL or a data attribute says
nothing about how many entries exist or what order they were created in, and it stays
stable if content is moved between installs.
