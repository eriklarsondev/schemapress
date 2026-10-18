<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Long work, done in pieces.
 *
 * Four operations walk every entry of a collection: rebuilding the index after a
 * field changes, deleting a collection, re-addressing entries after the slug
 * field changes, and minting identifiers. Done inside the request that asked for
 * them, a collection of ten thousand entries does not finish — and
 * half-finishes, leaving the index rebuilt for the entries it got through and
 * stale for the rest with nothing recording where it stopped.
 *
 * So the work is a job: it knows how far it has got, and it survives the request
 * that started it. WP-Cron carries on from the cursor, the admin sees progress,
 * and WP-CLI can run the whole thing to completion in one go.
 *
 * Small collections still run inline. Queuing a job to reindex six entries would
 * mean filters that do not work until cron next fires, which is worse than the
 * one-second pause it replaces. INLINE_LIMIT is where that trade turns over.
 */
class Batch
{
    /**
     * where the queue lives.
     */
    public const OPTION = 'schemapress_jobs';

    /**
     * the lock, so two cron runs cannot process the same cursor.
     */
    public const LOCK = 'schemapress_jobs_lock';

    /**
     * the cron hook jobs are drained on.
     */
    public const HOOK = 'schemapress/run_jobs';

    /**
     * how many entries one step handles.
     */
    public const CHUNK = 100;

    /**
     * how many entries a collection can hold before the work is queued rather
     * than done on the spot.
     */
    public const INLINE_LIMIT = 200;

    /**
     * How long one drain may run before it reschedules itself — comfortably under
     * the shortest PHP time limit this is likely to meet.
     */
    public const BUDGET = 20;

    /**
     * how long a lock is honored before it is assumed to be a crashed run.
     */
    public const LOCK_TTL = 300;

    /**
     * hooks the drain.
     */
    public function __construct()
    {
        add_action(self::HOOK, [self::class, 'drain']);
    }

    /**
     * Adds a job to the queue and asks for it to be run.
     *
     * A job naming the same operation on the same collection replaces one already
     * queued: reindexing twice produces the same index as reindexing once, and the
     * second request is usually somebody saving the schema again.
     *
     * @param string $job  reindex, purge, reslug or backfill
     * @param array  $args job-specific, always including type_id
     *
     * @return string the job's id
     */
    public static function queue($job, array $args = [])
    {
        $jobs = self::all();
        $id = $job . ':' . absint($args['type_id'] ?? 0);

        $jobs[$id] = [
            'id' => $id,
            'job' => (string) $job,
            'args' => $args,
            'cursor' => 0,
            'done' => 0,
            'total' => self::total($args),
            'queued' => time(),
        ];

        self::store($jobs);
        self::schedule();

        /**
         * Fires when long-running work is queued.
         *
         * @param string $id
         * @param string $job
         * @param array  $args
         */
        do_action('schemapress/job_queued', $id, $job, $args);

        return $id;
    }

    /**
     * Runs queued work until the budget is spent or the queue is empty.
     *
     * @param integer $budget seconds, or 0 for "until it is finished"
     *
     * @return array{ran: integer, remaining: integer}
     */
    public static function drain($budget = self::BUDGET)
    {
        if (!self::lock()) {
            return ['ran' => 0, 'remaining' => count(self::all())];
        }

        $started = time();
        $ran = 0;

        try {
            while ($job = self::next()) {
                $ran += self::step($job);

                if ($budget > 0 && (time() - $started) >= $budget) {
                    break;
                }
            }
        } finally {
            self::unlock();
        }

        $remaining = count(self::all());

        if ($remaining) {
            self::schedule();
        }

        return ['ran' => $ran, 'remaining' => $remaining];
    }

    /**
     * Runs one job to completion, ignoring the budget — what WP-CLI and the tests
     * call, since neither wants to wait for cron.
     *
     * There is no time limit here on purpose, which makes "every step either
     * advances or retires the job" a thing this method depends on rather than
     * merely expects. step() keeps a job only when a full chunk was handled, so
     * a pass that handled nothing ends it — but a job type that reported work
     * it had not done would spin here with nothing to escape on, which is
     * precisely what purge() used to do. So the invariant is checked rather
     * than trusted: a pass that leaves the job in place without handling
     * anything stops, and says so where somebody running this can see it.
     *
     * @param string $id
     *
     * @return integer how many entries were handled
     */
    public static function finish($id)
    {
        $ran = 0;

        while (true) {
            $jobs = self::all();

            if (!isset($jobs[$id])) {
                return $ran;
            }

            $handled = self::step($jobs[$id]);
            $ran += $handled;

            if ($handled < 1 && isset(self::all()[$id])) {
                self::abandon($id);

                return $ran;
            }
        }
    }

