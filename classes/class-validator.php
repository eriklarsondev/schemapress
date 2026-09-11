<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The write-side gate that refuses, as opposed to the one that coerces.
 *
 * ContentSanitizer makes a payload fit the definition's shape and cannot fail by
 * design. That leaves the rules where storing the value anyway is the wrong
 * answer — a required field left empty, a name past the length the collection
 * accepts, a second entry claiming an email the first already has.
 *
 * Which fields are asked is the subtle part, and why the visibility rules are
 * duplicated here rather than assumed: a field hidden by its condition is not
 * being asked for, so it cannot be missing. This mirrors src/shared/conditions.js
 * and src/shared/required.js clause for clause — the two have to agree, or the
 * form lets you save what the server then rejects.
 */
class Validator
{
    /**
     * Every rule a value bag breaks.
     *
     * @param array $values  sanitized values, so this checks what would be stored
     * @param array $fields  the collection's field definitions
     * @param array $context type_id and entry_id, for the uniqueness lookup.
     *                       without them uniqueness is skipped, which is what
     *                       makes the rest of this testable with no database
     *
     * @return array<array{key: string, label: string, message: string}>
     */
    public static function check(array $values, array $fields, array $context = [])
    {
        return self::walk($values, $fields, '', $context, true);
    }

    /**
     * Problems as the error a REST route returns. The joined messages are the
     * human sentence most clients show; the list is repeated under `fields` so one
     * that wants to mark up the offending controls can.
     *
     * @param array $problems
     *
     * @return \WP_Error
     */
    public static function error(array $problems)
    {
        return new \WP_Error(
            'schemapress_invalid_entry',
            implode(' ', array_column($problems, 'message')),
            ['status' => 400, 'fields' => $problems]
        );
    }

    /**
     * Walks a field list, recursing into the types that nest.
     *
     * @param array   $values
     * @param array   $fields
     * @param string  $path    labels of the groups and rows above, for the message
     * @param array   $context
     * @param boolean $top     whether this level is the entry's own
     *
     * @return array
     */
    private static function walk(array $values, array $fields, $path, array $context, $top)
    {
        $problems = [];

        foreach (self::visible($fields, $values) as $field) {
            $key = $field['key'];
            $value = array_key_exists($key, $values) ? $values[$key] : null;
            $label = $path === '' ? $field['label'] : $path . ' → ' . $field['label'];

            if ($field['type'] === 'repeater') {
                $problems = array_merge($problems, self::rows($value, $field, $label, $context));

                continue;
            }

            if (FieldTypes::hasChildren($field['type'])) {
                $problems = array_merge($problems, self::walk(
                    is_array($value) ? $value : [],
                    $field['fields'],
                    $label,
                    $context,
                    false
                ));

                continue;
            }

            if (!empty($field['required']) && self::blank($value)) {
                $problems[] = self::problem($key, $label, sprintf(
                    /* translators: %s: a field's name */
                    __('%s is required.', 'schemapress'),
                    $label
                ));

                // one complaint per field: telling someone an empty box is also
                // too short is noise on top of the thing they have to fix
                continue;
            }

            $problems = array_merge(
                $problems,
                self::rules($value, $field, $key, $label, $context, $top)
            );
        }

        return $problems;
    }

    /**
     * The rules that apply to a filled-in scalar.
     *
     * @param mixed   $value
     * @param array   $field
     * @param string  $key
     * @param string  $label
     * @param array   $context
     * @param boolean $top
     *
     * @return array
     */
    private static function rules($value, array $field, $key, $label, array $context, $top)
    {
        $problems = [];
        $config = $field['config'];

        $max = isset($config['maxlength']) ? (int) $config['maxlength'] : 0;

        if ($max > 0 && is_string($value) && mb_strlen($value) > $max) {
            $problems[] = self::problem($key, $label, sprintf(
                /* translators: 1: a field's name, 2: a number of characters */
                __('%1$s must be %2$d characters or fewer.', 'schemapress'),
                $label,
                $max
            ));
        }

        if ($field['type'] === 'number' && is_numeric($value)) {
            // isset, not a truthiness test: a minimum of 0 is a real bound, and
            // it is the one a quantity field is most likely to set
            if (isset($config['min']) && $value < $config['min']) {
                $problems[] = self::problem($key, $label, sprintf(
                    /* translators: 1: a field's name, 2: the smallest allowed value */
                    __('%1$s must be %2$s or more.', 'schemapress'),
                    $label,
                    $config['min']
                ));
            }

            if (isset($config['max']) && $value > $config['max']) {
                $problems[] = self::problem($key, $label, sprintf(
                    /* translators: 1: a field's name, 2: the largest allowed value */
                    __('%1$s must be %2$s or less.', 'schemapress'),
                    $label,
                    $config['max']
                ));
            }
        }

        if ($top && !empty($field['unique']) && self::taken($value, $field, $context)) {
            $problems[] = self::problem($key, $label, sprintf(
                /* translators: %s: a field's name */
                __('%s must be unique, and another entry already has that value.', 'schemapress'),
                $label
            ));
        }

        return $problems;
    }

