<?php

/**
 * PHP formatting.
 *
 * PSR-12, which is what this codebase was already written in — 4 spaces,
 * braces on their own line for classes and functions, short arrays, one
 * import per line. Running the fixer over the tree as it stood moved 506
 * lines across 42 files, nearly all of them a blank line after `<?php` and a
 * one-line function body opened out. Nothing here is a change of house style;
 * it is the style already in use, written down so it stops drifting.
 *
 * Deliberately NOT the WordPress standard. phpcs.xml.dist explains why at
 * length: WPCS is mostly house style — Yoda conditions, snake_case methods,
 * alignment — and this codebase has its own, stated in README.md. phpcs is
 * still the thing that checks escaping, nonces and prepared SQL; that is a
 * different job from this one and the two do not overlap.
 *
 * No risky rules. Every fixer enabled here is a whitespace or token-level
 * transform that cannot change behaviour, so `npm run format` is always safe
 * to run over a dirty tree.
 *
 *   composer format        fix          (or npm run format:php)
 *   composer format:check  report only  (or npm run format:php:check)
 *
 * The fixer is a require-dev dependency, so `composer install` is all a
 * contributor needs. The catch is that vendor/ is committed — Timber has to be
 * in the tree for anyone who unzips this into wp-content/plugins — so the 33
 * packages this drags in are ignored by name in .gitignore, and CI fails a
 * commit whose vendor/composer/installed.json lists any of them.
 */

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude(['vendor', 'node_modules', 'build', 'languages'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->setRules([
        '@PSR12' => true,

        // already true everywhere; pinned so it stays that way
        'array_syntax' => ['syntax' => 'short'],
        'single_quote' => true,

        // a trailing comma on the last element means adding the next one is a
        // one-line diff instead of a two-line one
        'trailing_comma_in_multiline' => true,

        // @PSR12 does not cover this one, and `[1,2]` gets through it
        'whitespace_after_comma_in_array' => true,

        'no_unused_imports' => true,
        'no_whitespace_in_blank_line' => true,
        'no_extra_blank_lines' => ['tokens' => ['extra', 'curly_brace_block']],
        'single_line_comment_spacing' => true,
    ]);
