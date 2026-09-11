<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The plugin's own capabilities.
 *
 *   schemapress_edit_content    fill entries in
 *   schemapress_manage_schema   decide what an entry IS, and what is published
 *
 * The split is Strapi's — filling in a Team Member and deciding what a Team
 * Member is are different jobs at different blast radii. These used to be
 * `edit_pages` and `manage_options` borrowed wholesale, which meant widening the
 * second one handed over the entire site's settings, users and plugins in order
 * to let somebody add a field.
 *
 * A collection may also name the roles allowed to edit its entries, so a Grants
 * collection can belong to finance and a News collection to comms.
 */
class Capabilities
{
    /**
     * What it takes to open the builder and work on entries.
     */
    public const EDIT = 'schemapress_edit_content';

    /**
     * What it takes to change the SHAPE of content, or what the site publishes.
     */
    public const MANAGE = 'schemapress_manage_schema';

    /**
     * Which roles get which capability on activation. Editors get content but
     * not schema, which is the division these exist to draw — and the one a site
     * gets by default rather than by reading the documentation.
     *
     * @var array<string, string[]>
     */
    public const ROLE_GRANTS = [
        'administrator' => [self::EDIT, self::MANAGE],
        'editor' => [self::EDIT],
    ];

    /**
     * Hooks the capability filter.
     */
    public function __construct()
    {
        add_filter('user_has_cap', [$this, 'grantToAdministrators'], 10, 4);
    }

    /**
     * Lets anyone who can manage the site manage schemas, capability row or not.
     *
     * A site whose roles were stored before these existed has neither, and would
     * find the admin menu gone. This is a floor, not a ceiling: it adds to a
     * user who already has `manage_options` and never removes from anyone. A
     * site that wants an administrator without schema access removes
     * `manage_options` from them, which is the honest way to say it.
     *
     * @param array $allcaps
     * @param array $caps
     * @param array $args
     * @param mixed $user
     *
     * @return array
     */
    public function grantToAdministrators($allcaps, $caps, $args, $user)
    {
        if (empty($allcaps['manage_options'])) {
            return $allcaps;
        }

        $allcaps[self::EDIT] = true;
        $allcaps[self::MANAGE] = true;

        return $allcaps;
    }

    /**
     * Writes the capabilities onto the site's roles.
     *
     * Called on activation and again from the upgrade routine, because a site
     * that updated its files without deactivating never runs an activation hook.
     *
     * @return void
     */
    public static function grant()
    {
        foreach (self::ROLE_GRANTS as $name => $caps) {
            $role = get_role($name);

            if (!$role) {
                continue;
            }

            foreach ($caps as $cap) {
                $role->add_cap($cap);
            }
        }
    }

    /**
     * Whether the current user may use the builder at all.
     *
     * @return boolean
     */
    public static function canEdit()
    {
        return current_user_can(self::EDIT);
    }

    /**
     * Whether the current user may change the shape of content.
     *
     * @return boolean
     */
    public static function canManage()
    {
        return current_user_can(self::MANAGE);
    }

    /**
     * Whether the current user may edit the entries of one collection.
     *
     * A collection naming no roles is open to everyone who may edit content.
     * Naming some narrows it to those, plus anyone who may manage schemas —
     * somebody who can delete the collection outright is not meaningfully kept
     * out of its entries.
     *
     * @param integer $type_id
     *
     * @return boolean
     */
    public static function canEditCollection($type_id)
    {
        if (!self::canEdit()) {
            return false;
        }

        $roles = self::rolesFor($type_id);

        if ($roles === []) {
            return true;
        }

        if (self::canManage()) {
            return true;
        }

        $user = wp_get_current_user();

        return (bool) array_intersect($roles, (array) ($user->roles ?? []));
    }

    /**
     * The roles a collection restricts its entries to.
     *
     * @param integer $type_id
     *
     * @return string[] empty when the collection is open
     */
    public static function rolesFor($type_id)
    {
        $settings = SchemaRepository::definition($type_id)['settings'];

        return isset($settings['editRoles']) && is_array($settings['editRoles'])
            ? $settings['editRoles']
            : [];
    }

    /**
     * Every role a collection could be restricted to, for the settings dialog.
     *
     * @return array<array{value: string, label: string}>
     */
    public static function roles()
    {
        $roles = [];

        foreach (wp_roles()->roles as $slug => $role) {
            $roles[] = [
                'value' => $slug,
                'label' => translate_user_role($role['name']),
            ];
        }

        return $roles;
    }
}
