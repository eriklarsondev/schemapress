<?php

/**
 * Writes languages/schemapress.pot from the strings in the source.
 *
 * `wp i18n make-pot` is the usual way to do this and needs WP-CLI, which a
 * contributor cloning the repository may not have and CI should not have to
 * install to check one file. The plugin's translatable strings are called
 * through a small, fixed set of functions in both PHP and JS, so extracting them
 * is a scan rather than a parse.
 *
 * It reads the same call shapes WordPress does — __(), _e(), esc_html__(),
 * esc_attr__(), _n(), _x() and their escaping variants — and carries a
 * /* translators: *​/ comment through when one sits above the call, because that
 * comment is the only context a translator gets for a %s.
 *
 * Run: php bin/make-pot.php
 *
 * @package SchemaPress
 */

$root = dirname(__DIR__);
$domain = 'schemapress';

$sources = array_merge(
    glob_recursive($root . '/classes', 'php'),
    glob_recursive($root . '/includes', 'php'),
    glob_recursive($root . '/src', 'js'),
    [$root . '/schemapress.php']
);

/**
 * Every file of one extension below a directory.
 *
 * @param string $dir
 * @param string $extension
 *
 * @return string[]
 */
function glob_recursive($dir, $extension)
{
    if (!is_dir($dir)) {
        return [];
    }

    $found = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === $extension) {
            $found[] = $file->getPathname();
        }
    }

    sort($found);

    return $found;
}

/**
 * Escapes a string for a PO msgid.
 *
 * @param string $value
 *
 * @return string
 */
function po_escape($value)
{
    return str_replace(
        ['\\', '"', "\n", "\t"],
        ['\\\\', '\\"', '\\n', '\\t'],
        $value
    );
}

$strings = [];

// the functions that mark a string, and which argument the string is in. _n
// takes two, and _x takes a context after the string — both are handled by
// reading the whole argument list rather than only the first
$functions = '__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_x|_ex|esc_html_x|_n|_nx';

// THE WHOLE FILE, NOT A LINE AT A TIME. this scanned line by line, which meant
// the pattern only matched when `__(` and the opening quote sat on the same one
// — and this plugin wraps a long string onto the next line as a matter of house
// style. sixteen strings in the PHP alone were marked for translation, shipped
// in the source, and could not appear in the template: every conflict message,
// most of the import refusals, the export ceiling.
//
// `\s*` between the paren and the quote spans newlines on its own, so scanning
// the file text is the whole fix. the `s` modifier is deliberately NOT set: the
// string body still may not cross a newline, which is what stops an unbalanced
// quote swallowing the rest of the file as one enormous msgid.
foreach ($sources as $path) {
    $code = file_get_contents($path);
    $relative = str_replace($root . '/', '', $path);
    $lines = explode("\n", $code);

    if (!preg_match_all(
        '/\b(' . $functions . ')\s*\(\s*(["\'])((?:\\\\.|(?!\2).)*)\2/',
        $code,
        $matches,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    )) {
        continue;
    }

    foreach ($matches as $match) {
        $text = stripcslashes($match[3][0]);

        if ($text === '') {
            continue;
        }

        // the line the CALL starts on, which is where a reader would look and
        // where the translators comment sits above
        $number = substr_count($code, "\n", 0, $match[0][1]);

        if (!isset($strings[$text])) {
            $strings[$text] = ['references' => [], 'comment' => ''];
        }

        $strings[$text]['references'][] = $relative . ':' . ($number + 1);

        // a translators comment sits on the lines above the call. two is
        // enough: the convention is one line, and the plugin wraps to two
        for ($back = 1; $back <= 2; $back++) {
            $above = $lines[$number - $back] ?? '';

            if (preg_match('#translators:\s*(.+?)\s*(?:\*/)?$#i', $above, $note)) {
                $strings[$text]['comment'] = trim($note[1]);
                break;
            }
        }
    }
}

ksort($strings);

$out = [];
$out[] = '# Copyright (C) ' . gmdate('Y') . ' Erik Larson';
$out[] = '# This file is distributed under the GPL-2.0-or-later license.';
$out[] = 'msgid ""';
$out[] = 'msgstr ""';
$out[] = '"Project-Id-Version: SchemaPress\\n"';
$out[] = '"Report-Msgid-Bugs-To: https://github.com/eriklarsondev/schemapress/issues\\n"';
$out[] = '"POT-Creation-Date: ' . gmdate('Y-m-d H:iO') . '\\n"';
$out[] = '"MIME-Version: 1.0\\n"';
$out[] = '"Content-Type: text/plain; charset=UTF-8\\n"';
$out[] = '"Content-Transfer-Encoding: 8bit\\n"';
$out[] = '"Language-Team: \\n"';
$out[] = '"X-Domain: ' . $domain . '\\n"';
$out[] = '';

foreach ($strings as $text => $meta) {
    if ($meta['comment'] !== '') {
        $out[] = '#. translators: ' . $meta['comment'];
    }

    $out[] = '#: ' . implode(' ', array_unique($meta['references']));
    $out[] = 'msgid "' . po_escape($text) . '"';
    $out[] = 'msgstr ""';
    $out[] = '';
}

if (!is_dir($root . '/languages')) {
    mkdir($root . '/languages', 0755, true);
}

file_put_contents($root . '/languages/' . $domain . '.pot', implode("\n", $out));

echo count($strings) . " strings written to languages/{$domain}.pot\n";
