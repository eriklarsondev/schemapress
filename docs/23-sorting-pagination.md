<!-- group: Querying content -->
<!-- description: Ordering by any field and paging by page or by offset, spelled for all three surfaces — and what the totals count. -->

## Sorting & pagination

### Sorting

:::tabs
```php In PHP
<?php
$byName = SchemaPress::collection('team-members')->sort('full_name')->get();
$newest = SchemaPress::collection('team-members')->sort('full_name', 'desc')->get();

// the entry's own keys sort too, and combine with a field sort
$grouped = SchemaPress::collection('team-members')
    ->sort('role')
    ->sort('publishedAt', 'desc')
    ->get();
```
```js Over REST
const url = new URL('https://your-site.example/wp-json/schemapress/api/team-members')

url.searchParams.set('sort', 'full_name')       // ascending
url.searchParams.set('sort', '-full_name')      // descending
url.searchParams.set('sort', 'role,-full_name') // several, comma separated

const { data } = await (await fetch(url)).json()
```
```graphql In GraphQL
query Ordered {
  teamMembers(
    sort: [
      { field: ROLE, direction: ASC }
      { field: PUBLISHED_AT, direction: DESC }
    ]
  ) {
    nodes { fullName }
  }
}
```
:::

Over REST `?sort[0]=role:asc&sort[1]=full_name:desc` says the same as the comma form, for a
client that builds arrays.

Direction defaults to `asc`. A leading `-` means descending, and `field:desc` says the
same thing — use whichever reads better. Alongside your own fields, five names sort on the
entry itself: `title`, `slug`, `createdAt`, `updatedAt`, `publishedAt`.

:::note One field sort per request
WordPress orders by a single custom field at a time, so a second field-based sort is
ignored rather than quietly reordering the first. `title`, `slug`, `createdAt` and
`updatedAt` live on the entry's own row and can be combined freely with each other and
with one field sort.

**`publishedAt` is the exception**, because it is not a column — it is read from the same
stored value the response reports, which is the only way the order and the values can
agree. That costs the one custom-field slot, so `sort=publishedAt,role` orders by
`publishedAt` and ignores `role`.
:::

:::caution `createdAt` and `publishedAt` are different questions
`createdAt` is when the entry was made and never moves again. `publishedAt` is when its
published copy last moved forward, so **it changes every time somebody republishes** — on
a collection that keeps no drafts, that is every save.

Sorting an "our latest" list by `createdAt` keeps a corrected old entry where it was;
sorting it by `publishedAt` brings it back to the top. Neither is wrong, but they are not
interchangeable, and `publishedAt` is the one most people reach for when they mean
`createdAt`.
:::

Ask for no order and you get the most recently edited first, on every surface. It is a
useful default and not a promise: two entries edited in the same second can come back in
either order, and republishing one moves it. Anything a reader might page through or
bookmark wants an explicit sort.

### Paging

By **page** — a page number and a size:

:::tabs
```php In PHP
<?php
// archive-news.php
$page = max(1, (int) get_query_var('paged'));

$news = SchemaPress::collection('news')
    ->sort('publishedAt', 'desc')
    ->limit(20)
    ->page($page);

foreach ($news as $item) {
    echo esc_html($item->headline);
}

$pages = (int) ceil($news->total() / 20);
```
```js Over REST
const url = new URL('https://your-site.example/wp-json/schemapress/api/news')

url.searchParams.set('sort', '-publishedAt')
url.searchParams.set('limit', '20')
url.searchParams.set('page', '2')

// `?pagination[page]=2&pagination[pageSize]=20` says the same thing

const { data, meta } = await (await fetch(url)).json()
const pages = meta.pagination.pageCount
```
```graphql In GraphQL
query SecondPage {
  news(sort: [{ field: PUBLISHED_AT, direction: DESC }], limit: 20, page: 2) {
    nodes { headline }
    total
  }
}
```
:::

Or by **offset** — skip this many entries, then take this many. The right shape for a
"load more" button, or for a feature row above a grid that has to skip what the row already
showed:

:::tabs
```php In PHP
<?php
$featured = SchemaPress::collection('news')->sort('publishedAt', 'desc')->limit(3)->get();

// the grid below it, skipping what the row already showed
$rest = SchemaPress::collection('news')
    ->sort('publishedAt', 'desc')
    ->offset(3)
    ->limit(12)
    ->get();
```
```js Over REST
const url = new URL('https://your-site.example/wp-json/schemapress/api/news')

url.searchParams.set('sort', '-publishedAt')
url.searchParams.set('start', '3')
url.searchParams.set('limit', '12')

// `?pagination[start]=3&pagination[limit]=12` says the same thing

const { data } = await (await fetch(url)).json()
```
```graphql In GraphQL
query Rest {
  news(sort: [{ field: PUBLISHED_AT, direction: DESC }], offset: 3, limit: 12) {
    nodes { headline }
  }
}
```
:::

:::note A page and an offset are alternatives
Setting an offset means the page number is not consulted — WordPress counts one or the
other, not both. Over REST, `start` is what makes a request an offset request: a bare
`limit` beside a bare `page` is a page size.
:::

### What comes back

Over REST, `meta.pagination` says where in the collection you are, and its shape follows
the style you asked in:

Asked by page:

```json
{ "meta": { "pagination": { "page": 2, "pageSize": 50, "pageCount": 4, "total": 173 } } }
```

Asked by offset:

```json
{ "meta": { "pagination": { "start": 25, "limit": 25, "total": 173 } } }
```

In PHP the same two numbers are `total()` and `count()`; in GraphQL they are the `total`
and `count` fields beside `nodes` — everything that matched, and however many this page
returned.

`total` counts what **matched the filters**, not the size of the collection, so paging
through a filtered set works and `pageCount` is the number of requests it will take. An
offset does not change it: `->offset(1)->limit(2)->total()` on four entries is still four.

### Sizes

| | In PHP | Over REST and GraphQL |
| --- | --- | --- |
| Default size | 10 | 25 |
| Maximum size | 100 | 100 |

The defaults differ on purpose. A template that loops a collection without asking for a
size should not render two thousand rows; a client that asked for a page is usually
drawing a page. Asking for more than the maximum is clamped to it rather than refused, so a
client that wants everything pages for it.
