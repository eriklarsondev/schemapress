<!-- group: In your theme -->
<!-- description: Reading collections from any theme file — the query object, entries, values, and paging. -->

## PHP

`SchemaPress` is registered as a global alias, so any theme file can read a collection with
no `use` statement and no bootstrapping:

```php
$people = SchemaPress::collection('team_member')->get();
```

:::caution `Content` and the `sp_` functions are gone
`Content::collection()` and `sp_collection()` were the original names and no longer answer.
Both lived in the global namespace, which every plugin on the site shares: `Content` is
about as generic as a class name gets, and `sp_` is two letters that SportsPress already
uses throughout. Two plugins declaring one name is a fatal error, and this plugin cannot be
the one that claims either.

Rename them in your theme — `Content::` to `SchemaPress::`, and `sp_collection()` to
`schemapress_collection()`. **Twig templates are unaffected**: `sp_collection()` is still
the Twig function, because that name is registered into this plugin's own environment and
collides with nothing.
:::

The same calls exist as plain functions, which read better inside a template file:

```php
schemapress_collection('team_member')      // the query
schemapress_entry('team_member', $id)      // one entry, or null
schemapress_collections()                  // every collection's key
schemapress_has_collection('team_member')  // whether it exists
```

Both names work — singular or plural, `team_member` or `team_members`. An unknown name
returns an empty query rather than `null`, so a typo renders nothing instead of fataling
the page.

### The query object

`SchemaPress::collection()` returns a **query**, not a list. It runs once, on first read, and
remembers the result — so counting a collection and then looping it makes one database
query.

| Call | Returns |
| --- | --- |
| `->get()` | Every entry, as `Entry` objects |
| `->find($id)` | One entry, or `null` |
| `->first()` | The first entry, or `null` |
| `->limit($n)` | A new query, capped at `$n` |
| `->page($n)` | A new query, on page `$n` |
| `->where($field, $value)` | A new query, filtered by a field |
| `->where($field, $op, $value)` | The same, with an operator |
| `->filter($tree)` | A new query, filtered by a whole `$and`/`$or` tree |
| `->sort($field, $dir)` | A new query, ordered by any field |
| `->orderBy($field, $dir)` | The same, and accepts `title`, `date`, `modified` |
| `->search($term)` | A new query, filtered by the entry’s text |
| `->total()` | How many entries match, ignoring paging |
| `->count()` | How many this page returned |
| `->isEmpty()` | Whether there are none |
| `->fields()` | The field definitions entries are built from |

It is iterable and countable, so it drops straight into a `foreach` or a `count()`.

### An archive template

A collection is iterable and countable, so it drops straight into a `foreach`. This is a
complete `archive-team.php`:

```php
<?php get_header(); ?>

<main class="team">
  <h1><?php esc_html_e('The team', 'my-theme'); ?></h1>

  <?php
  $people = SchemaPress::collection('team_member')
      ->where('role', 'Engineer')
      ->sort('full_name')
      ->limit(50);

  if ($people->isEmpty()) : ?>
    <p><?php esc_html_e('Nobody here yet.', 'my-theme'); ?></p>
  <?php else : ?>
    <ul>
      <?php foreach ($people as $person) : ?>
        <li>
          <?php if ($person->has('avatar')) : ?>
            <img
              src="<?php echo esc_url($person->avatar['url']); ?>"
              alt="<?php echo esc_attr($person->avatar['alt']); ?>"
              width="<?php echo esc_attr($person->avatar['width']); ?>"
              height="<?php echo esc_attr($person->avatar['height']); ?>">
          <?php endif; ?>

          <h2><?php echo esc_html($person->full_name); ?></h2>
          <p><?php echo esc_html($person->role); ?></p>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</main>

<?php get_footer(); ?>
```

Nothing is escaped for you. Values come back as data, and it is the template's job to
decide whether a given spot needs `esc_html`, `esc_attr` or `esc_url`.

### Reading one entry

`find()` takes the entry's id — the uuid, not a post id:

```php
$person = schemapress_entry('team_member', $id);

if (!$person) {
    // no such entry in this collection
    return;
}

echo esc_html($person->full_name);
```

A common shape is a URL like `/team/{id}`, read from a query var:

```php
$person = schemapress_entry('team_member', get_query_var('person'));
```

