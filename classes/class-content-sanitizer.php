<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * validates and coerces entry values against a content type's fields.
 *
 * this is the single write-side gate. it drops anything the definition does not
 * declare, fills anything it declares but the payload omits, and runs every
 * scalar through its field type's sanitizer. after this runs, stored values are
 * guaranteed to match the definition's shape.
 *
 * it is also the read-side reconciler: a field added after an entry was saved
 * resolves to its type default rather than being absent, so nothing downstream
 * needs to guard.
 */
class ContentSanitizer
{
    /**
     * sanitizes a flat value bag against a field list. every declared field is
     * present in the result; undeclared keys are dropped.
     *
     * @param mixed $values
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
                'id' => !empty($row['id']) ? sanitize_key($row['id']) : self::id(),
                'values' => self::values($values, $field['fields']),
            ];
        }

        // pad up to the configured minimum so templates can rely on the count
        while (count($clean) < $min) {
            $clean[] = ['id' => self::id(), 'values' => self::values([], $field['fields'])];
        }

        return $clean;
    }

    /**
     * generates a short, collision-resistant row id.
     *
     * rows carry their own identity so reordering never re-keys one, which is
     * what stops a React list dropping focus mid-edit.
     *
     * @return string
     */
    public static function id()
    {
        return 'r_' . substr(md5(uniqid((string) wp_rand(), true)), 0, 10);
    }
}
