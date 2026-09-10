<!-- group: Content API -->
<!-- description: The ETag every response carries, when to set a max-age, and what each one promises. -->

## Caching

Every API response carries an **ETag**, whatever the site's settings say.

```
HTTP/1.1 200 OK
ETag: "4f2a1c8e9b3d7a6f5e2c1b0a9d8e7f6c"
Cache-Control: public, max-age=0, must-revalidate
Vary: Accept-Encoding, Origin
```

Send it back on the next request and you get a 304 with no body:

```bash
curl -H 'If-None-Match: "4f2a1c8e9b3d7a6f5e2c1b0a9d8e7f6c"' \
  https://example.com/wp-json/schemapress/api/team-members
```

The ETag is a hash of the response body, which is exactly the right thing to key on here:
the body is a pure function of the published content and the query, so it changes when — and
only when — the answer does.

:::tip This is the win, and it costs nothing
A build step polling a collection every minute was getting a full WordPress bootstrap, a
query and the whole resolver each time, to be handed bytes it already had. The query still
runs on a revalidation, but nothing is serialized or transferred, and that is the bulk of a
listing's cost.
:::

### max-age is a different promise

`Settings → Caching` sets how long a response may be considered **fresh**. It is zero by
default, and the two directives mean different things:

| | Says |
| --- | --- |
| **ETag** | Ask me and I will answer cheaply |
| **max-age** | Do not ask me for this long |

Above zero, this reaches caches you do not control — a CDN, a reverse proxy, a browser. A
correction published now may take that long to appear.

:::caution Set it for content, not for traffic
An hour is right for a list of countries and wrong for a news collection. If you are not
sure, leave it at zero: `must-revalidate` plus an ETag already means a cache never has to
transfer a body it has, and never serves a stale one.
:::

The ceiling is one day. This is a directive to systems the site cannot reach to purge, and
a day of an editor wondering why their change has not appeared is the most this setting
should be able to cost somebody who turned it up without reading this page.

### What is not cached

The admin transport, `schemapress/admin/v1`, sets no cache headers at all. It returns draft
content and accepts writes, and neither is something to hold on to.

### Purging on publish

If you front the site with a CDN, hang a purge on the publish action:

```php
add_action('schemapress/entry_published', function ($id, $values, $type_id) {
    $key = SchemaPress\ContentType::key($type_id);

    wp_remote_post('https://example.test/purge', [
        'blocking' => false,
        'body' => ['tag' => "schemapress:{$key}"],
    ]);
}, 10, 3);
```

`entry_unpublished` and `entry_deleted` are the pair to it — anything built from an entry
being live has to come down when it stops being.
