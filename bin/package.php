<?php

/**
 * Builds the zip the plugin directory serves.
 *
 * The file list comes from `git ls-files` and is then filtered through
 * .distignore. Sourcing it from git is what makes an untracked file unable to
 * ship: the previous version walked the working directory, so anything nobody
 * had thought to name in .distignore went into the release — a local
 * .claude/settings.local.json did, and a .env would have.
 *
 * vendor/ is rebuilt with --no-dev into the staging copy, so the package is
 * correct regardless of what the working tree's vendor/ happens to hold.
 *
 * Run: npm run package
 *
 * @package SchemaPress
 */

$root = dirname(__DIR__);
$slug = 'schemapress';

/**
 * Prints a line and stops.
 *
 * @param string $message
 *
 * @return void
 */
function schemapress_fail($message)
{
    fwrite(STDERR, "\n  " . $message . "\n\n");
    exit(1);
}

/**
 * Runs a command in a directory, stopping on failure.
 *
 * @param string $command
 * @param string $cwd
 *
 * @return void
 */
function schemapress_run($command, $cwd)
{
    $output = [];
    $status = 0;

    exec('cd ' . escapeshellarg($cwd) . ' && ' . $command . ' 2>&1', $output, $status);

    if ($status !== 0) {
        schemapress_fail($command . " failed:\n\n" . implode("\n", $output));
    }
}

/**
 * Runs a command and splits its NUL-separated output.
 *
 * shell_exec rather than exec: exec() splits on newlines and drops them, which
 * would corrupt a path containing one. It returns null for empty output as well
 * as for failure, so the two are not distinguishable here — the caller checks
 * this is a git checkout first, which is the failure worth catching.
 *
 * @param string $command
 * @param string $cwd
 *
 * @return string[]
 */
function schemapress_lines($command, $cwd)
{
    $raw = (string) shell_exec('cd ' . escapeshellarg($cwd) . ' && ' . $command);

    return array_values(array_filter(
        explode("\0", $raw),
        function ($line) {
            return $line !== '';
        }
    ));
}

// --- the versions have to agree ---------------------------------------------
//
// A Stable tag that disagrees with the plugin header is the most common reason
// a directory release serves the wrong thing.

$header = file_get_contents($root . '/' . $slug . '.php');
$readme = file_get_contents($root . '/readme.txt');

preg_match('/^\s*\*\s*Version:\s*(\S+)/m', $header, $declared);
preg_match("/define\('SCHEMAPRESS_VERSION', '([^']+)'\)/", $header, $constant);
preg_match('/^Stable tag:\s*(\S+)/m', $readme, $stable);

$version = $declared[1] ?? '';

if ($version === '' || ($constant[1] ?? '') !== $version) {
    schemapress_fail('The plugin header and SCHEMAPRESS_VERSION disagree.');
}

if (($stable[1] ?? '') !== $version) {
    schemapress_fail(
        'readme.txt says Stable tag ' . ($stable[1] ?? '?') . ', the plugin says ' . $version . '.'
    );
}

// package.json ships, because src/ does and the build has to stay reproducible
// from what is in the zip
$manifest = json_decode((string) file_get_contents($root . '/package.json'), true);

if (($manifest['version'] ?? '') !== $version) {
    schemapress_fail(
        'package.json says ' . ($manifest['version'] ?? '?') . ', the plugin says ' . $version . '.'
    );
}

// --- what does not ship ------------------------------------------------------

$ignored = array_values(array_filter(array_map(
    'trim',
    explode("\n", (string) file_get_contents($root . '/.distignore'))
), function ($line) {
    return $line !== '' && strpos($line, '#') !== 0;
}));

/**
 * Whether a path is excluded by .distignore.
 *
 * Matched on any whole path segment, so `tests` excludes `tests/` wherever it
 * appears — the same reading `wp dist-archive` gives it. A pattern may also be
 * a glob.
 *
 * @param string $relative
 * @param array  $ignored
 *
 * @return boolean
 */
function schemapress_ignored($relative, array $ignored)
{
    $segments = explode('/', $relative);

    foreach ($ignored as $pattern) {
        if (in_array($pattern, $segments, true)) {
            return true;
        }

        foreach ($segments as $segment) {
            if (fnmatch($pattern, $segment)) {
                return true;
            }
        }
    }

    return false;
}

// --- the file list -----------------------------------------------------------

exec('cd ' . escapeshellarg($root) . ' && git rev-parse --is-inside-work-tree 2>/dev/null', $probe, $inRepo);

if ($inRepo !== 0) {
    schemapress_fail(
        'Not a git checkout. The file list comes from `git ls-files`, so this '
            . 'cannot build a package from an exported tree.'
    );
}

$tracked = schemapress_lines('git ls-files -z', $root);

// Reported rather than refused: a contributor who forgot to `git add` a new
// source file should see it named, not discover it missing from a release.
$untracked = array_filter(
    schemapress_lines('git ls-files -z --others --exclude-standard', $root),
    function ($path) use ($ignored) {
        return !schemapress_ignored($path, $ignored);
    }
);

// --- stage ------------------------------------------------------------------

$build = sys_get_temp_dir() . '/schemapress-package-' . getmypid();
$stage = $build . '/' . $slug;

schemapress_run('rm -rf ' . escapeshellarg($build), $root);
mkdir($stage, 0755, true);

foreach ($tracked as $relative) {
    if (schemapress_ignored($relative, $ignored) || !is_file($root . '/' . $relative)) {
        continue;
    }

    $directory = dirname($stage . '/' . $relative);

    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    copy($root . '/' . $relative, $stage . '/' . $relative);
}

// --- vendor, without the tooling --------------------------------------------
//
// The manifests are staged deliberately: .distignore excludes them from the
// package, but the rebuild below needs them. They are deleted again afterwards.

foreach (['composer.json', 'composer.lock'] as $file) {
    if (file_exists($root . '/' . $file)) {
        copy($root . '/' . $file, $stage . '/' . $file);
    }
}

if (file_exists($stage . '/composer.json')) {
    schemapress_run('rm -rf vendor', $stage);
    schemapress_run(
        'composer install --no-dev --optimize-autoloader --no-interaction --quiet',
        $stage
    );

    unlink($stage . '/composer.json');

    if (file_exists($stage . '/composer.lock')) {
        unlink($stage . '/composer.lock');
    }
}

// --- zip ---------------------------------------------------------------------

$name = $slug . '.' . $version . '.zip';
$target = $root . '/' . $name;

if (file_exists($target)) {
    unlink($target);
}

$packaged = 0;

$staged = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($stage, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($staged as $file) {
    if ($file->isFile()) {
        $packaged++;
    }
}

schemapress_run('zip -rq ' . escapeshellarg($target) . ' ' . escapeshellarg($slug), $build);
schemapress_run('rm -rf ' . escapeshellarg($build), $root);

printf(
    "\n  %s\n  %d files, %s\n",
    $name,
    $packaged,
    schemapress_size(filesize($target))
);

if ($untracked) {
    printf(
        "\n  %d untracked file(s) were NOT packaged:\n%s\n",
        count($untracked),
        '    ' . implode("\n    ", $untracked)
    );
}

echo "\n";

/**
 * Bytes as something readable, since WordPress is not loaded here.
 *
 * @param integer $bytes
 *
 * @return string
 */
function schemapress_size($bytes)
{
    return $bytes > 1048576
        ? round($bytes / 1048576, 1) . 'MB'
        : round($bytes / 1024) . 'KB';
}
