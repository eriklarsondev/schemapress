<!-- group: Get started -->
<!-- description: Structured content for WordPress, modelled the way Strapi does it — the concepts, and what this is not. -->

## Introduction

SchemaPress is a **structured content system for WordPress**. You define a content type —
a named shape with typed fields — and it gives you an admin for filling it in and an API
for reading it back.

If you have used **Strapi**, you already know the model. It is the same idea, living inside
WordPress instead of beside it:

| Strapi | SchemaPress |
| --- | --- |
| Collection type | **Collection type** |
| Field | **Field** |
| Entry / document | **Entry** |
| Content-Type Builder | The **Schema** tab |
| Content Manager | The **Entries** tab |
| REST API + Users & Permissions | **Public API** — read many and read one per collection, behind one master switch |
| `GET /api/:pluralApiId` | `GET /wp-json/schemapress/api/:collection` |

Filtering, sorting and paging work the same way, and Strapi's own `filters[…]` bracket
syntax is accepted as well — so a client written against one reads against the other. The
form to reach for here is plainer: `?role=Engineer&sort=-full_name&limit=50`.

### Why not just custom post types

A custom post type gives you a row in the database and leaves the rest to you: the fields,
the admin screen, the validation, the API shape. SchemaPress is the rest.

You describe the shape once and get the editing UI, the typed values, and a read API
without writing any of them. Underneath it is still WordPress — entries are posts, so your
existing queries, capabilities and backups keep working.

### What it is not

It does not render anything. A collection describes what content **is**; what it **looks
like** is your theme's business. There are no widths, columns, colours or CSS classes in a
collection.

That line is the whole design. It is why the same entry can come out as a Twig template, a
PHP loop, or JSON to a front end that is not WordPress at all — see **Endpoints**, **PHP**
and **Twig & Timber**.

:::note One exception, and it is not presentation
The **Layout** tab arranges fields on the *entry form* — which order you fill them in, how
wide each control is. It shapes the admin screen and never reaches the front end.
:::

### The model

Three things, deliberately:

| | |
| --- | --- |
| **Collection type** | A shape you have many of. Team Members, News Articles, Events. |
| **Field** | One piece of an entry. Name, Bio, Photo, Author. |
| **Entry** | One of the things. Ada Lovelace. |

A collection has a **machine key** derived from its name — `team_member` for "Team Member".
That key is what templates and the API use, and it does not follow later renames: renaming
a label should not break every template that reads it.
