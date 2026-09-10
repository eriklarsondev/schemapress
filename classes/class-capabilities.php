<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * the plugin's own capabilities.
 *
 * these used to be `edit_pages` and `manage_options` borrowed wholesale, and the
 * documentation's advice for widening the second one was to grant editors
 * `manage_options` — which hands over the entire site's settings, users and
 * plugins in order to let somebody add a field to a collection. borrowing a
 * capability means you cannot widen it without widening everything it also
 * governs, and you cannot narrow it at all.
 *
 * so there are two of this plugin's own, granted to roles on activation:
 *
 *   schemapress_edit_content    fill entries in
 *   schemapress_manage_schema   decide what an entry IS, and what is published
 *
 * the split is Strapi's, for Strapi's reason — see class-admin.php. what is new
 * is that both are revocable: taking `schemapress_manage_schema` off an
 * administrator now leaves the rest of their administration intact.
 *
 * PER COLLECTION. a site with a Grants collection the finance team owns and a
 * News collection the comms team owns could not express that: one capability
 * covered every collection at once. a collection may now name the roles allowed
 * to edit its entries, and an empty list means "anyone who may edit content",
 * which is what every existing collection has.
 */
class Capabilities
{
    /**
     * what it takes to open the builder and work on entries.
     */
    const EDIT = 'schemapress_edit_content';

    /**
     * what it takes to change the SHAPE of content, or what the site publishes.
     */
    const MANAGE = 'schemapress_manage_schema';

    /**
     * which roles get which capability when the plugin is activated.
     *
     * administrators get both. editors get content but not schema, which is the
     * division the two capabilities exist to draw — and the one a site gets by
     * default rather than by reading the documentation.
     *
     * @var array<string, string[]>
     */
    const ROLE_GRANTS = [
        'administrator' => [self::EDIT, self::MANAGE],
        'editor' => [self::EDIT],
    ];

    /**
     * hooks the capability filter.
     */
    public function __construct()
    {
        // a site whose roles were stored before this version has neither
        // capability, and would find the admin menu gone. rather than a
        // migration that could half-run, an administrator is always allowed —
        // the role that could grant itself the capability anyway
        add_filter('user_has_cap', [$this, 'grantToAdministrators'], 10, 4);
    }

    /**
     * lets anyone who can manage the site manage schemas, capability row or not.
     *
     * this is a floor, not a ceiling: it adds the plugin's capabilities to a
     * user who already has `manage_options` and never removes them from anyone.
     * a site that wants an administrator WITHOUT schema access removes
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
     * writes the capabilities onto the site's roles.
     *
     * called on activation and again from the upgrade routine, because a site
     * that updated the files without deactivating never runs an activation hook
     * — and would otherwise have the code for these capabilities and no role
     * holding them.
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
     * takes the capabilities back off every role.
     *
     * uninstall only, never deactivation: a deactivated plugin whose
     * capabilities were revoked would come back with every role's grant lost,
     * including ones the site had added by hand.
     *
     * @return void
     */
    public static function revoke()
    {
        foreach (wp_roles()->role_objects as $role) {
            $role->remove_cap(self::EDIT);
            $role->remove_cap(self::MANAGE);
        }
    }

    /**
     * whether the current user may use the builder at all.
     *
     * @return boolean
     */
    public static function canEdit()
    {
        return current_user_can(self::EDIT);
    }

    /**
     * whether the current user may change the shape of content.
     *
     * @return boolean
     */
    public static function canManage()
    {
        return current_user_can(self::MANAGE);
    }

    /**
     * whether the current user may edit the entries of one collection.
     *
     * a collection naming no roles is open to everyone who may edit content,
     * which is what every collection was before this existed. naming some
     * narrows it to those, plus anyone who may manage schemas — somebody who can
     * delete the collection outright is not meaningfully kept out of its entries.
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
     * the roles a collection restricts its entries to.
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
     * every role a collection could be restricted to, for the settings dialog.
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
