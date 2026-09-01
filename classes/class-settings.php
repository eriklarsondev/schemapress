<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * site-wide design tokens.
 *
 * layout options are a closed vocabulary - an author picks `narrow` or `dark`,
 * never a pixel value - but what those words mean has to be settable
 * somewhere, or the vocabulary is only as good as the numbers hardcoded in a
 * stylesheet.
 *
 * these are those numbers. they are emitted as CSS custom properties for the
 * reference renderer and shipped in the delivery contract, so a headless
 * front-end can honour the same definitions rather than guessing at them.
 */
class Settings
{
    const OPTION = 'schemapress_settings';

    /**
     * the shape of every token: its label, default, and how it is validated.
     *
     * @return array
     */
    public static function schema()
    {
        $schema = [
            'width_narrow' => [
                'label' => __('Narrow width', 'schemapress'),
                'group' => 'layout',
                'type' => 'length',
                'default' => '42rem',
                'help' => __('Used by sections set to Narrow.', 'schemapress'),
            ],
            'width_normal' => [
                'label' => __('Normal width', 'schemapress'),
                'group' => 'layout',
                'type' => 'length',
                'default' => '72rem',
                'help' => __('The default container width.', 'schemapress'),
            ],
            'gutter' => [
                'label' => __('Side padding', 'schemapress'),
                'group' => 'layout',
                'type' => 'length',
                'default' => '1.5rem',
                'help' => __('Breathing room at the edges of a container.', 'schemapress'),
            ],
            'section_space' => [
                'label' => __('Section spacing', 'schemapress'),
                'group' => 'layout',
                'type' => 'length',
                'default' => '3.5rem',
                'help' => __('Vertical padding above and below each section.', 'schemapress'),
            ],
            'grid_gap' => [
                'label' => __('Grid gap', 'schemapress'),
                'group' => 'layout',
                'type' => 'length',
                'default' => '1.5rem',
                'help' => __('Space between columns and cards.', 'schemapress'),
            ],
            'radius' => [
                'label' => __('Corner radius', 'schemapress'),
                'group' => 'layout',
                'type' => 'length',
                'default' => '0.5rem',
                'help' => '',
            ],
            'color_text' => [
                'label' => __('Text', 'schemapress'),
                'group' => 'colour',
                'type' => 'color',
                'default' => '#16181d',
                'help' => '',
            ],
            'color_page' => [
                'label' => __('Page background', 'schemapress'),
                'group' => 'colour',
                'type' => 'color',
                'default' => '#ffffff',
                'help' => '',
            ],
            'color_muted' => [
                'label' => __('Muted background', 'schemapress'),
                'group' => 'colour',
                'type' => 'color',
                'default' => '#f4f5f7',
                'help' => __('Sections set to a Muted background.', 'schemapress'),
            ],
            'color_dark' => [
                'label' => __('Dark background', 'schemapress'),
                'group' => 'colour',
                'type' => 'color',
                'default' => '#16181d',
                'help' => __('Sections set to a Dark background.', 'schemapress'),
            ],
            'color_dark_text' => [
                'label' => __('Text on dark', 'schemapress'),
                'group' => 'colour',
                'type' => 'color',
                'default' => '#f5f6f8',
                'help' => '',
            ],
            'color_accent' => [
                'label' => __('Accent', 'schemapress'),
                'group' => 'colour',
                'type' => 'color',
                'default' => '#16181d',
                'help' => __('Buttons and emphasis.', 'schemapress'),
            ],
        ];

        /**
         * filters the design tokens SchemaPress exposes.
         *
         * @param array $schema
         */
        return apply_filters('schemapress/settings_schema', $schema);
    }

    /**
     * the stored tokens, with defaults filled in.
     *
     * @return array<string, string>
     */
    public static function all()
    {
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        $values = [];

        foreach (self::schema() as $key => $token) {
            $values[$key] = isset($stored[$key])
                ? self::sanitizeValue($stored[$key], $token, $token['default'])
                : $token['default'];
        }

        return $values;
    }

    /**
     * validates and stores tokens.
     *
     * @param array $values
     *
     * @return array the stored tokens
     */
    public static function save($values)
    {
        $values = is_array($values) ? $values : [];
        $clean = [];

        foreach (self::schema() as $key => $token) {
            if (!array_key_exists($key, $values)) {
                continue;
            }

            $clean[$key] = self::sanitizeValue($values[$key], $token, $token['default']);
        }

        update_option(self::OPTION, $clean, true);

        return self::all();
    }

    /**
     * coerces one token.
     *
     * these values are printed inside a style block, so a value that does not
     * match its type is replaced with the default rather than escaped: there
     * is no such thing as a safely escaped malformed CSS length.
     *
     * @param mixed  $value
     * @param array  $token
     * @param string $fallback
     *
     * @return string
     */
    private static function sanitizeValue($value, array $token, $fallback)
    {
        $value = trim((string) $value);

        if ($token['type'] === 'color') {
            return preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) ? $value : $fallback;
        }

        // a number with a CSS unit, and nothing else
        return preg_match('/^-?[0-9]*\.?[0-9]+(px|rem|em|%|vw|vh|ch)$/', $value)
            ? $value
            : $fallback;
    }

    /**
     * the tokens as a CSS custom property block.
     *
     * @return string
     */
    public static function cssVariables()
    {
        $declarations = '';

        foreach (self::all() as $key => $value) {
            $declarations .= '--sp-' . str_replace('_', '-', $key) . ':' . $value . ';';
        }

        return ':root{' . $declarations . '}';
    }

    /**
     * the tokens grouped for the admin's settings screen.
     *
     * @return array
     */
    public static function forClient()
    {
        $values = self::all();
        $tokens = [];

        foreach (self::schema() as $key => $token) {
            $tokens[] = [
                'key' => $key,
                'label' => $token['label'],
                'group' => $token['group'],
                'type' => $token['type'],
                'help' => $token['help'],
                'default' => $token['default'],
                'value' => $values[$key],
            ];
        }

        return $tokens;
    }
}
