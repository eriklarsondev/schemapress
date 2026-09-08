<!-- group: In your theme -->
<!-- description: The Timber context pattern, the Twig functions, and reading values inside a template. -->

## Twig & Timber

%%timber_status%%

Timber is optional. Without it everything on the **PHP** page works exactly the same —
only the Twig functions below are missing, and a theme not using Twig would not have called
them. Timber **2.x** is what these register against.

### Two ways, and the first is the Timber one

**Fetch in the page template, pass it in the context.** This is how a Timber theme already
works — the PHP file gathers what the page needs and the Twig file renders it:

```php
// page-team.php
$context = Timber::context();

$context['team'] = SchemaPress::collection('team_member')
    ->where('role', 'Engineer')
    ->sort('full_name')
    ->get();

Timber::render('team.twig', $context);
```

```twig
{# team.twig #}
{% for person in team %}
  <h2>{{ person.full_name }}</h2>
{% endfor %}
```

Reach for this when the page does anything else with the data — a count in the title, a
redirect when it is empty, a value passed to two templates. The query is ordinary PHP at
that point, so all of **PHP** applies.

**Or ask for it from the template.** Three functions are registered on Twig, so a template
that only needs to loop something does not need a PHP file to hand it over:

| | |
| --- | --- |
| `sp_collection(key)` | the query for a collection |
| `sp_collections()` | every collection's key |
| `sp_has_collection(key)` | whether one exists |

```php
// page-team.php — nothing to fetch
Timber::render('team.twig', Timber::context());
```

```twig
{# team.twig #}
{% for person in sp_collection('team_member') %}
  <h2>{{ person.full_name }}</h2>
{% endfor %}
```

`sp_collection()` returns the same query object `SchemaPress::collection()` does, so every
method on it is callable from Twig too — including `.where()` and `.sort()`.

:::note Passing a query rather than its results
`$context['team']` above holds the entries, because `->get()` was called. Leave `->get()`
off and the context holds the *query*, which Twig will iterate just the same — and which
runs only if the template actually loops it.
:::

### A full template

```twig
{% extends "base.twig" %}

{% block content %}
  <main class="team">
    <h1>The team</h1>

    {% set people = sp_collection('team_member').sort('full_name').limit(50) %}

    {% if people.isEmpty %}
      <p>Nobody here yet.</p>
    {% else %}
      <ul class="team__grid">
        {% for person in people %}
          <li class="team__member">
            {% if person.avatar %}
              <img
                src="{{ person.avatar.sizes.medium.url }}"
                srcset="{{ person.avatar.srcset }}"
                alt="{{ person.avatar.alt }}"
                width="{{ person.avatar.width }}"
                height="{{ person.avatar.height }}">
            {% endif %}

            <h2>{{ person.full_name }}</h2>
            <p class="team__role">{{ person.role }}</p>

            {% if person.bio %}
              <div class="team__bio">{{ person.bio }}</div>
            {% endif %}
          </li>
        {% endfor %}
      </ul>
    {% endif %}
  </main>
{% endblock %}
```

Twig escapes on output, so `{{ person.full_name }}` is already safe. Rich text is the
exception — it is HTML by definition, so it needs `|raw`:

```twig
<div class="prose">{{ person.bio|raw }}</div>
```

:::warning
Only ever use `|raw` on a field you know is rich text. On any other field it disables the
escaping that makes the value safe to print.
:::

### Why properties work

Twig resolves `person.full_name` by trying, in order, an array key, a property, then a
method. Entries answer all three, so field keys read as properties and the entry's own
identity reads as methods — with no parentheses in either case:

```twig
{{ person.full_name }}     {# a field #}
{{ person.id }}            {# the entry's uuid #}
{{ person.title }}         {# the entry's title #}
{{ person.slug }}
{{ person.isPublished }}
```

Five names belong to the entry and win over a field of the same name — `id`, `title`,
`slug`, `state`, `rows`. If your collection has its own field called `title`, reach it
explicitly:

```twig
{{ person.get('title') }}
```

`get()` also takes a default and a dot path into group fields:

```twig
{{ person.get('nickname', 'Anonymous') }}
{{ person.get('address.city') }}
{% if person.has('bio') %}…{% endif %}
```

### Filtering and sorting in the template

The query object is chainable from Twig exactly as from PHP. The operators are on
**Filters** — same grammar as the REST API:

```twig
{% for person in sp_collection('team_member').where('role', 'Engineer').sort('full_name') %}
  {{ person.full_name }}
{% endfor %}
```

Longer chains read better assigned first:

```twig
{% set engineers = sp_collection('team_member')
    .where('role', 'Engineer')
    .where('full_name', '$startsWith', 'G')
    .sort('full_name', 'desc')
    .limit(20) %}
```

### Repeaters

`rows()` gives each row as its own accessor:

```twig
{% for link in person.rows('links') %}
  <a href="{{ link.get('url.url') }}">{{ link.get('label') }}</a>
{% endfor %}
```

### Images

An image resolves to an array, so every registered size and the srcset are already there:

```twig
{{ person.avatar.url }}                     {# full size #}
{{ person.avatar.sizes.thumbnail.url }}
{{ person.avatar.sizes.large.width }}
{{ person.avatar.srcset }}
{{ person.avatar.alt }}
```

An empty image field is `null`, which is falsey — so `{% if person.avatar %}` is the guard.

A link field is `['url', 'label', 'target']` or `null`:

```twig
{% if person.website %}
  <a href="{{ person.website.url }}" target="{{ person.website.target }}">
    {{ person.website.label ?: person.website.url }}
  </a>
{% endif %}
```

### Counting and paging

```twig
{% set news = sp_collection('news').sort('publishedAt', 'desc').limit(10).page(page) %}

<p>Showing {{ news|length }} of {{ news.total }}</p>
```

`|length` counts what this page returned; `.total` is the whole collection ignoring paging.
A query returns **10 entries** unless `.limit()` says otherwise.

### Listing what exists

```twig
{% for key in sp_collections() %}
  <li>{{ key }} — {{ sp_collection(key).total }} entries</li>
{% endfor %}
```

### Including a partial per entry

The usual Timber pattern works unchanged, because an entry is just an object:

```twig
{% for person in sp_collection('team_member') %}
  {% include 'partials/person-card.twig' with { person: person } %}
{% endfor %}
```

```twig
{# partials/person-card.twig #}
<article class="card">
  <h3>{{ person.full_name }}</h3>
  <p>{{ person.role }}</p>
</article>
```
