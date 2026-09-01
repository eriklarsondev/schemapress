<?php
/**
 * Pipeline checks for the pure data layer.
 *
 * SchemaModel, ContentSanitizer, Fields, ViewModel and Settings hold the
 * plugin's actual rules - what a key becomes, what a save is allowed to store,
 * what a template is guaranteed to be able to read. They touch only a handful
 * of WordPress functions, stubbed in stubs.php, so they run without a database.
 *
 * Run: php tests/pipeline.php
 *
 * @package SchemaPress
 */

require __DIR__ . '/stubs.php';

use SchemaPress\SchemaModel;
use SchemaPress\Content;
use SchemaPress\ContentSanitizer;
use SchemaPress\Fields;
// --- tiny harness -----------------------------------------------------------

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

// --- SchemaModel::normalize -------------------------------------------------

echo "SchemaModel::normalize\n";

$definition = SchemaModel::normalize([
    'sections' => [
        [
            'label' => 'Card Grid',
            'max' => '2',
            'layout' => ['columns', 'background', 'not_an_option'],
            'fields' => [
                ['label' => 'Heading', 'type' => 'text'],
                ['label' => 'Heading', 'type' => 'text'],          // duplicate key
                ['label' => 'Mystery', 'type' => 'not_a_type'],    // unknown type
                [
                    'label' => 'Cards',
                    'type' => 'repeater',
                    'config' => ['min' => 1, 'max' => 3, 'row_label' => 'title', 'junk' => 'x'],
                    'fields' => [
                        ['label' => 'Title', 'type' => 'text'],
                        ['label' => 'Link', 'type' => 'link'],
                    ],
                ],
            ],
        ],
        ['label' => 'Card Grid'],  // duplicate section key
    ],
]);

$section = $definition['sections'][0];

check('slugifies a label into a key', 'card_grid', $section['key']);
check('casts max to an integer', 2, $section['max']);
check('deduplicates sibling field keys', 'heading_2', $section['fields'][1]['key']);
check('drops fields with an unknown type', 3, count($section['fields']));
check('deduplicates sibling section keys', 'card_grid_2', $definition['sections'][1]['key']);
check('leaves a duplicate label alone', 'Card Grid', $definition['sections'][1]['label']);

$repeater = $section['fields'][2];

check('keeps whitelisted repeater config', 3, $repeater['config']['max']);
check('drops unknown config keys', false, array_key_exists('junk', $repeater['config']));
check('recurses into repeater children', 'link', $repeater['fields'][1]['type']);

$keyed = SchemaModel::normalize([
    'sections' => [['key' => 'hero_banner', 'fields' => [['key' => 'main_heading', 'type' => 'text']]]],
]);

check('defaults a missing section label from its key', 'Hero Banner', $keyed['sections'][0]['label']);
check(
    'defaults a missing field label from its key',
    'Main Heading',
    $keyed['sections'][0]['fields'][0]['label']
);

// --- ContentSanitizer -------------------------------------------------------

echo "\nContentSanitizer::sanitize\n";

// which options apply is a property of the component's shape, not a choice:
// this section has a repeater, so it gets all three
check(
    'offers every applicable layout option',
    ['columns', 'width', 'background', 'align'],
    $section['layout']
);

$content = Content::shape([
    'sections' => [
        ['id' => 's_one', 'type' => 'card_grid', 'layout' => ['columns' => '4', 'background' => 'nope'], 'values' => [
            'heading' => '  <b>Hello</b>  ',
            'undeclared' => 'should vanish',
            'cards' => [
                ['id' => 'r_one', 'values' => ['title' => 'A']],
                ['id' => 'r_two', 'values' => ['title' => 'B']],
                ['id' => 'r_three', 'values' => ['title' => 'C']],
                ['id' => 'r_four', 'values' => ['title' => 'D']],  // beyond max of 3
            ],
        ]],
        ['id' => 's_two', 'type' => 'card_grid', 'values' => []],
        ['id' => 's_three', 'type' => 'card_grid', 'values' => []],   // beyond max of 2
        ['id' => 's_four', 'type' => 'ghost_section', 'values' => []], // not in the schema
    ],
]);

$clean = ContentSanitizer::sanitize($content, $definition);

