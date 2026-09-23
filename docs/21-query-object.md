<!-- group: Querying content -->
<!-- description: What a PHP file holds — the methods, why it runs once, and how the result reaches a template. -->

## The query object

`SchemaPress::collection()` returns a **query**, not a list. Everything a theme does with
this plugin starts here, and the result is what gets handed to whatever renders the page.

Over REST and in GraphQL there is no object — the request is the whole query. The same
operations are the parameters on **Filters** and **Sorting & pagination**, and the
arguments on **GraphQL**.

### The methods

| Call | Returns |
| --- | --- |
| `get()` | Every entry that matches, as `Entry` objects |
| `find($id)` | One entry, or `null` |
| `first()` | The first entry, or `null` |
| `where($field, $value)` | A new query, filtered by a field |
| `where($field, $op, $value)` | The same, with an operator |
| `filter($tree)` | A new query, filtered by a whole `and`/`or` tree |
| `search($term)` | A new query, filtered by the entry’s text |
| `sort($field, $dir)` | A new query, ordered by any field |
| `orderBy($field, $dir)` | The same, and accepts `title`, `date`, `modified` |
| `limit($n)` | A new query, capped at `$n` |
| `page($n)` | A new query, on page `$n` |
| `offset($n)` | A new query, skipping `$n` entries — the `?start=` window |
| `total()` | How many entries match, ignoring paging |
| `count()` | How many this page returned |
| `isEmpty()` | Whether there are none |
| `fields()` | The field definitions entries are built from |

It is iterable and countable, so it drops straight into a `foreach` or a `count()`.

:::note `page()` and `offset()` are alternatives
An offset counts entries and a page counts pages, and setting an offset means the page is
not consulted. Pick one per query.
:::

### Reaching it

**There is nothing to instantiate and nothing to import.** Both spellings below are
available in any theme file the moment the plugin is active.

```php
<?php
$people = SchemaPress::collection('team-members')->get();
```

`SchemaPress::collection()` is a **static method**, so it is called on the class rather
than on an object — no `new`. The bare name `SchemaPress` works without a `use` statement
because the plugin aliases its `SchemaPress\Content` class to it, in the autoloader, the
first time anything asks for the name.

The same calls exist as **plain global functions**, declared in the plugin's
`includes/helpers.php` and loaded on every request. They are not methods and nothing is
inherited — each is a one-line wrapper around the static call above, there because a
WordPress template is a procedural place and `schemapress_entry(...)` reads better in one
than `SchemaPress::collection(...)->find(...)` does:

```php
<?php
// the query, exactly what SchemaPress::collection() returns
$people = schemapress_collection('team-members');

// one entry by id or by slug, or null
$person = schemapress_entry('team-members', 'ada-lovelace');

// every collection's machine key, and whether one exists
$keys = schemapress_collections();
$has = schemapress_has_collection('team-members');
```

### It runs once

The query runs on first read and remembers the result, so counting a collection and then
looping it is one database query rather than two.

Every call returns a **new** query and leaves the original alone, which is what makes a
query safe to hold in a variable and read more than once:

```php
<?php
$team      = SchemaPress::collection('team-members');
$engineers = $team->where('role', 'Engineer');

$team->total();       // 3 — unchanged
$engineers->total();  // 2
```

### Handing the result to a template

The query is built in the PHP file and the **result** is what the template gets. That is
true of a native PHP theme and of a Timber one, and it is the reason this plugin has
nothing to register with Twig: an `Entry` answers an array key, a property and a method, so
`person.full_name` resolves in a template with nothing installed for it.

:::tabs
```php A native PHP theme
<?php
// archive-team.php
$people = SchemaPress::collection('team-members')
    ->where('role', 'Engineer')
    ->sort('full_name')
    ->limit(50)
    ->get();

get_header();

require get_template_directory() . '/partials/team-grid.php';

get_footer();
```
```php A Timber theme
<?php
// archive-team.php
$context = Timber::context();

$context['team'] = SchemaPress::collection('team-members')
    ->where('role', 'Engineer')
    ->sort('full_name')
    ->limit(50)
    ->get();

Timber::render('team.twig', $context);
```
```twig The Twig template
{# team.twig — renders what it was handed, and asks for nothing #}
{% for person in team %}
  <article class="card">
    <h2>{{ person.full_name }}</h2>
    <p>{{ person.role }}</p>
  </article>
{% endfor %}
```
:::

:::note Pass the query, not its results, to defer the work
`->get()` above runs the query. Leave it off and the context holds the *query*, which Twig
iterates just the same — and which runs only if the template actually loops it. Useful when
a template decides between two blocks and only one of them needs the data.
:::

There is no way to fetch a collection from inside a template, and that is the design rather
than a gap: a page whose data layer is split between a PHP file and the templates it
renders has two places to look when something is missing.
