<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * pure transformations over a schema definition array.
 *
 * a definition is the developer-authored contract:
 *
 *   [
 *     'version'  => 1,
 *     'sections' => [
 *       [
 *         'key'    => 'hero',
 *         'label'  => 'Hero',
 *         'max'    => 1,
 *         'fields' => [ [ 'key' => 'heading', 'type' => 'text', ... ] ],
 *       ],
 *     ],
 *   ]
 *
 * `sections` is a library of allowed section types, not a fixed page outline —
 * a page composes its own ordered list from this library.
 *
 * this class touches no WordPress state; it is safe to unit test in isolation.
 */
class SchemaModel
{
    const VERSION = 1;

    /**
     * coerces an arbitrary decoded payload into a valid definition. unknown
     * keys are dropped, missing keys are defaulted, and duplicate keys within
     * the same level are suffixed so lookups stay unambiguous.
     *
     * @param mixed $definition
     *
     * @return array
     */
    public static function normalize($definition)
    {
        $definition = is_array($definition) ? $definition : [];

        $sections = isset($definition['sections']) && is_array($definition['sections'])
            ? $definition['sections']
            : [];

        $used = [];
        $normalized = [];

        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }

            $normalized[] = self::normalizeSection($section, $used);
        }

        return [
            'version' => self::VERSION,
            'sections' => $normalized,
        ];
    }

    /**
     * normalizes one section type definition.
     *
     * @param array $section
     * @param array $used  keys already claimed at this level, by reference
     *
     * @return array
     */
    private static function normalizeSection(array $section, array &$used)
    {
        $label = isset($section['label']) ? sanitize_text_field($section['label']) : '';
        $key = self::uniqueKey($section['key'] ?? $label, $used, 'section');

        $max = isset($section['max']) ? absint($section['max']) : 0;

        $childKeys = [];
        $raw = isset($section['fields']) && is_array($section['fields']) ? $section['fields'] : [];

        // fields are normalized first: which layout options apply depends on
        // what the section actually contains
        $fields = self::normalizeFields($raw, $childKeys);

        // a container holds other sections, which is what makes the page a
        // tree rather than a list
        $container = !empty($section['container']);

        return [
            'key' => $key,
            'label' => $label !== '' ? $label : self::humanize($key),
            'description' => isset($section['description'])
                ? sanitize_text_field($section['description'])
                : '',
            'icon' => isset($section['icon']) ? sanitize_key($section['icon']) : 'layout',
            // 0 means unlimited instances of this section on a page
            'max' => $max,
            'container' => $container,
            // every layout option that makes sense for this component.
            //
            // this used to be a subset an author chose, on the theory that
            // limiting the dials kept pages consistent. it did not survive
            // contact with the fact that the person choosing the subset and
            // the person using it are the same person - the restriction only
            // ever restricted its author, while costing a whole second
            // vocabulary of "which options exist" beside "what they are set
            // to". which options apply is now a property of the component's
            // shape, not a decision.
            'layout' => Layout::availableFor($fields, $container),
            'fields' => $fields,
        ];
    }

    /**
     * normalizes a list of field definitions, recursing into types that nest.
     *
     * @param array $fields
     * @param array $used
     *
     * @return array
     */
    private static function normalizeFields(array $fields, array &$used)
    {
        $normalized = [];

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $type = isset($field['type']) ? sanitize_key($field['type']) : 'text';

            if (!FieldTypes::exists($type)) {
                continue;
            }

            $label = isset($field['label']) ? sanitize_text_field($field['label']) : '';
            $key = self::uniqueKey($field['key'] ?? $label, $used, 'field');

            $normalized[] = self::normalizeField($field, $key, $label, $type);
        }

        return $normalized;
    }

    /**
     * builds a single normalized field, recursing for group/repeater children.
     *
     * @param array  $field
     * @param string $key
     * @param string $label
     * @param string $type
     *
     * @return array
     */
    private static function normalizeField(array $field, $key, $label, $type)
    {
        $normalized = [
            'key' => $key,
            'label' => $label !== '' ? $label : self::humanize($key),
            'type' => $type,
            'help' => isset($field['help']) ? sanitize_text_field($field['help']) : '',
            'required' => !empty($field['required']),
            // what the field is structurally for, as opposed to what it holds
            'role' => Roles::normalize($field['role'] ?? '', $type),
            'classes' => self::normalizeClasses($field['classes'] ?? ''),
            'config' => self::normalizeConfig($field, $type),
        ];

        if (FieldTypes::hasChildren($type)) {
            $childKeys = [];
            $children = isset($field['fields']) && is_array($field['fields']) ? $field['fields'] : [];
            $normalized['fields'] = self::normalizeFields($children, $childKeys);
        }

        return $normalized;
    }

    /**
     * cleans a CSS class list.
     *
     * the value lands in a class attribute, so anything that could close it or
     * open a new one is removed rather than escaped later: quotes, angle
     * brackets and backslashes go, and whitespace collapses to single spaces.
     * what survives still covers Tailwind's arbitrary-value syntax, which
     * legitimately needs brackets, slashes, colons and dots.
     *
     * @param mixed $classes
     *
     * @return string
     */
    private static function normalizeClasses($classes)
    {
        $value = is_array($classes) ? implode(' ', $classes) : (string) $classes;
        $value = preg_replace('/[^A-Za-z0-9 _:\/\[\]\.\-#%\(\),!+~>*=$&\'"]/u', '', $value);
        $value = str_replace(['"', "'"], '', $value);

        return trim(preg_replace('/\s+/', ' ', $value));
    }

    /**
     * whitelists the type-specific config bag. anything not recognised for the
     * type is discarded so stored definitions cannot accumulate junk.
     *
     * @param array  $field
     * @param string $type
     *
     * @return array
     */
    private static function normalizeConfig(array $field, $type)
    {
        $config = isset($field['config']) && is_array($field['config']) ? $field['config'] : [];
        $clean = [];

        switch ($type) {
            case 'select':
                $options = isset($config['options']) && is_array($config['options'])
                    ? $config['options']
                    : [];

                $clean['options'] = [];
                foreach ($options as $option) {
                    if (!is_array($option) || !isset($option['value'])) {
                        continue;
                    }

                    $clean['options'][] = [
                        'value' => sanitize_text_field($option['value']),
                        'label' => sanitize_text_field($option['label'] ?? $option['value']),
                    ];
                }

                $clean['multiple'] = !empty($config['multiple']);
                break;

            case 'post':
                $types = isset($config['post_types']) ? (array) $config['post_types'] : ['page'];
                $clean['post_types'] = array_values(array_filter(array_map('sanitize_key', $types)));
                $clean['multiple'] = !empty($config['multiple']);
                break;

            case 'repeater':
                $clean['min'] = isset($config['min']) ? absint($config['min']) : 0;
                $clean['max'] = isset($config['max']) ? absint($config['max']) : 0;
                // how the admin lays the rows out: a stacked list, or tiles
                // arranged to match the section's column setting
                $clean['display'] = ($config['display'] ?? '') === 'list' ? 'list' : 'grid';
                $clean['row_label'] = isset($config['row_label'])
                    ? sanitize_text_field($config['row_label'])
                    : '';
                $clean['button_label'] = isset($config['button_label'])
                    ? sanitize_text_field($config['button_label'])
                    : __('Add Row', 'schemapress');
                break;

            case 'text':
            case 'textarea':
                $clean['placeholder'] = isset($config['placeholder'])
                    ? sanitize_text_field($config['placeholder'])
                    : '';
                $clean['maxlength'] = isset($config['maxlength']) ? absint($config['maxlength']) : 0;
                break;

            case 'number':
                foreach (['min', 'max', 'step'] as $bound) {
                    if (isset($config[$bound]) && $config[$bound] !== '') {
                        $clean[$bound] = (float) $config[$bound];
                    }
                }
                break;
        }

        return $clean;
    }

    /**
     * slugifies a candidate key and guarantees uniqueness among its siblings.
     *
     * @param string $candidate
     * @param array  $used       by reference
     * @param string $fallback
     *
     * @return string
     */
    private static function uniqueKey($candidate, array &$used, $fallback)
    {
        $key = sanitize_key(str_replace([' ', '-'], '_', (string) $candidate));

        if ($key === '') {
            $key = $fallback;
        }

        $base = $key;
        $suffix = 2;

        while (isset($used[$key])) {
            $key = $base . '_' . $suffix;
            $suffix++;
        }

        $used[$key] = true;

        return $key;
    }

    /**
     * turns a snake_case key into a readable label.
     *
     * @param string $key
     *
     * @return string
     */
    private static function humanize($key)
    {
        return ucwords(str_replace('_', ' ', $key));
    }

    /**
     * finds a section type definition by key.
     *
     * @param array  $definition
     * @param string $key
     *
     * @return array|null
     */
    public static function section(array $definition, $key)
    {
        foreach ($definition['sections'] ?? [] as $section) {
            if ($section['key'] === $key) {
                return $section;
            }
        }

        return null;
    }

    /**
     * finds a field definition by key within a flat field list.
     *
     * @param array  $fields
     * @param string $key
     *
     * @return array|null
     */
    public static function field(array $fields, $key)
    {
        foreach ($fields as $field) {
            if ($field['key'] === $key) {
                return $field;
            }
        }

        return null;
    }
}