check('enforces the section instance cap', 2, count($clean['sections']));
check('drops sections the schema does not declare', 'card_grid', $clean['sections'][1]['type']);
check('sanitizes scalar values', 'Hello', $clean['sections'][0]['values']['heading']);
check(
    'drops undeclared keys',
    false,
    array_key_exists('undeclared', $clean['sections'][0]['values'])
);
check('fills declared-but-missing fields', '', $clean['sections'][0]['values']['heading_2']);
check('enforces the repeater max', 3, count($clean['sections'][0]['values']['cards']));
check('preserves row identity', 'r_two', $clean['sections'][0]['values']['cards'][1]['id']);
check('pads to the repeater min', 1, count($clean['sections'][1]['values']['cards']));
check(
    'defaults a link to its empty shape',
    ['url' => '', 'label' => '', 'target' => ''],
    $clean['sections'][0]['values']['cards'][0]['values']['link']
);

echo "\nLayout\n";

check('keeps a valid layout value', '4', $clean['sections'][0]['layout']['columns']);
check(
    'falls back to the default for an invalid one',
    'none',
    $clean['sections'][0]['layout']['background']
);
check(
    'fills every applicable option',
    ['columns', 'width', 'background', 'align'],
    array_keys($clean['sections'][1]['layout'])
);
check(
    'defaults an unset layout entirely',
    ['columns' => '3', 'width' => 'normal', 'background' => 'none', 'align' => 'left'],
    $clean['sections'][1]['layout']
);

// columns describe how a repeated thing is laid out, so a component with
// nothing to repeat should never be offered them
$noRepeater = SchemaModel::normalize([
    'sections' => [[
        'label' => 'Heading',
        'fields' => [['label' => 'Heading', 'type' => 'text']],
    ]],
]);

check(
    'omits columns on a component with no repeater',
    ['width', 'background', 'align'],
    $noRepeater['sections'][0]['layout']
);

$nestedRepeater = SchemaModel::normalize([
    'sections' => [[
        'label' => 'Wrapper',
        'fields' => [[
            'label' => 'Group',
            'type' => 'group',
            'fields' => [['label' => 'Items', 'type' => 'repeater', 'fields' => []]],
        ]],
    ]],
]);

check(
    'finds a repeater nested inside a group',
    true,
    in_array('columns', $nestedRepeater['sections'][0]['layout'], true)
);

// a stored subset is ignored: which options apply follows the component's
// shape, so an old schema that recorded a narrower list still gets them all
$legacy = SchemaModel::normalize([
    'sections' => [[
        'label' => 'Cards',
        'layout' => ['width'],
        'fields' => [['label' => 'Items', 'type' => 'repeater', 'fields' => []]],
    ]],
]);

check(
    'ignores a previously stored subset',
    ['columns', 'width', 'background', 'align'],
    $legacy['sections'][0]['layout']
);

$noLayout = SchemaModel::normalize([
    'sections' => [['label' => 'Plain', 'fields' => [['label' => 'Body', 'type' => 'textarea']]]],
]);

check(
    'a plain component still gets the universal options',
    ['width', 'background', 'align'],
    $noLayout['sections'][0]['layout']
);

// --- Fields accessor --------------------------------------------------------

echo "\nFields accessor\n";

$fields = new Fields($clean['sections'][0]['values'], $section['fields']);

check('reads a scalar by key', 'Hello', $fields->get('heading'));
check('returns the default for an unknown key', 'fallback', $fields->get('nope', 'fallback'));
check('returns the default for an empty value', 'fallback', $fields->get('heading_2', 'fallback'));
check('reports presence', true, $fields->has('heading'));
check('reports absence', false, $fields->has('heading_2'));
check('exposes repeater rows', 3, count($fields->rows('cards')));
check('rows read their own fields', 'B', $fields->rows('cards')[1]->get('title'));
check('rows are Fields instances', true, $fields->rows('cards')[0] instanceof Fields);

// a definition change must not break reads of content saved against the old one
echo "\nSchema drift\n";

$narrowed = SchemaModel::normalize([
    'sections' => [[
        'label' => 'Card Grid',
        'fields' => [
            ['label' => 'Heading', 'type' => 'text'],
            ['label' => 'Subheading', 'type' => 'text'],  // added after the save
        ],
    ]],
]);

