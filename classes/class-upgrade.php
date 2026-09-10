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
     * the lock, so two requests arriving together do not both upgrade.
     */
    const LOCK = 'schemapress_upgrade_lock';

    /**
     * how long a lock is honored before it is assumed to be a crashed run.
     */
    const LOCK_TTL = 300;

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
     * THE LOCK AND THE ORDER ARE THE POINT. this runs on whichever request
     * arrives first after the files change, which on a live site is an
     * anonymous front-end pageview and possibly several at once. the previous
     * version had neither: concurrent requests all ran the whole backfill
     * together, and the version was only recorded after every collection had
     * been walked — so a run that timed out recorded nothing and the next
     * request started again from the beginning, forever.
     *
     * now the version is written FIRST and the work is queued. a request cannot
     * fail to finish something it is not doing, and what is left is a job with a
     * cursor that survives being interrupted. see class-batch.php.
     *
     * @return void
     */
    public function run()
    {
        $from = get_option(self::OPTION);

        if ($from === SCHEMAPRESS_VERSION || !$this->lock()) {
            return;
        }

        // before the work, not after it. this is the flag that says "this
        // version has been seen", and every step below is either idempotent or
        // a queued job that tracks its own progress — so nothing is lost by not
        // being able to run the whole thing twice
        update_option(self::OPTION, SCHEMAPRESS_VERSION);

        // the capabilities the plugin defines for itself, which a site that
        // updated its files without deactivating has never been given. granting
        // is additive and repeatable, so it costs nothing to redo
        Capabilities::grant();

        $minted = 0;

        // reading the list mints every collection's own key and plural, which
        // are lazy for the same reason and were written by the same reads
        foreach (ContentType::all() as $type) {
            $minted += Entries::backfill($type['id']);
        }

        delete_option(self::LOCK);

        /**
         * fires after an upgrade has run.
         *
         * @param string  $version the version now stored
         * @param integer $minted  how many entries were given an identifier on
         *                         the spot; a large collection is queued instead
         * @param string  $from    the version that was stored before, or false
         */
        do_action('schemapress/upgraded', SCHEMAPRESS_VERSION, $minted, $from);
    }

    /**
     * takes the upgrade lock.
     *
     * @return boolean
     */
    private function lock()
    {
        $held = (int) get_option(self::LOCK, 0);

        if ($held && (time() - $held) < self::LOCK_TTL) {
            return false;
        }

        update_option(self::LOCK, time(), false);

        return true;
    }
}
