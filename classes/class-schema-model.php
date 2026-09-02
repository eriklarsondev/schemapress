<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * pure transformations over a content type's definition.
 *
 * a definition is the shape of one collection's entries:
 *
 *   [
 *     'version' => 1,
 *     'fields'  => [ [ 'key' => 'name', 'type' => 'text', ... ] ],
 *   ]
 *
 * this class touches no WordPress state beyond the sanitizers, so it is safe to
 * unit test in isolation.
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

        $fields = isset($definition['fields']) && is_array($definition['fields'])
            ? $definition['fields']
            : [];

        $used = [];

        return [
            'version' => self::VERSION,
            'fields' => self::normalizeFields($fields, $used),
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
    public static function normalizeFields(array $fields, array &$used)
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

        // how wide the control sits on the entry form. this is the admin's own
        // screen, not the delivered content, which is the only reason a layout
        // value is allowed to live in a definition at all
        $clean = [
            'width' => ($config['width'] ?? '') === 'half' ? 'half' : 'full',
        ];

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

            case 'relation':
                // which collection this points at, by content type id
                $clean['collection'] = isset($config['collection'])
                    ? absint($config['collection'])
                    : 0;
                $clean['multiple'] = !empty($config['multiple']);
                break;

            case 'repeater':
                $clean['min'] = isset($config['min']) ? absint($config['min']) : 0;
                $clean['max'] = isset($config['max']) ? absint($config['max']) : 0;
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
