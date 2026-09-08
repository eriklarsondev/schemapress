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

| Method | Path | Switch | Returns |
| --- | --- | --- | --- |
| `GET` | `/{collection}` | Read many | A page of entries |
| `GET` | `/{collection}/{id}` | Read one | One entry |

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
A route answers only when **two** switches agree — the site's master switch and the
collection's own. Until then the address is a `404` or a `403`.
:::

**The collection's own**, under **Public API** in its Settings: *Read many* and *Read one*,
separately. Every collection answers independently, and nothing is exposed by default.

**The site's master switch**, under **Settings** in the sidebar: *REST Content API*, one
switch over the whole thing. On by default, because it cannot expose anything by itself —
the opt-in is the per-collection pair.

Off, the `schemapress/api` namespace is **not registered at all**. It does not appear in the
`/wp-json/` index, and every address under it answers WordPress's own `rest_no_route` 404 —
so nothing advertises that this site has a content API, or which collections are in it.
That is a stronger thing than a route that answers in order to refuse.

The two answer different questions. The collection's says *should this be readable*; the
master switch says *is this site serving content over HTTP at all* — the one you reach for
when the answer has to be "not right now, for anything". What each collection chose is
kept, so turning it back on restores the site as it was rather than needing every
collection edited again.

The **Settings** screen lists every collection with its own pair of switches, so what the
site publishes can be read and changed in one place rather than one dialog at a time. The
switches there are the same ones as on each collection's screen — set in either, stored on
the collection. With the master switch off they are shown greyed, because they still say
what will happen when it goes back on.

Separating **Read one** from **Read many** is worth the extra switch. A client that already
holds an entry's id — from a webhook, a link, a build step — needs only *Read one*, and
leaving *Read many* off means nobody can walk the collection to find out what else is in
it. Ids are random uuids, so they cannot be guessed or counted through.

This mirrors Strapi, where the Public role has to be granted `find` and `findOne` per
content type. The reasoning is the same: a switch that makes content world-readable should
never be the default.

:::note The master switch is the content API only
It does not touch PHP or Twig. `SchemaPress::collection()` and `sp_collection()` run on the
server and never go over HTTP, so a page your theme renders is unaffected either way. Nor
does it touch the builder's own transport, which is how the admin screens load at all.
:::

### Authentication

None. The routes are public reads of published content — no key, no header, no cookie.

That is the whole access model: a shape of read is open or it is not. If you need
per-consumer access control, put a gateway in front of it.

:::note An entry is addressed by its id or its slug
`/{collection}/{id}` takes either the `id` the API reports — a random uuid — or the entry's
`slug`. Both are in every response, so a front end holding one never has to list the
collection to find the other.

It does **not** accept the underlying WordPress post id, deliberately: a post id is a row
number, so accepting one would let anybody read a collection by counting `1, 2, 3` with
*Read many* switched off. A slug is safe for the same reason it is useful — it is chosen,
not sequential, and says nothing about how many entries exist.
:::