`first()` is there for the single-entry case — a settings-style collection, or just the
most recent of something:

```php
$latest = SchemaPress::collection('news')->sort('publishedAt', 'desc')->first();
```

### Reading values

Field keys are properties. `get()` does the same thing with a default, and takes a dot
path down through group fields:

```php
$person->full_name;                    // a text field
$person->get('full_name');             // the same
$person->get('nickname', 'Anonymous'); // with a fallback
$person->get('address.city');          // into a group
$person->has('bio');                   // non-empty?
```

An undeclared key returns the default rather than raising a notice, so markup never needs
`isset()` around content.

Five names belong to the entry itself and always win over a field of the same name — `id`,
`title`, `slug`, `state`, `rows`. If your collection has a field called `title`, read it
with `get('title')`:

```php
$person->id();                      // the entry's uuid
$person->title();                   // the entry's title
$person->slug();
$person->modified();
$person->isPublished();
$person->get('title');              // YOUR field called title
```

### What each field type gives you

Values arrive **resolved** — an image is its attachment, not an id. **Response format** has the
full type-by-type table; it is the same data in PHP, as arrays rather than JSON.

The two worth knowing at the keyboard:

```php
$person->avatar['url'];                        // full size
$person->avatar['sizes']['large']['url'];      // any registered size
$person->avatar['srcset'];                     // ready for an <img>
$person->avatar['alt'];

$person->website['url'];                       // a link field
$person->website['label'];
$person->website['target'];
```

Both are `null` when empty, so guard with `has()` before reaching in:

```php
<?php if ($person->has('avatar')) : ?>
  <img src="<?php echo esc_url($person->avatar['sizes']['large']['url']); ?>"
       srcset="<?php echo esc_attr($person->avatar['srcset']); ?>"
       alt="<?php echo esc_attr($person->avatar['alt']); ?>">
<?php endif; ?>
```

### Repeaters

`rows()` returns each row as its own accessor:

```php
<?php foreach ($person->rows('links') as $link) : ?>
  <a href="<?php echo esc_url($link->get('url.url')); ?>">
    <?php echo esc_html($link->get('label')); ?>
  </a>
<?php endforeach; ?>
```

Rows nest. A repeater inside a repeater is `rows()` again on the inner accessor.

### Filtering and sorting

One grammar for all three surfaces — the operators, the aliases and the `$or` form are on
**Filters** and **Sorting & pagination**. In PHP it reads:

```php
SchemaPress::collection('team_member')
    ->where('role', 'Engineer')             // equals
    ->where('headcount', '>=', 10)          // or '$gte'
    ->where('full_name', '$startsWith', 'G')
    ->where('role', '$in', ['Design', 'Engineering'])
    ->sort('full_name', 'desc')
    ->get();
```

`filter()` takes a whole tree, for the queries `where()` cannot spell:

```php
SchemaPress::collection('team_member')->filter([
    '$or' => [
        ['role' => ['$eq' => 'Design']],
        ['lead' => ['$eq' => true]],
    ],
])->get();
```

### Paging

A query returns **10 entries** unless you say otherwise, so a template that loops a
collection without asking for a size cannot accidentally render two thousand rows.

```php
$page = max(1, (int) get_query_var('paged'));
$news = SchemaPress::collection('news')->sort('publishedAt', 'desc')->limit(20)->page($page);

foreach ($news as $item) { … }

$pages = (int) ceil($news->total() / 20);
```

`total()` is the count ignoring paging, which is what you need to draw pagination.
`count()` is how many this page returned.

### Queries are immutable

Every call returns a new query and leaves the original alone:

```php
$team      = SchemaPress::collection('team_member');
$engineers = $team->where('role', 'Engineer');

$team->total();       // 3 — unchanged
$engineers->total();  // 2
```

The query runs once, on first read, and remembers its result. Counting a collection and
then looping it is one database query, not two.

### Drafts never leak

This API only ever returns what is **published**. A collection with draft and publish
turned on keeps unpublished edits out of every read here, so a template cannot render
half-finished work by forgetting to ask.

`state()` is therefore `published` for anything you get this way. In a collection with
drafts on, a live entry that has newer unpublished edits reads as `modified` —
`hasUnpublishedChanges()` says the same as a boolean.