$reconciled = ContentSanitizer::values(
    $clean['sections'][0]['values'],
    $narrowed['sections'][0]['fields']
);

check('keeps values still declared', 'Hello', $reconciled['heading']);
check('defaults fields added since the save', '', $reconciled['subheading']);
check('drops values no longer declared', false, array_key_exists('cards', $reconciled));

// --- roles ------------------------------------------------------------------

echo "\nField roles\n";

$roled = SchemaModel::normalize([
    'sections' => [[
        'label' => 'Hero',
        'fields' => [
            ['label' => 'Backdrop', 'type' => 'image', 'role' => 'background'],
            ['label' => 'Title', 'type' => 'text', 'role' => 'heading'],
            // a role that does not suit the type is dropped, not stored
            ['label' => 'Body', 'type' => 'textarea', 'role' => 'background'],
            ['label' => 'Button', 'type' => 'link', 'role' => 'action'],
            ['label' => 'Note', 'type' => 'text', 'role' => 'not_a_role'],
        ],
    ]],
]);

$heroFields = $roled['sections'][0]['fields'];

check('keeps a role valid for the type', 'background', $heroFields[0]['role']);
check('drops a role the type cannot take', '', $heroFields[2]['role']);
check('drops an unregistered role', '', $heroFields[4]['role']);
check(
    'maps roles to field keys',
    ['background' => 'backdrop', 'heading' => 'title', 'action' => 'button'],
    SchemaPress\Resolver::roles($heroFields)
);

// --- classes ----------------------------------------------------------------

// classes are written into a class attribute, so anything that could close it
// or open a new one has to be gone before it gets there

echo "\nClasses\n";

/**
 * Normalizes a single field's classes through the schema model.
 *
 * @param string $classes
 *
 * @return string
 */
function classesFor($classes)
{
    $built = SchemaModel::normalize([
        'sections' => [[
            'label' => 'S',
            'fields' => [['label' => 'F', 'type' => 'text', 'classes' => $classes]],
        ]],
    ]);

    return $built['sections'][0]['fields'][0]['classes'];
}

check('keeps ordinary utilities', 'text-2xl font-bold', classesFor('text-2xl font-bold'));
check(
    'keeps Tailwind arbitrary values',
    'md:grid-cols-[1fr_2fr] bg-[#ff0000] w-1/2',
    classesFor('md:grid-cols-[1fr_2fr] bg-[#ff0000] w-1/2')
);
check('collapses whitespace', 'a b', classesFor("  a \n\t b  "));
check('strips quotes', 'onerror=alert', classesFor('" onerror=alert "'));
// `>` survives because Tailwind arbitrary variants need it, and esc_attr
// escapes it at output; `<` has no legitimate use and cannot be allowed to
// reach the attribute at all
check('strips opening angle brackets', 'p>text', classesFor('<p>text'));
check('keeps arbitrary variants', '[&>*]:mt-4', classesFor('[&>*]:mt-4'));
check('handles an array', 'a b', classesFor(['a', 'b']));

$withClasses = SchemaModel::normalize([
    'sections' => [[
        'label' => 'S',
        'fields' => [
            ['label' => 'Title', 'type' => 'text', 'classes' => 'text-xl'],
            ['label' => 'Plain', 'type' => 'text'],
            [
                'label' => 'Items',
                'type' => 'repeater',
                'fields' => [['label' => 'Name', 'type' => 'text', 'classes' => 'font-mono']],
            ],
        ],
    ]],
]);

check(
    'maps only fields that have classes, at any depth',
    ['title' => 'text-xl', 'name' => 'font-mono'],
    SchemaPress\Resolver::classes($withClasses['sections'][0]['fields'])
);

// --- element palette --------------------------------------------------------

// an element with an unregistered field type would be silently dropped on
// normalization, leaving a palette entry that adds nothing

echo "\nElement palette\n";

foreach (SchemaPress\Elements::all() as $element) {
    $built = SchemaModel::normalize([
        'sections' => [['label' => 'S', 'fields' => [$element['field']]]],
    ]);

    $fields = $built['sections'][0]['fields'];

    check("{$element['id']}: produces a field", 1, count($fields));

    if (!empty($element['field']['role'])) {
        check(
            "{$element['id']}: keeps its role",
            $element['field']['role'],
            $fields[0]['role'] ?? ''
        );
    }
}