    /**
     * Drops a job that cannot make progress, so it stops being offered as work
     * in flight.
     *
     * A job nothing can advance is worse than no job: the admin's banner reads
     * it as something still running, and every drain picks it up again. Taking
     * it off the queue is the honest state — the work did not finish, and the
     * next save or `wp schemapress reindex` queues it afresh from a clean
     * cursor.
     *
     * @param string $id
     *
     * @return void
     */
    private static function abandon($id)
    {
        $jobs = self::all();

        if (!isset($jobs[$id])) {
            return;
        }

        $job = $jobs[$id];
        unset($jobs[$id]);
        self::store($jobs);

        /**
         * Fires when a job is dropped without finishing, having stopped making
         * progress — something is refusing the work it is trying to do.
         *
         * @param string $id
         * @param array  $job the job as it was abandoned, cursor included
         */
        do_action('schemapress/job_abandoned', $id, $job);
    }

    /**
     * what is queued, for the admin's progress display.
     *
     * @return array
     */
    public static function status()
    {
        $status = [];

        foreach (self::all() as $job) {
            $status[] = [
                'id' => $job['id'],
                'job' => $job['job'],
                'typeId' => (int) ($job['args']['type_id'] ?? 0),
                'done' => (int) $job['done'],
                'total' => (int) $job['total'],
            ];
        }

        return $status;
    }

    /**
     * Asks cron to carry on with whatever is still queued.
     *
     * For activation. A job is deliberately durable — it is a cursor in an
     * option row, which is what lets it survive the request that queued it —
     * but the cron event that advances it is not: deactivating clears it, and
     * nothing put it back. So a reindex interrupted by a deactivate/reactivate
     * cycle sat in the queue at its last cursor forever, with the admin's
     * banner reporting it as work in flight and nothing on the site able to
     * advance it.
     *
     * Resuming rather than discarding, because the cursor is the useful half: a
     * half-rebuilt index is stale for the entries the job has not reached, and
     * throwing the job away would leave that with nothing recording it.
     *
     * @return boolean whether there was anything to carry on with
     */
    public static function resume()
    {
        if (!self::all()) {
            return false;
        }

        self::schedule();

        return true;
    }

    // --- the steps -----------------------------------------------------------

    /**
     * Advances one job by one chunk, removing it when it is finished.
     *
     * @param array $job
     *
     * @return integer how many entries this step handled
     */
    private static function step(array $job)
    {
        $handled = 0;

        switch ($job['job']) {
            case 'reindex':
                $handled = self::reindex($job);
                break;

            case 'purge':
                $handled = self::purge($job);
                break;

            case 'backfill':
                $handled = self::backfill($job);
                break;

            case 'reslug':
                $handled = self::reslug($job);
                break;
        }

        $jobs = self::all();

        if (!isset($jobs[$job['id']])) {
            return $handled;
        }

        $jobs[$job['id']]['done'] += $handled;

        // purge deletes what it reads, so the window it reads from is always the
        // front of the list; the other two page forward through a list that is
        // not moving
        if ($job['job'] !== 'purge') {
            $jobs[$job['id']]['cursor'] += $handled;
        }

        if ($handled < self::CHUNK) {
            $done = $jobs[$job['id']];
            unset($jobs[$job['id']]);
            self::store($jobs);

            /**
             * Fires when a queued job finishes.
             *
             * @param string $id
             * @param array  $job
             */
            do_action('schemapress/job_finished', $done['id'], $done);

            return $handled;
        }

        self::store($jobs);

        return $handled;
    }

    /**
     * Rebuilds a chunk of a collection's index.
     *
     * @param array $job
     *
     * @return integer
     */
    private static function reindex(array $job)
    {
        $type_id = absint($job['args']['type_id'] ?? 0);
        $ids = self::page($type_id, $job['cursor']);

        Index::reindex($type_id, $ids);

        return count($ids);
    }

    /**
     * Permanently deletes a chunk of a collection's entries.
     *
     * Reports what was ERASED rather than what was read, and the difference is
     * what makes this terminate. This method deletes the front of the list and
     * never advances a cursor — see step() — so a page that survives its own
     * deletion is read again on the next pass. wp_delete_post() refuses
     * whenever another plugin vetoes `pre_delete_post`, so reporting the read
     * kept a full page of undeletable entries alive as a job forever: cron
     * escaped on its budget and rescheduled at zero progress, and
     * Batch::finish() — which WP-CLI calls and which has no budget — did not
     * return at all.
     *
     * Entries::emptyTrash() stops on this same signal, for this same reason.
     *
     * @param array $job
     *
     * @return integer how many were actually erased
     */
    private static function purge(array $job)
    {
        $type_id = absint($job['args']['type_id'] ?? 0);
        $ids = self::page($type_id, 0);
        $erased = 0;

        foreach ($ids as $id) {
            if (wp_delete_post($id, true)) {
                $erased++;
            }
        }

        // The collection itself goes with the last of its entries, so a purge
        // that was interrupted leaves the definition behind to be finished from
        // rather than a collection with no record of what its entries were.
        //
        // Only once a short page came back AND all of it went. An entry that
        // survived its own deletion is still an entry, and dropping the
        // definition out from under it is exactly the orphaning this guards
        // against — the collection stays, and `wp schemapress list` still names
        // what is left behind.
        if (
            $erased === count($ids)
            && count($ids) < self::CHUNK
            && !empty($job['args']['delete_type'])
        ) {
            wp_delete_post($type_id, true);
            ContentType::flush();
        }

        return $erased;
    }

