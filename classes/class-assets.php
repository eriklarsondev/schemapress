<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves and enqueues the compiled admin bundles.
 *
 * @wordpress/scripts emits a sibling .asset.php for each entry declaring its
 * WordPress script dependencies and a content hash. Reading it is what lets the
 * React apps share WordPress's own React rather than bundling duplicates.
 */
class Assets
{
    public const BUILD_DIR = 'build';

    /**
     * Screens ask before deciding what to offer: an in-app route is only worth
     * linking to if the bundle that renders it exists.
     *
     * @param string $entry
     *
     * @return boolean
     */
    public static function built($entry)
    {
        return file_exists(SCHEMAPRESS_PATH . self::BUILD_DIR . '/' . $entry . '.js');
    }

    /**
     * Enqueues a built entry and its declared dependencies.
     *
     * @param string $entry handle-safe entry name, e.g. 'schema-builder'
     * @param array  $data  bootstrapped into window.SchemaPress
     *
     * @return boolean whether the bundle was found and enqueued
     */
    public static function enqueue($entry, array $data = [])
    {
        $handle = 'schemapress-' . $entry;
        $base = SCHEMAPRESS_PATH . self::BUILD_DIR . '/' . $entry;
        $script = $base . '.js';

        if (!file_exists($script)) {
            self::warnMissingBuild();
            return false;
        }

        $asset = file_exists($base . '.asset.php')
            ? include $base . '.asset.php'
            : ['dependencies' => [], 'version' => SCHEMAPRESS_VERSION];

        wp_enqueue_script(
            $handle,
            SCHEMAPRESS_URL . self::BUILD_DIR . '/' . $entry . '.js',
            // a dependency rather than merely enqueued alongside:
            // RichTextControl reads `wp.editor.initialize` as it first renders,
            // and both scripts sit in the footer, so without an ordering between
            // them whether the editor exists yet is a matter of which file the
            // browser finished first
            array_merge($asset['dependencies'], ['editor']),
            $asset['version'],
            true
        );

        self::enqueueStyles($asset['version']);

        // the media modal is used by the image and file field types
        wp_enqueue_media();

        // what loads TinyMCE, Quicktags and the `wp.editor` API the Rich Text
        // field initializes against. Without it RichTextControl silently falls
        // back to a plain textarea
        wp_enqueue_editor();

        wp_add_inline_script(
            $handle,
            'window.SchemaPress = Object.assign(window.SchemaPress || {}, '
                . wp_json_encode($data) . ');',
            'before'
        );

        wp_set_script_translations($handle, 'schemapress');

        return true;
    }

    /**
     * Every extracted sheet in the build directory, rather than a hard-coded
     * name: webpack hoists the shared stylesheet into a chunk named after
     * whichever entry it was attributed to. RTL variants are registered as
     * alternates so WordPress swaps them in on RTL locales.
     *
     * @param string $version
     *
     * @return void
     */
    private static function enqueueStyles($version)
    {
        static $enqueued = false;

        if ($enqueued) {
            return;
        }

        $enqueued = true;

        foreach (glob(SCHEMAPRESS_PATH . self::BUILD_DIR . '/*.css') ?: [] as $path) {
            $file = basename($path, '.css');

            if (substr($file, -4) === '-rtl') {
                continue;
            }

            $handle = 'schemapress-' . $file;

            // no dependency on wp-components: the app ships its own UI layer,
            // and core's component styles only add rules that compete with it
            wp_enqueue_style(
                $handle,
                SCHEMAPRESS_URL . self::BUILD_DIR . '/' . $file . '.css',
                [],
                $version
            );

            if (file_exists(SCHEMAPRESS_PATH . self::BUILD_DIR . '/' . $file . '-rtl.css')) {
                wp_style_add_data($handle, 'rtl', 'replace');
            }
        }
    }

    /**
     * Shows an admin notice once when the bundles have not been built.
     *
     * @return void
     */
    private static function warnMissingBuild()
    {
        static $warned = false;

        if ($warned) {
            return;
        }

        $warned = true;

        add_action('admin_notices', function () {
            printf(
                '<div class="notice notice-error"><p><strong>%s</strong> %s <code>npm install && npm run build</code></p></div>',
                esc_html__('SchemaPress:', 'schemapress'),
                esc_html__('admin bundles are missing. Run', 'schemapress')
            );
        });
    }

    /**
     * The REST root and nonce every admin app needs.
     *
     * @return array
     */
    public static function restContext()
    {
        return [
            // relative, not absolute: rest_url() answers with the host stored in
            // site options, and a local install is often reached at another one —
            // a port, a tunnel, a proxy — which would make every call
            // cross-origin. The builder only ever talks to the site serving it
            'root' => wp_make_link_relative(esc_url_raw(rest_url(Rest::NAMESPACE))),
            'nonce' => wp_create_nonce('wp_rest'),
        ];
    }
}
