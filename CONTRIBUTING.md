# Contributing to SchemaPress

Thanks for looking. This is a WordPress plugin that ships to sites through the plugin
directory, which shapes almost everything below — most of the unusual rules here exist
because the person installing this has no Composer, no npm, and no way to build anything.

- [Getting set up](#getting-set-up)
- [The unusual part: `vendor/` and `build/` are committed](#the-unusual-part-vendor-and-build-are-committed)
- [Formatting](#formatting)
- [Tests](#tests)
- [Making a change](#making-a-change)
- [Where things live](#where-things-live)
- [Documentation](#documentation)
- [Opening a pull request](#opening-a-pull-request)
- [Reporting bugs](#reporting-bugs)
- [Licensing](#licensing)

## Getting set up

You need PHP 8.2+, Node 18+, Composer, and a WordPress 6.2+ install to load the plugin
into.

```bash
git clone https://github.com/eriklarsondev/schemapress.git wp-content/plugins/schemapress
cd wp-content/plugins/schemapress

composer install     # Timber, the Markdown parser, and PHP-CS-Fixer
npm install          # the admin's toolchain, and the pre-commit hook
npm run build
```

Activate it in **Plugins**, then open **SchemaPress** in the admin menu.

Two failure modes worth recognising, because neither says anything useful on its own:

| Symptom | Cause |
| --- | --- |
| The admin screens do not appear | `build/` is missing — run `npm run build` |
| The documentation screen renders as plain text | `vendor/` is missing — run `composer install` |

Day to day:

```bash
npm start              # watch build while working on the admin
npm run build          # production build — commit build/ with your change
npm test               # both suites, 537 assertions, no framework
npm run format         # Prettier over JS/CSS, PHP-CS-Fixer over PHP
npm run format:check   # report without writing — what CI runs
npm run pot            # regenerate languages/schemapress.pot
npm run package        # build the zip the plugin directory serves
```

## The unusual part: `vendor/` and `build/` are committed

Both directories are in the repository, which is not what you would normally do. The
reason is the same for each: **the person installing this plugin unzips a folder into
`wp-content/plugins` and activates it.** They have no Composer and no npm. Anything they
cannot produce themselves has to be in the tree already, so as far as distribution is
concerned the compiled output *is* source.

This has two consequences you will run into.

**A change to `src/` must be rebuilt and committed with it.** CI fails if the committed
`build/` does not match what `src/` produces, because a mismatch ships an admin that does
not match its own source.

**`composer install` makes `vendor/` dirty, and that is expected.** PHP-CS-Fixer is a
`require-dev` dependency, so installing it writes 33 packages into the same directory that
ships. Three things keep them out of a release, and it is worth knowing which does what:

1. **`.gitignore` names every dev package**, so those 9 MB are untracked and invisible.
   `git add vendor/` cannot pick any of them up. If you change `require-dev`, regenerate
   the list — the command is in the comment above it.
2. **Six files under `vendor/composer/` are already tracked** — the autoload maps and
   `installed.json` — and every install rewrites them in place. They will show as modified
   for as long as you have dev dependencies installed. This is normal. `bin/vendor-no-dev`
   runs in the pre-commit hook and refuses to let them into a commit while they describe a
   dev install; CI runs the same check against the committed tree.
3. **`npm run package` rebuilds `vendor/` with `--no-dev` into a staging copy**, so the
   released zip is dev-free regardless of what your working tree looks like.

If you need to commit a genuine dependency change:

```bash
composer install --no-dev --optimize-autoloader   # restore the shipping state
git add vendor/ composer.json composer.lock
git commit
composer install                                  # get the tooling back
```

**The hook will tell you PHP formatting was skipped during that commit, and that is
correct.** `--no-dev` deletes php-cs-fixer, and the commit you are making is the one that
records its absence — there is no order of operations in which the formatter exists for
it. So a missing formatter is a notice rather than a refusal; CI still checks formatting
on the push, so nothing gets through unchecked. The same notice appears if you cloned and
ran `npm install` but not `composer install`.

## Formatting

Two formatters, because there are two languages and no tool does both well. Both run
automatically on commit; you should rarely need to think about them.

**JavaScript and CSS: Prettier** (`.prettierrc`). Two-space indent, single quotes, no
semicolons, 100 columns. That is deliberately *not* the WordPress house style — it is the
style the admin was already written in, and 100 columns was chosen by measuring churn
against the existing source (80 moved 1,984 lines, 120 moved 1,418, 100 moved 855).

**PHP: PHP-CS-Fixer** (`.php-cs-fixer.dist.php`). PSR-12, again because that is what the
codebase already was. Only non-risky fixers are enabled, so it cannot change behaviour and
is always safe to run over a dirty tree.

Neither of these is `phpcs`. `phpcs.xml.dist` is a separate, security-focused ruleset —
escaping, sanitising, nonces, capabilities, prepared SQL, the things a wordpress.org
review blocks on. It deliberately excludes the WordPress *style* sniffs, and the reasoning
is written at the top of that file. Run it with `npm run lint:php` or `composer lint`; it
comes with `composer install` and CI runs it on every push. `npm run lint:php:fix` is
`phpcbf`, which fixes the subset that can be fixed automatically.

**ESLint** (`.eslintrc.js`) is assembled by hand rather than taken from
`@wordpress/eslint-plugin`, because that preset cannot load in this tree — it pulls a
TypeScript toolchain to lint a codebase that contains no TypeScript, and the versions no
longer line up. What is left is the part worth having: the rules-of-hooks check, unused
and undefined bindings, and `eslint-config-prettier` last so ESLint never argues with the
formatter. `npm run lint:js`, and CI runs it.

ESLint is **not** in the pre-commit hook. It would have to run over the same files
Prettier is rewriting, and lint-staged runs separate glob groups concurrently — two
processes writing one file. Catching a lint error in CI a few minutes later is worth more
than that race.

A local `.php-cs-fixer.php` overrides the committed `.dist` file if you want to
experiment; it is not gitignored, so do not commit it.

**The pre-commit hook** (husky plus lint-staged; see the `lint-staged` block in
`package.json`) formats staged files, refuses a commit containing a PHP file that does not
parse, and blocks a dev-install `vendor/`. A file staged in part with `git add -p` has the
rest of its changes stashed while the formatters run, so nothing you held back gets swept
in. `git commit --no-verify` skips it, and CI catches what that misses.

## Tests

```bash
npm test        # or: php tests/run.php
```

The suite is a plain PHP script — no PHPUnit, no database, no WordPress. It stubs the
WordPress functions it needs in `tests/stubs.php` and runs the model layer against them,
which is why it takes seconds and why the model layer avoids WordPress calls wherever it
can. CI runs it on PHP 8.2, 8.3 and 8.4.

Add assertions for anything you change in `classes/`. `check()` takes a label, an expected
value and an actual value; follow what is already in `tests/collections.php`.

## Making a change

1. Branch off `main`.
2. Make the change. Match the surrounding style, and add comments where the reasoning is
   not obvious from the code — this codebase explains *why* rather than *what*, and a
   comment restating the line above it will be asked about in review.
3. Run `npm test` and `npm run build`. Commit `build/` alongside the source.
4. If you changed behaviour a user would notice, update the relevant page in `docs/`.
5. Add a line to `CHANGELOG.md` under the unreleased heading.

## Where things live

Three layers, and the boundary between them is the point.

| Path | What it is |
| --- | --- |
| `classes/` | The plugin. Model, storage, REST, admin wiring |
| `src/` | The admin's React source |
| `build/` | Compiled admin — generated, committed, never edited by hand |
| `docs/` | User documentation, compiled into the admin's Documentation screen |
| `tests/` | The suite and its WordPress stubs |
| `bin/` | Release and development scripts |

**Adding a field type** touches four places: the registry (`class-field-types.php`), the
resolver if it stores something other than what it renders (`class-resolver.php`), the
control (`src/shared/fields/`), and the index if it should be filterable
(`class-index.php`). The test suite covers the first two.

**Changing the query grammar** means changing `class-query.php` only — all three delivery
surfaces read it.

## Documentation

`docs/*.md` is **user-facing** and compiled into the admin's Documentation screen as well
as being readable on GitHub. One file per page. Numeric prefixes set the order, and two
HTML comments on the first two lines place it in the sidebar:

```markdown
<!-- group: Get started -->
<!-- description: One line, shown under the title in the sidebar. -->

## Page title
```

Add a file and it appears — there is no list to update.

Contributor documentation — this file, `README.md`, `CHANGELOG.md` — stays at the root and
does **not** ship, because it would otherwise turn up inside somebody's WordPress admin.

## Opening a pull request

Describe what changed and why the approach was chosen. The second half matters more than
the first; the diff already says what changed.

CI must be green. It runs five jobs: the suite on three PHP versions; the wordpress.org
review checks (`phpcs.xml.dist`); lint and formatting (ESLint, Prettier, PHP-CS-Fixer); a
`build/`-matches-`src/` check; and the `vendor/`-is-dev-free check.

## Reporting bugs

Open an issue with the WordPress version, PHP version, SchemaPress version, and the
smallest collection definition that reproduces it. A schema export
(**Settings → Portability**) is the most useful thing you can attach.

**Do not open a public issue for a security vulnerability.** Use GitHub's
[private vulnerability reporting](https://github.com/eriklarsondev/schemapress/security/advisories/new)
instead — it opens a thread visible only to the maintainers. The most useful things to
include are the steps to reproduce, what an attacker gets, and the role the attack starts
from; unauthenticated, subscriber, contributor and editor are meaningfully different here
and the difference usually decides the severity.

## Licensing

SchemaPress is GPL-2.0-or-later. By contributing you agree that your contribution is
licensed under the same terms. There is no CLA.
