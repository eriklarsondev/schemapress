<!-- group: Querying content -->
<!-- description: Every open collection as a WPGraphQL type — the naming rules, the arguments, and why the schema shows only what REST shows. -->

## GraphQL

Install [WPGraphQL](https://wordpress.org/plugins/wp-graphql/) and every collection you
have opened for reading joins the site's GraphQL schema, beside its posts and pages. There
is no second endpoint and nothing to configure: the schema is built from your collections
each time WPGraphQL assembles it.

```graphql
query Team {
  teamMembers(where: { role: { eq: "Engineer" } }, sort: [{ field: FULL_NAME }], limit: 20) {
    nodes {
      id
      slug
      fullName
      role
      avatar { url alt width height }
    }
    total
  }
}
```

:::note WPGraphQL is not bundled
For the reason nothing else is: a site that wants GraphQL already runs it, and a second
copy on the autoloader would make load order decide which version the schema was built
against. Without it, nothing here runs and nothing else changes.
:::

### It shows exactly what REST shows

The site's Content API switch and each collection's own **Read many** / **Read one** pair
govern the GraphQL schema as well — see **Endpoints**. A collection closed to REST is
**absent** from the schema, not present and refusing: no type, no query, nothing to
introspect.

That is the point. Adding a query language must not widen what is public, and the only way
to be sure of it is to have one answer to "is this readable" rather than two. Turning the
site's Content API off takes the whole thing out of the schema.

Drafts never appear here either, for the same reason they never appear anywhere else.

### Names

GraphQL has no dashes in its names, so the plural-hyphen form every other surface takes
cannot be spelled here. The rules are mechanical:

| | | |
| --- | --- | --- |
| The type | `team_member` | `TeamMember` |
| The list query | `team_members` | `teamMembers` |
| The single query | `team_member` | `teamMember` |
| A field | `full_name` | `fullName` |
| A sort value | `full_name` | `FULL_NAME` |

Two field keys can want one camelCase name — `full_name` and `fullname` both ask for
`fullName`. The first in the schema keeps it and the rest fall back to their key verbatim,
which is legal and unique by construction. The schema is introspectable, so what a field is
actually called is always one query away.

The same rule covers the names the entry brought with it. `id`, `slug`, `createdAt`,
`updatedAt` and `publishedAt` belong to the entry, so a field labelled "ID" does not get
`id` — it is registered as `idField`. `id` is declared non-null `ID` and is what addresses
the entry, and a collection's own field is not allowed to stand in for it.

### What a query returns

Not a Relay connection. A connection needs a cursor — a stable position in an ordering —
and this is a read API over content ordered by whatever you sorted it by, with no cursor to
hand out. Instead each list query returns the same two things `data` and `meta.pagination`
carry over REST:

| | |
| --- | --- |
| `nodes` | The entries on this page |
| `total` | Everything that matched, ignoring paging |
| `count` | How many this page returned |

### One entry

The single query takes `id` or `slug` — the same two identifiers `/{collection}/{id}`
takes over REST, and for the same reason: a front end routing `/team/ada-lovelace` holds
the slug and not the uuid.

```graphql
query OnePerson {
  teamMember(slug: "ada-lovelace") {
    fullName
    role
    avatar { url alt }
  }
}
```

Give it neither and it returns `null` rather than an arbitrary entry. So does an id that
matches nothing, and so does one matching an entry that is not published — `null` is the
only answer this query has, because an error would confirm that something is there.

It is governed by **Read one**, separately from the list query's **Read many**, exactly as
over REST. A collection can answer `teamMember` without answering `teamMembers`.

### Filtering

The same grammar as everywhere else, as typed inputs. Each field takes the filter its
values compare as — text, number or flag — and only fields that **can** be filtered are
offered, so the schema itself says what is askable:

```graphql
query Engineers {
  teamMembers(
    where: {
      role: { eq: "Engineer" }
      headcount: { gte: 10 }
      fullName: { startsWith: "G" }
    }
  ) {
    nodes { fullName }
  }
}
```

| Input | Takes |
| --- | --- |
| `SchemaPressStringFilter` | `eq` `ne` `contains` `notContains` `startsWith` `endsWith` `lt` `lte` `gt` `gte` `in` `notIn` `between` `isNull` |
| `SchemaPressNumberFilter` | `eq` `ne` `lt` `lte` `gt` `gte` `in` `notIn` `between` `isNull` |
| `SchemaPressBooleanFilter` | `eq` `ne` `isNull` |

`isNull` is the pair `$null`/`$notNull` as one flag, because `isNull: false` is what a
GraphQL author writes and two operators for one question is not.

**`and` and `or`** take a list, and each entry in the list is a whole `where` of its own —
so `or: [{a, b}, {c}]` asks for `(a AND b) OR c`, and two entries may name the same field:

```graphql
query Either {
  teamMembers(
    where: {
      or: [
        { role: { eq: "Designer" } }
        { joined: { isNull: true } }
      ]
    }
  ) {
    nodes { fullName }
  }
}
```

### Sorting and paging

`sort` takes a list of clauses, each naming a field from the collection's own sort enum and
a direction. The enum holds every field that can be sorted, plus `TITLE`, `SLUG`,
`CREATED_AT`, `UPDATED_AT` and `PUBLISHED_AT` for the entry's own keys.

`limit` is how many, up to 100. `page` and `offset` are alternatives — an offset counts
entries rather than pages, and setting one means the page is not consulted.

```graphql
query SecondPage {
  teamMembers(
    sort: [{ field: FULL_NAME, direction: ASC }]
    limit: 20
    page: 2
  ) {
    nodes { fullName }
    total
  }
}
```

`search` is there too, and matches the entry's text the way `?search=` does.

### Values

Every field type resolves to the same data the REST API serves — resolved, so an image is
its attachment rather than an id. **Displaying values** has the type-by-type table; in the
schema it comes out as:

Every entry type also carries `id` (non-null `ID`), `slug`, `createdAt`, `updatedAt` and
`publishedAt`, alongside the collection's own fields.

| Field type | GraphQL |
| --- | --- |
| Text, Textarea, Email, URL, Phone, Rich text | `String` |
| Date, Date and time, Time, Color, JSON | `String` |
| Number | `Float` |
| Toggle | `Boolean` |
| Dropdown | `String`, or `[String]` when it takes several |
| Image, File | `SchemaPressMedia` |
| Gallery | `[SchemaPressMedia]` |
| Link | `SchemaPressLink` |
| Group | a type of its own, named after the field |
| Repeater | a list of one, with the rows already unwrapped |

`SchemaPressMedia.sizes` is a **list** rather than a map, because GraphQL has no map type —
each entry carries the size's `name` alongside its `url`, `width` and `height`.

```graphql
{
  avatar {
    url
    alt
    srcset
    sizes { name url width height }
  }
}
```
