<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pure transformations over a content type's definition — the shape of one
 * collection's entries:
 *
 *   [
 *     'version'  => 1,
 *     'settings' => [ 'draftAndPublish' => true ],
 *     'fields'   => [ [ 'key' => 'name', 'type' => 'text', ... ] ],
 *   ]
 *
 * Touches no WordPress state beyond the sanitizers, so it unit tests in
 * isolation.
 */
class SchemaModel
{
    public const VERSION = 1;

    /**
     * Coerces an arbitrary decoded payload into a valid definition. Unknown keys
     * are dropped, missing keys defaulted, and duplicates within a level suffixed
     * so lookups stay unambiguous.
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
     * Coerces a collection's settings.
     *
     * draftAndPublish defaults to on, because turning it off is the destructive
     * direction: a collection that had drafts and stops having them publishes
     * them all.
     *
     * listColumns is null when nobody has chosen, and the table picks the first
     * few — which is what makes a field added later appear on its own. An empty
     * array is a real choice meaning no field columns, so the two stay distinct.
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
            // which shapes of read this collection answers on the public content
            // API. off unless it was explicitly turned on: the one default that
            // cannot be inferred from what the collection is, because getting it
            // wrong means publishing content nobody asked to publish
            'publicApi' => self::normalizePublicApi($settings['publicApi'] ?? null),
            // which field names an entry. see normalizeTitleField
            'titleField' => self::normalizeTitleField($settings['titleField'] ?? null, $fields),
            // which field the entry's slug is built from. absent means nobody
            // has chosen and one is picked; PRESENT AND EMPTY is a real choice
            // and means the uuid — the same distinction listColumns makes
            'slugField' => array_key_exists('slugField', $settings)
                ? self::normalizeSlugField($settings['slugField'], $fields)
                : self::defaultSlugField($fields),
            'listColumns' => self::normalizeColumns($settings['listColumns'] ?? null, $fields),
            // which roles may edit this collection's entries. empty is open to
            // everyone who may edit content, which is what every collection was
            // before this existed — see Capabilities::canEditCollection
            'editRoles' => self::normalizeRoles($settings['editRoles'] ?? null),
        ];
    }

    /**
     * Coerces a list of role slugs. Not checked against the roles this site has:
     * an import from a site with a `finance` role should keep the restriction
     * rather than drop it and open the collection to everybody. A role that does
     * not exist matches no user, which fails closed.
     *
     * @param mixed $roles
     *
     * @return string[]
     */
    private static function normalizeRoles($roles)
    {
        if (!is_array($roles)) {
            return [];
        }

        $clean = [];

        foreach ($roles as $role) {
            $slug = sanitize_key((string) $role);

            if ($slug !== '' && !in_array($slug, $clean, true)) {
                $clean[] = $slug;
            }
        }

        return $clean;
    }

    /**
     * Which shapes of read a collection publishes. Listing and fetching one entry
     * give away different amounts, so they are separate answers: a collection can
     * be readable by id without being walkable end to end.
     *
     * A bare boolean is the old single-switch shape; true means both, so a
     * collection stored under it keeps answering exactly as it did.
     *
     * @param mixed $publicApi
     *
     * @return array{list: boolean, single: boolean}
     */
    private static function normalizePublicApi($publicApi)
    {
        if (!is_array($publicApi)) {
            $on = !empty($publicApi);

            return ['list' => $on, 'single' => $on];
        }

        return [
            'list' => !empty($publicApi['list']),
            'single' => !empty($publicApi['single']),
        ];
    }

    /**
     * Field types that can name an entry: a name goes in a heading, so it has to
     * be a single line of text.
     *
     * @var string[]
     */
    public const TITLE_TYPES = [
        'text', 'textarea', 'email', 'url', 'phone', 'number', 'select',
        // a date names an entry in the collections that are a diary — a daily
        // note, a board meeting, a match report. a bare time does not name
        // anything, so it is not here
        'date', 'datetime',
    ];

    /**
     * The field a collection uses to name its entries.
     *
     * Empty means none, which is not a gap to be filled in: a collection of
     * settings has no name for a single one of them. WordPress still derives a
     * title for its own row (Entries::deriveTitle), but that never reaches the API.
     *
     * A key that no longer exists, or whose type cannot be a name, resolves to
     * none rather than pointing at nothing.
     *
     * @param mixed $key
     * @param array $fields
     *
     * @return string the field key, or ''
     */
    private static function normalizeTitleField($key, array $fields)
    {
        $key = is_string($key) ? sanitize_key($key) : '';

        if ($key === '') {
            // a field literally called `title` names its entries without having
            // to be nominated: it is already saying so
            $key = self::field($fields, 'title') ? 'title' : '';
        }

        $field = $key === '' ? null : self::field($fields, $key);

        return $field && in_array($field['type'], self::TITLE_TYPES, true) ? $key : '';
    }

    /**
     * Narrower than TITLE_TYPES: an email slugifies to `ada-example-com` and a
     * phone number to a run of digits. Both name an entry perfectly well and
     * neither is an address anybody wants to read in a URL.
     *
     * @var string[]
     */
    public const SLUG_TYPES = ['text', 'textarea', 'select', 'number', 'date', 'datetime'];

