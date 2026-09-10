<!-- group: Modeling content -->
<!-- description: Ready-made option lists a Dropdown can draw from, and registering your own site's vocabulary as one. -->

## Datasets

A country list typed by hand is a country list that is missing three countries, spells one
of them differently from the next collection, and stores "USA" where its neighbor stores
"United States".

A **dataset** is a named list a Dropdown can point at instead.

### The built-in ones

| Name | Values are |
| --- | --- |
| `countries` | ISO 3166-1 alpha-2 — `GB`, `US`, `DE` |
| `us_states` | USPS abbreviations, including DC and the territories |
| `ca_provinces` | Two-letter provincial codes |
| `au_states` | State and territory abbreviations |
| `months` | `1` to `12` |

Choose one on a Dropdown field's settings, under **Options from**.

:::note Values are codes, labels are words
What gets stored is `GB`, not "United Kingdom". That survives a label being reworded, sorts
predictably, and is what an API client or a shipping form actually wants. The label is only
what an editor reads while choosing.
:::

### A field stores which list, never a copy of it

That is the whole reason for naming a list rather than pasting one. A correction to a
dataset reaches every field that points at it, immediately, with nothing re-saved.

It also means the sanitizer and the control agree: a value the dropdown did not offer is
discarded on save, and both sides ask the same dataset what was on offer.

### Registering your own

A site with its own closed vocabulary registers it once and every Dropdown can use it, with
the same guarantee:

```php
add_filter('schemapress/datasets', function ($sets) {
    $sets['departments'] = [
        'label' => 'Departments',
        'options' => [
            ['value' => 'eng', 'label' => 'Engineering'],
            ['value' => 'design', 'label' => 'Design'],
            ['value' => 'ops', 'label' => 'Operations'],
        ],
    ];

    return $sets;
});
```

It appears in the **Options from** picker on every Dropdown, named by its label.

:::caution Removing a value does not remove it from entries
Entries that stored `ops` keep storing it after the option is gone — nothing goes and
rewrites content. They will fail validation the next time they are saved through the form,
which is the point at which somebody chooses what they should say instead.
:::

### Datasets in an export

They are **not** exported, and deliberately: a dataset is code, registered by a filter, and
a site reading the export either runs that code or does not. A field pointing at a dataset
the destination has never heard of falls back to its own options list, which is empty —
visibly broken rather than silently storing values nothing offered.
