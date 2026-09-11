=== SchemaPress ===
Contributors: eriklarson
Tags: custom fields, structured content, headless, rest api, content types
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Define a collection type — a named shape with typed fields — and get an admin for filling it in and a REST API for reading it back.

== Description ==

SchemaPress is a structured content system for WordPress, modeled on Strapi. You
define a **collection type** — Team Members, Grants, News Articles — as a named
shape with typed fields. It gives you an admin for filling it in and a read-only
REST API for reading it back.

Underneath it is still WordPress. Entries are posts, so your existing queries,
capabilities, search and backups keep working.

**The plugin renders nothing.** A collection describes what content *is*; what it
*looks like* is your theme's business. That one line explains most of the design
decisions in it.

= Reading your content =

Three surfaces over one query grammar, so a filter means the same thing in all
three:

* **PHP** — `SchemaPress::collection('team_members')->where('role', 'Engineer')`
* **Twig** — `{% for person in sp_collection('team_members') %}`, via Timber
* **HTTP** — `GET /wp-json/schemapress/api/team-members?role=Engineer&sort=name`

The HTTP API is Strapi-shaped, down to the parameter names and the `data`/`meta`
envelope, so a client written against one reads against the other.

= What is off by default =

The content API publishes nothing until a collection opts in, per collection and
per shape of read. There is a site-wide master switch above that, and with it off
the namespace is absent from the REST index entirely rather than answering to
refuse.

= Field types =

Text, Textarea, Rich Text, Email, URL, Phone, Number, Date, Date and time, Time,
Toggle, Dropdown (with built-in datasets for countries, states, provinces and
months), Image, Gallery, File, Color, JSON, Link, Group and Repeater.

= Rules and reuse =

A field can be required, bounded by length or range, matched against a pattern,
or made unique across the collection — enforced when the entry is saved and not
only in the form. A field can also be shown or hidden based on another field's
value.

A **component** is a named group of fields — an address, a call to action —
defined once and imported into as many collections as you like. Importing copies
the fields rather than pointing at them, so editing a component never reshapes
content that already exists somewhere else, and deleting one cannot take content
down with it.

= Editing =

Every entry keeps two copies of its values: what is published, and what is being
worked on. Editing a live entry never takes it off the site half finished, and
the admin says how far the draft has run ahead. Publish, unpublish, discard the
draft, duplicate, and act on a page of entries at once. Two people saving the
same entry get a refusal rather than one of them silently losing their work.

= Requirements =

PHP 8.2 or newer. That is what league/commonmark's own dependencies require —
the Markdown parser behind the documentation screen.

Timber is optional, is not bundled, and only affects the Twig functions. Install
it in your theme if you want them.

= Source code =

The admin screens are a React application, and what ships in `build/` is
compiled. The source it is compiled from ships beside it in `src/`, along with
the webpack and Tailwind configuration, so the package can be read and rebuilt
without leaving it. Development happens at
[github.com/eriklarsondev/schemapress](https://github.com/eriklarsondev/schemapress).

The plugin bundles league/commonmark and its dependencies — league/config,
dflydev/dot-access-data, nette/schema, nette/utils, psr/event-dispatcher and two
Symfony polyfills — to render the documentation screen. All are MIT or
BSD-licensed and so GPL-compatible.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/schemapress`, or install it through
   the Plugins screen.
2. Activate it. Administrators and editors are granted the plugin's capabilities
   on activation.
3. Open **SchemaPress** in the admin menu and create your first collection.

If you are installing from a git checkout rather than a release archive, run
`composer install` and `npm install && npm run build` first — the plugin ships
built, but a checkout is not.

== Frequently Asked Questions ==

= Does deleting the plugin delete my content? =

No, unless you have said so. There is a setting on the Settings screen — **Delete
all content when the plugin is uninstalled** — which is off by default and has to
be turned on in advance. Deleting a plugin is often how somebody reinstalls it,
and that is not a decision to destroy the content.

= Can I move a collection between sites? =

Yes. **Settings → Export** writes a JSON document with your collections, their
fields and optionally their entries; **Import** reads one back. Collections are
matched on their machine key rather than their name, so the same collection on
two sites is recognized as the same collection. An import never turns on a
collection's public API, whatever the file says.

On the command line, `wp schemapress export > schema.json` and
`wp schemapress import schema.json`.

= I deleted an entry by mistake. =

Open the collection and switch to **Trash**. Restoring an entry brings it back to
the state it was in, live or draft, with its filters working again.

= Can I stop an editor from reshaping my collections? =

That is the default. `schemapress_edit_content` lets somebody fill entries in;
`schemapress_manage_schema` lets them decide what an entry is and what the site
publishes. Editors get the first, administrators get both.

A collection can also name the roles allowed to edit its entries, so a Grants
collection can belong to finance and a News collection to comms.

= What happens on a very large collection? =

Rebuilding the index after a field change, deleting a collection and backfilling
identifiers are queued as resumable jobs rather than done inside the request.
Progress is visible in the admin, and `wp schemapress jobs --run` finishes them
now if you would rather not wait for cron.

= Are there relations between collections? =

Not yet. It is the largest remaining gap against Strapi and the next feature in.

== Changelog ==

= 0.2.0 =
* Added: trash, restore and permanent delete for entries.
* Added: schema export and import, in the admin and on the command line.
* Added: conflict detection on entry and schema saves.
* Added: the plugin's own capabilities, and per-collection permissions.
* Added: bulk actions and duplicate.
* Added: Gallery, Color and JSON field types.
* Added: ETag and Cache-Control on the public API.
* Added: WP-CLI commands, and an uninstaller that only runs when asked.
* Changed: long work on large collections is queued and resumable.
* Changed: the upgrade routine takes a lock and records its version first.
* Fixed: translations could never load — no textdomain was ever loaded.

= 0.1.0 =
* First release.

== Upgrade Notice ==

= 0.2.0 =
Adds a trash you can restore from, schema export/import, and conflict detection
on saves. Large collections now reindex in the background instead of timing out.
Existing capabilities keep working; the plugin's own are granted on upgrade.
