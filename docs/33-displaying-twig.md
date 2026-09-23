<!-- group: Displaying content -->
<!-- description: Rendering entries a PHP file handed you — why properties work, escaping and |raw, images, repeater rows and partials. -->

## Values in Twig

A Twig template renders entries; it does not fetch them. The query is built in the PHP file
above it and the result passed into the context — see **The query object**. Everything
below assumes `person` came in that way:

```php
<?php
// archive-team.php
$context = Timber::context();
$context['team'] = SchemaPress::collection('team-members')->sort('full_name')->get();

Timber::render('team.twig', $context);
```

This plugin registers nothing with Twig. An `Entry` answers an array key, a property and a
method on its own, which is all a template needs.

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

**The entry's own names win over a field of the same name.** `id`, `title`, `slug`,
`state`, `modified`, `isPublished` and `rows` are the ones worth remembering, and the rule
is the whole of an entry's own vocabulary — anything it answers as a method, `get` and
`has` included. If your collection has its own field called `title`, reach it explicitly:

```twig
{{ person.get('title') }}
```

The entry wins because it has to be the half that cannot change: which names are taken
would otherwise depend on what somebody called a field, and adding a field called `slug`
would quietly break every template already reading `person.slug`.

`get()` also takes a default and a dot path into group fields:

```twig
{{ person.get('nickname', 'Anonymous') }}
{{ person.get('address.city') }}
{% if person.has('bio') %}…{% endif %}
```

### Escaping, and the one exception

Twig escapes on output, so `{{ person.full_name }}` is already safe. Rich text is the
exception — it is HTML by definition, so it needs `|raw`:

```twig
<div class="prose">{{ person.bio|raw }}</div>
```

:::warning
Only ever use `|raw` on a field you know is rich text. On any other field it disables the
escaping that makes the value safe to print.
:::

### Images and links

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

### Repeaters

`rows()` gives each row as its own accessor:

```twig
{% for link in person.rows('links') %}
  <a href="{{ link.get('url.url') }}">{{ link.get('label') }}</a>
{% endfor %}
```

### Including a partial per entry

The usual Timber pattern works unchanged, because an entry is just an object:

```twig
{% for person in team %}
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

### A full template

```twig
{% extends "base.twig" %}

{% block content %}
  <main class="team">
    <h1>The team</h1>

    {% if team is empty %}
      <p>Nobody here yet.</p>
    {% else %}
      <ul class="team__grid">
        {% for person in team %}
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
              <div class="team__bio">{{ person.bio|raw }}</div>
            {% endif %}
          </li>
        {% endfor %}
      </ul>
    {% endif %}
  </main>
{% endblock %}
```
