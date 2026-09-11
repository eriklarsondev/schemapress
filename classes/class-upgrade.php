<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The one-time work a new version needs doing.
 *
 * Several identifiers — an entry's uid, a collection's machine key — are minted
 * lazily, which meant reading an entry wrote to the database: an unauthenticated
 * GET on the content API was not idempotent, took a row lock, and failed on a
 * read replica. The lazy mint stays as a safety net and this runs first to make
 * sure it never fires.
 *
 * On `init` at 30 — after ContentType registers its post types at 20, and before
 * `rest_api_init`, so the first request after an upgrade has backfilled before
 * any route is reached. It runs once per release rather than once per request.
 */
class Upgrade
{
    public const OPTION = 'schemapress_version';

    /**
     * The lock, so two requests arriving together do not both upgrade.
     */
    public const LOCK = 'schemapress_upgrade_lock';

    /**
     * How long a lock is honored before it is assumed to be a crashed run.
     */
    public const LOCK_TTL = 300;

    /**
     * Hooks the check.
     */
    public function __construct()
    {
        add_action('init', [$this, 'run'], 30);
    }

    /**
     * Brings the installation up to the running version.
     *
     * The version is written before the work, not after. This runs on whichever
     * request arrives first after the files change, which on a live site is an
     * anonymous pageview; recording it afterwards meant a run that timed out
     * recorded nothing and the next request started again from the beginning,
     * forever. Every step below is either idempotent or a queued job that tracks
     * its own progress.
     *
     * @return void
     */
    public function run()
    {
        $from = get_option(self::OPTION);

        if ($from === SCHEMAPRESS_VERSION || !$this->lock()) {
            return;
        }

        update_option(self::OPTION, SCHEMAPRESS_VERSION);

        try {
            // a site that updated its files without deactivating has never been
            // given these. granting is additive and repeatable
            Capabilities::grant();

            $minted = 0;

            // reading the list mints every collection's own key and plural,
            // which are lazy for the same reason
            foreach (ContentType::all() as $type) {
                $minted += Entries::backfill($type['id']);
            }
        } finally {
            // in a finally, because the version was recorded above: a run that
            // dies in the backfill would otherwise leave the lock behind
            // forever, since the next request returns early on the version check
            delete_option(self::LOCK);
        }

        /**
         * Fires after an upgrade has run.
         *
         * @param string  $version the version now stored
         * @param integer $minted  how many entries were given an identifier on
         *                         the spot; a large collection is queued instead
         * @param string  $from    the version that was stored before, or false
         */
        do_action('schemapress/upgraded', SCHEMAPRESS_VERSION, $minted, $from);
    }

    /**
     * Takes the upgrade lock.
     *
     * add_option rather than get-then-update: the row name is unique, so the
     * insert either wins or fails, and two requests arriving together cannot
     * both read "no lock" and both proceed.
     *
     * @return boolean
     */
    private function lock()
    {
        if (add_option(self::LOCK, time(), '', false)) {
            return true;
        }

        // an existing row is either a run in flight or one that died without
        // reaching its finally — after the TTL, assume the latter
        $held = (int) get_option(self::LOCK, 0);

        if ($held && (time() - $held) < self::LOCK_TTL) {
            return false;
        }

        update_option(self::LOCK, time(), false);

        return true;
    }
}
