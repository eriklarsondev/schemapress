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
 * These two, granted to roles, are the whole of it. A collection used to be able
 * to name the roles allowed to edit ITS entries on top — a Grants collection for
 * finance, a News collection for comms — which was a second permission system
 * beside WordPress's own, settable per collection, and one more place to look
 * when somebody could not edit something. Who may edit is a question about a
 * role, and WordPress already answers it: anyone with EDIT edits any collection.
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
}
