<!-- group: Querying content -->
<!-- description: The operator grammar every surface shares — equality, comparisons, lists and or, spelled for each. -->

## Filters

One grammar, three spellings. Whatever the surface, a filter names a field, names a
comparison and gives it a value — and the operator names are the same list everywhere.

The simplest case is equality, and over REST it needs no operator at all:

:::tabs
```php In PHP
<?php
$people = SchemaPress::collection('team-members')
    ->where('role', 'Engineer')
    ->where('full_name', 'Ada Lovelace')
    ->get();

$either = SchemaPress::collection('team-members')
    ->where('role', '$in', ['Design', 'Engineering'])
    ->get();

$matching = SchemaPress::collection('team-members')->search('ada')->get();
```
```js Over REST
const url = new URL('https://your-site.example/wp-json/schemapress/api/team-members')

url.searchParams.set('role', 'Engineer')          // equals
url.searchParams.set('full_name', 'Ada Lovelace')

// any of these — a comma is the only punctuation a value carries
url.searchParams.set('role', 'Design,Engineering')

url.searchParams.set('search', 'ada')

const { data } = await (await fetch(url)).json()
```
```graphql In GraphQL
query Engineers {
  teamMembers(
    where: {
      role: { eq: "Engineer" }
      fullName: { eq: "Ada Lovelace" }
    }
  ) {
    nodes { fullName }
  }
}

query Either {
  teamMembers(where: { role: { in: ["Design", "Engineering"] } }) {
    nodes { fullName }
  }
}

query Matching {
  teamMembers(search: "ada") {
    nodes { fullName }
  }
}
```
:::

`search` is the one that is not a field: it matches the entry's text — any text field, not
only the one it is named by. It combines with filters, so `?search=ada&role=Engineer` is
both.

### The shapes a URL accepts

Over HTTP the same filter can be written several ways, because a client written against
Strapi and a link typed by hand want different things. All of these are read:

| | |
| --- | --- |
| `?role=Engineer` | the field equals the value |
| `?role=Design,Engineering` | any of them — a comma is the only punctuation a value carries |
| `?role[]=Design&role[]=Engineering` | the same, for a client that builds arrays |
| `?headcount[$gte]=10` | an operator, without the `filters` wrapper |
| `?filters[headcount][$gte]=10` | Strapi's long form, which wins where both name one field |
| `?filters[role]=Engineer` | the long form with the operator left off, read as equals |

A parameter naming something that is not a field is ignored, so an analytics tag or a
cache-buster on the URL cannot filter anything. Strapi's `fields`, `populate`, `status` and
`locale` are recognised and ignored rather than read as filters — this API resolves values
and serves published content, so there is nothing for them to ask for.

There is no `?headcount_gte=10`: a parameter *name* should say which field it is about and
nothing else, which is why a comparison goes in brackets.

:::caution An ignored filter widens the result
There is no way to tell a mistyped field name from an unrelated parameter, so a filter that
names nothing is dropped rather than refused — and the query then returns *more* than you
asked for. If a filter seems to have no effect, check the field's machine key on the Schema
tab; the label is not the key.
:::

### Comparisons

Greater than, starts with, is empty, between — these take an operator, which over HTTP
means the bracket syntax and in PHP and Twig means a third argument:

:::tabs
```http Over HTTP
?filters[headcount][$gte]=10
?filters[full_name][$startsWith]=G
?filters[bio][$notNull]=true
```
```php In PHP
<?php
$found = SchemaPress::collection('team-members')
    ->where('headcount', '>=', 10)
    ->where('full_name', '$startsWith', 'G')
    ->where('bio', '$notNull', true)
    ->get();
```
```graphql In GraphQL
query Comparisons {
  teamMembers(
    where: {
      headcount: { gte: 10 }
      fullName: { startsWith: "G" }
      bio: { isNull: false }
    }
  ) {
    nodes { fullName }
  }
}
```
:::

The bracket form is Strapi's, and is accepted in full — so a client written against Strapi
runs unchanged, and the harder questions stay askable over HTTP. For plain equality, a
bare `?field=value` is the shorter and better-behaved way to ask.

### Operator reference

These are the operators `where()`, `filter()` and the bracket form accept. They are not
parameter names.

| Operator | Matches | Alias |
| --- | --- | --- |
| `$eq` | equal | `=` |
| `$ne` | not equal | `!=` |
| `$lt` `$lte` | less than, or equal | `<` `<=` |
| `$gt` `$gte` | greater than, or equal | `>` `>=` |
| `$in` | among a list | `in` |
| `$notIn` | not among a list | `not in` |
| `$contains` | anywhere in the value | `like` |
| `$notContains` | nowhere in the value | |
| `$startsWith` | anchored to the start | |
| `$endsWith` | anchored to the end | |
| `$between` | inside a range, given two values | |
| `$null` | empty | |
| `$notNull` | not empty | |

