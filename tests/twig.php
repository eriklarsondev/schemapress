<?php
/**
 * Parses every shipped Twig template with Twig itself.
 *
 * The structural check in pipeline.php counts delimiters, which catches a
 * missing `endfor` and nothing subtler. This runs the real parser, so a bad
 * filter name or a malformed expression fails here rather than on a page.
 *
 * Requires the Composer dependencies: php tests/twig.php
 *
 * @package SchemaPress
 */

$autoload = __DIR__ . '/../vendor/autoload.php';

if (!file_exists($autoload)) {
    fwrite(STDERR, "Composer dependencies are not installed. Run: composer install\n");
    exit(1);
}

require $autoload;

$views = __DIR__ . '/../views';

$loader = new Twig\Loader\FilesystemLoader($views);
$twig = new Twig\Environment($loader, ['cache' => false, 'strict_variables' => false]);

$passed = 0;
$failed = 0;

foreach (['sections', 'fields'] as $folder) {
    foreach (glob($views . '/' . $folder . '/*.twig') ?: [] as $path) {
        $name = $folder . '/' . basename($path);
        $source = file_get_contents($path);

        try {
            $twig->parse($twig->tokenize(new Twig\Source($source, $name, $path)));

            $passed++;
            echo "  ok    {$name}\n";
        } catch (Twig\Error\Error $error) {
            $failed++;
            echo "  FAIL  {$name}\n";
            echo '        ' . $error->getMessage() . "\n";
            echo '        line ' . $error->getTemplateLine() . "\n";
        }
    }
}

echo "\n{$passed} parsed, {$failed} failed\n";

exit($failed === 0 ? 0 : 1);
