<?php

/**
 * Builds the zip the plugin directory serves.
 *
 * The repository is not the plugin. `.distignore` says which is which; this
 * copies what is left into a staging directory named after the slug — a plugin
 * zip has to unpack into a folder called `schemapress`, whatever the checkout
 * is called — and zips that.
 *
 * IT REBUILDS vendor/ WITHOUT DEV DEPENDENCIES. The working tree's vendor/ is
 * whatever the last `composer install` left, and the one thing that must never
 * ship is somebody's linter. Rebuilding into the staging copy means the package
 * is correct regardless of the state of the tree it was built from, which is
 * the only version of this that survives being run in a hurry.
 *
 * It refuses to build a package whose declared versions disagree, because a
 * `Stable tag` that does not match the plugin header is the single most common
 * reason a directory release serves the wrong thing.
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

// --- the versions have to agree ---------------------------------------------

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
 * appears rather than only at the root — the same reading `wp dist-archive`
 * gives it.
 *
 * A pattern may also be a glob, and one of them has to be. The first version of
 * this compared segments for equality, which cannot express "any zip in the
 * root" — so the second run of this script found the first run's zip sitting in
 * the plugin directory and packaged it INSIDE the new one. The zip doubled in
 * size and nothing said why, because a plugin containing a copy of itself is
 * still a perfectly valid plugin.
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

// --- stage ------------------------------------------------------------------

$build = sys_get_temp_dir() . '/schemapress-package-' . getmypid();
$stage = $build . '/' . $slug;

schemapress_run('rm -rf ' . escapeshellarg($build), $root);
mkdir($stage, 0755, true);

$copied = 0;

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($files as $file) {
    $relative = substr($file->getPathname(), strlen($root) + 1);

    if (schemapress_ignored($relative, $ignored)) {
        continue;
    }

    if ($file->isDir()) {
        if (!is_dir($stage . '/' . $relative)) {
            mkdir($stage . '/' . $relative, 0755, true);
        }

        continue;
    }

    copy($file->getPathname(), $stage . '/' . $relative);
    $copied++;
}

// --- vendor, without the tooling --------------------------------------------

// composer.json and composer.lock are in .distignore, so the copy above skipped
// them — correctly, since neither belongs in the package. But the rebuild below
// needs them, and for a long time it was guarded by `file_exists($stage .
// '/composer.json')`, which the copy had just guaranteed to be false. The whole
// --no-dev rebuild silently never ran, and the zip shipped whatever vendor/ the
// tree happened to hold.
//
// That was invisible while there were no dev dependencies to ship. Adding
// php-cs-fixer to require-dev turned it into a release carrying a code
// formatter and 32 other packages. So they are staged deliberately here, used,
// and deleted again below.
foreach (['composer.json', 'composer.lock'] as $manifest) {
    if (file_exists($root . '/' . $manifest)) {
        copy($root . '/' . $manifest, $stage . '/' . $manifest);
    }
}

if (file_exists($stage . '/composer.json')) {
    schemapress_run('rm -rf vendor', $stage);
    schemapress_run(
        'composer install --no-dev --optimize-autoloader --no-interaction --quiet',
        $stage
    );

    // the manifest was only here to rebuild vendor/; it is not part of the plugin
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

// $copied counted what came out of the working tree. The vendor rebuild then
// replaced vendor/ wholesale, so it stopped describing the package the moment
// that rebuild started actually running — it reported 2,090 files for a zip
// holding 1,073. Count what is really in the stage instead.
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
    "\n  %s\n  %d files, %s\n\n",
    $name,
    $packaged,
    size_format(filesize($target))
);

/**
 * Bytes as something readable, since WordPress is not loaded here.
 *
 * @param integer $bytes
 *
 * @return string
 */
function size_format($bytes)
{
    return $bytes > 1048576
        ? round($bytes / 1048576, 1) . 'MB'
        : round($bytes / 1024) . 'KB';
}
