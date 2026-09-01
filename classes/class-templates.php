<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * the template registry.
 *
 * in a headless setup there are no theme template files to bind to, so
 * templates are declared here instead: a slug plus a label. the slug is the
 * contract with the front-end — it ships in the delivered JSON, and the client
 * app maps it to a page component.
 *
 * theme page templates, when a classic theme provides any, are merged in so
 * the same plugin works in both delivery models.
 */
class Templates
{
    const OPTION = 'schemapress_templates';

    /**
     * every registered template, keyed by slug.
     *
     * @return array<string, array>
     */
    public static function all()
    {
        $stored = get_option(self::OPTION, []);
        $templates = [];

        foreach (is_array($stored) ? $stored : [] as $template) {
            if (empty($template['slug'])) {
                continue;
            }

            $slug = sanitize_key($template['slug']);

            $templates[$slug] = [
                'slug' => $slug,
                'label' => isset($template['label']) ? $template['label'] : $slug,
                'description' => isset($template['description']) ? $template['description'] : '',
                'source' => 'plugin',
            ];
        }

        foreach (self::themeTemplates() as $file => $label) {
            if (isset($templates[$file])) {
                continue;
            }

            $templates[$file] = [
                'slug' => $file,
                'label' => $label,
                'description' => '',
                'source' => 'theme',
            ];
        }

        /**
         * filters the templates a schema may be bound to.
         *
         * @param array $templates keyed by slug
         */
        return apply_filters('schemapress/templates', $templates);
    }

    /**
     * page template files exposed by the active theme, if any.
     *
     * @return array<string, string>
     */
    private static function themeTemplates()
    {
        $theme = wp_get_theme();

        return $theme ? $theme->get_page_templates(null, 'page') : [];
    }

    /**
     * a single template, or null.
     *
     * @param string $slug
     *
     * @return array|null
     */
    public static function get($slug)
    {
        $templates = self::all();

        return isset($templates[$slug]) ? $templates[$slug] : null;
    }

    /**
     * whether a slug is registered.
     *
     * @param string $slug
     *
     * @return boolean
     */
    public static function exists($slug)
    {
        return self::get($slug) !== null;
    }

    /**
     * replaces the plugin-defined template list. theme templates are not
     * stored — they are discovered — so they are ignored here.
     *
     * @param array $templates
     *
     * @return array the stored plugin templates
     */
    public static function save($templates)
    {
        $clean = [];
        $seen = [];

        foreach ((array) $templates as $template) {
            if (!is_array($template)) {
                continue;
            }

            $label = isset($template['label']) ? sanitize_text_field($template['label']) : '';
            $slug = sanitize_key($template['slug'] ?? $label);

            if ($slug === '' || isset($seen[$slug])) {
                continue;
            }

            $seen[$slug] = true;

            $clean[] = [
                'slug' => $slug,
                'label' => $label !== '' ? $label : $slug,
                'description' => isset($template['description'])
                    ? sanitize_text_field($template['description'])
                    : '',
            ];
        }

        update_option(self::OPTION, $clean, false);

        return $clean;
    }

    /**
     * slug => label, the shape SchemaRepository validates bindings against.
     *
     * @return array<string, string>
     */
    public static function names()
    {
        return wp_list_pluck(self::all(), 'label', 'slug');
    }
}
