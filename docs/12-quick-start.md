<!-- group: Get started -->
<!-- description: A collection, a field and an entry — then reading it back from PHP, from Twig, or over HTTP. -->

## Quick start

A collection, an entry, and reading it back. Assumes the plugin is installed — see
**Installation** if not.

### Your first collection

Open **SchemaPress** in the admin sidebar and create a collection type. A collection is a
shape you have many of — Team Members, News Articles, Events.

Give it a name. A **machine key** is derived from it — `team_member` for "Team Member" — and
the name your templates and the API ask for is the plural, hyphenated: **`team-members`**,
the same string in a PHP file, a Twig template and a URL. Neither follows a later rename,
which is deliberate: renaming a label should not break every template that reads it.

Then add fields on the **Schema** tab. Each field has a type and a machine key of its own:

| Field | Key | Type |
| --- | --- | --- |
| Full Name | `full_name` | Text |
| Role | `role` | Text |
| Avatar | `avatar` | Image |
| Bio | `bio` | Rich text |

Two more tabs sit beside it. **Form** arranges those fields on the entry form — widths,
row breaks, what order you fill them in, and which go in the sidebar. It shapes the admin
screen and nothing else. **Entries** is where the content goes.

Add an entry and publish it.

### Reading it back

Now read it back. Pick the surface you are working in:

:::tabs
```php PHP
<?php
// archive-team.php
$people = SchemaPress::collection('team-members')->get();

foreach ($people as $person) {
    echo esc_html($person->full_name);
}
```
```php PHP + Twig
<?php
// archive-team.php — the PHP file queries; the template renders
$context = Timber::context();
$context['team'] = SchemaPress::collection('team-members')->get();

Timber::render('team.twig', $context);
```
```js REST
const res = await fetch('https://your-site.example/wp-json/schemapress/api/team-members', {
  headers: { Accept: 'application/json' },
})
const { data } = await res.json()

data.forEach((person) => console.log(person.full_name))
```
```graphql GraphQL
query Team {
  teamMembers {
    nodes { id fullName }
  }
}
```
:::

```twig
{# team.twig #}
{% for person in team %}
  <h3>{{ person.full_name }}</h3>
{% endfor %}
```

That is the whole integration. No import, no bootstrapping, no fetching in the PHP file
above the template.

Values arrive **resolved** — `$person->avatar['url']` is a URL, not an attachment id you
have to look up — and only **published** entries are ever returned, so a template cannot
render half-finished work by forgetting to ask.

From here the reference splits in two, and both halves cover all three surfaces:
**Querying** is how to ask for the entries you want, and **Displaying values** is what
comes back and how to put it on a page.

### Turning on the API

To read a collection from outside this WordPress install — a front end, a mobile app,
another service — open the collection's **Settings** and turn on **Public API**.

The switch shows the endpoint once it is on:

```http
GET /wp-json/schemapress/api/team-members
```

```json
{
  "data": [
    {
      "id": "86a8d140-…",
      "full_name": "Ada Lovelace",
      "role": "Engineer"
    }
  ],
  "meta": {
    "pagination": {
      "page": 1,
      "pageSize": 25,
      "pageCount": 1,
      "total": 1
    }
  }
}
```

:::caution Nothing is exposed by default
Every collection's API is off until you turn it on, and it only ever serves published
entries. Turning it on makes those entries readable by anyone who knows the address.
:::
