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
    const CAPABILITY = 'edit_pages';

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