// --- containers and nesting -------------------------------------------------

echo "\nContainers\n";

$nested = SchemaModel::normalize([
    'sections' => [
        [
            'label' => 'Columns',
            'container' => true,
            'layout' => ['columns', 'width'],
            'fields' => [],
        ],
        ['label' => 'Text', 'fields' => [['label' => 'Body', 'type' => 'textarea']]],
    ],
]);

check('marks a container', true, $nested['sections'][0]['container']);
check('marks a non-container', false, $nested['sections'][1]['container']);
check(
    'a container may use columns without a repeater',
    true,
    in_array('columns', $nested['sections'][0]['layout'], true)
);

$tree = ContentSanitizer::sanitize(
    Content::shape([
        'sections' => [[
            'id' => 's_row',
            'type' => 'columns',
            'children' => [
                ['id' => 's_a', 'type' => 'text', 'values' => ['body' => 'Left']],
                ['id' => 's_b', 'type' => 'text', 'values' => ['body' => 'Right']],
                ['id' => 's_c', 'type' => 'ghost', 'values' => []],
            ],
        ]],
    ]),
    $nested
);

check('keeps children on a container', 2, count($tree['sections'][0]['children']));
check('sanitizes nested values', 'Right', $tree['sections'][0]['children'][1]['values']['body']);
check('drops undeclared nested types', 'text', $tree['sections'][0]['children'][0]['type']);

// children on a type that is not a container are content nothing can render
$stripped = ContentSanitizer::sanitize(
    Content::shape([
        'sections' => [[
            'id' => 's_x',
            'type' => 'text',
            'children' => [['id' => 's_y', 'type' => 'text', 'values' => []]],
        ]],
    ]),
    $nested
);

check('drops children on a non-container', [], $stripped['sections'][0]['children']);

// a self-nesting payload must terminate rather than recurse until it dies
$deep = ['id' => 's_0', 'type' => 'columns', 'children' => []];
$cursor = &$deep;

for ($i = 1; $i <= 10; $i++) {
    $cursor['children'] = [['id' => 's_' . $i, 'type' => 'columns', 'children' => []]];
    $cursor = &$cursor['children'][0];
}
unset($cursor);

$capped = ContentSanitizer::sanitize(
    Content::shape(['sections' => [$deep]]),
    $nested
);

/**
 * Measures how deep a sanitized tree actually goes.
 *
 * @param array $sections
 *
 * @return integer
 */
function depthOf(array $sections)
{
    $deepest = 0;

    foreach ($sections as $section) {
        if (!empty($section['children'])) {
            $deepest = max($deepest, 1 + depthOf($section['children']));
        }
    }

    return $deepest;
}

check('caps runaway nesting', true, depthOf($capped['sections']) <= Content::MAX_DEPTH);

// --- component presets ------------------------------------------------------

// a preset with an unregistered field type, or a row_label naming a subfield
// that does not exist, degrades silently: the field is dropped on normalize
// and the repeater falls back to "Item 1". both are worth catching here.

echo "\nComponent presets\n";

foreach (SchemaPress\Presets::all() as $preset) {
    $id = $preset['id'];

    $normalized = SchemaModel::normalize(['sections' => [$preset]]);
    $built = $normalized['sections'][0];

    check(
        "{$id}: every field survives normalization",
        count($preset['fields']),
        count($built['fields'])
    );

    // columns only make sense where there is something to lay out, so a
    // preset gets them exactly when it repeats or contains
    $repeats = !empty($preset['container']);

    foreach ($preset['fields'] as $field) {
        if (($field['type'] ?? '') === 'repeater') {
            $repeats = true;
        }
    }

    check(
        "{$id}: columns offered only where they apply",
        $repeats,
        in_array('columns', $built['layout'], true)
    );

    check(
        "{$id}: always offers width",
        true,
        in_array('width', $built['layout'], true)
    );

    foreach ($built['fields'] as $index => $field) {
        if ($field['type'] !== 'repeater') {
            continue;
        }

        check(
            "{$id}: repeater children survive",
            count($preset['fields'][$index]['fields']),
            count($field['fields'])
        );

        $rowLabel = $field['config']['row_label'] ?? '';

        if ($rowLabel !== '') {
            check(
                "{$id}: row_label names a real subfield",
                true,
                SchemaModel::field($field['fields'], $rowLabel) !== null
            );
        }
    }
}