    /**
     * A repeater's own rule, and then every row's. Uniqueness is not carried into
     * a row: the index holds one value per field per entry, so there is nothing to
     * ask the question against.
     *
     * @param mixed  $value
     * @param array  $field
     * @param string $label
     * @param array  $context
     *
     * @return array
     */
    private static function rows($value, array $field, $label, array $context)
    {
        $rows = is_array($value) ? $value : [];
        $problems = [];

        if (!empty($field['required']) && $rows === []) {
            $problems[] = self::problem($field['key'], $label, sprintf(
                /* translators: %s: a repeater field's name */
                __('%s needs at least one row.', 'schemapress'),
                $label
            ));
        }

        foreach (array_values($rows) as $index => $row) {
            $problems = array_merge($problems, self::walk(
                isset($row['values']) && is_array($row['values']) ? $row['values'] : [],
                $field['fields'],
                $label . ' ' . ($index + 1),
                $context,
                false
            ));
        }

        return $problems;
    }

    /**
     * Whether another entry of this collection already holds a value.
     *
     * The draft index is asked, because it is the one every entry has. Two drafts
     * claiming the same slug is a collision waiting for the moment they are both
     * published, not one that has not happened.
     *
     * @param mixed $value
     * @param array $field
     * @param array $context
     *
     * @return boolean
     */
    private static function taken($value, array $field, array $context)
    {
        $type_id = absint($context['type_id'] ?? 0);

        // an empty value is not a claim on anything: a collection where three
        // entries have not filled in their reference number yet is not three
        // entries with the same reference number
        if (!$type_id || !Index::indexable($field) || !is_scalar($value) || self::blank($value)) {
            return false;
        }

        $type = ContentType::get($type_id);

        if (!$type) {
            return false;
        }

        $entry_id = absint($context['entry_id'] ?? 0);

        $found = get_posts([
            'post_type' => $type['postType'],
            'post_status' => ['publish', 'draft'],
            // two: the entry being saved may legitimately be one of them, so one
            // result is not yet an answer
            'numberposts' => 2,
            'fields' => 'ids',
            'meta_key' => Index::key($field['key'], true),
            'meta_value' => (string) $value,
            'suppress_filters' => false,
        ]);

        foreach ($found as $id) {
            if ((int) $id !== $entry_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * One problem, in the shape the error reports.
     *
     * @param string $key
     * @param string $label
     * @param string $message
     *
     * @return array
     */
    private static function problem($key, $label, $message)
    {
        return ['key' => $key, 'label' => $label, 'message' => $message];
    }

    // --- ported from the form ------------------------------------------------

    /**
     * Mirrors isBlank() in src/shared/required.js, including the two rules that
     * look odd written down: a boolean is never blank, because a toggle is
     * answered by being off as much as by being on; and zero is a number, not an
     * absence.
     *
     * @param mixed $value
     *
     * @return boolean
     */
    public static function blank($value)
    {
        if ($value === null) {
            return true;
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            if ($value === []) {
                return true;
            }

            // a LIST is its rows — a multi-select with a choice in it is
            // answered, whatever the choice was. a MAP is a composite, and a
            // link of { url: '', label: '', target: '' } is an empty control
            // rather than a filled one. JS tells the two apart by their type;
            // here they are both arrays, so the shape is what says which
            if (array_is_list($value)) {
                return false;
            }

            foreach ($value as $part) {
                if (!self::blank($part)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * The fields currently on screen, given their siblings' values. Mirrors
     * visibleFields() in src/shared/conditions.js.
     *
     * @param array $fields
     * @param array $values
     *
     * @return array
     */
    private static function visible(array $fields, array $values)
    {
        $shown = [];

        foreach ($fields as $field) {
            if (self::matches($field['config']['condition'] ?? null, $values)) {
                $shown[] = $field;
            }
        }

        return $shown;
    }

    /**
     * An unreadable condition shows the field, the same direction the form errs
     * in: a control you can reason about beats one that vanished.
     *
     * @param mixed $condition
     * @param array $values
     *
     * @return boolean
     */
    private static function matches($condition, array $values)
    {
        $key = is_array($condition) ? (string) ($condition['field'] ?? '') : '';

        if ($key === '') {
            return true;
        }

        $value = $values[$key] ?? null;
        $want = is_array($condition) ? ($condition['value'] ?? '') : '';

        switch ($condition['operator'] ?? 'filled') {
            case 'empty':
                return !self::filled($value);

            case 'equals':
                return self::equals($value, $want);

            case 'not_equals':
                return !self::equals($value, $want);

            default:
                return self::filled($value);
        }
    }

    /**
     * Compared as strings. A multi-select holds several values, and "equals
     * Design" about one of those means it is among them.
     *
     * @param mixed $value
     * @param mixed $want
     *
     * @return boolean
     */
    private static function equals($value, $want)
    {
        if (is_array($value)) {
            return in_array((string) $want, array_map('strval', $value), true);
        }

        return (string) ($value ?? '') === (string) $want;
    }

    /**
     * Mirrors isFilled() in src/shared/conditions.js. Deliberately not the same
     * question as blank(): an unticked toggle is not filled in — the case
     * conditions are usually written against — but it is answered, so it
     * satisfies `required`.
     *
     * @param mixed $value
     *
     * @return boolean
     */
    private static function filled($value)
    {
        if ($value === null || $value === '' || $value === false) {
            return false;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return count($value) > 0;
            }

            // a link is an empty shape until it has a url
            if (array_key_exists('url', $value)) {
                return (bool) $value['url'];
            }

            return count($value) > 0;
        }

        return true;
    }
}
