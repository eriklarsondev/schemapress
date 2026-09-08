<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * the SchemaPress admin screen.
 *
 * the whole management interface — listing schemas, building section and field
 * trees, binding templates — is one React application mounted here. WordPress
 * supplies the chrome and the capability check; everything inside the page is
 * client-rendered and talks to the plugin's REST namespace.
 */
class Admin
{
    const PAGE_SLUG = 'schemapress';

    /**
     * what it takes to open the builder and work on content.
     */
    const CAPABILITY = 'edit_pages';

    /**
     * what it takes to change the SHAPE of content, or what the site publishes.
     *
     * Strapi separates the Content-Type Builder from the Content Manager, and
     * for a reason worth copying: filling in a Team Member and deciding what a
     * Team Member IS are different jobs at different blast radii. renaming a
     * field orphans every value stored under it; deleting a collection deletes
     * every entry in it; turning on the content API publishes to the internet.
     *
     * one capability covered all of it here, so anyone who could write an entry
     * could also restructure the data model and delete the lot.
     */
    const SCHEMA_CAPABILITY = 'manage_options';

    /**
     * the screen hook returned by add_menu_page, used to scope asset loading.
     *
     * @var string|null
     */
    private $hook = null;

    /**
     * hooks the menu page and its assets.
     */
    public function __construct()
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    /**
     * registers the top-level menu page.
     *
     * @return void
     */
    public function registerMenu()
    {
        $this->hook = add_menu_page(
            __('SchemaPress', 'schemapress'),
            __('SchemaPress', 'schemapress'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render'],
            'dashicons-layout',
            26
        );
    }

    /**
     * renders the app's mount point.
     *
     * the loading state is server-rendered so the screen is never blank while
     * the bundle parses, and it doubles as the visible failure state if the
     * bundle is missing.
     *
     * @return void
     */
    public function render()
    {
        ?>
        <div class="wrap schemapress-wrap">
            <div id="schemapress-admin-root">
                <p class="schemapress-boot"><?php esc_html_e('Loading SchemaPress…', 'schemapress'); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * enqueues the admin bundle on this screen only.
     *
     * @param string $hook
     *
     * @return void
     */
    public function enqueue($hook)
    {
        if ($hook !== $this->hook) {
            return;
        }

        Assets::enqueue('admin', [
            'rest' => Assets::restContext(),
            'fieldTypes' => $this->fieldTypesForClient(),
            'datasets' => Datasets::forClient(),
            'elements' => Elements::all(),
            'adminUrl' => esc_url_raw(admin_url('admin.php?page=' . self::PAGE_SLUG)),
            // the site's own settings, bootstrapped rather than fetched: a
            // collection's settings dialog reads them to say when the API it is
            // offering to publish to is switched off, and should not wait on a
            // request to say so
            'site' => Settings::all(),
            // what this user may do beyond editing entries, so the screens can
            // stop offering what the transport would refuse
            'can' => ['manageSchema' => current_user_can(self::SCHEMA_CAPABILITY)],
            // the documentation is a screen in the app, so its text ships with
            // the page rather than costing a request: it is a few files of
            // Markdown this plugin ships, already compiled
            'docs' => Docs::forClient(),
            'version' => SCHEMAPRESS_VERSION,
        ]);
    }

    /**
     * the field type registry reduced to what the builder's UI needs: enough
     * to populate a type picker and to know which types nest.
     *
     * @return array
     */
    private function fieldTypesForClient()
    {
        $types = [];

        foreach (FieldTypes::all() as $slug => $definition) {
            // an internal type is still valid to store — it just is not
            // something you pick from a list
            if (!empty($definition['internal'])) {
                continue;
            }

            $types[] = [
                'type' => $slug,
                'label' => $definition['label'],
                'children' => !empty($definition['children']),
                'repeatable' => !empty($definition['repeatable']),
            ];
        }

        return $types;
    }
}
