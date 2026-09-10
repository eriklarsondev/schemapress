<?php
/**
 * Runs every suite, in its own process.
 *
 * Separate processes because each suite defines the same in-memory WordPress —
 * the same function names, the same globals — and PHP will not let two of them
 * exist at once. That is a feature rather than a limitation: a suite cannot
 * leave state behind for the next one to accidentally depend on.
 *
 * Run: php tests/run.php
 *
 * @package SchemaPress
 */

$suites = ['collections', 'lifecycle'];
$failed = 0;
$total = 0;

foreach ($suites as $suite) {
    $path = __DIR__ . '/' . $suite . '.php';

    echo "\n=== {$suite} ===\n";

    $output = [];
    $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path) . ' 2>&1', $output, $status);

    $text = implode("\n", $output);

    // the whole suite when it fails, so the failure is readable; the tally when
    // it passes, so a green run is one line each
    echo $status === 0 ? preg_grep('/passed,/', $output)[count($output) - 1] ?? $text : $text;
    echo "\n";

    if (preg_match('/(\d+) passed, (\d+) failed/', $text, $counts)) {
        $total += (int) $counts[1];
        $failed += (int) $counts[2];
    }

    if ($status !== 0 && !$failed) {
        // a suite that died rather than failing an assertion — a fatal, a parse
        // error — has no tally to read, and must not be reported as passing
        $failed++;
    }
}

echo "\n" . str_repeat('-', 40) . "\n";
echo $failed
    ? "{$total} passed, {$failed} FAILED\n"
    : "{$total} passed, 0 failed\n";

exit($failed ? 1 : 0);
