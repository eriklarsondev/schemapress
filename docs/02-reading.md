## Reading content

Everything goes through `Content`, which is a global — a theme needs no import.

```php
$people = Content::collection('team_member')->get();

foreach ($people as $person) {
    echo esc_html($person->name);
    echo esc_url($person->photo['url']);
}
```

The key is the machine name shown when the collection was created — `team_member`, not
`Team Member`.

### The query

`Content::collection()` returns a query. It runs once, on first read, and is remembered,
so counting a collection and then looping it makes one query.

| Call | Returns |
| --- | --- |
| `->get()` | Every entry, as `Entry` objects |
| `->find($id)` | One entry, or `null` |
| `->first()` | The first entry, or `null` |
| `->limit($n)` | A new query, capped at `$n` |
| `->page($n)` | A new query, on page `$n` |
| `->orderBy($field, $dir)` | A new query, ordered by `title`, `date` or `modified` |
| `->search($term)` | A new query, filtered by title |
| `->total()` | How many entries exist, ignoring paging |
| `->isEmpty()` | Whether there are none |
| `->fields()` | The field definitions entries are built from |

Queries are immutable — `limit()` returns a new one and leaves the original alone — so a
collection held in a variable can be read twice without the first read reshaping the
second.

An unknown key returns an empty query rather than null. A typo renders nothing instead of
fataling the page.

### An entry

Field values are read as properties, which is the form Twig reaches for first:

```php
$person->name;            // a text field
$person->photo['url'];    // an image resolves to its attachment array
$person->website['url'];  // a link resolves to url, label, target
$person->id();            // the entry's post id
$person->title();
$person->status();        // 'publish' or 'draft'
$person->isPublished();
```

Values arrive **resolved**. An image is its attachment array, not an id; a relation is the
entries it points at, not their ids. A template never handles an id.

Repeaters read as rows:

```php
foreach ($person->rows('links') as $link) {
    echo esc_url($link->url['url']);
}
```

A field key that collides with one of the accessor methods — `id`, `title`, `slug`,
`status`, `rows` — resolves to the **method**. Read a field of that name with
`get('id')` instead.

### In Twig

```twig
{% for person in sp_collection('team_member') %}
  <article>
    <img src="{{ person.photo.url }}" alt="{{ person.photo.alt }}">
    <h3>{{ person.name }}</h3>
    <p>{{ person.role }}</p>
  </article>
{% endfor %}
```

Registered Twig functions: `sp_collection`, `sp_collections`, `sp_has_collection`.

### As functions

The same calls exist as plain functions, for templates where that reads better:

`sp_collection($key)` · `sp_entry($key, $id)` · `sp_collections()` · `sp_has_collection($key)`
