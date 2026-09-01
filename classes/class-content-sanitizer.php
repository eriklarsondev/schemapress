<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * validates and coerces content against a schema definition.
 *
 * this is the single write-side gate. it drops anything the schema does not
 * declare, fills anything it declares but the payload omits, and runs every
 * scalar through its field type's sanitizer. after this runs, stored content
 * is guaranteed to match the definition's shape.
 */
class ContentSanitizer
{
    /**
     * sanitizes a shaped content envelope against a definition.
     *
     * @param array $content
     * @param array $definition
     *
     * @return array
     */
    public static function sanitize(array $content, array $definition)
    {
        return [
            'version' => SchemaModel::VERSION,
            'sections' => self::sections($content['sections'], $definition),
        ];
    }

    /**
     * sanitizes a list of placed sections against a definition.
     *
     * instance caps are counted per list rather than per page: a "max 1" hero
     * means one at each level it appears, which is the only reading that stays
     * meaningful once sections can nest.
     *
     * @param array   $sections
     * @param array   $definition
     * @param integer $depth
     *
     * @return array
     */
    public static function sections($sections, array $definition, $depth = 0)
    {
        if (!is_array($sections) || $depth > Content::MAX_DEPTH) {
            return [];
        }

        $counts = [];
        $clean = [];

        foreach ($sections as $section) {
            $type = SchemaModel::section($definition, $section['type']);

            // the schema no longer declares this section type
            if (!$type) {
                continue;
            }

            $key = $type['key'];
            $counts[$key] = isset($counts[$key]) ? $counts[$key] + 1 : 1;

            // section instances are capped by the definition's max
            if ($type['max'] > 0 && $counts[$key] > $type['max']) {
                continue;
            }

            $clean[] = [
                'id' => $section['id'],
                'type' => $key,
                'layout' => Layout::sanitize($section['layout'] ?? [], $type['layout']),
                'values' => self::values($section['values'], $type['fields']),
                // children only survive on a type that declares itself a
                // container; anything else drops them rather than carrying
                // content nothing will ever render
                'children' => !empty($type['container'])
                    ? self::sections($section['children'] ?? [], $definition, $depth + 1)
                    : [],
            ];
        }

        return $clean;
    }

    /**
     * sanitizes a flat value bag against a field list. every declared field is
     * present in the result; undeclared keys are dropped.
     *
     * @param array $values
     * @param array $fields
     *
     * @return array
     */
    public static function values($values, array $fields)
    {
        $values = is_array($values) ? $values : [];
        $clean = [];

        foreach ($fields as $field) {
            $key = $field['key'];
            $raw = array_key_exists($key, $values) ? $values[$key] : null;

            $clean[$key] = self::value($raw, $field);
        }

        return $clean;
    }

    /**
     * sanitizes one value, recursing for nesting types.
     *
     * @param mixed $value
     * @param array $field
     *
     * @return mixed
     */
    private static function value($value, array $field)
    {
        $type = $field['type'];

        if (FieldTypes::isRepeatable($type)) {
            return self::rows($value, $field);
        }

        if (FieldTypes::hasChildren($type)) {
            return self::values($value, $field['fields']);
        }

        if ($value === null) {
            return FieldTypes::defaultValue($type);
        }

        return FieldTypes::sanitize($value, $field);
    }

    /**
     * sanitizes repeater rows, enforcing the configured min and max.
     *
     * @param mixed $value
     * @param array $field
     *
     * @return array
     */
    private static function rows($value, array $field)
    {
        $rows = is_array($value) ? $value : [];
        $min = isset($field['config']['min']) ? (int) $field['config']['min'] : 0;
        $max = isset($field['config']['max']) ? (int) $field['config']['max'] : 0;

        $clean = [];

        foreach ($rows as $row) {
            if ($max > 0 && count($clean) >= $max) {
                break;
            }

            // rows may arrive either enveloped or as a bare value bag
            $values = isset($row['values']) && is_array($row['values'])
                ? $row['values']
                : (is_array($row) ? $row : []);

            $clean[] = [
                'id' => !empty($row['id']) ? sanitize_key($row['id']) : Content::id('r'),
                'values' => self::values($values, $field['fields']),
            ];
        }

        // pad up to the configured minimum so templates can rely on the count
        while (count($clean) < $min) {
            $clean[] = [
                'id' => Content::id('r'),
                'values' => self::values([], $field['fields']),
            ];
        }

        return $clean;
    }
}
