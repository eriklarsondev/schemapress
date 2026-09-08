<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * the one-time work a new version needs doing.
 *
 * this exists because of a specific bad shape. several identifiers in here were
 * minted LAZILY — an entry's uid, a collection's machine key — on the argument
 * that a migration which could half-run is worse than one that never has to
 * happen. that argument is right about migrations and wrong about where the
 * minting ended up: reading an entry wrote to the database, so an
 * unauthenticated GET on the content API was not idempotent, took a row lock,
 * and failed outright on a read replica.
 *
 * so the lazy mint stays as the safety net it always was, and this runs first
 * to make sure it never fires. on `init` at 30 — after ContentType has
 * registered its post types at 20, and before `rest_api_init`, so the very
 * first request after an upgrade has already backfilled by the time any route
 * is reached.
 *
 * it runs when the stored version differs from the running one, which is once
 * per release rather than once per request. on a very large site that one
 * request is slow; it is still a better trade than every read being a write.
 */
class Upgrade
{
    const OPTION = 'schemapress_version';

    /**
     * hooks the check.
     */
    public function __construct()
    {
        add_action('init', [$this, 'run'], 30);
    }

    /**
     * brings the installation up to the running version.
     *
     * @return void
     */
    public function run()
    {
        if (get_option(self::OPTION) === SCHEMAPRESS_VERSION) {
            return;
        }

        $minted = 0;

        // reading the list mints every collection's own key and plural, which
        // are lazy for the same reason and were written by the same reads
        foreach (ContentType::all() as $type) {
            $minted += Entries::backfill($type['id']);
        }

        update_option(self::OPTION, SCHEMAPRESS_VERSION);

        /**
         * fires after an upgrade has run.
         *
         * @param string  $version the version now stored
         * @param integer $minted  how many entries were given an identifier
         */
        do_action('schemapress/upgraded', SCHEMAPRESS_VERSION, $minted);
    }
}
