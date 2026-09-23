<?php

/**
 * The documentation, checked rather than proofread.
 *
 * Every code sample in docs/*.md is something a reader will paste into a theme,
 * a console or a GraphQL client, so a sample that does not parse is a defect in
 * the product and not a typo in the prose. Two shipped in one afternoon —
 * a list of function signatures written as though it were runnable code, and a
 * chained query with no opening tag and no semicolon — and neither was visible
 * in review, because a fenced block looks like code whether or not it is.
 *
 * The structural checks are here for the same reason: a `:::tabs` block that
 * never closes, or a pane with no label, renders as something silently wrong
 * rather than as an error, and the compiler in class-docs.php has no way to
 * complain about it.
 *
 * Run: php tests/docs.php
 *
 * @package SchemaPress
 */

$passed = 0;
$failed = 0;

/**
 * Asserts two values match.
 *
 * @param string $label
 * @param mixed  $expected
 * @param mixed  $actual
 *
 * @return void
 */
function check($label, $expected, $actual)
{
    global $passed, $failed;

    if ($expected === $actual) {
        $passed++;
        echo "  ok    {$label}\n";

        return;
    }

    $failed++;
    echo "  FAIL  {$label}\n";
    echo '        expected: ' . json_encode($expected) . "\n";
    echo '        actual:   ' . json_encode($actual) . "\n";
}

/**
 * Every fenced block of one language, as [page, index, label, code].
 *
 * @param string $language
 *
 * @return array
 */
function sp_docs_blocks($language)
{
    $blocks = [];

    foreach (sp_docs_files() as $path) {
        $markdown = (string) file_get_contents($path);

        // the language has to END here: without the guard, `js` matches every
        // ```json block, and every one of them fails as JavaScript
        preg_match_all(
            '/^```' . preg_quote($language, '/') . '(?![A-Za-z0-9_+-])[ \t]*([^\n]*)\n(.*?)^```[ \t]*$/ms',
            $markdown,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $index => $match) {
            $blocks[] = [
                'page' => basename($path),
                'at' => $index + 1,
                'label' => trim($match[1]),
                'code' => $match[2],
            ];
        }
    }

    return $blocks;
}

/**
 * The source files, in the order the compiler reads them.
 *
 * @return string[]
 */
function sp_docs_files()
{
    static $files = null;

    if ($files === null) {
        $files = glob(dirname(__DIR__) . '/docs/*.md') ?: [];
        sort($files);
    }

    return $files;
}

/**
 * Whether a source parses, using the real parser rather than a guess at one.
 *
 * @param string $source
 * @param string $extension
 * @param array  $command   the binary and its check flag
 *
 * @return string empty when it parses, the first line of the complaint otherwise
 */
function sp_docs_parses($source, $extension, array $command)
{
    $path = tempnam(sys_get_temp_dir(), 'sp-docs') . '.' . $extension;

    file_put_contents($path, $source);

    $output = [];
    $status = 0;

    exec(
        implode(' ', array_map('escapeshellarg', array_merge($command, [$path]))) . ' 2>&1',
        $output,
        $status
    );

    unlink($path);

    return $status === 0 ? '' : trim(str_replace($path, '…', $output[0] ?? 'did not parse'));
}

// --- PHP ---------------------------------------------------------------------

echo "PHP samples parse\n";

$php = sp_docs_blocks('php');

check('there are php samples to check', true, count($php) > 10);

foreach ($php as $block) {
    // a fence without an opening tag is a fragment of a file, so it is linted
    // as one — which is also the shape a reader pastes it into
    $source = strncmp(ltrim($block['code']), '<?php', 5) === 0
        ? $block['code']
        : "<?php\n" . $block['code'];

    check(
        sprintf('%s, php block %d', $block['page'], $block['at']),
        '',
        sp_docs_parses($source, 'php', [PHP_BINARY, '-l'])
    );
}

// --- JavaScript --------------------------------------------------------------

echo "\nJavaScript samples parse\n";

$js = sp_docs_blocks('js');
$node = trim((string) shell_exec('command -v node 2>/dev/null'));

if ($node === '') {
    echo "  --    skipped: no node on this machine\n";
} else {
    check('there are js samples to check', true, count($js) > 0);

    foreach ($js as $block) {
        // .mjs, because every one of these is a module: they use top-level
        // await, which is exactly what a reader pastes into a build script
        check(
            sprintf('%s, js block %d', $block['page'], $block['at']),
            '',
            sp_docs_parses($block['code'], 'mjs', [$node, '--check'])
        );
    }
}

// --- JSON --------------------------------------------------------------------

echo "\nJSON samples parse\n";

foreach (sp_docs_blocks('json') as $block) {
    $code = trim($block['code']);

    // a sample showing one key of a response is a fragment on purpose —
    // `"meta": {…}` — and is checked as the object it is part of
    $source = strncmp($code, '"', 1) === 0 ? '{' . $code . '}' : $code;

    json_decode($source);

    check(
        sprintf('%s, json block %d', $block['page'], $block['at']),
        'No error',
        json_last_error_msg()
    );
}

// --- GraphQL -----------------------------------------------------------------

echo "\nGraphQL samples are balanced\n";

