<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * renders sections through Timber.
 *
 * it takes the same payload the delivery API emits, so the admin preview and
 * the published page read one contract and cannot drift apart - the preview
 * renders the shipped data through the shipped templates.
 *
 * there is no PHP fallback renderer. Twig is the view layer, and a second one
 * written in PHP would be a second thing to keep in step and a place for the
 * logic Twig exists to keep out.
 */
class Renderer
{
    /**
     * renders a list of delivered sections.
     *
     * @param array $sections
     *
     * @return string
     */
    public static function sections(array $sections, array $options = [])
    {
        if (!Timber::available()) {
            return self::unavailable();
        }

        $html = '';

        foreach ($sections as $section) {
            $html .= self::section($section, $options);
        }

        return $html;
    }

    /**
     * renders one delivered section.
     *
     * the template is chosen by section type, falling back to the default -
     * types are author-named, so most will never have a template of their own
     * and still have to render sensibly.
     *
     * @param array $section
     *
     * @return string
     */
    public static function section(array $section, array $options = [])
    {
        if (!Timber::available()) {
            return self::unavailable();
        }

        $context = ViewModel::section($section, $options);

        /**
         * filters the context passed to a section template.
         *
         * @param array $context
         * @param array $section the delivered section
         */
        $context = apply_filters('schemapress/render/context', $context, $section);

        return Timber::compile(Timber::candidates($section['type']), $context);
    }

    /**
     * renders a post's sections.
     *
     * @param integer $post_id
     *
     * @return string
     */
    public static function post($post_id)
    {
        return self::sections(Resolver::sections($post_id));
    }

    /**
     * the design tokens as a style block, for the front end.
     *
     * @return string
     */
    public static function styles()
    {
        return '<style id="schemapress-tokens">' . Settings::cssVariables() . '</style>';
    }

    /**
     * shown in place of output when Timber is not installed.
     *
     * a visible explanation rather than an empty page: a blank preview looks
     * like broken content, and the actual problem is a missing dependency.
     *
     * @return string
     */
    private static function unavailable()
    {
        return sprintf(
            '<div class="sp-unavailable"><p><strong>%s</strong></p><p>%s</p><p><code>composer require timber/timber</code></p></div>',
            esc_html__('Timber is not installed.', 'schemapress'),
            esc_html__(
                'SchemaPress renders with Twig. Schemas and content are safe — nothing will render until Timber is available.',
                'schemapress'
            )
        );
    }
}
