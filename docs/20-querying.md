<!-- group: Querying content -->
<!-- description: One grammar, three surfaces — the same query written for PHP, REST and GraphQL, and what you get when you do not ask. -->

## Querying

Asking for content and rendering it are two jobs, and this plugin keeps them apart. This
group is the first one: how to ask a collection for the entries you want. What comes back,
and what each field type turns into, is **Displaying values**.

There are three surfaces and they share one grammar — the same operator names, the same
field keys, the same answers. Every page in this group shows them side by side, so working
out a query in one place is never wasted when you move it to another.

| | |
| --- | --- |
| **In PHP** | A theme file, a shortcode, a block render callback. Runs in process, so there is no request and nothing to authenticate. |
| **Over REST** | Something outside WordPress is asking — a front end, a build step, another service. Opt-in per collection. |
| **In GraphQL** | The same, through the site's WPGraphQL schema when that plugin is installed. Opt-in through the same switches. |

:::note Twig does not query
A Twig template renders what it was handed. The query is built in the PHP file above it and
the result passed into the context — see **The query object**. There is no Twig function for
fetching a collection, deliberately: a template that fetches its own data puts the page's
data layer in two places.
:::

:::caution HTTP is opt-in; PHP is not
A collection answers over REST or GraphQL only when you switch it on, and nothing is
exposed by default — see **Endpoints**. PHP never leaves the server, so there is nothing to
expose and no switch: a theme can read any collection the moment it exists.
:::

### Naming a collection

`team-members`: **the plural, hyphenated**. The same string in a PHP file, in a URL and on
the command line, because it is the one that reads like a URL and there is no reason for a
template to spell it differently from the address it is served at.

Three other spellings reach the same collection, and always will — a template that already
types one should not stop rendering:

```text
team-members     ← the one to type
team_members     team-member     team_member
```

Spaces are read as hyphens too, so a name pasted out of the admin arrives intact. A name
that matches nothing is not an error: over HTTP it is a `404`, and in PHP it is an empty
query, so a typo renders nothing rather than fataling the page.

GraphQL is the exception, and has to be: a GraphQL name cannot contain a dash. There the
collection is `teamMembers`, camelCased — see **GraphQL**.

### A whole collection

:::tabs
```php In PHP
<?php
// archive-team.php
$people = SchemaPress::collection('team-members')->get();

foreach ($people as $person) {
    echo esc_html($person->full_name);
}
```
```js Over REST
const res = await fetch('https://your-site.example/wp-json/schemapress/api/team-members', {
  headers: { Accept: 'application/json' },
})

if (!res.ok) {
  throw new Error(`SchemaPress: ${res.status}`)
}

const { data, meta } = await res.json()

data.forEach((person) => console.log(person.full_name))
console.log(`${data.length} of ${meta.pagination.total}`)
```
```graphql In GraphQL
query TeamMembers {
  teamMembers {
    nodes {
      id
      fullName
    }
    total
  }
}
```
:::

### One entry

By its `id` — a uuid — or by its `slug`. Both are in every response, so a front end holding
one never has to list the collection to find the other.

:::tabs
```php In PHP
<?php
// single-team-member.php
$person = schemapress_entry('team-members', get_query_var('person'));

if (!$person) {
    get_template_part('404');

    return;
}

echo esc_html($person->full_name);
```
```js Over REST
const base = 'https://your-site.example/wp-json/schemapress/api/team-members'

async function teamMember(idOrSlug) {
  const res = await fetch(`${base}/${idOrSlug}`, {
    headers: { Accept: 'application/json' },
  })

  // no published entry with that id or slug
  if (res.status === 404) {
    return null
  }

  const { data } = await res.json()

  return data
}

const person = await teamMember('ada-lovelace')
```
```graphql In GraphQL
query TeamMember {
  teamMember(slug: "ada-lovelace") {
    id
    fullName
  }
}
```
:::

Missing is missing: a `404` over REST, `null` in PHP and GraphQL. Knowing an id is not
permission to read a draft — see **Only published content, everywhere** below.

### The first one, the count, and whether there are any

:::tabs
```php In PHP
<?php
$news = SchemaPress::collection('news')->sort('publishedAt', 'desc');

$latest = $news->first();          // one entry, or null
$showing = $news->count();         // how many this page returned
$everything = $news->total();      // everything that matched, ignoring paging

if ($news->isEmpty()) {
    esc_html_e('Nothing published yet.', 'my-theme');
}
```
```js Over REST
const url = new URL('https://your-site.example/wp-json/schemapress/api/news')
url.searchParams.set('sort', '-publishedAt')
url.searchParams.set('limit', '1')

const { data, meta } = await (await fetch(url)).json()

const latest = data[0] ?? null
const everything = meta.pagination.total
```
```graphql In GraphQL
query LatestNews {
  news(sort: [{ field: PUBLISHED_AT, direction: DESC }], limit: 1) {
    nodes { id title: headline }
    total
  }
}
```
:::

### Filtering, ordering and paging

Each has a page of its own, and each spells every example for every surface:

| | |
| --- | --- |
| **The query object** | What PHP holds, and how the result reaches a template. |
| **Filters** | Equality, comparisons, lists, `and` and `or`. |
| **Sorting & pagination** | Ordering by any field; pages and offsets. |
| **What can be queried** | Which field types can be filtered and sorted at all. |
| **GraphQL** | The schema, and how the same grammar is spelled in it. |

### What you get when you do not ask

A query with nothing on it still has answers to these, and they are worth knowing because
they are what a first attempt returns:

| | In PHP | Over REST and GraphQL |
| --- | --- | --- |
| How many | 10 | 25 |
| Most it will give | 100 | 100 |
| In what order | Most recently edited first | Most recently edited first |

The two sizes differ on purpose: a template that loops a collection without asking for a
size should not render two thousand rows, and a client that asked for a page is usually
drawing a page. The order is the same everywhere — the entry edited most recently comes
first, which is the useful default for a listing.

:::caution An order you did not ask for is not a promise
Two entries edited in the same second can come back in either order, and republishing one
moves it. Any listing a reader might page through, bookmark or diff wants an explicit
sort — see **Sorting & pagination**.
:::

### Only published content, everywhere

Every surface returns published content and nothing else, including the one that runs
inside WordPress with no permission check of its own. A draft is not in a list, and a draft
asked for by id is a `404` over REST and a `null` in PHP and GraphQL.

That is deliberate and not configurable. A template cannot render half-finished work by
forgetting to ask, which is the failure nobody notices until it is on the live site. See
**Drafts**.
