# Changelog

All notable changes to SchemaPress. Dates are the day the version was tagged.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
versions follow [semantic versioning](https://semver.org/) — while this is `0.x`,
a minor bump may still change behavior, and the notes say when it does.

## [0.2.0] — 2026-09-10

The release that closes the gaps an audit of 0.1.0 found. Everything below was
either missing entirely or was a surface that only existed in one direction.

### Added

- **Trash, restore and permanent delete for entries.** Deleting an entry has
  always trashed rather than erased it, but a collection's post type has no
  admin screen of its own — so there was nowhere to see a trashed entry or bring
  it back, and WordPress erased it on its own schedule 30 days later. There is
  now a Trash view per collection, a Restore action, and an explicit permanent
  delete gated on the schema capability.
- **Export and import.** A collection's definition lived only as JSON in one
  meta row, so a schema could not be version-controlled, moved from staging to
  production, or used to seed a fresh install. `GET /export` and `POST /import`,
  a panel on the Settings screen, and `wp schemapress export|import`. Collections
  are matched on their machine key, never on a title or a post id. **An import
  never turns on a collection's public API**, whatever the file says.
- **Conflict detection on entry and schema saves.** Two people editing the same
  entry silently lost one of their work. A save may now state the version it was
  made against and is refused with a 409 if that version has moved. Optional, so
  scripts and importers are unaffected; the admin always sends it.
- **The plugin's own capabilities**, `schemapress_edit_content` and
  `schemapress_manage_schema`, granted to administrators and editors on
  activation. They replace the borrowed `edit_pages` and `manage_options`.
- **Per-collection permissions.** A collection may name the roles allowed to edit
  its entries. Empty means open to anyone who may edit content, which is what
  every existing collection is.
- **Bulk actions** — publish, unpublish, discard, duplicate, delete, restore —
  over a page of entries at a time, reporting per entry rather than per request.
- **Duplicate an entry.** The copy is always a draft, whatever the original was.
- **Three field types**: Gallery (many images, its own type rather than a flag on
  Image), Color (filterable and sortable), and JSON (stored decoded).
- **ETag and Cache-Control on the public API**, with 304 handling. `max-age` is 0
  by default — the ETag is the win, staleness is a separate decision — and is
  configurable up to a day.
- **WP-CLI**: `list`, `reindex`, `backfill`, `jobs`, `export`, `import`, `trash`.
- **An uninstaller.** Deleting the plugin left every collection, entry, index row
  and option in the database forever, unreachable because the post types naming
  them were gone. It now removes them **only when the site has asked for that in
  advance** — the default is still to keep everything, because deleting a plugin
  is often how somebody reinstalls it.
- **Continuous integration**: PHP 8.2/8.3/8.4 lint and suite, plus a check that
  the committed `build/` matches `src/`.
- Documentation pages for field types, components, the draft and publish
  workflow, datasets, and data portability.
- **Addressed by lists unique fields first**, and says what choosing one that
  repeats means: which of two entries gets the bare address and which gets the
  numbered one depends on the order they were created in. Any suitable field is
  still offered — requiring uniqueness for a readable URL would mean a
  collection could not be addressed by name without also refusing to store a
  second person of that name. The note appears whether the URL was chosen in
  the picker or followed the name field, which is how most collections get one.

### Changed

- **BREAKING: the global template functions are `schemapress_` rather than
  `sp_`, and the `Content` class alias is gone.** Both lived in the namespace
  every plugin on a site shares. `sp_` is two letters that SportsPress already
  uses throughout, and `Content` is about as generic as a class name gets — two
  plugins declaring either one is a fatal error, not a warning. Rename
  `sp_collection()` to `schemapress_collection()` and `Content::` to
  `SchemaPress::` in theme PHP. **Twig templates are unaffected**: the Twig
  functions are still `sp_collection()` and friends, because those names are
  registered into this plugin's own environment and collide with nothing.
- **Long work is now queued and resumable.** Rebuilding an index after a field
  change, deleting a collection, and backfilling identifiers each read every
  entry with `numberposts => -1` inside the request that asked. On a collection
  of any size that did not finish, and left the work half-applied with nothing
  recording where it stopped. Collections under 200 entries are still handled on
  the spot; anything larger becomes a job with a cursor that survives being
  interrupted. `wp schemapress reindex` runs one to completion for a deploy step.
- **The upgrade routine takes a lock, records the version first, and queues the
  rest.** It runs on whichever request arrives first after the files change —
  often an anonymous pageview, sometimes several at once. Previously they all ran
  the whole backfill concurrently, and a timeout meant it started again from the
  beginning on every subsequent request, forever.
- **Trashing an entry clears both of its index rows.** Leaving them was harmless
  only because every read also filters on post status, which is a second thing
  that has to stay true rather than a fact.
- `Entries::count()` takes a second argument for whether the trash counts.
- **New fields start at a width that suits their type** instead of always full — a third
  for Image, Toggle, Color, Number, Date, Time and Phone; half for Text, Email, URL,
  Dropdown, File, Date and time and JSON; full for the rest. Only a starting point: any
  field can still be set to any width, and existing fields keep theirs.

### Fixed

- **The Markdown parser was unreachable on any site that already had Timber.**
  The Composer autoloader was loaded only when `Timber\Timber` did not exist —
  a guard about Timber placed over the whole of `vendor/`, which also carries
  league/commonmark. On a site whose theme loaded Timber first, the plugin
  skipped its own autoloader, the parser was absent, and the documentation
  screen rendered as unparsed text under a notice advising `composer install`.
  Worst on exactly the sites most likely to install this.
- **Choosing a slug field did nothing to the entries a collection already had.**
  An entry's address freezes when it is published, which is right for a rename
  — but it also meant a collection that took up **Addressed by** after its
  entries were live kept every one of them on its random id, with nothing
  saying why. An id is not an address anybody chose to link to, so an entry
  still carrying one now adopts the named slug, published or not, and changing
  the setting sweeps the collection. Entries that already have a real address
  keep it.
- **Conflict detection on schema saves never fired.** A definition is post
  meta, and writing meta does not touch the post row — so the modified stamp the
  check compares could only move when somebody renamed the collection. Two
  people with the same Schema tab open still overwrote each other silently,
  which is the loss the check was written to stop. Saving a definition now moves
  the stamp, and only when the definition actually changed.
- **A component save had no conflict check at all.** It takes the whole field
  list, exactly as a collection's does, and a component is imported by copy — so
  a field lost to the second of two tabs is lost again in every collection that
  imports it afterwards.
- **Emptying a large trash could hang the request.** The sweep reads the front
  of the trash and deletes what it read, which only terminates while something
  is going. `pre_delete_post` lets any other plugin veto a deletion, and a page
  that survived its own deletion was read and vetoed forever. A pass that erases
  nothing now stops and reports what went.
- **86 translatable strings never reached the template.** The POT extractor
  scanned a line at a time, so it only saw a string whose opening quote shared a
  line with its `__(` — and the plugin wraps long strings as a matter of style.
  Every conflict message, most of the import refusals and the export ceiling
  were marked for translation and untranslatable.
- **Translations could never load.** Every `__()` in the plugin and the
  `wp_set_script_translations()` call were decorative: there was no
  `load_plugin_textdomain`, no `Domain Path` header and no `languages`
  directory. All three are there now.
- The documentation advised granting editors `manage_options` to let them build
  schemas, which hands over the entire site. It now describes the plugin's own
  capability.

## [0.1.0] — 2026-09-04

First release. Collection types, typed fields, components, the entry builder,
draft and publish, the filter index, and the read-only public content API.
