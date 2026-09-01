<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * builds the context a Twig template renders.
 *
 * the delivered payload identifies a value by its shape - an attachment is an
 * array with a url and a mime type, a link is an array with a url and a label.
 * working that out is the kind of branching that has no business in a
 * template, so it happens once here and Twig receives a flat list of fields
 * that already know what they are.
 *
 * the result is that a section template is a loop and an include, and a field
 * template is markup.
 */
class ViewModel
{
    /**
     * the context for one delivered section.
     *
     * @param array $section
     *
     * @return array
     */
    public static function section(array $section, array $options = [])
    {
        $layout = isset($section['layout']) ? $section['layout'] : [];
        $roles = isset($section['roles']) ? $section['roles'] : [];
        $classes = isset($section['classes']) ? $section['classes'] : [];

        $columns = isset($layout['columns']) ? absint($layout['columns']) : 0;

        // in the editor, empty fields are filled with sample content and every
        // field is tagged so the rendered layout can be clicked into. neither
        // happens on a delivered page
        $options = $options + [
            'editing' => false,
            'types' => isset($section['types']) ? $section['types'] : [],
        ];

        $composed = self::compose($section['data'], $roles, $classes, $columns, $options);

        $names = ['sp-section', 'sp-section--' . $section['type']];

        foreach ($layout as $key => $value) {
            $names[] = 'sp-' . $key . '-' . $value;
        }

        if ($composed['backdrop']) {
            // a backdrop changes the whole section, not just the field: it
            // goes full width behind the content, and the text on top of it
            // has to survive whatever the image happens to be
            $names[] = 'sp-has-background';
        }

        $children = [];

        foreach (isset($section['children']) ? $section['children'] : [] as $child) {
            $children[] = self::section($child, $options);
        }

        return [
            'id' => $section['id'],
            'type' => $section['type'],
            'layout' => $layout,
            'columns' => $columns,
            // the canvas needs to know which section a click landed in, which
            // means the section itself carries a marker, not just its fields
            'editing' => !empty($options['editing']),
            'classes' => implode(' ', $names),
            'backdrop' => $composed['backdrop'],
            'flow' => $composed['flow'],
            'actions' => $composed['actions'],
            'children' => $children,
        ];
    }

    /**
     * splits a section's values into its backdrop, its content flow and its
     * trailing row of actions.
     *
     * fields keep their authored order within the flow. only the two roles
     * that are structurally different are moved: a backdrop, which is not in
     * the flow at all, and actions, which belong together at the end.
     *
     * @param array   $data
     * @param array   $roles   role => field key
     * @param array   $classes field key => class string
     * @param integer $columns
     *
     * @return array{backdrop: ?array, flow: array, actions: array}
     */
    private static function compose(
        array $data,
        array $roles,
        array $classes,
        $columns,
        array $options = []
    ) {
        $keys = array_flip($roles);
        $editing = !empty($options['editing']);

        $backdrop = null;
        $flow = [];
        $actions = [];

        foreach ($data as $key => $value) {
            $role = isset($keys[$key]) ? $keys[$key] : '';
            $empty = $value === null || $value === '' || $value === [] || is_bool($value);

            // an empty field would render as nothing, which in the editor
            // leaves a layout with holes in it. sample content stands in so
            // the shape of the section is legible before it is written
            if ($empty && $editing) {
                $value = Samples::forField($key, $role, $options['types'] ?? []);
            }

            if (Roles::placement($role) === 'layer') {
                $backdrop = self::backdrop($value);

                continue;
            }

            $field = self::field($key, $value, $role, $classes, $columns, $options);

            if ($field === null) {
                continue;
            }

            $field['empty'] = $empty;
            $field['editing'] = $editing;

            if (Roles::placement($role) === 'actions') {
                $actions[] = $field;

                continue;
            }

            $flow[] = $field;
        }

        return ['backdrop' => $backdrop, 'flow' => $flow, 'actions' => $actions];
    }

    /**
     * describes one resolved value for a template.
     *
     * @param string  $key
     * @param mixed   $value
     * @param string  $role
     * @param array   $classes
     * @param integer $columns
     *
     * @return array|null null when there is nothing to render
     */
    private static function field($key, $value, $role, array $classes, $columns, array $options = [])
    {
        if ($value === null || $value === '' || $value === [] || is_bool($value)) {
            return null;
        }

        $field = [
            'key' => $key,
            'role' => $role,
            'classes' => isset($classes[$key]) ? $classes[$key] : '',
            'columns' => $columns,
            'value' => $value,
        ];

        // a list of { id, data } is a repeater
        if (is_array($value) && isset($value[0]['data'])) {
            $rows = [];

            foreach ($value as $row) {
                $rows[] = [
                    'id' => $row['id'],
                    'fields' => self::compose($row['data'], [], $classes, 0, $options)['flow'],
                ];
            }

            return ['kind' => 'repeater', 'rows' => $rows] + $field;
        }

        if (is_array($value) && isset($value['url'], $value['mime'])) {
            $image = strpos((string) $value['mime'], 'image/') === 0;

            return ['kind' => $image ? 'image' : 'file'] + $field;
        }

        if (is_array($value) && isset($value['url'])) {
            return ['kind' => 'link'] + $field;
        }

        if (is_array($value) && isset($value['permalink'])) {
            return ['kind' => 'post'] + $field;
        }

        if (is_array($value) && isset($value[0]['permalink'])) {
            return ['kind' => 'posts'] + $field;
        }

        // a nested value bag is a group
        if (is_array($value)) {
            return [
                'kind' => 'group',
                'fields' => self::compose($value, [], $classes, $columns, $options)['flow'],
            ] + $field;
        }

        if (is_string($value) && strpos($value, '<') !== false) {
            return ['kind' => 'rich'] + $field;
        }

        // a heading is a heading element, not a paragraph in bold. the role
        // says so; the key-name check is a fallback for schemas authored
        // before roles existed
        $heading = $role === 'heading'
            || ($role === '' && preg_match('/(^|_)(heading|title)($|_)/', $key));

        if ($heading) {
            return ['kind' => 'heading'] + $field;
        }

        return ['kind' => $role === 'eyebrow' ? 'eyebrow' : 'text'] + $field;
    }

    /**
     * the background layer, from a resolved attachment.
     *
     * @param mixed $value
     *
     * @return array|null
     */
    private static function backdrop($value)
    {
        if (!is_array($value) || empty($value['url'])) {
            return null;
        }

        return [
            'url' => $value['sizes']['full']['url'] ?? $value['url'],
            'alt' => isset($value['alt']) ? $value['alt'] : '',
        ];
    }
}
