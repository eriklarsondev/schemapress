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
| REST API + Users & Permissions | **Public API** — read many and read one per collection, behind one master switch |
| `GET /api/:pluralApiId` | `GET /wp-json/schemapress/api/:collection` |

Underneath it is still WordPress. Entries are posts, so existing queries, capabilities and
backups keep working.

The plugin **renders nothing**. A collection describes what content *is*; what it *looks
like* is your theme's business. That one line explains most of the design decisions in here.

## What it does

**Modeling.** Twenty field types — text, rich text, the contact types, numbers, dates,
dropdowns (with built-in datasets for countries, states, provinces, months), image,
gallery, file, color, JSON, link, group and repeater. Fields carry rules — required,
length, range, pattern, unique — enforced on the server as well as in the form, and
conditions that show one field based on another's value. A **component** is a named group
of fields defined once and imported into as many collections as you like; importing copies
it rather than pointing at it, so deleting the original cannot take content down with it.

**Editing.** Every entry keeps two copies of its values — what is published and what is
being worked on — so editing a live entry never takes it off the site half finished, and
the divergence is countable ("3 changes ahead of published"). Publish, unpublish, discard
the draft, duplicate. Delete goes to a **trash** with its own view, a restore that brings
an entry back to the state it was in, and a permanent delete gated separately. Bulk
actions run over a page at a time and report per entry rather than per request. Two people
saving the same entry, or the same schema, get a 409 instead of one silently overwriting
the other.

**Permissions.** Two capabilities of the plugin's own — `schemapress_edit_content` to fill
entries in, `schemapress_manage_schema` to decide what an entry *is* and what the site
publishes. Administrators get both, editors the first. A collection may also name the
roles allowed to edit its entries, so a Grants collection can belong to finance and a News
collection to comms.

**Reading.** Three surfaces over one query grammar — PHP, Twig and HTTP — so a filter means
the same thing in all three. The HTTP API is Strapi-shaped down to the parameter names and
the `data`/`meta` envelope, publishes nothing until a collection opts in, and carries an
ETag on every response with an optional `max-age` above it.

**Operating it.** Rebuilding an index after a field change, deleting a collection and
backfilling identifiers are resumable jobs on anything over 200 entries, with progress in
the admin. Schemas and optionally their entries move between sites as one JSON document,
in the admin or on the command line. WP-CLI covers `list`, `reindex`, `backfill`, `jobs`,
`export`, `import` and `trash`. Deleting the plugin erases nothing unless the site turned
that on in advance.

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
npm test                  # both suites (537 assertions, no framework)
npm run pot               # regenerate languages/schemapress.pot
npm run lint:php          # the checks a wordpress.org review blocks on
npm run package           # build the zip the plugin directory serves
```

`lint:php` needs PHP_CodeSniffer with the WordPress standards on your PATH; the
ruleset it runs is `phpcs.xml.dist`, and what it does **not** include — and why —
is written at the top of that file.

`package` reads `.distignore`, rebuilds `vendor/` without dev dependencies into a
staging copy, and refuses to build at all if the plugin header, `SCHEMAPRESS_VERSION`
and the readme's `Stable tag` disagree.

CI runs the suite on PHP 8.2, 8.3 and 8.4, and checks that the committed `build/` matches
`src/` — the plugin ships built, so a change to the admin has to be rebuilt and committed
with it.

## How it fits together

Three layers, and the boundary between them is the point.

**The model** — `classes/class-schema-model.php` and friends. Pure transformations over a
collection's definition: normalizing it, validating field types, resolving stored values
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

Four supporting pieces sit beside those:

| | |
| --- | --- |
| `class-capabilities.php` | the plugin's own two capabilities, and per-collection roles |
| `class-batch.php` | work that walks every entry, as a job with a cursor cron resumes |
| `class-portability.php` | export and import; the key is the identity, never the title or the post id |
| `class-cli.php` | the same operations for a deploy script, registered only under WP-CLI |

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

**Color and contrast.** Every token in `src/shared/style.css` is measured, and the comments
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
4. If you changed behavior a user would notice, update the relevant page in `docs/`.
5. Open a PR describing what changed and why the approach was chosen.

**Adding a field type** touches four places: the registry (`class-field-types.php`), the
resolver if it stores something other than what it renders (`class-resolver.php`), the
control (`src/shared/fields/`), and the index if it should be filterable
(`class-index.php`). The test suite covers the first two.

**Changing the query grammar** means changing `class-query.php` only — all three delivery
surfaces read it.

## Known rough edges

- **No relations.** A collection cannot point at another one, which is the largest single
  gap against Strapi and the next feature in.
- The content API is **read-only and unauthenticated**. There are no API tokens, so access
  is per collection rather than per consumer.
- No single types. An entry is addressed by its slug or its uuid.
- Repeater and Gallery contents cannot be filtered or sorted — one meta row cannot hold
  many values. A custom index table is the way in if that is ever needed.
- `$or` filtering is only expressible in the bracket syntax; the flat parameter form has no
  spelling for it.
- **Search matches what is published.** The searchable text lives in `post_content` and
  only moves on publish, so a live entry's unpublished wording cannot be found in the
  builder's own listing until it goes live. Deliberate — the same row is what the front end
  searches — but it surprises people editing a long draft.
- The admin bundle is ~490KB, over webpack's advisory. Prism accounts for ~37KB and the
  compiled documentation ships inline with the screen; both are candidates for a dynamic
  import once someone can verify chunk loading in a plugin subdirectory.
- ESLint does not currently run — `@wordpress/eslint-plugin` cannot load its TypeScript
  peer in this tree. `npm run build` is the check that matters; CI runs it and verifies the
  committed `build/` matches `src/`.
- An export is one JSON document, so it is capped at 5,000 entries. Use a database backup
  for anything larger.