// no parser to hand, so the check is the one mistake worth catching without
// one: a query whose braces do not close
foreach (sp_docs_blocks('graphql') as $block) {
    $depth = 0;
    $lowest = 0;

    foreach (str_split($block['code']) as $character) {
        if ($character === '{') {
            $depth++;
        } elseif ($character === '}') {
            $depth--;
            $lowest = min($lowest, $depth);
        }
    }

    check(
        sprintf('%s, graphql block %d', $block['page'], $block['at']),
        [0, 0],
        [$depth, $lowest]
    );
}

// --- structure ---------------------------------------------------------------

echo "\nEvery page is well formed\n";

$ids = [];

foreach (sp_docs_files() as $path) {
    $page = basename($path);
    $markdown = (string) file_get_contents($path);

    check(
        "{$page} declares a group",
        1,
        preg_match('/<!--\s*group:\s*[^>]+?\s*-->/i', $markdown)
    );

    check(
        "{$page} declares a description",
        1,
        preg_match('/<!--\s*description:\s*[^>]+?\s*-->/i', $markdown)
    );

    // the compiler lifts the leading h2 out as the page's title and derives its
    // id from it, so a page without one is a page the sidebar cannot name
    check("{$page} opens with a heading", 1, preg_match('/^##\s+\S/m', $markdown));

    preg_match('/^##\s+(.+)$/m', $markdown, $heading);
    $id = sanitize_title_for_tests(trim($heading[1] ?? ''));

    check("{$page} has an id nothing else claims", false, isset($ids[$id]));

    $ids[$id] = $page;

    // openers and their closers, since both spell `:::`
    $openers = preg_match_all('/^:::(tabs|note|tip|info|caution|warning)/m', $markdown);

    check(
        "{$page} closes every ::: block",
        $openers * 2,
        preg_match_all('/^:::/m', $markdown)
    );

    check("{$page} closes every code fence", 0, preg_match_all('/^```/m', $markdown) % 2);

    preg_match_all('/^:::tabs[ \t]*\n(.*?)^:::[ \t]*$/ms', $markdown, $groups);

    foreach ($groups[1] as $index => $body) {
        preg_match_all('/^```([A-Za-z0-9_+-]+)[ \t]*([^\n]*)\n/m', $body, $panes);

        // a pane with no label renders with the language as its tab, which is
        // not what any of these are trying to say
        $unlabeled = array_filter($panes[2], function ($label) {
            return trim($label) === '';
        });

        check(
            sprintf('%s, tab group %d labels every pane', $page, $index + 1),
            [],
            array_values($unlabeled)
        );

        check(
            sprintf('%s, tab group %d has panes', $page, $index + 1),
            true,
            count($panes[1]) > 0
        );
    }
}

/**
 * The compiler's id, without loading WordPress for it.
 *
 * @param string $title
 *
 * @return string
 */
function sanitize_title_for_tests($title)
{
    $id = strtolower(preg_replace('/[^a-z0-9]+/i', '-', html_entity_decode($title)));

    return trim(preg_replace('/-+/', '-', $id), '-');
}

// --- links off this site -----------------------------------------------------

echo "\nA link out of the documentation opens in its own tab\n";

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

/**
 * Just enough WordPress for the one method under test. The compiler needs
 * a parser and a post type registry; this does not.
 */
if (!function_exists('home_url')) {
    function home_url($path = '')
    {
        return 'https://example.test/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1)
    {
        return parse_url($url, $component);
    }
}

require_once dirname(__DIR__) . '/classes/class-docs.php';

$links = new ReflectionMethod('SchemaPress\\Docs', 'links');

/**
 * Runs the rewrite over one anchor.
 *
 * @param string $html
 *
 * @return string
 */
function sp_docs_links($html)
{
    global $links;

    return $links->invoke(null, $html);
}

check(
    'a link to another site gets a target and a rel',
    '<p><a href="https://wordpress.org/plugins/wp-graphql/" target="_blank" rel="noopener noreferrer">WPGraphQL</a></p>',
    sp_docs_links('<p><a href="https://wordpress.org/plugins/wp-graphql/">WPGraphQL</a></p>')
);

check(
    'a link back to this site is left alone',
    '<p><a href="https://example.test/wp-admin/">Settings</a></p>',
    sp_docs_links('<p><a href="https://example.test/wp-admin/">Settings</a></p>')
);

// the contents list and the cross-page references route on the fragment, and
// a new tab is the wrong answer for both
check(
    'an anchor on this page is left alone',
    '<p><a href="#validation">Must be unique</a></p>',
    sp_docs_links('<p><a href="#validation">Must be unique</a></p>')
);

check(
    'a link that already said what it wants keeps it',
    '<p><a href="https://x.test/" target="_self">there</a></p>',
    sp_docs_links('<p><a href="https://x.test/" target="_self">there</a></p>')
);

// kses runs over the compiled HTML, so the pair has to survive it or the
// behavior lives in the compiler and never reaches the page
$allowed = new ReflectionMethod('SchemaPress\\Docs', 'allowedHtml');

if (!function_exists('wp_kses_allowed_html')) {
    function wp_kses_allowed_html($context = '')
    {
        return ['a' => ['href' => true]];
    }
}

$tags = $allowed->invoke(null);

check('the allow-list permits target', true, !empty($tags['a']['target']));
check('and rel', true, !empty($tags['a']['rel']));

// --- result ------------------------------------------------------------------

echo "\n{$passed} passed, {$failed} failed\n";

exit($failed === 0 ? 0 : 1);
