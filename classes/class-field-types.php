<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * registry of available field types.
 *
 * a type describes how a value is defaulted and sanitized, and whether it
 * holds child fields. everything else — how it is drawn — lives in the React
 * admin, keyed by the same type slug.
 */
class FieldTypes
{
    /**
     * @var array<string, array>
     */
    private static $types = [];

    /**
     * registers the built-in types on construction. third parties extend the
     * set through the schemapress/field_types filter.
     */
    public function __construct()
    {
        self::$types = $this->defaults();

        /**
         * filters the registered field types.
         *
         * @param array $types
         */
        self::$types = apply_filters('schemapress/field_types', self::$types);
    }

    /**
     * built-in field type definitions.
     *
     * @return array<string, array>
     */
    private function defaults()
    {
        return [
            'text' => [
                'label' => __('Text', 'schemapress'),
                'default' => '',
                'sanitize' => 'sanitize_text_field',
            ],
            'textarea' => [
                'label' => __('Textarea', 'schemapress'),
                'default' => '',
                'sanitize' => function ($value) {
                    return sanitize_textarea_field($value);
                },
            ],
            'wysiwyg' => [
                'label' => __('Rich Text', 'schemapress'),
                'default' => '',
                'sanitize' => function ($value) {
                    return wp_kses_post($value);
                },
            ],
            'email' => [
                'label' => __('Email', 'schemapress'),
                'default' => '',
                'sanitize' => function ($value) {
                    $email = sanitize_email((string) $value);

                    // an address that will not validate is stored as nothing
                    // rather than as text that only looks like an address
                    return is_email($email) ? $email : '';
                },
            ],
            'url' => [
                'label' => __('URL', 'schemapress'),
                'default' => '',
                'sanitize' => function ($value) {
                    return esc_url_raw(trim((string) $value));
                },
            ],
            'phone' => [
                'label' => __('Phone', 'schemapress'),
                'default' => '',
                'sanitize' => function ($value) {
                    // there is no universal phone format, so this keeps the
                    // characters a phone number is written with and drops the
                    // rest rather than trying to impose one
                    $clean = preg_replace('/[^0-9+()\-.\s]/', '', (string) $value);

                    return trim(preg_replace('/\s+/', ' ', $clean));
                },
            ],
            'number' => [
                'label' => __('Number', 'schemapress'),
                'default' => null,
                'sanitize' => function ($value) {
                    return $value === '' || $value === null ? null : (float) $value;
                },
            ],
            'toggle' => [
                'label' => __('Toggle', 'schemapress'),
                'default' => false,
                'sanitize' => function ($value) {
                    return (bool) $value;
                },
            ],
            'select' => [
                'label' => __('Select', 'schemapress'),
                'default' => '',
                'sanitize' => function ($value, $field) {
                    $allowed = wp_list_pluck(
                        isset($field['config']['options']) ? $field['config']['options'] : [],
                        'value'
                    );

                    if (!empty($field['config']['multiple'])) {
                        $values = is_array($value) ? $value : [];

                        return array_values(array_intersect($values, $allowed));
                    }

                    return in_array($value, $allowed, true) ? $value : '';
                },
            ],
            'image' => [
                'label' => __('Image', 'schemapress'),
                'default' => null,
                'sanitize' => function ($value) {
                    $id = absint(is_array($value) ? ($value['id'] ?? 0) : $value);

                    return $id && wp_attachment_is_image($id) ? $id : null;
                },
            ],
            'file' => [
                'label' => __('File', 'schemapress'),
                'default' => null,
                'sanitize' => function ($value) {
                    $id = absint(is_array($value) ? ($value['id'] ?? 0) : $value);

                    return $id && get_post_type($id) === 'attachment' ? $id : null;
                },
            ],
            'link' => [
                'label' => __('Link', 'schemapress'),
                'default' => ['url' => '', 'label' => '', 'target' => ''],
                'sanitize' => function ($value) {
                    $value = is_array($value) ? $value : [];

                    return [
                        'url' => esc_url_raw($value['url'] ?? ''),
                        'label' => sanitize_text_field($value['label'] ?? ''),
                        'target' => ($value['target'] ?? '') === '_blank' ? '_blank' : '',
                    ];
                },
            ],
            'group' => [
                'label' => __('Group', 'schemapress'),
                'default' => [],
                'children' => true,
            ],
            'repeater' => [
                'label' => __('Repeater', 'schemapress'),
                'default' => [],
                'children' => true,
                'repeatable' => true,
            ],
        ];
    }

    /**
     * all registered types.
     *
     * @return array<string, array>
     */
    public static function all()
    {
        return self::$types;
    }

    /**
     * a single type definition.
     *
     * @param string $type
     *
     * @return array|null
     */
    public static function get($type)
    {
        return isset(self::$types[$type]) ? self::$types[$type] : null;
    }

    /**
     * whether a type slug is registered.
     *
     * @param string $type
     *
     * @return boolean
     */
    public static function exists($type)
    {
        return isset(self::$types[$type]);
    }

    /**
     * whether a type nests child fields (group, repeater).
     *
     * @param string $type
     *
     * @return boolean
     */
    public static function hasChildren($type)
    {
        $definition = self::get($type);

        return !empty($definition['children']);
    }

    /**
     * whether a type holds an ordered list of child rows.
     *
     * @param string $type
     *
     * @return boolean
     */
    public static function isRepeatable($type)
    {
        $definition = self::get($type);

        return !empty($definition['repeatable']);
    }

    /**
     * the empty value for a type.
     *
     * @param string $type
     *
     * @return mixed
     */
    public static function defaultValue($type)
    {
        $definition = self::get($type);

        return $definition && array_key_exists('default', $definition)
            ? $definition['default']
            : null;
    }

    /**
     * runs a value through its type's sanitizer. types that nest children have
     * no scalar sanitizer — ContentSanitizer walks into them instead.
     *
     * @param mixed $value
     * @param array $field
     *
     * @return mixed
     */
    public static function sanitize($value, array $field)
    {
        $definition = self::get($field['type']);

        if (!$definition || empty($definition['sanitize'])) {
            return $value;
        }

        return call_user_func($definition['sanitize'], $value, $field);
    }
}
