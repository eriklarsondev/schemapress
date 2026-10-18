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
     * The menu mark: the plugin's icon with the tile taken off.
     *
     * Drawn for a 20px box rather than scaled down from assets/icon.svg — at
     * this size the bars carry the shape and the tile only darkens it, and a
     * mark reduced from 256px lands on half pixels and goes soft. The intrinsic
     * width and height are the rendered size, so the mask needs no sizing to be
     * right in a browser that does not read `mask-size`.
     *
     * No `fill`: this is a MASK, only the alpha channel is read, and SVG already
     * fills black by default. The colour comes from the rule below. Every byte
     * here is paid for on every admin screen and then again url-encoded, so the
     * markup is as short as it can be and still be legible.
     *
     * Same construction as the icon: every square is 4.4 and every gap is 1.6,
     * so the middle bar is 4.4 + 1.6 + 4.4 = 10.4 and rows two and three end
     * together at 17.4.
     */
    private const MARK = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="20" height="20">'
        . '<rect x="1" y="1.2" width="4.4" height="4.4" rx="1.3"/>'
        . '<rect x="7" y="1.2" width="12" height="4.4" rx="1.3"/>'
        . '<rect x="1" y="7.8" width="4.4" height="4.4" rx="1.3"/>'
        . '<rect x="7" y="7.8" width="10.4" height="4.4" rx="1.3"/>'
        . '<rect x="1" y="14.4" width="4.4" height="4.4" rx="1.3"/>'
        . '<rect x="7" y="14.4" width="4.4" height="4.4" rx="1.3"/>'
        . '<rect x="13" y="14.4" width="4.4" height="4.4" rx="1.3"/>'
        . '</svg>';

    /**
     * Hooks the menu page and its assets.
     */
    public function __construct()
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        // separate from enqueue(), which scopes itself to this plugin's screen:
        // the menu is drawn on every admin page, so its icon has to be
        add_action('admin_enqueue_scripts', [$this, 'menuIcon']);
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
            // 'none' rather than a dashicon or an SVG: menuIcon() paints it.
            'none',
            26
        );
    }

    /**
     * Paints the menu icon.
     *
     * Not the `data:image/svg+xml;base64,` icon `add_menu_page()` accepts,
     * because of what core does with one. menu-header.php renders it as a
     * background image ON THE DIV and drops the `<img>` it would otherwise
     * write — so `#adminmenu .wp-menu-image img`, and with it the 0.6 opacity
     * every other icon rests at and the full strength it takes when current,
     * matches nothing. An SVG icon stays exactly the colour baked into the file
     * while the label beside it turns white, which reads as a bug.
     *
     * A mask tinted with `currentColor` inherits instead. The icon is whatever
     * colour the link is, which is what carries it across the eight admin
     * colour schemes rather than hard-coding the grey one of them uses.
     *
     * Attached to `admin-menu`, a core style and a dependency of the `wp-admin`
     * bundle, so it prints wherever the menu does without adding a request.
     *
     * @return void
     */
    public function menuIcon()
    {
        // the rule describes a menu item that is not drawn for this user
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        // url-encoded rather than base64: the SVG stays legible in the page
        // source, and an opaque blob in a stylesheet is the thing a plugin
        // reviewer stops on
        $mark = 'url("data:image/svg+xml,' . rawurlencode(self::MARK) . '")';
        $item = '#adminmenu #toplevel_page_' . self::PAGE_SLUG;

        // Behind @supports because of how this fails without it: the colour is
        // a BACKGROUND and the mark is what cuts the shape out of it, so a
        // browser that has no mask paints the whole 36px box solid. A missing
        // icon is a gap in a row; a solid block is a defect.
        //
        // The URI goes in a custom property and is read twice, rather than
        // written twice. It is about a kilobyte url-encoded and this rule is on
        // every admin screen, so saying it once is most of the weight.
        //
        // The -webkit- pair is Safari before 15.4, which has the mask under the
        // old name. Its repeat and position are prefixed too: inheriting the
        // `repeat` default would tile the 20px mark across the box.
        wp_add_inline_style('admin-menu', sprintf(
            '@supports (mask-image:none) or (-webkit-mask-image:none){'
                . '%1$s div.wp-menu-image{--sp-mark:%2$s;'
                . 'background-color:currentColor;'
                . '-webkit-mask-image:var(--sp-mark);mask-image:var(--sp-mark);'
                . '-webkit-mask-repeat:no-repeat;mask-repeat:no-repeat;'
                . '-webkit-mask-position:center;mask-position:center;'
                . 'mask-size:20px auto;opacity:.6}'
                . '%1$s:hover div.wp-menu-image,'
                . '%1$s.opensub div.wp-menu-image,'
                . '%1$s.current div.wp-menu-image,'
                . '%1$s.wp-has-current-submenu div.wp-menu-image{opacity:1}}',
            $item,
            $mark
        ));
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
            'adminUrl' => esc_url_raw(admin_url('admin.php?page=' . self::PAGE_SLUG)),
            // bootstrapped rather than fetched: a collection's settings dialog
            // reads these to say when the API it offers to publish to is off,
            // and should not wait on a request to say so
            'site' => Settings::all(),
            'can' => ['manageSchema' => Capabilities::canManage()],
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
