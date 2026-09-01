<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * the field role registry.
 *
 * a field type says what a value *is* - an image, a link. a role says what it
 * is *for* - the backdrop behind a hero, the button at the end of a call to
 * action. without roles a renderer can only lay fields out in the order they
 * were defined, which is why a hero's background image would otherwise appear
 * as a picture in the middle of the flow instead of behind the text.
 *
 * roles are advisory. they are delivered with the schema contract so a
 * front-end can compose on them too, and a section with no roles at all still
 * renders correctly - just linearly.
 */
class Roles
{
    /**
     * @var array<string, array>
     */
    private static $roles = null;

    /**
     * every available role.
     *
     * @return array<string, array>
     */
    public static function all()
    {
        if (self::$roles !== null) {
            return self::$roles;
        }

        $roles = [
            'background' => [
                'label' => __('Background', 'schemapress'),
                'description' => __('Sits behind the section, full width.', 'schemapress'),
                'types' => ['image'],
                // lifted out of the content flow into its own layer
                'placement' => 'layer',
            ],
            'eyebrow' => [
                'label' => __('Eyebrow', 'schemapress'),
                'description' => __('Small label above the heading.', 'schemapress'),
                'types' => ['text'],
                'placement' => 'flow',
            ],
            'heading' => [
                'label' => __('Heading', 'schemapress'),
                'description' => __('The section’s main title.', 'schemapress'),
                'types' => ['text'],
                'placement' => 'flow',
            ],
            'action' => [
                'label' => __('Action', 'schemapress'),
                'description' => __('Buttons, grouped together at the end.', 'schemapress'),
                'types' => ['link'],
                // collected into one row after everything else
                'placement' => 'actions',
            ],
        ];

        /**
         * filters the available field roles.
         *
         * @param array $roles
         */
        self::$roles = apply_filters('schemapress/roles', $roles);

        return self::$roles;
    }

    /**
     * whether a role exists and may be used on a field type.
     *
     * @param string $role
     * @param string $type
     *
     * @return boolean
     */
    public static function applies($role, $type)
    {
        $roles = self::all();

        if (!isset($roles[$role])) {
            return false;
        }

        $types = $roles[$role]['types'];

        return empty($types) || in_array($type, $types, true);
    }

    /**
     * coerces a role, dropping any that does not suit the field's type.
     *
     * @param mixed  $role
     * @param string $type
     *
     * @return string an empty string when the field has no role
     */
    public static function normalize($role, $type)
    {
        $role = sanitize_key((string) $role);

        return self::applies($role, $type) ? $role : '';
    }

    /**
     * where a role's field belongs when composing a section.
     *
     * @param string $role
     *
     * @return string one of 'layer', 'actions', 'flow'
     */
    public static function placement($role)
    {
        $roles = self::all();

        return isset($roles[$role]) ? $roles[$role]['placement'] : 'flow';
    }

    /**
     * the roles a field of a given type may take, for the admin's picker.
     *
     * @return array
     */
    public static function forClient()
    {
        $roles = [];

        foreach (self::all() as $key => $role) {
            $roles[] = [
                'key' => $key,
                'label' => $role['label'],
                'description' => $role['description'],
                'types' => $role['types'],
            ];
        }

        return $roles;
    }
}
