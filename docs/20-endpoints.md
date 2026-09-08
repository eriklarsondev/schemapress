<!-- group: Content API -->
<!-- description: The two addresses every collection answers on, how they are named, and switching one on. -->

## Endpoints

The addresses, and what may be asked of them. **Response format** covers what comes
back; **Filters** and **Sorting & pagination** cover the parameters.

The API is modelled on Strapi, deliberately and down to the parameter names, so a client
written against one reads against the other.

### Base URL

```http
https://your-site.example/wp-json/schemapress/api
```

`schemapress/api` is a WordPress REST namespace, which is why the address reads
`/wp-json/schemapress/api/…` rather than Strapi's bare `/api/…`. Everything after that
segment is the same.

There is no version in the path. The namespace is the version; a breaking change would
arrive as a new one.

### Resources

Every collection is one resource, with two routes:

| Method | Path | Returns |
| --- | --- | --- |
| `GET` | `/{collection}` | A page of entries |
| `GET` | `/{collection}/{id}` | One entry |

Read-only. There is no `POST`, `PUT` or `DELETE` — writing is the admin's job, and the
builder's own transport is a separate, capability-checked namespace.

The `{collection}` segment accepts either machine name and reads hyphens as underscores, so
all of these reach the same resource:

```http
/team-members     /team_members     /team_member
```

Use the plural-hyphen form. It is the one that reads like a URL.

### Enabling a resource

:::caution Prerequisites
A collection answers only after **Public API** is turned on in its Settings. Until then
both of its routes return `403`.
:::

Nothing is exposed by default.

This mirrors Strapi, where the Public role has to be granted `find` and `findOne` per
content type. The reasoning is the same: a switch that makes content world-readable should
never be the default.

### Authentication

None. The routes are public reads of published content — no key, no header, no cookie.

That is the whole access model: a collection is either published to the API or it is not.
If you need per-consumer access control, put a gateway in front of it.
