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
    public const VERSION = 1;

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
     * coerces a list of role slugs.
     *
     * the roles are NOT checked against the ones this site has. an export from
     * a site with a `finance` role, imported somewhere that has not created it
     * yet, should keep the restriction rather than silently drop it and open the
     * collection to everybody — a role that does not exist matches no user,
     * which fails closed.
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
     * which shapes of read a collection publishes to the content API.
     *
     * listing a collection and fetching one entry of it give away different
     * amounts, so they are separate answers: a collection can be readable by id
     * — for a client that already holds a reference to one — without being
     * walkable from end to end.
     *
     * a BARE BOOLEAN is what this setting was before it was a pair, when one
     * switch covered both routes. true meant both and still does, so a
     * collection stored under the old shape keeps answering exactly as it did.
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
     * field types that can name an entry.
     *
     * a name is something you can read in a list and put in a heading, so it
     * has to be a single line of text. an image cannot name anything, a
     * repeater is many things, and rich text is a document rather than a name.
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
     * the field a collection uses to name its entries.
     *
     * empty means none, which is not a gap to be filled in: a collection of
     * settings or of link rows has no name for a single one of them, and
     * inventing one is what this exists to stop. WordPress still needs a title
     * for its own row and still derives one — see Entries::deriveTitle — but
     * nothing about that reaches the API.
     *
     * a field key that no longer exists, or one whose type cannot be a name,
     * resolves to none. deleting the field a collection was named by should
     * leave it unnamed rather than pointing at nothing.
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
     * field types a readable slug can be built from.
     *
     * narrower than TITLE_TYPES, and narrower for a reason: an email address
     * slugifies to `ada-example-com` and a phone number to a run of digits.
     * both are perfectly good names for an entry and neither is an address
     * anybody would want to read in a URL.
     *
     * @var string[]
     */
    public const SLUG_TYPES = ['text', 'textarea', 'select', 'number', 'date', 'datetime'];

    /**
     * the field an entry's slug is built from, or '' for the uuid.
     *
     * EMPTY IS A REAL ANSWER. an entry always has a slug — where no field is
     * chosen it is the uuid, which is unique by construction and needs nothing
     * filled in to exist. that is the right slug for a collection whose entries
     * have no name, and it is what a deleted or retyped field falls back to
     * rather than leaving entries unaddressable.
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
     * the slug field a collection gets when nobody has chosen one.
     *
     * the first UNIQUE field wins, because a field the collection already
     * refuses duplicates in is the one that produces slugs which do not collide
     * — which is the whole job. failing that, the first field that could carry a
     * name, in the order they were defined: the first field of a collection is
     * almost always the thing it is called.
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
     * whitelists the type-specific config bag. anything not recognized for the
     * type is discarded so stored definitions cannot accumulate junk.
     *
     * @param array  $field
     * @param string $type
     *
     * @return array
     */
    /**
     * clamps a leading offset so the control still fits on its row.
     *
     * an offset that pushed a field past the twelfth column would wrap it to a
     * row of its own with the gap still in front of it, which is neither what
     * was asked for nor recoverable from the screen.
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
        // a width somebody chose is kept — FULL INCLUDED, which is why it is in
        // the list: it used to be the fallback rather than a value, and that
        // was harmless only while every type fell back to it. a field with no
        // width yet starts at its type's own, see FieldTypes::WIDTHS. every
        // field already stored has an explicit width, so none of them move
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
