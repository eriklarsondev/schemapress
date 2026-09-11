<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The SchemaPress admin screen: one React application mounted here. WordPress
 * supplies the chrome and the capability check; everything inside the page is
 * client-rendered and talks to the plugin's REST namespace.
 */
class Admin
{
    public const PAGE_SLUG = 'schemapress';

    /**
     * What it takes to open the builder and work on content.
     */
    public const CAPABILITY = Capabilities::EDIT;

    /**
     * the screen hook returned by add_menu_page, used to scope asset loading.
     *
     * @var string|null
     */
    private $hook = null;

    /**
     * Hooks the menu page and its assets.
     */
    public function __construct()
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    /**
     * Registers the top-level menu page.
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
     * The loading state is server-rendered so the screen is never blank while the
     * bundle parses, and doubles as the visible failure state if it is missing.
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
     * Enqueues the admin bundle on this screen only.
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
            // bootstrapped rather than fetched: a collection's settings dialog
            // reads these to say when the API it offers to publish to is off,
            // and should not wait on a request to say so
            'site' => Settings::all(),
            'can' => ['manageSchema' => Capabilities::canManage()],
            // the site's own role list, so one added by another plugin is
            // offered without this plugin knowing about it
            'roles' => Capabilities::roles(),
            // work already in flight, so a reindex started before a reload is
            // still visible after it
            'jobs' => Batch::status(),
            'docs' => Docs::forClient(),
            'version' => SCHEMAPRESS_VERSION,
        ]);
    }

    /**
     * The field type registry reduced to what the builder's UI needs: enough to
     * populate a type picker and to know which types nest.
     *
     * @return array
     */
    private function fieldTypesForClient()
    {
        $types = [];

        foreach (FieldTypes::all() as $slug => $definition) {
            // still valid to store, just not something you pick from a list
            if (!empty($definition['internal'])) {
                continue;
            }

            $types[] = [
                'type' => $slug,
                'label' => $definition['label'],
                'children' => !empty($definition['children']),
                'repeatable' => !empty($definition['repeatable']),
                // so the form draws a new field at the width it will be saved
                // at, rather than full until the first save moves it
                'width' => FieldTypes::defaultWidth($slug),
            ];
        }

        return $types;
    }
}