    /**
     * The field an entry's slug is built from, or '' for the uuid.
     *
     * Empty is a real answer: the uuid is unique by construction and needs nothing
     * filled in to exist, which is the right slug for a collection whose entries
     * have no name and the fallback for a deleted field.
     *
     * @param mixed $key
     * @param array $fields
     *
     * @return string the field key, or ''
     */
    private static function normalizeSlugField($key, array $fields)
    {
        $key = is_string($key) ? sanitize_key($key) : '';
        $field = $key === '' ? null : self::field($fields, $key);

        return $field && in_array($field['type'], self::SLUG_TYPES, true) ? $key : '';
    }

    /**
     * The first unique field wins, because one the collection already refuses
     * duplicates in produces slugs that do not collide. Failing that, the first
     * field that could carry a name — which is almost always what it is called.
     *
     * @param array $fields
     *
     * @return string
     */
    private static function defaultSlugField(array $fields)
    {
        $first = '';

        foreach ($fields as $field) {
            if (!in_array($field['type'], self::SLUG_TYPES, true)) {
                continue;
            }

            if (!empty($field['unique'])) {
                return $field['key'];
            }

            if ($first === '') {
                $first = $field['key'];
            }
        }

        return $first;
    }

    /**
     * Coerces a chosen column list to keys that exist, in the order given.
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
     * Normalizes a list of field definitions, recursing into types that nest.
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
     * Builds a single normalized field, recursing for group/repeater children.
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
            // whether two entries may hold the same value here. beside
            // `required` rather than in the config bag, because both are facts
            // about the DATA — what the collection will accept — while the
            // config bag is what the form does with it
            'unique' => !empty($field['unique']),
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
     * Whitelists the type-specific config bag, so stored definitions cannot
     * accumulate junk.
     *
     * @param array  $field
     * @param string $type
     *
     * @return array
     */
    /**
     * Clamps a leading offset so the control still fits on its row. One pushing a
     * field past the twelfth column wraps it to a row of its own with the gap
     * still in front of it — not what was asked for, and not recoverable from the
     * screen.
     *
     * @param mixed  $offset
     * @param string $width
     *
     * @return integer
     */
    private static function normalizeOffset($offset, $width)
    {
        $spans = ['third' => 4, 'half' => 6, 'two-thirds' => 8, 'full' => 12];
        $span = $spans[$width] ?? 12;

        // (int) rather than absint: a negative offset means none, not the same
        // gap on the other side
        return max(0, min((int) $offset, 12 - $span));
    }

    /**
     * Whitelists a field's type-specific config bag.
     *
     * @param array  $field
     * @param string $type
     *
     * @return array
     */
    private static function normalizeConfig(array $field, $type)
    {
        $config = isset($field['config']) && is_array($field['config']) ? $field['config'] : [];

        // Width and visibility describe the admin's own screen rather than the
        // delivered content, which is the only reason a presentation value lives
        // in a definition. A width somebody chose is kept, `full` included; a
        // field with no width yet starts at its type's own — see FieldTypes::WIDTHS
        $width = in_array($config['width'] ?? '', ['third', 'half', 'two-thirds', 'full'], true)
            ? $config['width']
            : FieldTypes::defaultWidth($type);

        $clean = [
            'width' => $width,
            // how many twelfths of blank space sit before the control on its
            // row. a grid flows its items together, so leaving a deliberate gap
            // — a half-width field on the RIGHT of an otherwise empty row —
            // needs the offset stated rather than implied
            'offset' => self::normalizeOffset($config['offset'] ?? 0, $width),
            // whether the control begins a row of its own. a grid packs its
            // items together, so where a row ENDS cannot be read off the
            // widths — a half-width control after a third-width one shares that
            // row whether or not that was the intent. this is how the intent is
            // stated, and it survives the fields before it being resized
            'new_row' => !empty($config['new_row']),
            'condition' => self::normalizeCondition($config['condition'] ?? null),
        ];

        switch ($type) {
            case 'select':
                // a named dataset is stored as a name, never as a copy of the
                // list. that is what lets a correction to the country list
                // reach every field that pointed at it
                $source = isset($config['source']) ? sanitize_key($config['source']) : '';
                $clean['source'] = Datasets::exists($source) ? $source : '';

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

            case 'gallery':
                // a ceiling on how many images, for the layouts that only have
                // room for so many. no minimum: `required` already says "at
                // least one", and a gallery padded to three empty slots the way
                // a repeater pads its rows would be three broken images
                $clean['max'] = isset($config['max']) ? absint($config['max']) : 0;
                break;

            case 'json':
                // how tall the editor is, in rows. a payload is usually either
                // three lines or three hundred, and the field knows which
                $clean['rows'] = isset($config['rows']) ? max(3, absint($config['rows'])) : 8;
                break;
        }

        return $clean;
    }

    /**
     * Coerces a field's visibility condition.
     *
     * A condition names a sibling field, so one inside a repeater row reads that
     * row's own values. Anything else would need a path language, and "show the
     * phone field once someone ticked Contactable" is the case that comes up.
     *
     * A malformed value falls back to no condition, so a field that fails to parse
     * one stays visible rather than disappearing.
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
     * Slugifies a candidate key and guarantees uniqueness among its siblings.
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
     * Turns a snake_case key into a readable label.
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
     * Finds a field definition by key within a flat field list.
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
