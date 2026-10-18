<!-- group: Content API -->
<!-- description: Encoding, a client with error handling, reading every page, caching and cross-origin requests. -->

## Writing a client

How to actually call it. **Endpoints** describes the addresses, **Querying** the
request, and **Values in JSON** the shape that comes back.

### Before anything works

Open the collection in the admin, go to **Settings**, and turn on **Public API**. The
switch shows the collection's endpoint once it is on — copy it from there rather than
assembling it by hand.

:::caution
Until it is on, every request to that collection answers `403`. If the site's **REST
Content API** master switch is off instead, there is no route to refuse and you get a `404`
— see **Errors**.
:::

### Encoding the query string

Plain equality is a flat `name=value` pair, so there is nothing exotic to encode:

```js
const params = new URLSearchParams({
  role: 'Engineer',
  sort: 'full_name',
  limit: '50',
})

const res = await fetch(`${BASE}/team-members?${params}`)
```

With curl, plainly:

```bash
curl -G 'https://example.com/wp-json/schemapress/api/team-members' \
  --data-urlencode 'role=Engineer' \
  --data-urlencode 'sort=full_name'
```

A list of alternatives is a comma-separated value, which is the only punctuation a value
carries:

```js
const params = new URLSearchParams({ role: 'Design,Engineering' })
```

An **operator goes in brackets** — `filters[field][$op]` — because a parameter name should
say which field it is about and nothing else. There is no `?headcount_gte=10`:

```js
const params = new URLSearchParams({
  'filters[headcount][$gte]': '10',
  'filters[full_name][$startsWith]': 'G',
})
```

:::caution A name that is not a field filters nothing
`?headcount_gte=10` is not an error — it is read as a filter on a field called
`headcount_gte`, which does not exist, so it is **dropped** and the request comes back with
the whole collection in it. Every filter on this API fails wide, so a client built on a
spelling it does not accept looks like it is working until somebody counts the rows. The
accepted shapes are listed in full under **Filters**.
:::

### A client

```js
const BASE = 'https://example.com/wp-json/schemapress/api'

async function fetchCollection(collection, params = {}) {
  const res = await fetch(`${BASE}/${collection}?${new URLSearchParams(params)}`)

  if (!res.ok) {
    // the error body carries a code worth branching on
    const { code, message } = await res.json()
    throw new Error(`${code}: ${message}`)
  }

  return res.json() // { data, meta }
}

const { data, meta } = await fetchCollection('team-members', {
  role: 'Engineer',
  sort: 'full_name',
})

data.forEach((person) => {
  console.log(person.full_name, person.avatar?.url ?? 'no photo')
})

console.log(`${data.length} of ${meta.pagination.total}`)
```

Two things worth copying from that: check `res.ok` before parsing, and read `code` rather
than matching on `message`. The message is human-facing text and may be translated; the
code is the contract.

### One entry

```js
const { data: person } = await fetchCollection(`team-members/${id}`)
```

A `404` here means no **published** entry with that id — which covers both "no such entry"
and "that one is still a draft". The API does not distinguish them, on purpose.

### Reading every page

`meta.pagination.pageCount` says how many pages the filter matched, so a full sweep is a
loop rather than a guess:

```js
async function fetchAll(collection, params = {}) {
  const entries = []
  let page = 1
  let pageCount = 1

  while (page <= pageCount) {
    const { data, meta } = await fetchCollection(collection, {
      ...params,
      page: String(page),
      limit: '100',
    })

    entries.push(...data)
    pageCount = meta.pagination.pageCount
    page += 1
  }

  return entries
}
```

100 is the cap, so that is the fewest requests a sweep can take. `total` counts what
matched the filters, so this stays correct with filters applied.

If entries are being published while you sweep, page boundaries can shift under you. Sort
by something stable — `?sort=publishedAt:asc` — if that matters.

### From PHP, off-site

Another WordPress install, or any PHP that is not this one:

```php
$url = 'https://example.com/wp-json/schemapress/api/team-members?' . http_build_query([
    'role' => 'Engineer',
    'sort' => 'full_name:asc',
]);

$response = wp_remote_get($url);

if (is_wp_error($response)) {
    return;
}

$body = json_decode(wp_remote_retrieve_body($response), true);

foreach ($body['data'] as $person) {
    echo esc_html($person['full_name']);
}
```

`http_build_query()` produces exactly the bracket syntax the API expects from a nested
array, so filters can be written as PHP arrays rather than as strings.

:::warning Do not call your own site over HTTP
On the same install, use the PHP API directly — see **The query object**. An HTTP round trip to
yourself is slower, can deadlock on a single-worker server, and returns the same data.
:::

### Caching

Every response carries an **ETag**, hashed from the body. Send it back in `If-None-Match`
and you get a `304` with no body — which is the single cheapest thing a polling client can
do:

```js
let etag = null
let cached = null

async function poll(url) {
  const res = await fetch(url, { headers: etag ? { 'If-None-Match': etag } : {} })

  // nothing has changed, and nothing was transferred to find that out
  if (res.status === 304) {
    return cached
  }

  etag = res.headers.get('ETag')
  cached = await res.json()

  return cached
}
```

`fetch` does this for you when the response is already in the browser's HTTP cache; a build
step or a server-side client has to hold the ETag itself.

`Cache-Control` is `max-age=0, must-revalidate` unless the site has set a max-age, so by
default a cache must ask every time — and gets a 304 for its trouble rather than a body. A
published entry changes only when somebody publishes it, so if you cache beyond that, key
on the full query string: two different filters are two different resources. See
**Caching** for what each directive promises and where the max-age is set.

### Cross-origin requests

These are ordinary WordPress REST routes and carry whatever CORS headers your install
sends. WordPress permits cross-origin reads of the REST API by default. If a request from
another origin is blocked, that is the site's configuration and not this plugin — look at
the `rest_allowed_cors_headers` and `rest_pre_serve_request` filters.

### Recipes

**Everything, newest first**

```http
?sort=-publishedAt
```

**A landing page's featured items**

```http
?featured=1&sort=title&limit=6
```

**Search as you type**

```http
?search=ada&limit=10
```

**One of several categories**

```http
?role=Design,Engineering
```

**A page of results, ordered**

```http
?role=Engineer&sort=-publishedAt&page=2&limit=20
```

**A comparison** — not something a parameter carries, so the bracket form:

```http
?filters[headcount][$gte]=10&sort=headcount
```

**Either of two conditions** — likewise:

```http
?filters[$or][0][role][$eq]=Design&filters[$or][1][lead][$eq]=1
```
