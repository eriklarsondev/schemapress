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
| Repeater | `[{ id, data }]` | `$e->rows('links')` | `{% for r in e.rows('links') %}` |
| Relation | an object, a list, or `null` | `$e->author['title']` | `{{ e.author.title }}` |
| Relation, reading back | a list | `$e->articles` | `{% for a in e.articles %}` |

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

In JSON a text field that is empty is `""` rather than `null`, and a number that is empty is
`null` rather than `0` — a blank is not a zero, and a client that prints a total should be
able to tell the difference.

In PHP and Twig an empty text field reads as `null` too, because `get()` returns the default
for anything empty and the default is `null` unless you pass one. `get('bio', '')` is how
you ask for the empty string back. The raw stored bag, `""` and all, is `all()`.

### Relations arrive resolved too

A relation is a reference while it is stored and a whole entry by the time you read it, so
there is still nothing to populate:

:::tabs
```twig In Twig
<article>
  <h2>{{ article.title }}</h2>

  {% if article.author %}
    <p>By <a href="/team/{{ article.author.slug }}">{{ article.author.title }}</a></p>
    {{ article.author.bio|raw }}
  {% endif %}

  {% for tag in article.tags %}
    <span class="tag">{{ tag.title }}</span>
  {% endfor %}
</article>
```
```php In PHP
<?php if ($article->has('author')) : ?>
  <p>By <?php echo esc_html($article->author['title']); ?></p>
<?php endif; ?>

<?php foreach ($article->get('tags', []) as $tag) : ?>
  <span class="tag"><?php echo esc_html($tag['title']); ?></span>
<?php endforeach; ?>
```
```js From JSON
if (article.author) {
  byline.textContent = article.author.title
}

article.tags.forEach((tag) => list.append(chip(tag.title)))
```
:::

A relation holding one entry is an object or `null`; one holding several, and every one
set to read back, is a list — and both follow the **field** rather than what it happens to hold, so
a multiple relation with one entry in it is still a list of one. `title` is there when the
collection at the other end names its entries. **Field types** covers the rest: one hop,
the published view, and what a closed collection gives away.

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

Some names belong to the entry rather than to your fields, and **which ones, and what
happens when a field wants one, differs by surface** — so each page says it for its own:

| | |
| --- | --- |
| **In JSON** | `id`, `slug`, `createdAt`, `updatedAt`, `publishedAt`. A field wanting one of those is delivered with `_field` on the end — see **Values in JSON** |
| **In PHP** | Nothing collides. The entry's names are *methods*, so `$e->title` is your field and `$e->title()` is the entry's — see **Values in PHP** |
| **In Twig** | The entry wins, and the rule covers everything it answers as a method — `id`, `title`, `slug`, `state`, `modified`, `isPublished`, `rows`, `get`, `has` and the rest. Reach your own field with `get('title')` — see **Values in Twig** |

`id` is a generated uuid, not a row number. Putting it in a URL or a data attribute says
nothing about how many entries exist or what order they were created in, and it stays
stable if content is moved between installs.
