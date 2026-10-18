<!-- group: Get started -->
<!-- description: Structured content for WordPress — the concepts, the vocabulary, and what this is not. -->

## Introduction

SchemaPress is a **structured content system for WordPress**. You define a content type —
a named shape with typed fields — and it gives you an admin for filling it in and an API
for reading it back.

The vocabulary is small, and the admin is laid out along it:

| Term | What it is |
| --- | --- |
| **Collection type** | A named shape with typed fields — Team Members, Grants, News Articles |
| **Field** | One typed value on that shape, with its own rules |
| **Entry** | One filled-in instance of a collection type |
| **Component** | A group of fields defined once and imported into as many collections as you like |
| The **Schema** tab | Where you decide what an entry is |
| The **Entries** tab | Where you fill entries in |
| **Public API** | Read many and read one, per collection, behind one master switch |

One collection answers on one address: `GET /wp-json/schemapress/api/:collection`.
Filtering, sorting and paging are query-string parameters, and the plain form is the one to
reach for: `?role=Engineer&sort=-full_name&limit=50`. A bracketed long form —
`?filters[headcount][$gte]=10` — is accepted as well, for clients that build queries that
way.

### Why not just custom post types

A custom post type gives you a row in the database and leaves the rest to you: the fields,
the admin screen, the validation, the API shape. SchemaPress is the rest.

You describe the shape once and get the editing UI, the typed values, and a read API
without writing any of them. Underneath it is still WordPress — entries are posts, so your
existing queries, capabilities and backups keep working.

### What it is not

It does not render anything. A collection describes what content **is**; what it **looks
like** is your theme's business. There are no widths, columns, colors or CSS classes in a
collection.

That line is the whole design. It is why the same entry can come out as a Twig template, a
PHP loop, or JSON to a front end that is not WordPress at all. **Querying** covers asking
for it on all three, and **Displaying values** covers rendering what comes back.

:::note One exception, and it is not presentation
The **Form** tab arranges fields on the *entry form* — which order you fill them in, how
wide each control is, which sit in the sidebar beside the entry's status. It shapes the
admin screen and never reaches the front end.
:::

### The model

Three things, deliberately:

| | |
| --- | --- |
| **Collection type** | A shape you have many of. Team Members, News Articles, Events. |
| **Field** | One piece of an entry. Name, Bio, Photo, Author. |
| **Entry** | One of the things. Ada Lovelace. |

A collection has a **machine key** derived from its name — `team_member` for "Team Member" —
and you ask for it by the plural, hyphenated: `team-members`, in a template and in a URL
alike. Neither follows a later rename: renaming a label should not break every template
that reads it.
