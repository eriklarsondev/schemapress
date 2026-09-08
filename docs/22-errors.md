<!-- group: Content API -->
<!-- description: The three refusals, what each one means, and why a collection that is switched off answers 403. -->

## Errors

WordPress's standard error shape, with the status in the body as well as in the response:

```json
{
  "code": "schemapress_api_disabled",
  "message": "This collection is not published to the API. Turn on Public API in its Settings.",
  "data": {
    "status": 403
  }
}
```

| Status | Code | Means |
| --- | --- | --- |
| `404` | `schemapress_unknown_collection` | No collection with that name |
| `403` | `schemapress_api_disabled` | The collection does not publish this shape of read |
| `404` | `schemapress_not_found` | No **published** entry with that id |

`403` rather than `404` for a closed collection is deliberate. You are building against a
collection you can see in the admin, and "not found" would send you hunting for a typo that
is not there.

`schemapress_api_disabled` means this collection's own **Read many** or **Read one** is off,
under Public API in its Settings.

:::caution A 404 on every address means the API is switched off
There is no error code for the master switch, because with it off there is nothing left to
answer: the `schemapress/api` namespace is not registered at all. It stops appearing in the
`/wp-json/` index and every address under it gets WordPress's own `rest_no_route` 404.

If a whole site's worth of routes has gone to `404` at once, check **REST Content API**
under **Settings** in the sidebar before hunting for a typo.
:::

:::note A 404 on a single entry can mean the id is a post id
`/{collection}/{id}` takes the uuid the API reports and nothing else. A WordPress post id
is refused rather than resolved, so a `404` on an entry you can see in the admin usually
means an internal id got as far as the URL.
:::
