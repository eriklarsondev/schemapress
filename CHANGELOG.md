# Changelog

All notable changes to SchemaPress. Dates are the day the version was tagged.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
versions follow [semantic versioning](https://semver.org/) — while this is `0.x`,
a minor bump may still change behavior, and the notes say when it does.

## [0.4.0] — 2026-11-13

### Added

- **Relations.** A collection can point at another. **One field type**, facing either way,
  picked as a shape in the field editor:

  | | |
  | --- | --- |
  | **Holds one** | One entry's identifier — several entries may hold the same one |
  | **Holds one, exclusively** | The same, refused to anybody else. What a one-to-one is |
  | **Holds several** | An ordered list of identifiers; the order is content |
  | **Reads back** | Nothing at all — lists whatever points at this entry |

  One type rather than two, because "an author's articles" and "an article's author" are
  two views of one relationship and nobody models them as different *kinds* of field —
  making them different types meant choosing the direction before you had chosen the
  collection. Between them the four shapes say every relationship a content API usually
  names separately: one-to-many, many-to-one, one-to-one, many-to-many, and the one-way
  pair where nothing reads back.

  The three that hold something store the identifier and nothing else, so correcting a name
  at the other end corrects it everywhere pointing at it — the bargain Image already makes
  with the media library. A related entry comes back resolved: its identity and then its
  own fields beside them, the same shape the API delivers an entry it was asked for.

- **Reading back stores nothing.** "The articles whose Author points at me" is a query
  against the index, run when the entry is read. Writing both sides would mean two copies
  of one fact, and there are four places in this plugin where copies come apart — a draft
  ahead of what is published, the trash, an import, a bulk action. A derived inverse cannot
  drift, and nothing has to be rebuilt when a collection changes. It is read-only in the
  admin, because the reference lives on the *other* entry and saving a draft of one entry
  to change another is not something the entry form can promise to do safely — so the field
  names the collection and the field it *is* set on, which makes the list a direction to
  follow rather than a dead end. It says when it has stopped at its fiftieth entry, too,
  rather than letting a ceiling read as the whole answer.

- **The Schema tab draws the relation and names its kind** — one-way, many-to-one,
  one-to-one, many-way, many-to-many or one-to-many — rather than leaving you to work out
  which one some switches add up to. It reads in words, `many → one`, not in the `1`/`N` a
  database diagram uses: the person reading it is deciding what their content *is*, and
  "many" is the word they would have used anyway. The line is read from the field's own
  collection outwards, which is what keeps the marks and the name agreeing — standing on
  Article it is a many-to-one and standing on Author the very same relationship is a
  one-to-many.

  One arrow or two is the whole of what an inverse looks like. That is the one part of the
  kind a field does not decide for itself, and it is drawn rather than offered as a fifth
  shape on purpose: a control here that wrote a field into a collection you are not looking
  at would be a schema change you did not see. Where the second arrow is missing, the panel
  names the field to add and the collection to add it to.

- **Relations are filterable**, by the identifier they hold: `?author=9f2c41ab-…` is every
  article by that author, on all three surfaces. A relation holding several is indexed a
  reference at a time, so it matches if any of them does — the behaviour a multi-valued
  dropdown already has. Sorting by one is offered nowhere; it would order a list by a uuid.

- **`SchemaPressReference` and `<Collection>Ref` in the GraphQL schema.** A reference type
  exists only for a collection open for Read one; a relation into one that is closed
  resolves as the shared identity-only type, so a closed collection's fields are absent
  from a response *and* from introspection. The same rule governs REST, where a relation
  into a closed collection delivers `id`, `slug` and `title` and no fields — a published
  entry may say *which* author wrote it, but a collection that has not opted in does not
  have its fields read out through the back of one that has.

- **`schemapress/relation_depth`**, for sites that want more than one hop. One by default:
  two collections pointing at each other would otherwise be a read with no end to it, and
  each hop multiplies — a page of 25 entries each pointing at 5 others is 125 reads at one
  hop and 625 at two. Past the last hop a relation still carries `id`, `slug` and `title`.

- **`GET /relations` and `GET /lookup/{collection}`** on the admin transport, both
  capability-checked: what a relation may point at, and the entries of one collection as
  names to choose from. The picker searches rather than scrolls — a collection is however
  many entries the site has — and lists drafts, saying which they are, because linking to a
  draft is ordinary and finding out at publish time that half the references resolve to
  nothing is not.

- **A field that reads back can be neither required nor unique.** There is nobody to
  require it of and no stored value to be unique about, so a definition claiming either is
  corrected on the way in rather than enforced forever against a value nobody can fill in —
  and the entry form stops offering the asterisk. It is also absent from the index: writing
  an empty row per entry would make `$null` on it true of every entry in the collection, a
  filter that matches everything, which is the direction a filter must never be wrong in.

- **`Relations::pointingAt()`**, which names the collections that embed entries of a given
  one. A relation is a dereference, so correcting an author's name changes the delivered
  body — and the ETag — of every article carrying it, while nothing about those articles
  was saved and nothing fired for them. A site behind a CDN has to purge them too, and this
  is how it knows which. Nested fields are walked, since a relation inside a group or a
  repeater row embeds exactly as a top-level one does.

### Changed

- **`Resolver::values()` takes the view and the entry being read**, as a fourth `$context`
  argument. A relation is the first value whose expansion depends on more than the value
  itself: which copy of the entry at the other end to show, and — reading back — who is
  being pointed at. A published read only ever resolves published entries, so a
  relation pointing at a draft comes back `null` on the front end and is visible, labelled,
  in the builder. The `$depth` argument that had been threaded through this class since the
  first release is now the thing that bounds those reads.

- **`Entries::all()` accepts a depth**, so the listing a relation runs when it reads back
  carries on counting from where the read that triggered it got to, rather than starting
  again at zero.

### Fixed

- **Picking a relation's shape applied only half of it.** The shape sets both halves of the
  field — `unique` sits beside `required`, the rest in the config bag — and it was written
  in two calls. Each spread the same field object, the one the panel was rendered with, so
  the second landed on the parent's unchanged list and silently discarded the first:
  choosing **Holds several** set `unique` and never set `multiple`. One call now writes
  both.

- **An imported relation no longer depends on the order the file was read in.** A reference
  is stored whether or not it currently resolves, which is what lets an import write the
  articles before the authors they point at have been read out of the same document. An
  import that brings half a pair now says which collection is missing rather than leaving a
  field that is filled in and delivers nothing.

- **Documentation that had drifted.** An audit against the code found five things stated
  wrongly or not at all: `schemapress/job_abandoned` fires and was not in the hooks table
  (nor was the fact that a job which cannot progress is dropped rather than retried
  forever); the filterable list under **What can be queried** omitted Relation; the
  validation rules table omitted both ceilings that shape a value rather than refusing it —
  Gallery's **Most images**, which has never been listed, and Relation's **Most entries** —
  and omitted uniqueness on a relation; and the CDN purge recipe under **Caching** purged
  only the collection that changed. The counts, type lists, operator tables, widths,
  capability names, settings keys and CLI commands are now checked against the code rather
  than maintained by hand.

- **What a relation that reads back costs on a list endpoint is now written down**, with
  the measured number: a page of 20 authors with 10 posts each is 110 queries and 200
  resolved entries for one response. It is what was asked for rather than a defect, but it
  is worth knowing before putting such a field on a collection served as a list — the
  other collection's own endpoint, filtered, is one query and pages.

## [0.3.1] — 2026-11-05

A documentation and tooling release. Nothing in the plugin's behavior changed.

### Changed

- **Prettier is configured by `.prettierrc` rather than a `package.json` key.** 0.3.0 had
  folded it in the other direction, to thin out a root directory that had grown to 27
  visible entries, but `.config/` is not somewhere Prettier looks: config discovery walks
  up from the file being formatted, and support for a `.config/` directory only arrived in
  Prettier 3.5 — this tree pins 3.0.3. Pointing the npm scripts at `--config
  .config/prettierrc` would have worked and is the trap: `lint-staged` and every editor
  integration run Prettier without that flag, so the pre-commit hook and format-on-save
  would each have silently formatted to the defaults and fought the other. One file in the
  root that everything finds is worth more than a tidy listing.

### Fixed

- **0.3.0's own release notes.** `Fixed` and `Removed` each appeared twice and eleven
  additions were filed under `Removed`, including the WPGraphQL integration — the largest
  thing in the release was listed as something taken out of it. Two entries also
  contradicted each other on Timber, one describing it as moved to `suggest` and
  `require-dev` and the other as gone entirely; it is gone, and `twig/twig` is the dev
  dependency that replaced it. Re-filed under one heading each, in Keep a Changelog's
  order.

- **Documentation that had drifted from the code.** `README.md` credited
  `class-capabilities.php` with per-collection roles, which 0.3.0 removed and which the
  same file contradicts forty lines earlier; counted two test suites and 547 assertions
  where there are three and 951; and put the admin bundle at ~490KB where it is ~380KB.
  0.3.0's note about grouping the documentation by job still listed the three
  surface-specific querying pages that the same release folded away.

## [0.3.0] — 2026-10-17

### Added

- **A WPGraphQL integration.** Install [WPGraphQL](https://wordpress.org/plugins/wp-graphql/)
  and every collection you have opened for reading joins the site's GraphQL schema, beside
  its posts and pages — a type per collection, a list query and a single query, with the
  same filter, sort and paging grammar as typed inputs. It is not bundled, for the reason
  nothing else is: a site that wants GraphQL already runs it, and a second copy on the
  autoloader would let load order decide which version the schema was built against.
  Without it the file costs one `function_exists()` per request.

  **It exposes exactly what REST exposes.** The site's Content API switch and each
  collection's own Read many / Read one pair govern both, so a collection closed to REST is
  *absent* from the schema rather than present and refusing — no type, no query, nothing to
  introspect. Adding a query language must not widen what is public, and the only way to be
  sure is to have one answer to "is this readable" rather than two.

  GraphQL names cannot contain a dash, so this is the one surface that cannot take
  `team-members`: the type is `TeamMember`, the queries are `teamMembers` and `teamMember`,
  and `full_name` is `fullName`. Two keys that want one camelCase name are settled rather
  than silently dropped — the first keeps it, the rest fall back to their key verbatim.
  Lists return `nodes`, `total` and `count` rather than a Relay connection: a connection
  needs a cursor, and this is a read API over content with no cursor to hand out.

- **`createdAt` on every entry**, over REST and in GraphQL. It was already accepted as a
  sort key and documented as one, but no surface returned it, so a client could order by
  it and had nothing to show. It is when the entry was made and never moves again, which
  is the question most people are actually asking when they reach for `publishedAt`.

- **Fields in the sidebar.** A collection's entry screen has a sidebar holding the entry's
  status, endpoint, details and delete button, and a field can now sit there too, under
  the status card and the full width of the column. The Form tab draws the same two
  columns, with those cards as silhouettes in the sidebar: drag a field across, or use
  **Move to the sidebar** under its width badge — which is also the way back, at whichever
  width. Stored as `config.region` (`main` or `sidebar`); only a collection's own fields
  can have one, since a field inside a group is drawn by the group and a component's are
  drawn wherever it is used.

- **`offset()` on a collection query**, so PHP can open the same window `?start=` opens
  over HTTP. Paging existed on every surface; skipping existed only on the one that leaves
  the server, which left a "load 10 more" button or a feature row above a grid with no way
  to say what it meant. An offset and a page are alternatives — setting one means the other
  is not consulted — and `total()` still counts everything that matched.

- **The documentation's code samples are checked, not proofread.** A third suite,
  `tests/docs.php`, parses every fenced block with the real parser for its language —
  `php -l`, `node --check`, `json_decode`, Twig's own compiler, `bash -n` — and checks the
  GraphQL ones for balanced braces. It found two samples that could not run: a list of
  function signatures written as though it were code, and a chained query with no opening
  tag and no semicolon. Neither was visible in review, because a fenced block looks like
  code whether or not it is one, and both were something a reader would have pasted into a
  theme.

  Twig and shell are checked for the same reason and were the two languages going
  unchecked: fourteen Twig samples and six shell ones shipped on the strength of looking
  right, and an unclosed `{% for %}` is a white screen rather than a mis-rendered block.
  Twig is a dev dependency for this and nothing else — the plugin has no Twig integration
  to ship.

  It also checks what the compiler cannot complain about: every page declares its group
  and description, opens with a heading, claims an id nothing else claims, closes every
  `:::` block and code fence, and labels every pane of every tab group.

- **Plugin Check in CI**, run against the built package rather than the checkout. The
  directory's own review tool catches what a phpcs ruleset cannot express — readme parsing,
  header fields, trademark and naming rules — but pointed at the repository it reviews
  files that never ship, and reported 38 errors in `tests/`, `bin/` and the dotfiles. The
  job packages the plugin and unpacks it first, so what is checked is what a reviewer sees.

- **Formatting, and a pre-commit hook that applies it.** Prettier for JavaScript and CSS,
  PHP-CS-Fixer for PHP, both wired into husky and lint-staged so staged files are
  formatted on the way into a commit and a PHP file that does not parse cannot get into
  one. `npm run format` does the same by hand, and CI checks it.

- **PHP_CodeSniffer and the WordPress standards as dev dependencies**, so `npm run
  lint:php` runs `.config/phpcs.xml` without anything installed globally. The ruleset had
  never actually been executed; on its first run it reported no violations.

- **Tag-triggered releases.** `.github/workflows/release.yml` runs the suite, verifies the
  tag matches the plugin header, rebuilds, packages and publishes to wordpress.org, then
  attaches the zip to a GitHub release. Edits to `readme.txt` or `assets/` on `main` update
  the directory listing on their own, since wordpress.org versions those separately from
  the code. Both SVN steps are skipped when `SVN_USERNAME` is unset — until the plugin is
  approved there are no credentials, and a readme edit should not turn `main` red over
  something there is nothing to do about. Everything else on a tag still runs, and the zip
  is still attached to a GitHub release.

- `CONTRIBUTING.md`, a `LICENSE` file with the GPL-2.0 text, and GitHub issue and pull
  request templates.

### Changed

- **`team-members` is the name you ask for a collection by, everywhere.** The plural,
  hyphenated, the same string in a PHP file, a URL and on the command line. It already
  worked in two of those three and the documentation only mentioned it for URLs, so every
  template example printed `team_member` and a reader had no way to know one name would do.
  Every example now uses it, the collection's Settings leads with it under **Ask for it
  by** rather than showing two underscore keys as "what templates ask for", and
  `wp schemapress list` reports it as the first column.

  The three other spellings — `team_members`, `team-member`, `team_member` — still answer
  and always will; a template that types one should not stop rendering.

  **`wp schemapress --collection=` now accepts it too**, which it did not: the rule lived
  in three places and had drifted, with the reading API reading hyphens and spaces, the
  REST layer reading hyphens, and the CLI reading neither — so the one name the
  documentation printed was refused by the one surface that cannot guess what you meant.
  All three now call `ContentType::find()`.

- **The documentation is grouped by job rather than by surface.** It used to have a
  *Content API* section and an *In your theme* section, so the two questions a reader
  actually arrives with were each answered in three separate places: "how do I filter?"
  was in Filters, in the middle of the PHP page and in the middle of the Twig page, and
  "what does an image field give me?" was in Response format, in the middle of the PHP
  page and in the middle of the Twig page. Neither page said the other two existed.

  There are now two groups, each covering every surface it applies to. **Querying
  content** — Querying, The query object, Filters, Sorting & pagination, What can be
  queried, GraphQL. **Displaying content** — Displaying values, Values in JSON, Values in
  PHP, Values in Twig. Each group opens with a page showing the same operation written
  three ways side by side: one query in all three surfaces, and one table giving every
  field type's JSON, PHP and Twig form. *Content API* keeps the pages that are about the
  transport itself — Endpoints, Errors, Writing a client, Caching.

  The two groups list different surfaces, because Twig does not query and GraphQL is not a
  template language. That asymmetry is the point of splitting on the job rather than the
  surface.

  Filters and Sorting & pagination now spell every example for all three surfaces rather
  than for one with the others mentioned; a tab group in Filters that had been left
  half-written, with an empty pane and prose stranded between two fences, is fixed.

- **Leaving a tab with unsaved work asks first.** The Form tab and the Schema tab each
  hold their arrangement until their own Save button, and a tab panel unmounts the moment
  you leave it — so switching tabs threw the work away without a word. The tab strip now
  asks, as the sidebar already did, and says which it is: the layout, the schema, or the
  component. An unsaved layout also stops a reload and a move to another collection, which
  it did not before. A component's own name and description are guarded too, but only
  against navigating away — they are held by the view rather than by a panel, so changing
  tabs does not lose them and does not ask.

- **Widths are set by dragging, both ways and at every size.** Dropping into a row's
  spare space always widened a field to fill it, a New row strip always kept its width,
  and a full-width field in a form with no spare space could not be narrowed by dragging
  at all. A New row strip now offers all four widths, full included, in equal quarters of
  the row. Holding a field over its own place resizes it where it stands: back towards the
  start of the row narrows it, on into any space after it widens it, measured from where
  it was picked up so a wobble changes nothing, and the fields around it stay where they
  were, as the width badge leaves them. Every target is drawn at the width you will get.

- **A card dropped on the flank of another shares its row.** Dragging onto a card only
  ever swapped the two, so on a form of full-width fields — which is every form nobody has
  arranged yet — there was no spare space anywhere and no width could be set by dragging
  at all. The outer quarter of each side of a card is now an offer to join its row, and
  the row divides evenly between everything on it: two across is a half each, three a
  third each, and a fourth is refused because a quarter is narrower than any control is
  drawn. The middle half of a card still swaps.

- **A row's spare space divides between the widths that fit it.** It used to give each
  width the part of the space its own span covered, so in six spare columns a half won
  only the last sixth and a third had everything before it. Now the fitting widths share
  the space equally — the near half of six columns is a third, the far half a half — and
  the far end always fills what is there, whatever is free.

- **Resizing a field in place resizes the card.** Holding a field over its own place and
  moving across it used to tint the space past its border while the card stayed the size
  it was. The card now takes the width it is being given, so its outline is the answer,
  and the space after it gives up exactly what the card takes so the row never re-wraps
  under the pointer. Only for a field at the end of its row, which is where the columns
  can come from — and which a full-width field always is.

- **One dashed outline per card while dragging.** A drag lit up seven or more dashed boxes
  at once: the card being dragged, a second ring inside it for the resize preview, two
  nested ones on whichever space was being aimed at, and the four sidebar silhouettes
  standing there the whole time. Dashes now mean one thing — where the field is about to
  land — and everything else is solid or a fill.

- **A card being dragged on the Form tab keeps its height.** It collapsed to its label the
  moment a drag began, moving everything below it — in the sidebar's single column, enough
  to put a different card under the pointer and swap the two.

- **The entry sidebar scrolls with the page.** It was sticky and capped to the height of
  the pane with a scrollbar of its own, so the wheel did one thing over the form and
  another over the sidebar. It is an ordinary column of cards now.

- **An image field shows the whole picture**, at the full width of its cell and at its
  own height, from the large size rather than the 300px medium one. It was a 128px strip
  cropped across the middle, which said little about whether it was the right image.

- **Tab walks an object in a JSON field.** From a key it selects the value; from a value
  it opens the next pair, with an empty value of the same kind as the one just written —
  so a table of settings is typed straight through. Shift+Tab, Tab outside any braces, and
  Escape-then-Tab all still leave the field.

- **Links out of the documentation open in their own tab.** Somebody following one is
  mid-task, and this is a screen inside an app — taking the page away means coming back
  through a reload of the admin rather than a back button. Applied in the compiler, so
  every link gets it and the Markdown sources stay readable on GitHub; `rel="noopener"`
  comes with it, since a tab opened this way can otherwise navigate the one it came from.
  A link back to this site, and an in-page anchor, are left alone.

- **The compiled documentation is cached.** `Docs::forClient()` read and CommonMark-parsed
  every Markdown file in `docs/` on every admin page load, and built a fresh converter per
  callout. Now memoized per request and held in a transient keyed on the plugin version,
  the sources' modification times and whether the parser is installed, so an edit still
  lands immediately — and so does `composer install`. Without the last of those, pages
  compiled to raw Markdown before the parser was there kept being served for a week after
  it arrived, with the warning that would have explained it already gone.

- **`vendor/` is no longer committed.** The zip is built by `npm run package`, which runs
  its own `composer install --no-dev` into a staging copy, so distribution never needed
  Composer output in git. Committing it cost a permanently dirty working tree — every
  install rewrites six tracked files under `vendor/composer/` — and required a guard
  script and a CI job purely to stop a dev install reaching a release. Both are gone.
  Installing from a git clone now needs `composer install`.

- **Tooling configuration moved out of the root**, which had grown to 27 visible entries.
  `phpcs.xml.dist` and `.php-cs-fixer.dist.php` are now `.config/phpcs.xml` and
  `.config/php-cs-fixer.php`; `.eslintignore` folded into `.eslintrc.js`, `.prettierrc`
  into `package.json`, and `CONTRIBUTING.md` into `.github/`. The formatter cache writes to
  `.cache/`. Build configuration stays in the root, because it ships with `src/` and has to
  stay where the build looks for it.

- **Comments rewritten to carry reasoning rather than narration.** 2,262 comment lines came
  out of `classes/`, `src/` and the tooling config and 1,352 went back — a net 910 fewer.
  What went is prose that recounted a bug already fixed, or walked through code that reads
  plainly on its own; `CHANGELOG.md` is where the first kind belongs. What came back is the
  docblock coverage below, plus the notes that record something the code cannot say.

- **Standard PHPDoc on every class, method and property.** A one-line summary, then
  `@param` for each argument and `@return`, verified across `classes/`, `includes/` and
  `uninstall.php`. That check turned up tag drift nobody had noticed: `Entries::promote()`,
  `Entries::deriveTitle()` and three methods in `Query` had gained parameters their
  docblocks never documented, `Query::parsePagination()` documented one that no longer
  existed, and `Docs::callouts()` carried a `@var` where a `@return` belonged.

- **A working ESLint setup.** The `@wordpress/eslint-plugin` preset could not load in
  this tree — it pulls a TypeScript toolchain to lint a codebase with no TypeScript in
  it, and the versions no longer line up. `.eslintrc.js` keeps the parts worth having:
  rules-of-hooks, unused and undefined bindings, and `eslint-config-prettier` last.

- **CI runs five jobs**, up from two: the suite on PHP 8.2, 8.3 and 8.4; the
  wordpress.org review checks; Plugin Check; lint and formatting; and a check that the
  committed `build/` still matches `src/`.

- `readme.txt` no longer carries a `== Screenshots ==` section, because the images do not
  exist yet and a caption without one renders as a numbered blank. The captions are kept in
  `assets/README.md` for when they land.

### Removed

- **The Twig query functions, and the Timber dependency with them.** `sp_collection()`,
  `sp_entry()`, `sp_collections()` and `sp_has_collection()` let a template fetch its own
  data. **A query now lives in the PHP file — native theme or Timber — and the result is
  handed to the template.** A page whose data layer is split between a PHP file and the
  templates it renders has two places to look when something is missing.

  The name was its own argument: `sp_collection()` was documented as *removed* (it was the
  old PHP function, dropped because SportsPress owns `sp_`) and as *current* (it was still
  the live Twig function), so the same string meant two opposite things depending on which
  page you were reading.

  Nothing is lost in rendering. This plugin never needed a Twig integration — an `Entry`
  answers an array key, a property and a method on its own, so `person.full_name` resolves
  in a template with nothing registered for it. `classes/class-timber.php`, the
  `%%timber_status%%` placeholder and the `timber/timber` dependency are all gone; Timber
  was in `require`, where it also put a second copy on the autoloader beside the theme's
  and let load order decide which one answered. Install it in the **theme** if the theme
  renders through Twig. The shipped `vendor/` drops from 4.5 MB to 2.1 MB and from twelve
  packages to eight.

  **To migrate:** move the query into the PHP file that renders the template and pass the
  result into the context.

  ```php
  // page-team.php
  $context = Timber::context();
  $context['team'] = SchemaPress::collection('team-members')->sort('full_name')->get();

  Timber::render('team.twig', $context);
  ```

  ```twig
  {# team.twig — was: {% for person in sp_collection('team_members') %} #}
  {% for person in team %}
  ```

- **Per-collection edit roles.** A collection could name the roles allowed to edit its
  entries, under **Who can edit these** in its settings dialog. It was a second permission
  system beside WordPress's own, settable in a different place for every collection, and
  one more thing to check when somebody could not edit something. Who may edit is a
  question about a role and WordPress already answers it: anyone with
  `schemapress_edit_content` now edits every collection. `Capabilities::canEditCollection()`,
  `Capabilities::rolesFor()` and `Capabilities::roles()` are gone with it, and the
  `editRoles` setting is dropped from a collection the next time it is saved. **A site
  that had restricted a collection to a role opens it to everyone with the capability**;
  draw the division with roles and capabilities instead.

- **The element palette, which nothing had drawn for some time.** `Elements::all()` built
  thirteen elements on every admin page load and put them on `window.SchemaPress`, and no
  screen imported them — `fieldTypes` beside it had nineteen references and `datasets`
  seven. The `schemapress/elements` filter was documented as adding to a palette that was
  not there, so a site registering one got silence. `classes/class-elements.php`, the
  bootstrapped `elements` payload and the filter are all gone.

- **The three surface-specific querying pages.** *Querying over HTTP*, *Querying in PHP*
  and *Querying in Twig* each repeated the same operations for one surface. They are
  folded into **Querying** and **The query object**, where every operation is shown for
  all three surfaces in one tabbed block — one operation, one place. The methods PHP and
  Twig share are documented once rather than twice, since it is the same object.

- **Four unused public members.** None is referenced anywhere in the plugin, its tests or
  its documentation, but all four were `public`, so a theme could in principle have
  reached them: `Schema::META_TEMPLATES` (left over from the page-template era),
  `Capabilities::revoke()` (duplicated inline by `uninstall.php`, which runs with the
  plugin unloaded and cannot call the class), `Settings::deletesData()` (`uninstall.php`
  reads the option directly for the same reason) and `Admin::SCHEMA_CAPABILITY` (an alias
  of `Capabilities::MANAGE`).

### Fixed

- **`$or` gave the wrong answer, two ways.** It takes a list of filter objects, and the
  list was merged into a single flat object before being read. So
  `$or: [{role: Engineer}, {role: Designer}]` **lost a branch outright** — merging two
  objects that name the same field keeps one of them, and the query answered as though
  only the second had been asked. And `$or: [{a, b}, {c}]` came out as `a OR b OR c`
  rather than `(a AND b) OR c`, which is a strictly wider question than the one asked.

  Both failed by returning **more** than was wanted, which is the one direction a filter
  on a published collection must never fail in — a listing that quietly includes entries
  the filter was there to exclude looks like it is working. Each entry of the list is now
  a query in its own right, with its own conditions joined by and, and `$and` nests inside
  `$or` the same way. Affects every surface: the REST bracket form, `filter()` in PHP and
  the GraphQL `where` input.

- **`?filters[role]=Engineer` was ignored.** The long form without an operator, and a list
  under `?filters[role][]=`, were both dropped — and a dropped filter widens the result.
  The long form wants an operator there; a filter that names a real field and gives it a real
  value has said plainly what it means, so the first is read as `$eq` and the second as
  `$in`, exactly as the short form already read them.

- **A field called `id` replaced the entry's own in the API response.** A collection with a
  field labelled "ID" — an external reference, a SKU, a legacy number — keys it to `id`,
  and the values were merged over the identifiers rather than under them. The document
  came back reporting the field's value as its `id`, which `/{collection}/{id}` does not
  answer to: the entry could not be fetched by the identifier it had just handed out.
  `slug` had the same problem, and in GraphQL the field replaced one declared non-null
  `ID`.

  The entry's own names now win, on both surfaces, and the field **moves** rather than
  going missing — `id_field` over REST, `idField` in GraphQL. Dropping it would have
  traded a broken identifier for silently lost content. The five reserved names are `id`,
  `slug`, `createdAt`, `updatedAt` and `publishedAt`.

- **`sort=publishedAt` ordered by a value the response does not report.** `publishedAt` is
  read from a meta row that moves forward every time the published copy does; the sort
  ordered by `post_date`, which WordPress sets once and never moves. On a collection that
  keeps no drafts every save republishes, so the two diverged after the first edit and a
  list sorted by `publishedAt` came back in an order its own `publishedAt` values
  contradicted. It now orders by the row the value is read from.

  The cost is that `publishedAt` spends the single custom field WP_Query can order by, so
  it cannot be combined with a sort on one of the collection's own fields. That is stated
  in **Sorting & pagination** rather than left to be discovered.

  The test suite could not have caught either of these: its WordPress had no `post_date`
  at all and fell back to insertion order, which made `createdAt` and `publishedAt`
  indistinguishable. The stub now models the column the way WordPress does — set once at
  insert, never moved by an update — and the ordering tests fail without the fix.

- **A GraphQL query with no `limit` returned ten entries, not twenty-five.** The default
  fell through to the PHP API's page size instead of the one `?limit=` uses over HTTP —
  the single number where the two surfaces disagreed, and the documentation stated the
  REST figure for both. GraphQL now pages exactly as REST does.

- **Every collection reported zero entries.** `ContentType::all()` counted entries eagerly,
  but `registerAll()` reaches it on `init` *before* it has registered the post types — and
  `wp_count_posts()` answers with an empty object for a post type that does not exist yet.
  The zeros were then cached for the whole request. `wp schemapress list` showed every
  collection empty. Counts now wait until the post types are visible, behind a re-entrancy
  guard because counting reads back through `all()`. The test stub had modelled
  `wp_count_posts()` as returning zeros rather than nothing, which is why the suite never
  caught it; it is faithful now, and three assertions cover the case.

- **An import re-dated every entry it restored.** `publishedAt` has been in the export
  format since it was written and nothing read it back, so saving an entry stamped it with
  the moment of the import. A restored archive came back claiming every entry went live the
  same afternoon — in an order its own values contradicted, and `publishedAt` is what an
  archive is almost always sorted by. The file's date is now kept. An entry the file had as
  a draft still gets none, having no published copy to date.

- **Saving part of the site settings reset the rest.** A payload naming one setting had the
  others defaulted rather than left alone — and `restApi` defaults to on, so a caller that
  posted only `deleteDataOnUninstall` switched the content API back on without ever naming
  it. A save is a patch now. The Settings screen always sent all three, so this was only
  ever reachable from a script.

- **Deleting a large collection could hang the queue forever.** The queued purge read the
  front of the entry list, deleted what it read, and reported how many it had *read*
  rather than how many went. `wp_delete_post()` returns false whenever another plugin
  vetoes `pre_delete_post`, so a page nothing could delete was read again, vetoed again,
  and counted again: cron escaped on its time budget and rescheduled at zero progress
  forever, and `Batch::finish()` — which `wp schemapress jobs --run` calls and which has no
  budget — did not return at all.

  It now reports what was erased, so a pass that moved nothing ends the job. This is the
  bug `Entries::emptyTrash()` already guarded against; the queued half had the same shape
  and no guard. A collection it could not empty is **left in place** rather than having its
  definition deleted out from under the entries that survived.

- **Queued work was stranded by deactivating.** A job is a cursor in an option row and
  survives the request that made it; the cron event that advances it does not. Deactivating
  cleared the event and nothing put it back, so an interrupted reindex sat in the queue at
  its last cursor forever with the admin's banner reporting it as work in flight.
  Activation now schedules it again and it carries on from where it stopped.

- **The job lock was not atomic.** Two cron runs arriving together could both read "no
  lock" and both advance the same cursor. It takes the lock with `add_option()` now, the
  way the upgrade lock already did.

- **The upgrade lock could leak and was not atomic.** The version is recorded before the
  backfill runs, so a run that died left `schemapress_upgrade_lock` behind forever — the
  next request returned early on the version check and never reached the delete. Released
  in a `finally` now, and taken with `add_option` so two requests arriving together cannot
  both read "no lock" and proceed.

- **A repeater could be configured to contradict itself.** `min` 5 with `max` 2 was stored
  as given; rows are truncated at the max and then padded up to the min, so the padding won
  and produced a list the field's own ceiling said was impossible. A max below the min is
  read as the min.

- **A field type added through `schemapress/field_types` could not nest or be filtered.**
  Sanitizing asked the registry whether a type was repeatable, but validating, resolving,
  searching and the GraphQL schema all tested for the literal names `group` and `repeater`
  — so a custom nesting type was *stored* as rows and then validated as though the list of
  rows were a single value bag, which found no fields in it and passed every required field
  inside without looking. All of them ask the registry now, and `repeatable` implies
  `children`. A type can also declare `index` as `CHAR` or `NUMERIC` to be filterable and
  sortable, which is what the documentation had been describing: the instruction before
  this was "add it to `Index::TYPES`", a constant no plugin on the other side of a filter
  can reach.

- **`wp schemapress list --format=json` was rejected.** The command read the option and its
  synopsis never declared it, so WP-CLI refused the flag as unknown.

- **The sidebar scrolled away whenever WordPress showed a notice.** The shell was sized to
  `100vh - 32px` — the window less the admin bar — on the assumption that nothing else sat
  above it. Core's update nag does, on every screen until the site is updated, and so does
  any plugin's notice: the page came out taller than the window, and scrolling it took the
  sidebar along. wp-admin's notices are now moved to the top of the pane, where they scroll
  away with the content as they do on every other screen, and the shell takes whatever
  height is left instead of one worked out in advance, so anything else printed above it
  can only make it shorter. The admin bar's height is read from core's
  `--wp-admin--admin-bar--height`, which also makes it right below 783px, where the bar is
  46px, and the pane no longer hands its scroll on to the page when it reaches the end.

- **`Docs::sections()` did not run through `wp_kses`.** The server-rendered page did; the
  path the React app renders with `dangerouslySetInnerHTML` did not. The input is this
  plugin's own Markdown, but `schemapress/docs/files` is a public filter.

- **The documentation described an operator syntax this API does not accept.** *Writing a
  client* said an operator was a suffix on the parameter name — `role_ne`,
  `headcount_gte`, `full_name_startsWith` — which *Filters* contradicts in as many words
  ("there is no `?headcount_gte=10`"). A name that is not a field is read as a filter on a
  field that does not exist and **dropped**, so a client built on that spelling got the
  whole collection back and looked like it was working. Every filter on this API fails
  wide, which is the one direction worth being loud about. The page now shows the bracket
  form and says what happens when a name does not match.

  Six more corrections where the prose had drifted from the code: the sample entry carried
  a `title` key the API has never sent and the page implied it appeared when a title field
  was nominated; *Writing a client* said responses carry no cache headers, which is what
  the whole of *Caching* is about; both display pages reached a link inside a repeater with
  `get('url.url')`, which returns the default because a dot path descends through a group's
  *fields* and a link has none; *Errors* quoted a message replaced when Read many and Read
  one were split; *Permissions* listed a per-collection setting the same page says was
  removed; and *Running it* counted three kinds of queued work where there are four.

  Smaller: `step` was listed as enforced on save and is not, the `weekdays` dataset was
  undocumented, Date and time was missing from the types a uniqueness rule is offered for,
  and "an empty text field is `""`" is true of JSON but not of PHP, where `get()` returns
  the default.

- **`array_is_list()` in the validator, against a declared floor of WordPress 6.2.**
  WordPress only polyfilled it in 6.5. Nothing could actually break — the function is
  native from PHP 8.1 and the plugin's header requires 8.2, which WordPress enforces before
  it will activate — so this was a declaration the directory's tooling reads as a violation
  rather than a live fault. Replaced with an explicit key comparison so the two headers
  agree without narrowing the supported range. Found by Plugin Check on its first run.

- **The release zip shipped untracked local files.** `bin/package.php` walked the working
  directory and filtered it through `.distignore`, which is a denylist — so anything nobody
  had thought to name went into the release. A local `.claude/settings.local.json` did, and
  a `.env` would have. The file list now comes from `git ls-files`, so an untracked file
  cannot ship whether or not it is named; `.distignore` is a second filter, and untracked
  files that were skipped are reported rather than silently dropped.

- **`npm run package` shipped whatever `vendor/` happened to be in the tree.** Its
  `--no-dev` rebuild was guarded by `file_exists($stage . '/composer.json')`, and
  `.distignore` had already stopped `composer.json` reaching the stage — so the rebuild
  never ran, in any release. Harmless while there were no dev dependencies; it would
  have shipped a code formatter and 40 other packages the moment there were.

- **`composer.lock` could not be installed on PHP 8.2**, the version the plugin header
  promises. The dev dependencies were resolved on 8.5, which locked `symfony/console` 8.1
  and `sebastian/diff` 9 — both needing `>=8.4`. `config.platform.php` is now pinned to
  8.2 so Composer resolves against the declared floor rather than the maintainer's
  machine. Runtime dependencies were never affected.

- The packaging script reported the pre-rebuild file count: 2,090 files for a zip that
  held 911.

- **`readme.txt` claimed to bundle Timber and Twig.** It does not; the package carries
  league/commonmark and its dependencies. The stated reason for the PHP 8.2 floor was
  stale in the same way — it comes from `nette/utils`, which league/commonmark pulls in
  through league/config and nette/schema.

- `README.md` contradicted itself and the repository: it said there was no Prettier or
  ESLint config while describing both twelve lines later, and still described `vendor/` as
  committed.

- The `Plugin URI`, the readme and the POT file's bug-report address all pointed at a
  GitHub repository that does not exist.

- Three unused imports and one unused local in the admin.

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
