<!--
Describe what changed and why the approach was chosen. The second half matters more
than the first — the diff already says what changed.
-->

## What this does

## Why this approach

<!-- What else you considered, and what decided it. Skip if it is a one-line fix. -->

## Checklist

<!-- The pre-commit hook handles formatting; these are the ones it cannot. -->

- [ ] `npm test` passes
- [ ] `npm run build` was run and `build/` is committed with the change, if `src/` changed
- [ ] `vendor/composer/` is not in this diff, unless a dependency genuinely changed
      (see CONTRIBUTING.md — `composer install` dirties it and that is expected)
- [ ] The relevant page in `docs/` is updated, if a user would notice the change
- [ ] `CHANGELOG.md` has a line under the unreleased heading

## Anything reviewers should know

<!-- Known gaps, things you were unsure about, parts you would like a second opinion on. -->
