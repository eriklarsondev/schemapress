<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registry of available field types. A type describes how a value is defaulted
 * and sanitized, and whether it holds child fields. How it is drawn lives in the
 * React admin, keyed by the same type slug.
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
            // the three date shapes are separate types rather than one type with
            // a format setting, because which of them a field is changes what
            // can be asked of it: a date sorts against other dates, a time
            // sorts within a day, and a datetime is the only one that does both.
            // storing them apart is also what keeps the control right without a
            // branch — each maps to one HTML input
            'date' => [
                'label' => __('Date', 'schemapress'),
                'default' => '',
                'sanitize' => function ($value) {
                    return Dates::date($value);
                },
            ],
            'datetime' => [
                'label' => __('Date and time', 'schemapress'),
                'default' => '',
                'sanitize' => function ($value) {
                    return Dates::datetime($value);
                },
            ],
            'time' => [
                'label' => __('Time', 'schemapress'),
                'default' => '',
                'sanitize' => function ($value) {
                    return Dates::time($value);
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
                'label' => __('Dropdown', 'schemapress'),
                'default' => '',
                'sanitize' => function ($value, $field) {
                    // whichever source the field names — a hand-written list or
                    // a dataset. asking Datasets keeps the sanitizer from
                    // disagreeing with what the control offered
                    $allowed = wp_list_pluck(Datasets::forField($field), 'value');

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
            // its own type rather than a `multiple` flag on `image`, for the
            // reason the three date shapes are separate: what a field IS changes
            // what can be asked of it. one image can be filtered on and sorted
            // by, a list of them cannot; one image resolves to an attachment and
            // a gallery to a list of them. a flag would have made every consumer
            // branch on a config value to know which shape it was holding
            'gallery' => [
                'label' => __('Gallery', 'schemapress'),
                'default' => [],
                'sanitize' => function ($value, $field) {
                    $ids = [];
                    $max = isset($field['config']['max']) ? (int) $field['config']['max'] : 0;

                    foreach (is_array($value) ? $value : [] as $item) {
                        if ($max > 0 && count($ids) >= $max) {
                            break;
                        }

                        $id = absint(is_array($item) ? ($item['id'] ?? 0) : $item);

                        // the same image twice in one gallery is a slip rather
                        // than a choice — it is a list of what to show, not a
                        // count of anything
                        if ($id && wp_attachment_is_image($id) && !in_array($id, $ids, true)) {
                            $ids[] = $id;
                        }
                    }

                    return $ids;
                },
            ],
            'color' => [
                'label' => __('Color', 'schemapress'),
                'default' => '',
                'sanitize' => function ($value) {
                    // WordPress's own, which returns '' for anything that is not
                    // a hex color — so a stored value is always something a
                    // stylesheet can use, and never a string that only looks it
                    $color = sanitize_hex_color((string) $value);

                    return is_string($color) ? $color : '';
                },
            ],
            // for the shapes a schema should not try to describe: a third
            // party's payload, a chart's series, anything whose structure
            // belongs to something other than this collection. it is stored as
            // decoded data rather than as a string, so it travels through the
            // API as JSON rather than as JSON inside a string
            'json' => [
                'label' => __('JSON', 'schemapress'),
                'default' => null,
                'sanitize' => function ($value) {
                    if (is_string($value)) {
                        $decoded = json_decode($value, true);

                        // invalid JSON is stored as nothing rather than as the
                        // text somebody typed, which is the rule `email` and the
                        // date types already follow — a value that is not the
                        // thing is not kept as an approximation of it
                        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
                    }

                    return is_array($value) ? $value : null;
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
            // not offered in the picker: a group is what a component becomes
            // when you import one, and "Group" on its own was a container with
            // no reason to exist until you had already decided what went in it
            'group' => [
                'label' => __('Group', 'schemapress'),
                'default' => [],
                'children' => true,
                'internal' => true,
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
     * A single type definition.
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
     * Whether a type slug is registered.
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
     * Whether a type nests child fields (group, repeater).
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
     * Whether a type holds an ordered list of child rows.
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
     * The empty value for a type.
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
     * How wide each built-in type's control starts on the entry form — a starting
     * point, never a rule. The Layout tab sets any field to any width, and one
     * somebody chose is kept exactly; this only answers for a field that has not
     * been given one yet.
     *
     * The widths follow what each control actually draws: a switch or a swatch
     * needs a third, a line of text half, anything with inputs side by side or a
     * document inside it the whole row.
     *
     * @var array<string, string>
     */
    public const WIDTHS = [
        'text' => 'half',
        'textarea' => 'full',
        'wysiwyg' => 'full',
        'email' => 'half',
        'url' => 'half',
        'phone' => 'third',
        'number' => 'third',
        'date' => 'third',
        // the date and the time side by side, which is wider than either
        'datetime' => 'half',
        'time' => 'third',
        'toggle' => 'third',
        'select' => 'half',
        'color' => 'third',
        'image' => 'third',
        // a grid of thumbnails, which wants the room to be a grid
        'gallery' => 'full',
        'file' => 'half',
        // the address and its label sit side by side
        'link' => 'full',
        'json' => 'half',
        'group' => 'full',
        'repeater' => 'full',
    ];

    /**
     * A type registered through the schemapress/field_types filter can say its own
     * with a `width` key; anything unrecognised starts full, the one width that
     * can never be too narrow for what is in it.
     *
     * @param string $type
     *
     * @return string third, half, two-thirds or full
     */
    public static function defaultWidth($type)
    {
        $definition = self::get($type);
        $width = $definition['width'] ?? (self::WIDTHS[$type] ?? 'full');

        return in_array($width, ['third', 'half', 'two-thirds', 'full'], true) ? $width : 'full';
    }

    /**
     * Runs a value through its type's sanitizer. Types that nest children have no
     * scalar sanitizer — ContentSanitizer walks into them instead.
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