// --- view model -------------------------------------------------------------

// the view model is what a Twig template receives. it decides what each value
// *is* - a backdrop, a button, a repeater - so that templates can be markup
// rather than branching. every wrong guess here is a wrong template.

echo "\nView model\n";

$vm = SchemaPress\ViewModel::section([
    'id' => 's_hero',
    'type' => 'hero',
    'layout' => ['width' => 'full', 'columns' => '3'],
    'roles' => ['background' => 'backdrop', 'heading' => 'title', 'action' => 'cta'],
    'classes' => ['title' => 'text-5xl'],
    'data' => [
        'backdrop' => ['url' => 'http://x/bg.jpg', 'mime' => 'image/jpeg', 'alt' => 'A', 'sizes' => []],
        'title' => 'Hello',
        'body' => '<p>Rich</p>',
        'shot' => ['url' => 'http://x/a.jpg', 'mime' => 'image/png', 'alt' => '', 'sizes' => []],
        'brochure' => ['url' => 'http://x/a.pdf', 'mime' => 'application/pdf', 'title' => 'PDF', 'sizes' => []],
        'cta' => ['url' => 'http://x', 'label' => 'Go', 'target' => ''],
        'related' => ['permalink' => 'http://x/p', 'title' => 'P'],
        'cards' => [
            ['id' => 'r_1', 'data' => ['name' => 'One']],
            ['id' => 'r_2', 'data' => ['name' => 'Two']],
        ],
        'empty' => '',
        'flag' => true,
    ],
    'children' => [
        ['id' => 's_kid', 'type' => 'text', 'layout' => [], 'roles' => [], 'classes' => [], 'data' => ['body' => 'Kid'], 'children' => []],
    ],
]);

check('lifts a backdrop out of the flow', 'http://x/bg.jpg', $vm['backdrop']['url']);
check('marks a section that has one', true, strpos($vm['classes'], 'sp-has-background') !== false);
check('turns layout tokens into classes', true, strpos($vm['classes'], 'sp-width-full') !== false);
check('names the section type', true, strpos($vm['classes'], 'sp-section--hero') !== false);

$kinds = wp_list_pluck($vm['flow'], 'kind', 'key');

check('a heading role is a heading', 'heading', $kinds['title']);
check('markup is rich text', 'rich', $kinds['body']);
check('an image mime is an image', 'image', $kinds['shot']);
check('a non-image mime is a file', 'file', $kinds['brochure']);
check('a permalink is a post', 'post', $kinds['related']);
check('a list of rows is a repeater', 'repeater', $kinds['cards']);
check('an empty value is dropped', false, array_key_exists('empty', $kinds));
check('a boolean is dropped', false, array_key_exists('flag', $kinds));
check('an action is not in the flow', false, array_key_exists('cta', $kinds));
check('an action is collected separately', 'cta', $vm['actions'][0]['key']);
check('a backdrop is not in the flow', false, array_key_exists('backdrop', $kinds));

$cards = null;
foreach ($vm['flow'] as $entry) {
    if ($entry['key'] === 'cards') {
        $cards = $entry;
    }
}

check('repeater rows keep their identity', 'r_2', $cards['rows'][1]['id']);
check('repeater rows describe their own fields', 'Two', $cards['rows'][1]['fields'][0]['value']);
check('a repeater is told the column count', 3, $cards['columns']);
check('author classes reach the field', 'text-5xl', $kinds === null ? '' : $vm['flow'][0]['classes']);
check('children are view models too', 's_kid', $vm['children'][0]['id']);
check('a child with no backdrop is not marked', false, strpos($vm['children'][0]['classes'], 'sp-has-background') !== false);

// --- design tokens ----------------------------------------------------------

// tokens are printed inside a style block, where a malformed value does not
// merely fail to apply - it can close the declaration and open another

echo "\nDesign tokens\n";

use SchemaPress\Settings;

