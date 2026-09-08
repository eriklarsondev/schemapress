<!-- group: Content API -->
<!-- description: A parameter names a field and gives it a value. Comparisons live in the query object instead. -->

## Filters

A parameter names a field and gives it a value. That is the whole grammar:

```http
?role=Engineer
?full_name=Ada Lovelace
?role=Design,Engineering
?search=ada
```

| | |
| --- | --- |
| `?field=value` | entries where the field is that value |
| `?field=a,b` | entries where it is any of them |
| `?search=term` | entries whose text matches — any text field, not only the name |
| `?sort=field` `?sort=-field` | ordering — see **Sorting & pagination** |
| `?page=` `?limit=` | paging — see **Sorting & pagination** |

Parameters are for **filtering and ordering**. They do not carry comparisons: there is no
`?headcount_gte=10`, because a parameter name should say which field it is about and
nothing else.

A parameter naming something that is not a field is ignored, so an analytics tag or a
cache-buster on the URL cannot filter anything.

:::caution An ignored filter widens the result
There is no way to tell a mistyped field name from an unrelated parameter, so a filter that
names nothing is dropped rather than refused — and the query then returns *more* than you
asked for. If a filter seems to have no effect, check the field's machine key on the Schema
tab; the label is not the key.
:::

### Comparisons

Greater than, starts with, is empty, between — these are comparisons, and they live in the
query object rather than in a parameter name:

:::tabs
```php PHP
SchemaPress::collection('team_member')
    ->where('headcount', '>=', 10)
    ->where('full_name', '$startsWith', 'G')
    ->where('bio', '$notNull', true)
    ->get()
```
```twig Twig
{% set found = sp_collection('team_member')
    .where('headcount', '>=', 10)
    .where('full_name', '$startsWith', 'G') %}
```
:::

Over HTTP they are available through Strapi's bracket syntax, which is accepted in full:

```http
?filters[headcount][$gte]=10
?filters[full_name][$startsWith]=G
```

That form exists so a client written against Strapi runs unchanged, and so that the harder
questions are still askable. For everything else, a plain `?field=value` is the shorter and
better-behaved way to ask.

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

A list operator takes an indexed array:

:::tabs
```http REST
?role=Design,Engineering
?filters[headcount][$between][0]=5&filters[headcount][$between][1]=50
```
```php PHP
->where('role', '$in', ['Design', 'Engineering'])
->where('headcount', '$between', [5, 50])
```
:::

`$null` and `$notNull` take a flag, and the string `false` is read as false rather than as
a non-empty string:

```http
?filters[bio][$notNull]=true
```

### Combining filters

Several filters are joined with **and**:

```http
?role=Engineer&filters[full_name][$startsWith]=G
```

For **or**, nest under `$or` — each entry in the list is its own filter object:

:::tabs
```http REST
?filters[$or][0][role][$eq]=Design&filters[$or][1][lead][$eq]=1
```

`$or` is the one thing the flat form cannot spell, because "or" is a relationship between
filters rather than a property of one. It stays in brackets:

```http
```
```php PHP
->filter([
    '$or' => [
        ['role' => ['$eq' => 'Design']],
        ['lead' => ['$eq' => true]],
    ],
])
```
:::

`$and` works the same way and is what you get without asking. `filter()` is the PHP and
Twig door to whole trees — `where()` cannot spell an `$or`.
