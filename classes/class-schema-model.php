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
 *     'version'  => 1,
 *     'settings' => [ 'draftAndPublish' => true ],
 *     'fields'   => [ [ 'key' => 'name', 'type' => 'text', ... ] ],
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
        $normalized = self::normalizeFields($fields, $used);

        return [
            'version' => self::VERSION,
            // settings after fields, because one of them names fields and a
            // column pointing at a field that was deleted is a blank column
            'settings' => self::normalizeSettings($definition['settings'] ?? null, $normalized),
            'fields' => $normalized,
        ];
    }

    /**
     * coerces a collection's settings.
     *
     * draftAndPublish: whether an entry has a working copy separate from what
     * the site is serving. some collections want that — a page of copy someone
     * drafts over a week — and some are a list of facts where an extra step
     * before anything appears is only friction. it defaults to on, because
     * turning it off is the destructive direction: a collection that had
     * drafts and stops having them publishes them all.
     *
     * listColumns: which fields the entries table shows, in order. null means
     * nobody has chosen, and the table picks the first few — which is what
     * makes a field added later appear on its own. an empty ARRAY is a real
     * choice and means no field columns at all, so the two are kept distinct.
     *
     * @param mixed $settings
     * @param array $fields   the normalized field list
     *
     * @return array{draftAndPublish: boolean, listColumns: array|null}
     */
    private static function normalizeSettings($settings, array $fields)
    {
        $settings = is_array($settings) ? $settings : [];

        return [
            'draftAndPublish' => array_key_exists('draftAndPublish', $settings)
                ? (bool) $settings['draftAndPublish']
                : true,
            'listColumns' => self::normalizeColumns($settings['listColumns'] ?? null, $fields),
        ];
    }

    /**
     * coerces a chosen column list to keys that exist, in the order given.
     *
     * @param mixed $columns
     * @param array $fields
     *
     * @return array|null
     */
    private static function normalizeColumns($columns, array $fields)
    {
        if (!is_array($columns)) {
            return null;
        }

        $keys = array_column($fields, 'key');
        $chosen = [];

        foreach ($columns as $column) {
            $key = sanitize_key((string) $column);

            if (in_array($key, $keys, true) && !in_array($key, $chosen, true)) {
                $chosen[] = $key;
            }
        }

        return $chosen;
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

        // how wide the control sits on the entry form, and when it appears at
        // all. both describe the admin's own screen rather than the delivered
        // content, which is the only reason a presentation value is allowed to
        // live in a definition
        $clean = [
            'width' => in_array($config['width'] ?? '', ['third', 'half', 'two-thirds'], true)
                ? $config['width']
                : 'full',
            'condition' => self::normalizeCondition($config['condition'] ?? null),
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
            case 'email':
            case 'url':
            case 'phone':
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
     * coerces a field's visibility condition.
     *
     * a condition names a SIBLING field — one at the same level, so a condition
     * inside a repeater row reads that row's own values. anything else would
     * need a path language, and "show the phone field once someone ticked
     * Contactable" is the case that actually comes up.
     *
     * an empty field name means no condition, which is the normal state, so it
     * is what a malformed value falls back to: a field that fails to parse its
     * condition stays visible rather than disappearing.
     *
     * @param mixed $condition
     *
     * @return array{field: string, operator: string, value: string}
     */
    private static function normalizeCondition($condition)
    {
        $condition = is_array($condition) ? $condition : [];

        $operators = ['filled', 'empty', 'equals', 'not_equals'];
        $operator = isset($condition['operator']) ? sanitize_key($condition['operator']) : '';

        return [
            'field' => isset($condition['field']) ? sanitize_key($condition['field']) : '',
            'operator' => in_array($operator, $operators, true) ? $operator : 'filled',
            'value' => isset($condition['value']) ? sanitize_text_field($condition['value']) : '',
        ];
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
