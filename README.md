# SchemaPress

A structured content system for WordPress. You define a **collection type** — a named shape
with typed fields — and it gives you an admin for filling it in and a REST API for reading
it back.

If you know **Strapi**, you know the model. Same idea, living inside WordPress instead of
beside it:

| Strapi | SchemaPress |
| --- | --- |
| Collection type | Collection type |
| Content-Type Builder | The **Schema** tab |
| Content Manager | The **Entries** tab |
| REST API + Users & Permissions | **Public API**, switched on per collection |
| `GET /api/:pluralApiId` | `GET /wp-json/schemapress/api/:collection` |

Underneath it is still WordPress. Entries are posts, so existing queries, capabilities and
backups keep working.

The plugin **renders nothing**. A collection describes what content *is*; what it *looks
like* is your theme's business. That one line explains most of the design decisions in here.

## Requirements

| | |
| --- | --- |
| WordPress | 6.2+ |
| PHP | 8.2+ (enforced in the plugin header — Timber 2 needs it) |
| Node | 18+ for the build |
| Timber | Optional, 2.x, only for the Twig functions |

## Getting set up

```bash
git clone <repo> wp-content/plugins/schemapress
cd wp-content/plugins/schemapress

composer install          # Markdown parser for the docs screen, Timber
npm install && npm run build
```

Activate it in **Plugins**, then open **SchemaPress** in the admin menu.

If the admin screens do not appear, `build/` is missing — run the npm step. If the built-in
documentation renders as plain text, `vendor/` is missing — run the composer step.

```bash
npm start                 # watch build while working on the admin
npm run build             # production build; commit build/ with your change
php tests/collections.php # the test suite (197 assertions, no framework)
```

There is no CI yet. Run the suite and the build before you push.

## How it fits together

Three layers, and the boundary between them is the point.

**The model** — `classes/class-schema-model.php` and friends. Pure transformations over a
collection's definition: normalising it, validating field types, resolving stored values
into what a template consumes. No WordPress calls where it can be avoided, which is why the
test suite can run it against stubs.

**Storage** — `class-entries.php`. Entries are posts; a whole entry's values are one JSON
blob in one meta row. That is the right shape for reading an entry and the wrong shape for
asking questions across a collection, so `class-index.php` mirrors every scalar value into
its own meta row where SQL can reach it. The blob is the record; the index is derived and
rebuilt from it on every publish.

**Delivery** — three surfaces over one query:

| | |
| --- | --- |
| `class-content.php` | `SchemaPress::collection('team_member')` — aliased globally for themes |
| `class-timber.php` | `sp_collection()` and friends, registered on Twig |
| `class-api.php` | the public REST API, off until a collection opts in |

`class-query.php` is the shared query grammar, so a filter means the same thing in a Twig
template as it does in a URL. `class-rest.php` is a **separate** transport for the admin
app — every route on it is capability-checked. Keeping the two apart means a change to the
editor's transport cannot widen what the public can read.

The admin is React (`src/admin`), sharing field controls with the front-of-house renderer
(`src/shared/fields`) so the entry form and a rendered group cannot drift apart.

## Conventions

**Comments say why, not what.** The code says what it does. A comment earns its place by
recording the thing that is not visible — the bug that made a rule necessary, the option
that was rejected, the browser that misbehaves. Several in here name a specific failure;
that is deliberate, so nobody re-introduces it.

**Class files** are `classes/class-{kebab-name}.php`, autoloaded from the namespaced class
name. No Composer classmap to regenerate.

**Formatting.** The JS is 2-space, single quotes, no semicolons. There is no Prettier or
ESLint config in the repo, and running either with default settings will reformat whole
files — please don't. Match the file you are editing.

**Colour and contrast.** Every token in `src/shared/style.css` is measured, and the comments
record the ratio and why it was chosen. If you change one, measure it. Body text clears
4.5:1; the focus ring clears 3:1.

**Documentation lives in `docs/*.md`**, one file per page, compiled into the admin's
Documentation screen and readable as-is on GitHub. Numeric prefixes order it; a
`<!-- group: -->` and `<!-- description: -->` comment on the first two lines place it in
the sidebar. Add a file and it appears — there is no list to update.

## Contributing

1. Branch off `main`.
2. Make the change. Match the surrounding style; add comments where the reasoning is not
   obvious from the code.
3. Run `php tests/collections.php` and `npm run build`. Commit `build/` alongside the
   source, since the plugin ships built.
4. If you changed behaviour a user would notice, update the relevant page in `docs/`.
5. Open a PR describing what changed and why the approach was chosen.

**Adding a field type** touches four places: the registry (`class-field-types.php`), the
resolver if it stores something other than what it renders (`class-resolver.php`), the
control (`src/shared/fields/`), and the index if it should be filterable
(`class-index.php`). The test suite covers the first two.

**Changing the query grammar** means changing `class-query.php` only — all three delivery
surfaces read it.

## Known rough edges

- No CI. The suite is a plain PHP script; wiring it to Actions is an obvious first job.
- The admin bundle is ~300KB, over webpack's advisory. Prism accounts for ~37KB and is a
  candidate for a dynamic import once someone can verify chunk loading in a plugin
  subdirectory.
- `$or` filtering is only expressible in the bracket syntax; the flat parameter form has no
  spelling for it.
- Repeater contents cannot be filtered or sorted — one meta row cannot hold many values.
  A custom index table is the way in if that is ever needed.
