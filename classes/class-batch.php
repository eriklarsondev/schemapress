<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * long work, done in pieces.
 *
 * three operations in this plugin walk every entry of a collection: rebuilding
 * the index after a field changes, deleting a collection, and minting
 * identifiers for entries that predate having any. all three were written as
 * `numberposts => -1` inside the request that asked for them.
 *
 * that is fine at the size a collection is when you are building it and wrong at
 * the size it reaches. renaming one field on a collection of ten thousand
 * entries meant a REST request holding ten thousand post objects in memory and
 * writing a meta row per indexable field per entry, which does not finish. what
 * made it worse than slow was that it half-finished: the index was rebuilt for
 * the entries the request got through and stale for the rest, with nothing
 * recording where it stopped.
 *
 * so the work is a JOB. a job knows what it is doing, how far it has got and how
 * much is left, and it survives the request that started it — WP-Cron picks it
 * up and carries on from the cursor. the admin can see the progress and WP-CLI
 * can run the whole thing to completion in one go.
 *
 * SMALL COLLECTIONS STILL RUN INLINE. queuing a job to reindex six entries would
 * mean a collection whose filters do not work until cron next fires, which is a
 * worse answer than the one-second pause it replaces. INLINE_LIMIT is where that
 * trade turns over.
 */
class Batch
{
    /**
     * where the queue lives.
     */
    const OPTION = 'schemapress_jobs';

    /**
     * the lock, so two cron runs cannot process the same cursor.
     */
    const LOCK = 'schemapress_jobs_lock';

    /**
     * the cron hook jobs are drained on.
     */
    const HOOK = 'schemapress/run_jobs';

    /**
     * how many entries one step handles.
     */
    const CHUNK = 100;

    /**
     * how many entries a collection can hold before the work is queued rather
     * than done on the spot.
     */
    const INLINE_LIMIT = 200;

    /**
     * how long one drain may run before it reschedules itself.
     *
     * twenty seconds, which is comfortably under the shortest PHP time limit
     * this plugin is likely to meet and long enough to be worth the trip.
     */
    const BUDGET = 20;

    /**
     * how long a lock is honored before it is assumed to be a crashed run.
     */
    const LOCK_TTL = 300;

    /**
     * hooks the drain.
     */
    public function __construct()
    {
        add_action(self::HOOK, [self::class, 'drain']);
    }

    /**
     * adds a job to the queue and asks for it to be run.
     *
     * a job naming the same operation on the same collection as one already
     * queued replaces it rather than joining it — reindexing a collection twice
     * produces the same index as reindexing it once, and the second request is
     * usually somebody saving the schema again while the first is still running.
     *
     * @param string $job  reindex, purge or backfill
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
            'total' => self::total($job, $args),
            'queued' => time(),
        ];

        self::store($jobs);
        self::schedule();

        /**
         * fires when long-running work is queued.
         *
         * @param string $id
         * @param string $job
         * @param array  $args
         */
        do_action('schemapress/job_queued', $id, $job, $args);

        return $id;
    }

    /**
     * runs queued work until the budget is spent or the queue is empty.
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
     * runs one job to completion, ignoring the budget.
     *
     * what WP-CLI calls, and what a test calls: neither wants to wait for cron.
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

            $ran += self::step($jobs[$id]);
        }
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

    // --- the steps -----------------------------------------------------------

    /**
     * advances one job by one chunk, removing it when it is finished.
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
             * fires when a queued job finishes.
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
     * rebuilds a chunk of a collection's index.
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
     * permanently deletes a chunk of a collection's entries.
     *
     * @param array $job
     *
     * @return integer
     */
    private static function purge(array $job)
    {
        $type_id = absint($job['args']['type_id'] ?? 0);
        $ids = self::page($type_id, 0);

        foreach ($ids as $id) {
            wp_delete_post($id, true);
        }

        // the collection itself goes with the last of its entries, so a purge
        // that was interrupted leaves the definition behind to be finished from
        // rather than a collection with no record of what its entries were
        if (count($ids) < self::CHUNK && !empty($job['args']['delete_type'])) {
            wp_delete_post($type_id, true);
            ContentType::flush();
        }

        return count($ids);
    }

    /**
     * re-addresses a chunk of a collection's entries.
     *
     * queued when a collection changes the field its slugs are built from. only
     * entries still carrying a uuid are touched, so this is idempotent and a
     * re-run cannot rewrite an address anybody has published.
     *
     * the trash is left out: a trashed entry's post_name is WordPress's own
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
     * mints identifiers for a chunk of a collection's entries.
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
     * one page of a collection's entry ids.
     *
     * ordered by ID so the cursor means the same thing between requests. the
     * default `date` ordering would let an entry edited mid-job move across the
     * cursor and be handled twice or not at all.
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
     * how many entries a job has to get through, for the progress display.
     *
     * @param string $job
     * @param array  $args
     *
     * @return integer
     */
    private static function total($job, array $args)
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
     * takes the lock, unless a run that has not gone stale already holds it.
     *
     * @return boolean
     */
    private static function lock()
    {
        $held = (int) get_option(self::LOCK, 0);

        if ($held && (time() - $held) < self::LOCK_TTL) {
            return false;
        }

        update_option(self::LOCK, time(), false);

        return true;
    }

    /**
     * @return void
     */
    private static function unlock()
    {
        delete_option(self::LOCK);
    }

    /**
     * whether a collection is small enough to do the work on the spot.
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