$saved = Settings::save([
    'width_narrow' => '48rem',
    'width_normal' => '90ch',
    'gutter' => '20px',
    'color_muted' => '#EEF1F4',
    'color_dark' => '#000',
]);

check('keeps a valid rem length', '48rem', $saved['width_narrow']);
check('keeps other CSS units', '90ch', $saved['width_normal']);
check('keeps pixels', '20px', $saved['gutter']);
check('keeps a six-digit colour', '#EEF1F4', $saved['color_muted']);
check('keeps a shorthand colour', '#000', $saved['color_dark']);

$rejected = Settings::save([
    // a bare number has no unit and would be ignored by CSS anyway
    'width_narrow' => '48',
    // the classic break-out: close this declaration, open a rule of your own
    'width_normal' => '10rem; } body { display: none; } .x {',
    'gutter' => 'expression(alert(1))',
    'color_muted' => 'red; } html { background: url(javascript:alert(1))',
    'color_dark' => 'rgb(0,0,0)',
]);

check('rejects a length with no unit', '42rem', $rejected['width_narrow']);
check('rejects a declaration break-out', '72rem', $rejected['width_normal']);
check('rejects a CSS expression', '1.5rem', $rejected['gutter']);
check('rejects a non-hex colour', '#f4f5f7', $rejected['color_muted']);
check('rejects rgb() notation', '#16181d', $rejected['color_dark']);

$css = Settings::cssVariables();

check('emits a custom property block', true, strpos($css, ':root{') === 0);
check('names properties after their token', true, strpos($css, '--sp-width-narrow:42rem;') !== false);
check('cannot contain a closing brace mid-value', 1, substr_count($css, '}'));

// an unknown key is not a token and must not reach the stylesheet
Settings::save(['made_up' => '10rem']);

check('ignores unknown keys', false, strpos(Settings::cssVariables(), 'made-up') !== false);

// --- twig templates ---------------------------------------------------------

// not a parse - Twig is not installed here, and this makes no claim to be a
// substitute for one. it catches the two failures that are otherwise invisible
// until a page is rendered: a template whose tags do not balance, and a field
// kind with no template, which silently degrades to plain text.

echo "\nTwig templates\n";

/**
 * Counts unbalanced Twig delimiters and block tags in a template.
 *
 * @param string $source
 *
 * @return array problems found
 */
function twigProblems($source)
{
    $problems = [];

    $pairs = ['{{' => '}}', '{%' => '%}', '{#' => '#}'];

    foreach ($pairs as $open => $close) {
        $opened = substr_count($source, $open);
        $closed = substr_count($source, $close);

        if ($opened !== $closed) {
            $problems[] = "{$open} x{$opened} vs {$close} x{$closed}";
        }
    }

    foreach (['for', 'if', 'block', 'macro'] as $tag) {
        $opened = preg_match_all('/\{%-?\s*' . $tag . '\s/', $source);
        $closed = preg_match_all('/\{%-?\s*end' . $tag . '\s*-?%\}/', $source);

        if ($opened !== $closed) {
            $problems[] = "{$tag} x{$opened} vs end{$tag} x{$closed}";
        }
    }

    return $problems;
}

$views = __DIR__ . '/../views';
$templates = [];

foreach (['sections', 'fields'] as $folder) {
    foreach (glob($views . '/' . $folder . '/*.twig') ?: [] as $path) {
        $templates[$folder . '/' . basename($path)] = file_get_contents($path);
    }
}

check('ships templates', true, count($templates) > 0);

foreach ($templates as $name => $source) {
    check("{$name} balances its tags", [], twigProblems($source));
}

// every kind ViewModel can emit needs a template, or it renders as plain text
$kindsEmitted = ['heading', 'eyebrow', 'text', 'rich', 'image', 'file', 'link', 'post', 'posts', 'repeater', 'group'];

foreach ($kindsEmitted as $kind) {
    check(
        "the {$kind} kind has a template",
        true,
        isset($templates['fields/' . $kind . '.twig'])
    );
}

check('there is a default section template', true, isset($templates['sections/default.twig']));

// --- result -----------------------------------------------------------------

echo "\n{$passed} passed, {$failed} failed\n";

exit($failed === 0 ? 0 : 1);