    /**
     * Re-addresses a chunk of a collection's entries. Only entries still carrying
     * a uuid are touched, so a re-run cannot rewrite a published address.
     *
     * The trash is left out: a trashed entry's post_name is WordPress's own
     * `__trashed` form, and restoring is what gives it a real one back.
     *
     * @param array $job
     *
     * @return integer
     */
    private static function reslug(array $job)
    {
        $type_id = absint($job['args']['type_id'] ?? 0);
        $ids = self::page($type_id, $job['cursor'], ['publish', 'draft']);

        Entries::reslugEntries($type_id, $ids);

        return count($ids);
    }

    /**
     * Mints identifiers for a chunk of a collection's entries.
     *
     * @param array $job
     *
     * @return integer
     */
    private static function backfill(array $job)
    {
        $type_id = absint($job['args']['type_id'] ?? 0);
        $ids = self::page($type_id, $job['cursor'], ['publish', 'draft', 'trash']);

        Entries::mint($ids);

        return count($ids);
    }

    /**
     * One page of a collection's entry ids, ordered by ID so the cursor means the
     * same thing between requests. The default `date` ordering would let an entry
     * edited mid-job move across the cursor and be handled twice or not at all.
     *
     * @param integer $type_id
     * @param integer $offset
     * @param array   $statuses
     *
     * @return integer[]
     */
    private static function page($type_id, $offset, array $statuses = ['publish', 'draft', 'trash'])
    {
        $type = ContentType::get($type_id);

        if (!$type) {
            return [];
        }

        return get_posts([
            'post_type' => $type['postType'],
            'post_status' => $statuses,
            'numberposts' => self::CHUNK,
            'offset' => absint($offset),
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
            'suppress_filters' => false,
        ]);
    }

    /**
     * How many entries a job has to get through, for the progress display.
     *
     * Every job walks the whole collection, trash included, so the answer does
     * not depend on which job it is — and counting the trash matters because
     * purge and backfill both reach into it.
     *
     * @param array $args
     *
     * @return integer
     */
    private static function total(array $args)
    {
        return Entries::count(absint($args['type_id'] ?? 0), true);
    }

    // --- the queue -----------------------------------------------------------

    /**
     * every queued job, keyed by id.
     *
     * @return array
     */
    private static function all()
    {
        $jobs = get_option(self::OPTION, []);

        return is_array($jobs) ? $jobs : [];
    }

    /**
     * the job to work on next.
     *
     * @return array|null
     */
    private static function next()
    {
        foreach (self::all() as $job) {
            return $job;
        }

        return null;
    }

    /**
     * Writes the queue back, removing it when nothing is left.
     *
     * @param array $jobs
     *
     * @return void
     */
    private static function store(array $jobs)
    {
        if ($jobs === []) {
            delete_option(self::OPTION);

            return;
        }

        update_option(self::OPTION, $jobs, false);
    }

    /**
     * asks cron to drain the queue, if it is not already going to.
     *
     * @return void
     */
    private static function schedule()
    {
        if (!function_exists('wp_next_scheduled') || wp_next_scheduled(self::HOOK)) {
            return;
        }

        wp_schedule_single_event(time(), self::HOOK);
    }

    /**
     * Takes the lock, unless a run that has not gone stale already holds it.
     *
     * add_option rather than get-then-update, for the reason Upgrade::lock()
     * spells out: the option name is unique, so the insert either wins or
     * fails, and two cron runs arriving together cannot both read "no lock" and
     * both proceed onto the same cursor. Reading first and writing second is
     * two statements with a gap in the middle, and cron is exactly where two
     * requests arrive at once.
     *
     * @return boolean
     */
    private static function lock()
    {
        if (add_option(self::LOCK, time(), '', false)) {
            return true;
        }

        // an existing row is either a run in flight or one that died without
        // reaching its unlock — after the TTL, assume the latter
        $held = (int) get_option(self::LOCK, 0);

        if ($held && (time() - $held) < self::LOCK_TTL) {
            return false;
        }

        update_option(self::LOCK, time(), false);

        return true;
    }

    /**
     * Releases the queue lock.
     *
     * @return void
     */
    private static function unlock()
    {
        delete_option(self::LOCK);
    }

    /**
     * Whether a collection is small enough to do the work on the spot.
     *
     * @param integer $type_id
     *
     * @return boolean
     */
    public static function inline($type_id)
    {
        return Entries::count($type_id, true) <= self::INLINE_LIMIT;
    }
}
