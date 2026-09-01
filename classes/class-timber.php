<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * the Timber integration.
 *
 * rendering is Twig. that is an opinion, and a deliberate one: Twig cannot
 * contain the query, the conditional and the inline style that PHP templates
 * accumulate, so the separation this plugin asks for is enforced by the
 * template language rather than by convention.
 *
 * the requirement is scoped to rendering. a site without Timber can still
 * define schemas and serve the JSON contract - the parts that never needed a
 * view layer - and is told plainly what it is missing.
 */
class Timber
{
    /**
     * the plugin's own Twig templates, used when a theme provides none.
     */
    const VIEWS = 'views';

    /**
     * registers the plugin's template location and the missing-dependency
     * notice.
     */
    public function __construct()
    {
        add_action('timber/locations', [$this, 'locations']);
        add_action('admin_notices', [$this, 'notice']);
    }

    /**
     * the Timber major version this plugin's templates are written against.
     */
    const REQUIRES = 2;

    /**
     * whether Timber is loaded and a version this plugin can render with.
     *
     * @return boolean
     */
    public static function available()
    {
        return class_exists('Timber\\Timber') && self::major() >= self::REQUIRES;
    }

    /**
     * the loaded Timber major version, or 0 when Timber is absent.
     *
     * @return integer
     */
    public static function major()
    {
        if (!class_exists('Timber\\Timber')) {
            return 0;
        }

        // Timber 2 exposes a version constant; 1.x did not, so its absence is
        // itself the signal that an older copy won the autoloader race
        return defined('Timber\\Timber::VERSION')
            ? (int) constant('Timber\\Timber::VERSION')
            : 1;
    }

    /**
     * whether the plugin's own dependencies have been installed.
     *
     * @return boolean
     */
    public static function bundled()
    {
        return file_exists(SCHEMAPRESS_PATH . 'vendor/autoload.php');
    }

    /**
     * adds the plugin's views directory to Timber's search path.
     *
     * appended rather than prepended, so a theme's `views/sections/hero.twig`
     * wins over the one shipped here - overriding a template is the normal way
     * to change how a component looks.
     *
     * @param array $locations
     *
     * @return array
     */
    public function locations($locations)
    {
        $locations[] = [SCHEMAPRESS_PATH . self::VIEWS];

        return $locations;
    }

    /**
     * compiles a Twig template with Timber.
     *
     * @param string|string[] $templates candidates, most specific first
     * @param array           $context
     *
     * @return string
     */
    public static function compile($templates, array $context)
    {
        if (!self::available()) {
            return '';
        }

        $compiled = \Timber\Timber::compile($templates, $context);

        return is_string($compiled) ? $compiled : '';
    }

    /**
     * the template candidates for a section type, most specific first.
     *
     * a type is author-named, so there may well be no template for it. the
     * default handles any shape by composing from roles, which is what makes
     * a new component render sensibly before anyone has written Twig for it.
     *
     * @param string $type
     *
     * @return string[]
     */
    public static function candidates($type)
    {
        /**
         * filters the Twig templates considered for a section type.
         *
         * @param array  $candidates
         * @param string $type
         */
        return apply_filters('schemapress/templates/section', [
            'sections/' . $type . '.twig',
            'sections/default.twig',
        ], $type);
    }

    /**
     * warns when rendering is unavailable, on the plugin's own screens only.
     *
     * @return void
     */
    public function notice()
    {
        if (self::available()) {
            return;
        }

        $screen = get_current_screen();

        if (!$screen || strpos($screen->id, Admin::PAGE_SLUG) === false) {
            return;
        }

        // three different problems wear the same symptom - nothing renders -
        // and the fix for each is different, so the notice says which one it is
        $major = self::major();

        if ($major > 0 && $major < self::REQUIRES) {
            $message = sprintf(
                /* translators: 1: loaded major version, 2: required major version */
                __(
                    'Timber %1$d is loaded, but these templates need Timber %2$d. Another plugin or your theme is loading the older copy first.',
                    'schemapress'
                ),
                $major,
                self::REQUIRES
            );
            $fix = '';
        } elseif (!self::bundled()) {
            $message = __(
                'This copy of the plugin has no vendor directory, so its dependencies were never installed.',
                'schemapress'
            );
            $fix = 'composer install';
        } else {
            $message = __(
                'Timber could not be loaded from the plugin’s vendor directory.',
                'schemapress'
            );
            $fix = 'composer install';
        }

        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong> %s</p>%s<p>%s</p></div>',
            esc_html__('SchemaPress cannot render.', 'schemapress'),
            esc_html($message),
            $fix ? '<p><code>' . esc_html($fix) . '</code></p>' : '',
            esc_html__(
                'Schemas and content are unaffected — only rendering and the preview are stopped.',
                'schemapress'
            )
        );
    }
}