`$containsi` and `$notContainsi` are accepted as aliases so a query written for Strapi
still runs. WordPress's database collation is case-insensitive, so they behave identically
to `$contains` — as, in practice, do all the string comparisons. `$startsWith=z` matches
`Zaha`.

In GraphQL the same operators are the fields of a filter input, without the `$` — `gte`,
`startsWith`, `in` — and `$null`/`$notNull` become the single flag `isNull`. See
**GraphQL**.

A list operator takes an indexed array:

:::tabs
```php In PHP
<?php
$some = SchemaPress::collection('team-members')
    ->where('role', '$in', ['Design', 'Engineering'])
    ->where('headcount', '$between', [5, 50])
    ->get();
```
```js Over REST
const url = new URL('https://your-site.example/wp-json/schemapress/api/team-members')

url.searchParams.set('role', 'Design,Engineering')
url.searchParams.set('filters[headcount][$between][0]', '5')
url.searchParams.set('filters[headcount][$between][1]', '50')

const { data } = await (await fetch(url)).json()
```
```graphql In GraphQL
query Some {
  teamMembers(
    where: {
      role: { in: ["Design", "Engineering"] }
      headcount: { between: [5, 50] }
    }
  ) {
    nodes { fullName }
  }
}
```
:::

`$null` and `$notNull` take a flag, and over REST the string `false` is read as false
rather than as a non-empty string:

```http
?filters[bio][$notNull]=true
```

### Combining filters

Several filters are joined with **and**, which is what you get without asking:

:::tabs
```php In PHP
<?php
$found = SchemaPress::collection('team-members')
    ->where('role', 'Engineer')
    ->where('full_name', '$startsWith', 'G')
    ->get();
```
```js Over REST
const url = new URL('https://your-site.example/wp-json/schemapress/api/team-members')

url.searchParams.set('role', 'Engineer')
url.searchParams.set('filters[full_name][$startsWith]', 'G')

const { data } = await (await fetch(url)).json()
```
```graphql In GraphQL
query Found {
  teamMembers(where: { role: { eq: "Engineer" }, fullName: { startsWith: "G" } }) {
    nodes { fullName }
  }
}
```
:::

For **or**, nest under `$or`. It takes a **list**, and each entry in the list is a whole
filter object — a query in its own right, whose own conditions are joined by and. So
`$or: [{a, b}, {c}]` asks for `(a AND b) OR c`, and two entries may name the same field
without one displacing the other.

This is the one thing a flat parameter cannot spell, because "or" is a relationship between
filters rather than a property of one — so over REST it stays in brackets, and in PHP it
needs `filter()` rather than `where()`:

:::tabs
```php In PHP
<?php
$leads = SchemaPress::collection('team-members')
    ->filter([
        '$or' => [
            ['role' => ['$eq' => 'Design']],
            ['lead' => ['$eq' => true]],
        ],
    ])
    ->get();
```
```js Over REST
const url = new URL('https://your-site.example/wp-json/schemapress/api/team-members')

url.searchParams.set('filters[$or][0][role][$eq]', 'Design')
url.searchParams.set('filters[$or][1][lead][$eq]', '1')

const { data } = await (await fetch(url)).json()
```
```graphql In GraphQL
query Leads {
  teamMembers(
    where: { or: [{ role: { eq: "Design" } }, { lead: { eq: true } }] }
  ) {
    nodes { fullName }
  }
}
```
:::

`$and` takes a list the same way, nests inside `$or`, and is what you get without asking.
`filter()` is the PHP door to whole trees; `where()` cannot spell an `$or`.

Two branches on the same field is the ordinary way to write "either of these" when the
values need different operators:

```php
<?php
$lapsed = SchemaPress::collection('team-members')
    ->filter(['$or' => [
        ['joined' => ['$lt' => '2020-01-01']],
        ['joined' => ['$null' => true]],
    ]])
    ->get();
```

Which fields any of this can be used on is **What can be queried** — a value has to be in
the index before it can be compared, and not every field type is.

:::caution Every way a filter fails, it fails wide
A filter naming a field that does not exist or cannot be indexed is dropped; so is an
operator this API does not have, and a `$between` given one value instead of two. In each
case the query runs without that condition and returns **more** than you asked for. If a
result looks too large, that is the first thing to check.
:::
