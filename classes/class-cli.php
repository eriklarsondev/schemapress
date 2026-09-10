<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * the command line.
 *
 * three of the things this plugin does are the wrong shape for a browser, and
 * were only ever available there.
 *
 * REINDEXING and PURGING now queue themselves and finish on cron, which is right
 * for somebody who saved a schema and wants to get on with their day, and wrong
 * for a deploy script that needs to know the index is current before it runs the
 * next step. `wp schemapress reindex` runs the job to completion and exits when
 * it is done, which is what a script can wait on.
 *
 * EXPORTING was a button that hands a browser a file. That is the right shape
 * for a person and a useless one for CI: committing a schema, diffing one
 * against the last release, seeding a fresh checkout. Those are all `wp
 * schemapress export > schema.json`.
 *
 * registered only when WP-CLI is loaded, so nothing here costs a web request
 * anything.
 */
class Cli
{
    /**
     * registers the command, if there is a WP-CLI to register it with.
     *
     * @return void
     */
    public static function register()
    {
        if (!defined('WP_CLI') || !\WP_CLI) {
            return;
        }

        \WP_CLI::add_command('schemapress', self::class);
    }

    /**
     * Lists the collections on this site.
     *
     * ## EXAMPLES
     *
     *     wp schemapress list
     *
     * @subcommand list
     *
     * @param array $args
     * @param array $options
     *
     * @return void
     */
    public function list_($args, $options = [])
    {
        $rows = [];

        foreach (ContentType::all() as $type) {
            $rows[] = [
                'key' => $type['key'],
                'label' => $type['label'],
                'entries' => $type['entries'],
                'fields' => $type['fields'],
                'api' => ($type['publicApi']['list'] ? 'list ' : '')
                    . ($type['publicApi']['single'] ? 'single' : ''),
            ];
        }

        if (!$rows) {
            \WP_CLI::log('No collections yet.');

            return;
        }

        \WP_CLI\Utils\format_items(
            $options['format'] ?? 'table',
            $rows,
            ['key', 'label', 'entries', 'fields', 'api']
        );
    }

    /**
     * Rebuilds the filter index for one collection or all of them.
     *
     * Runs to completion rather than queuing, so a deploy step can wait on it.
     *
     * ## OPTIONS
     *
     * [--collection=<key>]
     * : The machine key of one collection. Omit for every collection.
     *
     * ## EXAMPLES
     *
     *     wp schemapress reindex
     *     wp schemapress reindex --collection=team_member
     *
     * @param array $args
     * @param array $options
     *
     * @return void
     */
    public function reindex($args, $options = [])
    {
        $total = 0;

        foreach (self::chosen($options) as $type) {
            // queued and then immediately finished, rather than reindexed
            // inline, so the collection goes through exactly the code path a
            // cron run would — a command that exercised a different path would
            // be testing something nobody else runs
            $id = Batch::queue('reindex', ['type_id' => $type['id']]);
            $done = Batch::finish($id);
            $total += $done;

            \WP_CLI::log(sprintf('%s: %d entries reindexed.', $type['key'], $done));
        }

        \WP_CLI::success(sprintf('%d entries reindexed.', $total));
    }

    /**
     * Gives every entry the public identifier the API addresses it by.
     *
     * Only entries that predate having one need this; it is what the upgrade
     * routine queues, and running it here is how a large site gets it over with
     * on its own schedule rather than on a visitor's request.
     *
     * ## OPTIONS
     *
     * [--collection=<key>]
     * : The machine key of one collection. Omit for every collection.
     *
     * @param array $args
     * @param array $options
     *
     * @return void
     */
    public function backfill($args, $options = [])
    {
        $total = 0;

        foreach (self::chosen($options) as $type) {
            $id = Batch::queue('backfill', ['type_id' => $type['id']]);
            $total += Batch::finish($id);
        }

        \WP_CLI::success(sprintf('%d entries checked.', $total));
    }

    /**
     * Runs whatever long work is queued, to completion.
     *
     * ## EXAMPLES
     *
     *     wp schemapress jobs
     *     wp schemapress jobs --run
     *
     * ## OPTIONS
     *
     * [--run]
     * : Drain the queue instead of only reporting it.
     *
     * @param array $args
     * @param array $options
     *
     * @return void
     */
    public function jobs($args, $options = [])
    {
        $queued = Batch::status();

        if (!$queued) {
            \WP_CLI::success('Nothing queued.');

            return;
        }

        foreach ($queued as $job) {
            \WP_CLI::log(sprintf('%s — %d of %d', $job['id'], $job['done'], $job['total']));
        }

        if (empty($options['run'])) {
            \WP_CLI::log('Pass --run to work through them now.');

            return;
        }

        foreach ($queued as $job) {
            Batch::finish($job['id']);
        }

        \WP_CLI::success('Queue drained.');
    }

