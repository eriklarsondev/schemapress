<?php
/**
 * Renders the Hero preset end to end and prints what comes out.
 *
 * Diagnostic, not a check: it exists to answer "does a hero actually put its
 * content over its background image" with output rather than an assertion.
 *
 * Run: php tests/hero.php
 *
 * @package SchemaPress
 */

require __DIR__ . '/stubs.php';

use SchemaPress\SchemaModel;
use SchemaPress\Presets;
use SchemaPress\Resolver;
use SchemaPress\ViewModel;
use SchemaPress\Content;

$preset = null;

foreach (Presets::all() as $candidate) {
    if ($candidate['id'] === 'hero') {
        $preset = $candidate;
    }
}

$definition = SchemaModel::normalize(['sections' => [$preset]]);
$type = $definition['sections'][0];

echo "Hero fields, as normalized:\n";

foreach ($type['fields'] as $field) {
    printf("  %-14s type=%-10s role=%s\n", $field['key'], $field['type'], $field['role'] ?: '(none)');
}

$resolved = Resolver::resolve(
    ['sections' => [['id' => 's_1', 'type' => $type['key'], 'values' => []]]],
    $definition
);

echo "\nRole map delivered: " . json_encode($resolved[0]['roles']) . "\n";
echo "Type map delivered: " . json_encode($resolved[0]['types']) . "\n";

$context = ViewModel::section($resolved[0], ['editing' => true]);

echo "\nView model:\n";
echo '  classes:  ' . $context['classes'] . "\n";
echo '  backdrop: ' . ($context['backdrop'] ? 'YES' : 'NO') . "\n";
echo '  flow:     ' . implode(', ', array_map(
    function ($field) {
        return $field['key'] . '(' . $field['kind'] . ')';
    },
    $context['flow']
)) . "\n";
echo '  actions:  ' . implode(', ', array_column($context['actions'], 'key')) . "\n";

echo "\nVerdict: ";

if ($context['backdrop'] && strpos($context['classes'], 'sp-has-background') !== false) {
    echo "the background IS lifted into its own layer.\n";
} else {
    echo "the background is NOT lifted - it renders in the flow.\n";
}
