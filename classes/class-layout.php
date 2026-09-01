<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * the layout option registry.
 *
 * layout is stored as data — 'columns' => '3' — never as CSS classes. the
 * front-end maps those values to its own classes, which keeps the styling in
 * the codebase Tailwind actually scans and stops the database from holding
 * class strings that a build would purge.
 *
 * a section type declares which of these options its authors may set; each
 * placed section then carries its own chosen values.
 */
class Layout
{
    /**
     * @var array<string, array>
     */
    private static $options = null;

    /**
     * every available layout option.
     *
     * @return array<string, array>
     */
    public static function options()
    {
        if (self::$options !== null) {
            return self::$options;
        }

        $options = [
            'columns' => [
                'label' => __('Columns', 'schemapress'),
                'default' => '3',
                // columns describe how a repeated thing is laid out, so the
                // option is meaningless — and is not offered — on a component
                // that has nothing to repeat
                'requires' => 'repeater',
                'default_choices' => true,
                'choices' => [
                    '1' => __('1', 'schemapress'),
                    '2' => __('2', 'schemapress'),
                    '3' => __('3', 'schemapress'),
                    '4' => __('4', 'schemapress'),
                ],
            ],
            'width' => [
                'label' => __('Width', 'schemapress'),
                'default' => 'normal',
                'choices' => [
                    'narrow' => __('Narrow', 'schemapress'),
                    'normal' => __('Normal', 'schemapress'),
                    'full' => __('Full', 'schemapress'),
                ],
            ],
            'background' => [
                'label' => __('Background', 'schemapress'),
                'default' => 'none',
                'choices' => [
                    'none' => __('None', 'schemapress'),
                    'muted' => __('Muted', 'schemapress'),
                    'dark' => __('Dark', 'schemapress'),
                ],
            ],
            'align' => [
                'label' => __('Align', 'schemapress'),
                'default' => 'left',
                'choices' => [
                    'left' => __('Left', 'schemapress'),
                    'center' => __('Center', 'schemapress'),
                    'right' => __('Right', 'schemapress'),
                ],
            ],
            'height' => [
                'label' => __('Height', 'schemapress'),
                'default' => 'medium',
                // only a section with something behind it has a height worth
                // setting; everywhere else the content decides
                'requires' => 'role:background',
                'choices' => [
                    'auto' => __('Auto', 'schemapress'),
                    'medium' => __('Medium', 'schemapress'),
                    'tall' => __('Tall', 'schemapress'),
                    'screen' => __('Full screen', 'schemapress'),
                ],
            ],
            'vertical' => [
                'label' => __('Position', 'schemapress'),
                'default' => 'middle',
                'requires' => 'role:background',
                'choices' => [
                    'top' => __('Top', 'schemapress'),
                    'middle' => __('Middle', 'schemapress'),
                    'bottom' => __('Bottom', 'schemapress'),
                ],
            ],
        ];

        /**
         * filters the available layout options.
         *
         * values added here are delivered verbatim, so the front-end must know
         * how to map them.
         *
         * @param array $options
         */
        self::$options = apply_filters('schemapress/layout_options', $options);

        return self::$options;
    }

    /**
     * whether an option key is registered.
     *
     * @param string $key
     *
     * @return boolean
     */
    public static function exists($key)
    {
        $options = self::options();

        return isset($options[$key]);
    }

    /**
     * whether an option applies to a section built from these fields.
     *
     * @param string $key
     * @param array  $fields normalized field definitions
     *
     * @return boolean
     */
    public static function applies($key, array $fields, $container = false)
    {
        $options = self::options();

        if (!isset($options[$key])) {
            return false;
        }

        $requires = $options[$key]['requires'] ?? null;

        if ($requires === null) {
            return true;
        }

        // a container lays out the components nested inside it, so columns
        // apply to it for the same reason they apply to a repeater
        if ($requires === 'repeater' && $container) {
            return true;
        }

        if (strpos($requires, 'role:') === 0) {
            return self::containsRole($fields, substr($requires, 5));
        }

        return self::containsType($fields, $requires);
    }

    /**
     * whether a field list contains a field with a given role, at any depth.
     *
     * @param array  $fields
     * @param string $role
     *
     * @return boolean
     */
    private static function containsRole(array $fields, $role)
    {
        foreach ($fields as $field) {
            if (($field['role'] ?? '') === $role) {
                return true;
            }

            if (!empty($field['fields']) && self::containsRole($field['fields'], $role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * whether a field list contains a field of a given type, at any depth.
     *
     * @param array  $fields
     * @param string $type
     *
     * @return boolean
     */
    private static function containsType(array $fields, $type)
    {
        foreach ($fields as $field) {
            if (($field['type'] ?? '') === $type) {
                return true;
            }

            if (!empty($field['fields']) && self::containsType($field['fields'], $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * the option keys offered for a section built from these fields.
     *
     * @param array $fields
     *
     * @return string[]
     */
    public static function availableFor(array $fields, $container = false)
    {
        return array_values(array_filter(
            array_keys(self::options()),
            function ($key) use ($fields, $container) {
                return self::applies($key, $fields, $container);
            }
        ));
    }

    /**
     * normalizes the list of option keys a section type enables, dropping any
     * that do not apply to its fields.
     *
     * filtering here rather than only in the UI means removing a repeater also
     * removes `columns` from what the section delivers, instead of leaving a
     * stale key the front-end would still branch on.
     *
     * @param mixed $enabled
     * @param array $fields  normalized field definitions
     *
     * @return string[]
     */
    public static function normalizeEnabled($enabled, array $fields = [], $container = false)
    {
        $clean = [];

        foreach ((array) $enabled as $key) {
            $key = sanitize_key($key);

            if (self::applies($key, $fields, $container) && !in_array($key, $clean, true)) {
                $clean[] = $key;
            }
        }

        return $clean;
    }

    /**
     * coerces a placed section's layout values against the options its type
     * enables. every enabled option is present in the result, so a template
     * or a front-end component can read them without guarding.
     *
     * @param mixed    $values
     * @param string[] $enabled
     *
     * @return array
     */
    public static function sanitize($values, array $enabled)
    {
        $values = is_array($values) ? $values : [];
        $options = self::options();
        $clean = [];

        foreach ($enabled as $key) {
            if (!isset($options[$key])) {
                continue;
            }

            $option = $options[$key];
            $value = isset($values[$key]) ? (string) $values[$key] : '';

            $clean[$key] = isset($option['choices'][$value]) ? $value : $option['default'];
        }

        return $clean;
    }

    /**
     * the registry in the shape the admin client consumes.
     *
     * @return array
     */
    public static function forClient()
    {
        $options = [];

        foreach (self::options() as $key => $option) {
            $choices = [];

            foreach ($option['choices'] as $value => $label) {
                $choices[] = ['value' => (string) $value, 'label' => $label];
            }

            $options[] = [
                'key' => $key,
                'label' => $option['label'],
                'default' => $option['default'],
                'requires' => $option['requires'] ?? null,
                'choices' => $choices,
            ];
        }

        return $options;
    }
}
