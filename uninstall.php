<?php

/**
 * What happens when somebody deletes the plugin.
 *
 * The default is to keep everything. Deleting a plugin is often how somebody
 * reinstalls it, moves it, or clears a broken update — none of which is a
 * decision to destroy the content. This only erases when the site has said so,
 * in Settings, in advance.
 *
 * It is all direct SQL because uninstall runs with the plugin unloaded: no
 * autoloader, no post types registered, no classes. wp_delete_post() on a post
 * whose type is unregistered still works but fires no hooks anything listens to,
 * and would be one query per post per meta row across what might be a hundred
 * thousand rows.
 *
 * @package SchemaPress
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Erases everything this plugin stored, if the site asked for that.
 *
 * @return void
 */
function schemapress_uninstall()
{
    global $wpdb;

    $settings = get_option('schemapress_settings', []);

    // The queue and the locks go either way. They describe work this plugin was
    // going to do, and it is being deleted — there is nothing to preserve in a
    // cursor into a collection that may be about to be erased.
    delete_option('schemapress_jobs');
    delete_option('schemapress_jobs_lock');
    delete_option('schemapress_upgrade_lock');
    wp_clear_scheduled_hook('schemapress/run_jobs');

    // So do the caches. The compiled documentation is keyed on the plugin
    // version and the source files' modification times, so there is no single
    // name to delete — and every one of them is derived from files that are
    // about to stop existing.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see the note at the top of this file; a wildcard over transient names has no options API equivalent.
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_schemapress_') . '%',
            $wpdb->esc_like('_transient_timeout_schemapress_') . '%'
        )
    );

    if (empty($settings['deleteDataOnUninstall'])) {
        return;
    }

    // Collections name their post types after a key that is not knowable
    // without reading the schema posts, so the list is gathered before anything
    // is deleted — after the schema posts go, nothing says what the entry post
    // types were called.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see the note at the top of this file: uninstall runs with the plugin unloaded, so no post type is registered and WP_Query cannot reach these rows.
    $types = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = %s AND meta_value != ''",
            '_schemapress_key'
        )
    );

    $post_types = ['sp_schema', 'sp_component'];

    foreach ((array) $types as $key) {
        $post_types[] = 'spc_' . $key;
    }

    schemapress_uninstall_post_types($post_types);

    // Index rows belong to entries that have just gone, so this is a sweep for
    // anything the pass above could not see — an entry whose collection was
    // deleted while a purge was half finished, most plausibly.
    foreach (['_sp_f_', '_sp_d_'] as $prefix) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
                $wpdb->esc_like($prefix) . '%'
            )
        );
    }

    delete_option('schemapress_settings');
    delete_option('schemapress_version');

    schemapress_uninstall_capabilities();
}

/**
 * Deletes every post of the given types, and the meta hanging off them.
 *
 * In batches: this is the one place guaranteed to be looking at the largest the
 * data ever got, and a DELETE with a subquery over a hundred thousand rows is how
 * an uninstall becomes a white screen.
 *
 * @param string[] $post_types
 *
 * @return void
 */
function schemapress_uninstall_post_types(array $post_types)
{
    global $wpdb;

    if (!$post_types) {
        return;
    }

    // An IN() list is as many placeholders as there are values, built rather
    // than written — every value still goes through prepare(), which is what
    // makes this the safe form of a variable-length list rather than the
    // exception to it.
    $types = implode(', ', array_fill(0, count($post_types), '%s'));

    while (true) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall runs with the plugin unloaded: no post types are registered, so WP_Query cannot see these rows, and there is no cache to prime for data being deleted.
        $ids = $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $types is a generated run of %s placeholders, not data; the values are the second argument.
            $wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$types}) LIMIT 500", $post_types)
        );

        if (!$ids) {
            return;
        }

        $ids = array_map('intval', $ids);
        $rows = implode(', ', array_fill(0, count($ids), '%d'));

        // Meta first. The other order leaves orphaned meta behind if the second
        // query is the one that fails, and orphaned meta is exactly what this
        // whole file exists to stop.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.
        $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- generated placeholders; the ids are passed as values.
            $wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$rows})", $ids)
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.
        $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- generated placeholders; the ids are passed as values.
            $wpdb->prepare("DELETE FROM {$wpdb->posts} WHERE ID IN ({$rows})", $ids)
        );
    }
}

/**
 * Takes the plugin's capabilities back off every role.
 *
 * @return void
 */
function schemapress_uninstall_capabilities()
{
    if (!function_exists('wp_roles')) {
        return;
    }

    foreach (wp_roles()->role_objects as $role) {
        $role->remove_cap('schemapress_edit_content');
        $role->remove_cap('schemapress_manage_schema');
    }
}

/**
 * Erases the plugin's data everywhere it was stored. A multisite delete runs the
 * uninstaller once per site, because each has its own tables, its own collections
 * and its own answer to whether it wanted them kept.
 *
 * In a function rather than at the top level, where a variable would be a global
 * shared with the whole of WordPress.
 *
 * @return void
 */
function schemapress_uninstall_everywhere()
{
    if (!is_multisite()) {
        schemapress_uninstall();

        return;
    }

    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $site_id) {
        switch_to_blog($site_id);
        schemapress_uninstall();
        restore_current_blog();
    }
}

schemapress_uninstall_everywhere();