    /**
     * Writes the site's collections to stdout as a portable JSON document.
     *
     * ## OPTIONS
     *
     * [--collection=<key>]
     * : Export one collection rather than all of them.
     *
     * [--entries]
     * : Include the content, not only the shape of it.
     *
     * ## EXAMPLES
     *
     *     wp schemapress export > schema.json
     *     wp schemapress export --collection=team_member --entries > team.json
     *
     * @param array $args
     * @param array $options
     *
     * @return void
     */
    public function export($args, $options = [])
    {
        $ids = array_column(self::chosen($options), 'id');
        $payload = Portability::export($ids, ['entries' => !empty($options['entries'])]);

        if (is_wp_error($payload)) {
            \WP_CLI::error($payload->get_error_message());
        }

        // to stdout rather than to a file, so it composes: a shell redirect, a
        // pipe into jq, a commit. WP_CLI::log would prefix and wrap it
        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * Reads a portable JSON document back in.
     *
     * ## OPTIONS
     *
     * <file>
     * : The document to read, or - for stdin.
     *
     * [--mode=<mode>]
     * : merge keeps fields the file does not mention; replace makes the
     * collection exactly what the file says. Default: merge.
     * ---
     * default: merge
     * options:
     *   - merge
     *   - replace
     * ---
     *
     * [--entries]
     * : Restore the content too, where the file carries it.
     *
     * ## EXAMPLES
     *
     *     wp schemapress import schema.json
     *     cat schema.json | wp schemapress import -
     *
     * @param array $args
     * @param array $options
     *
     * @return void
     */
    public function import($args, $options = [])
    {
        $file = $args[0] ?? '';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- a path the operator typed at their own shell, or stdin. WP_Filesystem is for a web request that may not own the disk; this is WP-CLI, which does. The silence is deliberate: an unreadable file is reported by the check below as a sentence rather than as a PHP warning in the middle of the output.
        $raw = $file === '-' ? file_get_contents('php://stdin') : @file_get_contents($file);

        if ($raw === false) {
            \WP_CLI::error(sprintf('Could not read %s.', $file));
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            \WP_CLI::error('That file is not valid JSON.');
        }

        $report = Portability::import($decoded, [
            'mode' => $options['mode'] ?? 'merge',
            'entries' => !empty($options['entries']),
        ]);

        if (is_wp_error($report)) {
            \WP_CLI::error($report->get_error_message());
        }

        foreach ($report['collections'] as $collection) {
            \WP_CLI::log(sprintf(
                '%s %s (%d entries)',
                $collection['created'] ? 'Created' : 'Updated',
                $collection['key'],
                $collection['entries']
            ));

            foreach ($collection['skipped'] as $skipped) {
                \WP_CLI::log('  skipped: ' . $skipped['message']);
            }
        }

        // warnings rather than errors: the import happened, and these are the
        // parts of it somebody should look at before they forget it did
        foreach ($report['warnings'] as $warning) {
            \WP_CLI::warning($warning);
        }

        \WP_CLI::success(sprintf(
            '%d collections, %d components, %d entries, %d skipped. Media: %d matched, %d missing.',
            count($report['collections']),
            $report['components'],
            $report['entries'],
            $report['skipped'],
            $report['media']['matched'],
            $report['media']['missing']
        ));
    }

    /**
     * Empties one collection's trash, permanently.
     *
     * ## OPTIONS
     *
     * --collection=<key>
     * : The machine key of the collection.
     *
     * [--yes]
     * : Do not ask.
     *
     * @param array $args
     * @param array $options
     *
     * @return void
     */
    public function trash($args, $options = [])
    {
        $chosen = self::chosen($options);

        if (count($chosen) !== 1) {
            \WP_CLI::error('Name one collection with --collection.');
        }

        $type = $chosen[0];

        \WP_CLI::confirm(
            sprintf('Permanently erase everything in %s\'s trash?', $type['key']),
            $options
        );

        \WP_CLI::success(sprintf('%d entries erased.', Entries::emptyTrash($type['id'])));
    }

    /**
     * the collections a command was pointed at.
     *
     * @param array $options
     *
     * @return array
     */
    private static function chosen(array $options)
    {
        $key = isset($options['collection']) ? sanitize_key($options['collection']) : '';

        if ($key === '') {
            return ContentType::all();
        }

        foreach (ContentType::all() as $type) {
            if ($type['key'] === $key || $type['plural'] === $key) {
                return [$type];
            }
        }

        \WP_CLI::error(sprintf('No collection called %s.', $key));
    }
}
