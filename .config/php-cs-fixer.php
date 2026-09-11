<?php

/**
 * PHP formatting.
 *
 * PSR-12, which is what this codebase was already written in. Deliberately NOT
 * the WordPress standard — .config/phpcs.xml explains why: WPCS is mostly house
 * style, and phpcs here is doing a different job (escaping, nonces, prepared
 * SQL) that does not overlap with this one.
 *
 * Every fixer enabled is a whitespace or token-level transform that cannot
 * change behavior, so `npm run format` is always safe to run over a dirty tree.
 *
 *   npm run format:php        fix
 *   npm run format:php:check  report only
 */

$root = dirname(__DIR__);

$finder = (new PhpCsFixer\Finder())
    ->in($root)
    ->exclude(['vendor', 'node_modules', 'build', 'languages'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setFinder($finder)
    ->setCacheFile($root . '/.cache/php-cs-fixer.json')
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
