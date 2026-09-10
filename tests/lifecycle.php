<?php
/**
 * Checks for everything an entry can have happen to it after it is saved, and
 * for the machinery around collections that is not about their shape.
 *
 * The other suite is about definitions and delivery — what a collection is and
 * what comes back out of it. This one is about the operations: the trash and
 * coming back from it, copying an entry, two people saving at once, moving a
 * collection between installations, and the work that is too big to do inside
 * one request.
 *
 * Run: php tests/lifecycle.php
 *
 * @package SchemaPress
 */

require __DIR__ . '/stubs.php';

use SchemaPress\Batch;
use SchemaPress\Capabilities;
use SchemaPress\ContentSanitizer;
use SchemaPress\ContentType;
use SchemaPress\Entries;
use SchemaPress\Index;
use SchemaPress\Portability;
use SchemaPress\Resolver;
use SchemaPress\SchemaModel;
use SchemaPress\SchemaRepository;
use SchemaPress\Settings;

$passed = 0;
$failed = 0;

/**
 * Asserts two values match.
 *
 * @param string $label
 * @param mixed  $expected
 * @param mixed  $actual
 *
 * @return void
 */
function check($label, $expected, $actual)
{
    global $passed, $failed;

    if ($expected === $actual) {
        $passed++;
        echo "  ok    {$label}\n";
        return;
    }

    $failed++;
    echo "  FAIL  {$label}\n";
    echo '        expected: ' . json_encode($expected) . "\n";
    echo '        actual:   ' . json_encode($actual) . "\n";
}

/**
 * A collection with one required name and one plain field.
 *
 * @param array $settings
 *
 * @return integer
 */
function lifecycle_type(array $settings = [])
{
    return sp_test_type('Team Member', [
        ['label' => 'Full Name', 'type' => 'text', 'required' => true],
        ['label' => 'Role', 'type' => 'text'],
    ], array_merge(['titleField' => 'full_name'], $settings));
}

// --- the version header ------------------------------------------------------

echo "The plugin header\n";

$header = file_get_contents(SCHEMAPRESS_PATH . 'schemapress.php');

preg_match('/^\s*\*\s*Version:\s*(\S+)/m', $header, $declared);
preg_match("/define\('SCHEMAPRESS_VERSION', '([^']+)'\)/", $header, $constant);

// WordPress reads the header with a regular expression before any PHP runs, so
// the version cannot only be a constant — and the plugin cannot read its own
// header without WordPress. two copies, and this is the cheapest place to catch
// the one that gets forgotten
check('the header and the constant agree', $declared[1] ?? 'header', $constant[1] ?? 'constant');

check(
    'the text domain has somewhere to load from',
    true,
    (bool) preg_match('/^\s*\*\s*Domain Path:\s*\/languages/m', $header)
);

check(
    'and something loads it',
    true,
    strpos($header, 'load_plugin_textdomain') !== false
);

// --- the trash ---------------------------------------------------------------

echo "\nThe trash\n";

sp_test_reset();
$type = lifecycle_type();

$entry = Entries::save($type, null, [
    'values' => ['full_name' => 'Ada Lovelace', 'role' => 'Engineer'],
    'publish' => true,
]);

$uid = $entry['id'];

check('an entry starts out of the trash', 0, Entries::trashed($type)['total']);
check('and is published', true, $entry['isPublished']);

Entries::delete($type, $uid);

check('trashing takes it out of the listing', 0, Entries::all($type, ['view' => Entries::DRAFT])['total']);
check('and puts it in the trash', 1, Entries::trashed($type)['total']);
check('where it is readable', 'Ada Lovelace', Entries::trashed($type)['entries'][0]['title']);
check('and says when it went', true, Entries::trashed($type)['entries'][0]['trashedAt'] !== '');

// the index is what a filter reads, and a trashed entry has no answer to give.
// leaving the rows behind was only ever harmless because every read ALSO filters
// on post status — which is a second thing that has to stay true rather than a
// fact
check('trashing clears the published index', '', get_post_meta(
    $GLOBALS['wp_posts'][array_key_first($GLOBALS['wp_posts'])]->ID,
    Index::key('full_name'),
    true
));

$restored = Entries::restore($type, $uid);

check('restoring returns the entry', 'Ada Lovelace', $restored['title']);

// the one that matters, and it is only meaningful because the stub now behaves
// the way WordPress 5.6+ does — see the fidelity check below. before that, the
// stub restored the previous status on its own and this passed without the
// plugin doing anything
check('to the status it had, not the draft WordPress defaults to', true, $restored['isPublished']);

// the override is scoped to that one call. a site relying on WordPress's
// draft-on-restore rule for its own posts has to keep it
check(
    'and the override does not outlive the call',
    [],
    array_merge(...array_values($GLOBALS['wp_filters']['wp_untrash_post_status'] ?? [[]]))
);
check('the trash is empty again', 0, Entries::trashed($type)['total']);
check('and the listing has it back', 1, Entries::all($type, ['view' => Entries::DRAFT])['total']);

// restoring rebuilds from what is stored rather than assuming, because
// wp_untrash_post has not always restored to the status the post had
check(
    'and its index is back, so filters find it',
    1,
    Entries::all($type, [
        'view' => Entries::PUBLISHED,
        'spec' => ['filters' => ['role' => ['$eq' => 'Engineer']]],
    ])['total']
);

// the stub has to be as unhelpful as WordPress, or the restore checks above
// prove nothing. untrash a published post WITHOUT going through the plugin and
// it should come back a draft — that is the default the plugin is overriding
$raw = Entries::save($type, null, ['values' => ['full_name' => 'Grace'], 'publish' => true]);
Entries::delete($type, $raw['id']);

$rawPost = null;

foreach ($GLOBALS['wp_posts'] as $candidate) {
    if (get_post_meta($candidate->ID, Entries::META_UID, true) === $raw['id']) {
        $rawPost = $candidate;
    }
}

wp_untrash_post($rawPost->ID);

check('fidelity: WordPress on its own restores a published post as a draft', 'draft', $rawPost->post_status);

echo "\nErasing\n";

sp_test_reset();
$type = lifecycle_type();

$one = Entries::save($type, null, ['values' => ['full_name' => 'Ada'], 'publish' => true]);
$two = Entries::save($type, null, ['values' => ['full_name' => 'Grace'], 'publish' => true]);

check('an entry not in the trash cannot be erased', false, Entries::purge($type, $one['id']));

Entries::delete($type, $one['id']);

check('one in the trash can be', true, Entries::purge($type, $one['id']));
check('and is gone for good', 0, Entries::trashed($type)['total']);
check('leaving the others alone', 1, Entries::all($type, ['view' => Entries::DRAFT])['total']);

Entries::delete($type, $two['id']);
check('emptying the trash reports what it erased', 1, Entries::emptyTrash($type));
check('and empties it', 0, Entries::trashed($type)['total']);

// EMPTYING HAS NO CURSOR and cannot have one: it reads the front of the trash
// and deletes what it read, so the next pass sees what is left. that terminates
// only while something is actually going — and `pre_delete_post` lets any other
// plugin veto a deletion, at which point the same page is read and vetoed
// forever and the request hangs.
//
// IF THIS REGRESSES THE SUITE HANGS rather than failing, because there is no
// way to bound a loop from outside it. That is the honest shape of the bug.
sp_test_reset();
$type = lifecycle_type();

$stuck = Entries::save($type, null, ['values' => ['full_name' => 'Stuck'], 'publish' => true]);
$fine = Entries::save($type, null, ['values' => ['full_name' => 'Fine'], 'publish' => true]);

Entries::delete($type, $stuck['id']);
Entries::delete($type, $fine['id']);

foreach ($GLOBALS['wp_posts'] as $candidate) {
    if (get_post_meta($candidate->ID, Entries::META_UID, true) === $stuck['id']) {
        $GLOBALS['sp_test_undeletable'] = [$candidate->ID];
    }
}

check('a vetoed delete stops the sweep rather than looping on it', 1, Entries::emptyTrash($type));
check('and what would not go is still in the trash', 1, Entries::trashed($type)['total']);

$GLOBALS['sp_test_undeletable'] = [];

// --- copying -----------------------------------------------------------------

echo "\nDuplicating\n";

sp_test_reset();
$type = lifecycle_type();

$original = Entries::save($type, null, [
    'values' => ['full_name' => 'Ada Lovelace', 'role' => 'Engineer'],
    'publish' => true,
]);

$copy = Entries::duplicate($type, $original['id']);

check('the copy carries the values', 'Engineer', $copy['values']['role']);
check('and says it is one', 'Ada Lovelace (copy)', $copy['title']);
check('with an identifier of its own', false, $copy['id'] === $original['id']);

// duplicating a live entry to get a starting point should not put the result of
// that on the site the moment it exists
check('a copy of a published entry is a draft', false, $copy['isPublished']);
check('and the original is untouched', true, Entries::get($type, $original['id'])['isPublished']);
check('the collection now holds two', 2, Entries::all($type, ['view' => Entries::DRAFT])['total']);

sp_test_reset();
$unique = sp_test_type('Member', [
    ['label' => 'Email', 'type' => 'email', 'unique' => true],
], ['titleField' => '']);

Entries::save($unique, null, ['values' => ['email' => 'ada@example.com'], 'publish' => true]);
$refused = Entries::duplicate($unique, Entries::all($unique, ['view' => Entries::DRAFT])['entries'][0]['id']);

check('a copy that breaks a unique field is refused', true, is_wp_error($refused));
// and leaves nothing behind, the same way a rejected save does
check('and leaves no half-made entry', 1, Entries::all($unique, ['view' => Entries::DRAFT])['total']);

// --- two people at once ------------------------------------------------------

echo "\nConflicts\n";

sp_test_reset();
$type = lifecycle_type();

$entry = Entries::save($type, null, ['values' => ['full_name' => 'Ada'], 'publish' => true]);
$loaded = $entry['modified'];

$fine = Entries::save($type, $entry['id'], [
    'values' => ['full_name' => 'Ada Lovelace'],
    'expectedModified' => $loaded,
]);

check('a save against the current version is applied', false, is_wp_error($fine));

$stale = Entries::save($type, $entry['id'], [
    'values' => ['full_name' => 'Somebody Else'],
    'expectedModified' => '2020-01-01T00:00:00Z',
]);

check('a save against an older one is refused', true, is_wp_error($stale));
check('with a status a client can act on', 409, $stale->get_error_data()['status']);
check(
    'and the first save is still there',
    'Ada Lovelace',
    Entries::get($type, $entry['id'], 0, Entries::DRAFT)['values']['full_name']
);

// the check is opt-in, because a save without one is what every existing caller
// does — an importer, a migration, a script — and turning those into failures
// would be a worse bug than the one this fixes
$quiet = Entries::save($type, $entry['id'], ['values' => ['full_name' => 'No Version Sent']]);

check('a save that states no version still works', false, is_wp_error($quiet));

echo "\nTwo people on one schema\n";

// THE STAMP HAS TO MOVE, and for a release it did not. a definition is post
// META, and writing meta does not touch the post row — so the check below was
// comparing a version that only ever changed when somebody renamed the
// collection. it passed every test written for it and refused nothing.
sp_test_reset();
require_once SCHEMAPRESS_PATH . 'classes/class-rest.php';

$type = lifecycle_type();
$was = ContentType::get($type)['modified'];

SchemaRepository::saveDefinition($type, [
    'fields' => [
        ['label' => 'Full Name', 'type' => 'text', 'required' => true],
        ['label' => 'Role', 'type' => 'text'],
        ['label' => 'Bio', 'type' => 'textarea'],
    ],
    'settings' => ['titleField' => 'full_name'],
]);
ContentType::flush();

$now = ContentType::get($type)['modified'];

check('saving a definition moves the collection version', true, $now !== $was);

// a save that stores what is already stored is not a version anybody has to
// reload past — bumping on every write would refuse the next honest save
SchemaRepository::saveDefinition($type, SchemaRepository::definition($type));
ContentType::flush();

check('storing the same definition again does not', $now, ContentType::get($type)['modified']);

$rest = new SchemaPress\Rest();

$stale = $rest->updateType(new WP_REST_Request(['id' => $type, '__json' => [
    'expectedModified' => '2020-01-01T00:00:00Z',
    'definition' => ['fields' => [], 'settings' => []],
]]));

check('a schema save against an older version is refused', true, is_wp_error($stale));
check('with a status a client can act on', 409, $stale->get_error_data()['status']);
check(
    'and the fields it would have replaced are still there',
    3,
    count(SchemaRepository::definition($type)['fields'])
);

$fresh = $rest->updateType(new WP_REST_Request(['id' => $type, '__json' => [
    'expectedModified' => ContentType::get($type)['modified'],
    'definition' => ['fields' => [['label' => 'Full Name', 'type' => 'text']], 'settings' => []],
]]));

check('a schema save against the current one is applied', false, is_wp_error($fresh));

echo "\nTwo people on one component\n";

// a component is imported by COPY, so a field lost here is lost again in every
// collection that imports it afterwards
sp_test_reset();

$component = wp_insert_post([
    'post_type' => SchemaPress\Component::POST_TYPE,
    'post_title' => 'Address',
    'post_status' => 'publish',
]);

SchemaRepository::saveDefinition($component, [
    'fields' => [
        ['label' => 'Street', 'type' => 'text'],
        ['label' => 'City', 'type' => 'text'],
    ],
]);

$rest = new SchemaPress\Rest();

check('a component carries the version it was read at', true, SchemaPress\Component::get($component)['modified'] !== '');

$stale = $rest->updateComponent(new WP_REST_Request(['id' => $component, '__json' => [
    'expectedModified' => '2020-01-01T00:00:00Z',
    'fields' => [['label' => 'Street', 'type' => 'text']],
]]));

check('a component save against an older version is refused', true, is_wp_error($stale));
check('with the same status', 409, $stale->get_error_data()['status']);
check(
    'and says it is a component rather than a collection',
    true,
    strpos($stale->get_error_message(), 'component') !== false
);
check(
    'and the field it would have dropped is still there',
    2,
    count(SchemaPress\Component::get($component)['fields'])
);

$fresh = $rest->updateComponent(new WP_REST_Request(['id' => $component, '__json' => [
    'expectedModified' => SchemaPress\Component::get($component)['modified'],
    'fields' => [['label' => 'Street', 'type' => 'text']],
]]));

check('a component save against the current one is applied', false, is_wp_error($fresh));
check('and stores what it was sent', 1, count(SchemaPress\Component::get($component)['fields']));

// the check is opt-in here too, for the importer and the seed script
$quiet = $rest->updateComponent(new WP_REST_Request(['id' => $component, '__json' => [
    'fields' => [['label' => 'Street', 'type' => 'text'], ['label' => 'City', 'type' => 'text']],
]]));

check('a component save that states no version still works', false, is_wp_error($quiet));

// --- addresses ---------------------------------------------------------------

echo "\nTaking up a slug field later\n";

// A COLLECTION USUALLY GROWS ITS SLUG FIELD AFTER IT HAS ENTRIES. The address
// freezes on publish, so every entry published before the setting existed kept
// its uuid and no amount of re-saving moved it — the setting changed and
// nothing anywhere acted on it or said why.
sp_test_reset();

$type = sp_test_type('Team Member', [
    ['label' => 'Full Name', 'type' => 'text', 'required' => true],
], ['titleField' => 'full_name', 'slugField' => '']);

$ada = Entries::save($type, null, ['values' => ['full_name' => 'Ada Lovelace'], 'publish' => true]);
$katherine = Entries::save($type, null, ['values' => ['full_name' => 'Katherine Johnson']]);

check('with no slug field an entry is addressed by its id', $ada['id'], $ada['slug']);

// the only thing that happens: the setting changes. no entry is re-saved
SchemaRepository::saveSettings($type, ['slugField' => 'full_name']);
ContentType::flush();

check(
    'taking one up re-addresses a published entry that had none',
    'ada-lovelace',
    Entries::get($type, $ada['id'], 0, Entries::DRAFT)['slug']
);

check(
    'and a draft',
    'katherine-johnson',
    Entries::get($type, $katherine['id'], 0, Entries::DRAFT)['slug']
);

// two people of the same name are two addresses, which is WordPress's own rule
// for post_name and the reason every comparison in here is a prefix
$second = Entries::save($type, null, ['values' => ['full_name' => 'Ada Lovelace'], 'publish' => true]);

check('a second entry of the same name is uniquified', 'ada-lovelace-2', $second['slug']);

// the sweep only ever looks at entries still carrying a uuid, so running it
// again is not a second chance to rewrite somebody's address
SchemaRepository::saveSettings($type, ['slugField' => '']);
ContentType::flush();
SchemaRepository::saveSettings($type, ['slugField' => 'full_name']);
ContentType::flush();

check(
    'sweeping again leaves a real address alone',
    'ada-lovelace-2',
    Entries::get($type, $second['id'], 0, Entries::DRAFT)['slug']
);

// THE FREEZE IS STILL THE FREEZE. giving an entry its first address is not a
// rename, and a rename is what the freeze exists to refuse — somebody has
// linked to this one
Entries::save($type, $ada['id'], ['values' => ['full_name' => 'Ada Byron'], 'publish' => true]);

check(
    'renaming a published entry still does not move it',
    'ada-lovelace',
    Entries::get($type, $ada['id'], 0, Entries::DRAFT)['slug']
);

// a collection too big to walk inside the request queues the sweep instead, the
// same way a reindex does — so the sweep has to survive being run from the queue
// rather than only from the settings save that asked for it
sp_test_reset();

$big = sp_test_type('Member', [
    ['label' => 'Full Name', 'type' => 'text'],
], ['titleField' => 'full_name', 'slugField' => '']);

$one = Entries::save($big, null, ['values' => ['full_name' => 'Ada Lovelace'], 'publish' => true]);

check('an entry with no slug field is on its uuid', $one['id'], $one['slug']);

$job = Batch::queue('reslug', ['type_id' => $big]);

check('the sweep is a job the queue knows', 'reslug', Batch::status()[0]['job']);

// the setting is written without going through saveSettings, so nothing but
// the job itself can be what re-addresses the entry below
SchemaRepository::saveDefinition($big, [
    'fields' => [['label' => 'Full Name', 'type' => 'text', 'key' => 'full_name']],
    'settings' => ['titleField' => 'full_name', 'slugField' => 'full_name'],
]);

Batch::finish($job);

check(
    'running it re-addresses the collection',
    'ada-lovelace',
    Entries::get($big, $one['id'], 0, Entries::DRAFT)['slug']
);

check('and takes itself off the queue', [], Batch::status());

// --- the new field types -----------------------------------------------------

echo "\nGalleries, colors and JSON\n";

sp_test_reset();

$GLOBALS['wp_posts'][900] = (object) ['ID' => 900, 'post_type' => 'attachment', 'post_status' => 'publish', 'post_title' => 'One', 'post_name' => 'one'];
$GLOBALS['wp_posts'][901] = (object) ['ID' => 901, 'post_type' => 'attachment', 'post_status' => 'publish', 'post_title' => 'Two', 'post_name' => 'two'];

$fields = SchemaModel::normalize([
    'fields' => [
        ['label' => 'Photos', 'type' => 'gallery', 'config' => ['max' => 2]],
        ['label' => 'Brand', 'type' => 'color'],
        ['label' => 'Payload', 'type' => 'json'],
    ],
])['fields'];

$values = ContentSanitizer::values([
    'photos' => [900, 901, 900, 999],
    'brand' => '#ff0000',
    'payload' => '{"a":1,"b":[2,3]}',
], $fields);

check('a gallery keeps the attachments it is given', [900, 901], $values['photos']);
check('a color keeps a hex value', '#ff0000', $values['brand']);
check('JSON is stored decoded, not as a string', ['a' => 1, 'b' => [2, 3]], $values['payload']);

$rejected = ContentSanitizer::values([
    'photos' => [999],
    'brand' => 'red',
    'payload' => '{not json',
], $fields);

check('a gallery drops what is not an image', [], $rejected['photos']);
check('a color that is not one is stored as nothing', '', $rejected['brand']);
check('and neither is invalid JSON', null, $rejected['payload']);

$resolved = Resolver::values($values, $fields);

check('a gallery resolves to its attachments', 2, count($resolved['photos']));
check('each of which is expanded', 900, $resolved['photos'][0]['id']);

check('a color can be filtered on', true, Index::indexable($fields[1]));
check('a gallery cannot', false, Index::indexable($fields[0]));
check('and neither can JSON', false, Index::indexable($fields[2]));

// --- moving a collection -----------------------------------------------------

echo "\nExport and import\n";

sp_test_reset();
$type = lifecycle_type(['publicApi' => ['list' => true, 'single' => true]]);

Entries::save($type, null, [
    'values' => ['full_name' => 'Ada Lovelace', 'role' => 'Engineer'],
    'publish' => true,
]);

$export = Portability::export([], ['entries' => true]);

check('an export names its format', Portability::FORMAT, $export['schemapress']);
check('and carries the collection', 1, count($export['collections']));
check('by its machine key, which is the identity', 'team_member', $export['collections'][0]['key']);
check('with its fields', 2, count($export['collections'][0]['definition']['fields']));
check('and its entries when asked', 1, count($export['collections'][0]['entries']));
check(
    'as stored values rather than resolved ones',
    'Ada Lovelace',
    $export['collections'][0]['entries'][0]['values']['full_name']
);

// a fresh site, reading the file
sp_test_reset();

$report = Portability::import($export, ['entries' => true]);

check('an import creates the collection', 1, count($report['collections']));
check('and says so', true, $report['collections'][0]['created']);
check('with the key from the file', 'team_member', ContentType::all()[0]['key']);
check('so the post type matches the one it came from', 'spc_team_member', ContentType::all()[0]['postType']);
check('the fields came too', 2, count(SchemaRepository::definition(ContentType::all()[0]['id'])['fields']));
check('and the entries', 1, $report['entries']);

$imported = ContentType::all()[0]['id'];

check(
    'which kept their identifiers, so a published link still works',
    'Ada Lovelace',
    Entries::get($imported, $export['collections'][0]['entries'][0]['id'], 0, Entries::DRAFT)['values']['full_name']
);

// AN IMPORT IS NOT A DECISION TO PUBLISH. a staging export whose collections
// were open to the internet must not open them here
check(
    'and did not publish the collection to the internet',
    ['list' => false, 'single' => false],
    SchemaRepository::definition($imported)['settings']['publicApi']
);

// re-importing the same file updates rather than duplicating
$again = Portability::import($export, ['entries' => true]);

check('re-importing does not make a second collection', false, $again['collections'][0]['created']);
check('nor a second copy of each entry', 1, Entries::all($imported, ['view' => Entries::DRAFT])['total']);

echo "\nMerge and replace\n";

sp_test_reset();
$type = sp_test_type('Team Member', [
    ['label' => 'Full Name', 'type' => 'text'],
    ['label' => 'Bio', 'type' => 'textarea'],
], ['titleField' => 'full_name']);

// a file that knows about one of the two fields
$partial = [
    'schemapress' => Portability::FORMAT,
    'collections' => [[
        'key' => 'team_member',
        'label' => 'Team Member',
        'definition' => ['fields' => [['key' => 'full_name', 'label' => 'Name', 'type' => 'text']]],
    ]],
];

Portability::import($partial, ['mode' => 'merge']);

$merged = SchemaRepository::definition($type)['fields'];

check('merge keeps a field the file does not mention', 2, count($merged));
check('and applies the one it does', 'Name', $merged[0]['label']);

Portability::import($partial, ['mode' => 'replace']);

check('replace makes it exactly what the file says', 1, count(SchemaRepository::definition($type)['fields']));

echo "\nRefusals\n";

check(
    'a file that is not an export is refused',
    'schemapress_not_an_export',
    Portability::import(['hello' => true])->get_error_code()
);

check(
    'and one from a newer version',
    'schemapress_export_too_new',
    Portability::import(['schemapress' => Portability::FORMAT + 1])->get_error_code()
);

// --- importing next to a site's own content ----------------------------------

echo "\nImporting into a site with its own content\n";

sp_test_reset();

// what a real site already has: a page, a post, and a media library — one of
// whose ids happens to be the id the staging site used for a different image
$GLOBALS['wp_posts'][500] = (object) ['ID' => 500, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'About', 'post_name' => 'about', 'post_content' => 'Our story'];
$GLOBALS['wp_posts'][501] = (object) ['ID' => 501, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Hello', 'post_name' => 'hello', 'post_content' => 'First post'];

foreach ([
    900 => '2026/01/unrelated.jpg',
    901 => '2026/09/photo.jpg',
    902 => '2025/03/logo.png',
    903 => '2024/02/logo.png',
] as $attachment => $file) {
    $GLOBALS['wp_posts'][$attachment] = (object) [
        'ID' => $attachment, 'post_type' => 'attachment', 'post_status' => 'inherit',
        'post_title' => $file, 'post_name' => 'a' . $attachment,
    ];
    update_post_meta($attachment, '_wp_attached_file', $file);
}

$native = serialize([$GLOBALS['wp_posts'][500], $GLOBALS['wp_posts'][501], get_post_meta(500, '_edit_lock', true)]);

$foreign = [
    'schemapress' => Portability::FORMAT,
    'site' => 'https://staging.example.org',
    'collections' => [[
        'key' => 'team_member',
        'plural' => 'team_members',
        'label' => 'Team Member',
        'definition' => [
            'settings' => ['titleField' => 'full_name'],
            'fields' => [
                ['key' => 'full_name', 'label' => 'Full Name', 'type' => 'text'],
                ['key' => 'photo', 'label' => 'Photo', 'type' => 'image'],
                ['key' => 'shots', 'label' => 'Shots', 'type' => 'gallery'],
            ],
        ],
        'entries' => [[
            'id' => 'aaaaaaaa-0000-4000-8000-000000000001',
            'status' => 'publish',
            'title' => 'Ada',
            'values' => ['full_name' => 'Ada', 'photo' => 900, 'shots' => [900, 77, 902]],
        ]],
    ]],
    // on staging, 900 was photo.jpg — which is 901 here, while 900 here is
    // something else entirely. 77 is a file this site never had. and 902 was a
    // logo.png, of which this site has two
    'media' => [
        '900' => ['file' => '2026/09/photo.jpg'],
        '77' => ['file' => '2019/05/gone.jpg'],
        '902' => ['file' => '2023/07/logo.png'],
    ],
];

$report = Portability::import($foreign, ['entries' => true]);
$imported = ContentType::all()[0]['id'];
$ada = Entries::get($imported, 'aaaaaaaa-0000-4000-8000-000000000001', 0, Entries::DRAFT);

check(
    'the site\'s own page and post are untouched',
    $native,
    serialize([$GLOBALS['wp_posts'][500], $GLOBALS['wp_posts'][501], get_post_meta(500, '_edit_lock', true)])
);

check(
    'and nothing of theirs was created or removed',
    2,
    count(array_filter($GLOBALS['wp_posts'], function ($post) {
        return in_array($post->post_type, ['page', 'post'], true);
    }))
);

// the bug this replaced: the sanitizer accepts any id that is an image HERE,
// so 900 would have been stored and shown as this site's unrelated.jpg
check('an image is matched by its file, not trusted by its id', 901, $ada['values']['photo']);
check('a gallery keeps the images it can find', [901], $ada['values']['shots']);
check('and drops, rather than guesses at, the rest', ['matched' => 1, 'missing' => 2], $report['media']);
check('which the report says', true, count($report['warnings']) > 0);

$export = Portability::export([], ['entries' => true]);

check('an export holds none of the site\'s own content', false, strpos(json_encode($export), 'Our story') !== false);
check('and describes each image by its file', '2026/09/photo.jpg', $export['media']['901']['file']);

// the round trip on the same site is the case that has to be exact
Portability::import($export, ['entries' => true]);

check(
    'reading it back into the same site keeps the same image',
    901,
    Entries::get($imported, 'aaaaaaaa-0000-4000-8000-000000000001', 0, Entries::DRAFT)['values']['photo']
);

$bare = $foreign;
unset($bare['media']);
$bare['collections'][0]['entries'][0]['id'] = 'aaaaaaaa-0000-4000-8000-000000000002';

Portability::import($bare, ['entries' => true]);

check(
    'with no manifest, an id from another site is dropped',
    null,
    Entries::get($imported, 'aaaaaaaa-0000-4000-8000-000000000002', 0, Entries::DRAFT)['values']['photo']
);

$bare['site'] = home_url();
$bare['collections'][0]['entries'][0]['id'] = 'aaaaaaaa-0000-4000-8000-000000000003';
$bare['collections'][0]['entries'][0]['values']['photo'] = 901;

Portability::import($bare, ['entries' => true]);

check(
    'and one from this very site is kept',
    901,
    Entries::get($imported, 'aaaaaaaa-0000-4000-8000-000000000003', 0, Entries::DRAFT)['values']['photo']
);

echo "\nA collection's own site settings survive an import\n";

sp_test_reset();
$owned = sp_test_type('Grant', [['label' => 'Title', 'type' => 'text']], [
    'editRoles' => ['finance'],
    'draftAndPublish' => true,
]);

$loose = [
    'schemapress' => Portability::FORMAT,
    'collections' => [[
        'key' => 'grant',
        'label' => 'Grant',
        'definition' => [
            'fields' => [['key' => 'title', 'label' => 'Title', 'type' => 'text']],
            'settings' => [
                'editRoles' => [],
                'draftAndPublish' => false,
                'publicApi' => ['list' => true, 'single' => true],
            ],
        ],
    ]],
];

Portability::import($loose, ['mode' => 'merge']);
$settings = SchemaRepository::definition($owned)['settings'];

check('merge keeps who may edit it', ['finance'], $settings['editRoles']);
check('and whether it has drafts', true, $settings['draftAndPublish']);
check('and does not publish it', ['list' => false, 'single' => false], $settings['publicApi']);

Portability::import($loose, ['mode' => 'replace']);
$settings = SchemaRepository::definition($owned)['settings'];

check('replace still keeps who may edit it', ['finance'], $settings['editRoles']);
check('and still does not publish it', ['list' => false, 'single' => false], $settings['publicApi']);

echo "\nComponents with the same name\n";

sp_test_reset();
$address = wp_insert_post(['post_type' => SchemaPress\Component::POST_TYPE, 'post_title' => 'Address', 'post_status' => 'publish']);
SchemaRepository::saveDefinition($address, ['fields' => [
    ['key' => 'street', 'label' => 'Street', 'type' => 'text'],
    ['key' => 'county', 'label' => 'County', 'type' => 'text'],
]]);

Portability::import([
    'schemapress' => Portability::FORMAT,
    'components' => [[
        'label' => 'Address',
        'fields' => [
            ['key' => 'street', 'label' => 'Street line', 'type' => 'text'],
            ['key' => 'city', 'label' => 'City', 'type' => 'text'],
        ],
    ]],
]);

check(
    'merge keeps a field only this site\'s component has',
    ['street', 'city', 'county'],
    array_column(SchemaRepository::definition($address)['fields'], 'key')
);

check(
    'and updates the one rather than making a second',
    1,
    count(array_filter($GLOBALS['wp_posts'], function ($post) {
        return $post->post_type === SchemaPress\Component::POST_TYPE;
    }))
);

echo "\nNothing is written until the whole file checks out\n";

sp_test_reset();
sp_test_type('Team Member', [['label' => 'Name', 'type' => 'text']]);
$GLOBALS['wp_post_types']['spc_event'] = true;
$rows = count($GLOBALS['wp_posts']);

$refusal = function (array $collections) {
    $result = Portability::import(['schemapress' => Portability::FORMAT, 'collections' => $collections]);

    return is_wp_error($result) ? $result->get_error_code() : 'imported';
};

check(
    'a key too long for a post type is refused',
    'schemapress_import_key_too_long',
    $refusal([
        ['key' => 'first', 'label' => 'First'],
        ['key' => 'a_key_far_too_long_for_wordpress', 'label' => 'Long'],
    ])
);

// the first collection in that file was fine, and would have been created by
// the version that wrote as it read
check('and the valid collection before it was not created', $rows, count($GLOBALS['wp_posts']));

check(
    'a collection named twice is refused',
    'schemapress_import_duplicate',
    $refusal([['key' => 'news', 'label' => 'News'], ['key' => 'news', 'label' => 'News']])
);

check(
    'one that would answer on another collection\'s address is refused',
    'schemapress_import_ambiguous',
    $refusal([['key' => 'team_members', 'label' => 'Team Members']])
);

check(
    'one whose post type another plugin already uses is refused',
    'schemapress_import_post_type_taken',
    $refusal([['key' => 'event', 'label' => 'Event']])
);

$staff = Portability::import([
    'schemapress' => Portability::FORMAT,
    'collections' => [['key' => 'staff', 'plural' => 'team_members', 'label' => 'Staff']],
]);

check(
    'a plural another collection answers on is not claimed',
    false,
    ContentType::get($staff['collections'][0]['id'])['plural'] === 'team_members'
);

echo "\nEntries this site has already dealt with\n";

sp_test_reset();
$type = lifecycle_type();
$gone = Entries::save($type, null, ['values' => ['full_name' => 'Ada'], 'publish' => true]);
$file = Portability::export([], ['entries' => true]);

Entries::delete($type, $gone['id']);

$again = Portability::import($file, ['entries' => true]);

check('an entry in this site\'s trash is not brought back', 0, $again['entries']);
check('it is reported as skipped', 1, $again['skipped']);
check('and stays in the trash, alone', 1, Entries::trashed($type)['total']);
check('with nothing re-created beside it', 0, Entries::all($type, ['view' => Entries::DRAFT])['total']);

echo "\nWarnings\n";

sp_test_reset();
sp_test_type('Team Member', [['label' => 'Role', 'type' => 'text']]);

$warned = Portability::import([
    'schemapress' => Portability::FORMAT,
    'collections' => [[
        'key' => 'team_member',
        'label' => 'Team Member',
        'definition' => ['fields' => [
            ['key' => 'role', 'label' => 'Role', 'type' => 'select', 'config' => ['options' => [['value' => 'eng']]]],
            ['key' => 'rating', 'label' => 'Rating', 'type' => 'stars'],
        ]],
    ]],
]);

check('a changed type and a type this site lacks are both reported', 2, count($warned['warnings']));

// --- work too big for one request --------------------------------------------

echo "\nQueued work\n";

sp_test_reset();
$type = lifecycle_type();

// a collection under the threshold is done on the spot, because queuing a
// reindex of six entries would mean filters that do not work until cron fires
Entries::save($type, null, ['values' => ['full_name' => 'Ada'], 'publish' => true]);

check('a small collection reindexes inline', true, Batch::inline($type));
check('so nothing is queued', [], Batch::status());
check('and its index is current', 1, Entries::all($type, [
    'view' => Entries::PUBLISHED,
    'spec' => ['filters' => ['full_name' => ['$eq' => 'Ada']]],
])['total']);

$id = Batch::queue('reindex', ['type_id' => $type]);

check('a queued job reports itself', 1, count(Batch::status()));
check('naming what it is doing', 'reindex', Batch::status()[0]['job']);
check('and how much there is', 1, Batch::status()[0]['total']);

$ran = Batch::finish($id);

check('running it to completion handles every entry', 1, $ran);
check('and takes it off the queue', [], Batch::status());

// queuing the same work twice is one job, because reindexing a collection twice
// produces the same index as reindexing it once
Batch::queue('reindex', ['type_id' => $type]);
Batch::queue('reindex', ['type_id' => $type]);

check('the same job queued twice is one job', 1, count(Batch::status()));

Batch::finish('reindex:' . $type);

echo "\nPurging a collection\n";

sp_test_reset();
$type = lifecycle_type();

Entries::save($type, null, ['values' => ['full_name' => 'Ada'], 'publish' => true]);
Entries::save($type, null, ['values' => ['full_name' => 'Grace'], 'publish' => true]);

$job = Batch::queue('purge', ['type_id' => $type, 'delete_type' => true]);
Batch::finish($job);

check('a purge erases every entry', 0, count($GLOBALS['wp_posts']));
check('and the collection with the last of them', null, ContentType::get($type));

// --- capabilities ------------------------------------------------------------

echo "\nCapabilities\n";

sp_test_reset();

Capabilities::grant();

check(
    'administrators may change the shape of content',
    true,
    isset(get_role('administrator')->capabilities[Capabilities::MANAGE])
);

check(
    'editors may fill entries in',
    true,
    isset(get_role('editor')->capabilities[Capabilities::EDIT])
);

// the division the two capabilities exist to draw, and the one a site gets by
// default rather than by reading the documentation
check(
    'but not restructure them',
    false,
    isset(get_role('editor')->capabilities[Capabilities::MANAGE])
);

check(
    'and both are the plugin\'s own, not borrowed',
    true,
    strpos(Capabilities::MANAGE, 'schemapress_') === 0
);

echo "\nPer-collection access\n";

sp_test_reset();
$open = lifecycle_type();
$owned = sp_test_type('Grant', [
    ['label' => 'Title', 'type' => 'text'],
], ['editRoles' => ['finance']]);

check('a collection is open by default', [], ContentType::get($open)['editRoles']);
check('and can name who owns it', ['finance'], ContentType::get($owned)['editRoles']);

// a role that does not exist on this site matches no user, which fails closed —
// the right direction for an import from somewhere that had one
check(
    'a role the site has not created is kept rather than dropped',
    ['finance'],
    SchemaModel::normalize(['settings' => ['editRoles' => ['finance']]])['settings']['editRoles']
);

$GLOBALS['wp_current_roles'] = ['editor'];
$GLOBALS['sp_test_caps'] = [Capabilities::EDIT => true];

check('an editor may edit an open collection', true, Capabilities::canEditCollection($open));
check('but not one owned by another role', false, Capabilities::canEditCollection($owned));

$GLOBALS['wp_current_roles'] = ['finance'];

check('the role that owns it may', true, Capabilities::canEditCollection($owned));

// somebody who can delete the collection outright is not meaningfully kept out
// of its entries
$GLOBALS['wp_current_roles'] = ['administrator'];
$GLOBALS['sp_test_caps'] = [Capabilities::EDIT => true, Capabilities::MANAGE => true];

check('and so may anyone who can reshape it', true, Capabilities::canEditCollection($owned));

$GLOBALS['sp_test_caps'] = null;
$GLOBALS['wp_current_roles'] = null;

// --- caching -----------------------------------------------------------------

echo "\nAPI caching\n";

sp_test_reset();
Settings::save(['restApi' => true]);

$type = lifecycle_type(['publicApi' => ['list' => true, 'single' => true]]);
Entries::save($type, null, ['values' => ['full_name' => 'Ada'], 'publish' => true]);

$api = new SchemaPress\Api();
$first = $api->list(new WP_REST_Request(['collection' => 'team-members'], []));
$etag = $first->get_headers()['ETag'] ?? '';

check('a response carries an ETag', true, $etag !== '');
check('and a cache directive', 'public, max-age=0, must-revalidate', $first->get_headers()['Cache-Control']);

$again = $api->list(new WP_REST_Request(['collection' => 'team-members'], [], ['if_none_match' => $etag]));

check('asking again with it gets a 304', 304, $again->get_status());
check('and no body to transfer', null, $again->get_data());

// the ETag is a hash of the body, so it changes when and only when the answer
// does — which is what makes it safe to key on
Entries::save($type, null, ['values' => ['full_name' => 'Grace'], 'publish' => true]);

$changed = $api->list(new WP_REST_Request(['collection' => 'team-members'], []));

check('publishing something changes it', false, $changed->get_headers()['ETag'] === $etag);

Settings::save(['restApi' => true, 'apiCacheMaxAge' => 300]);

$aged = $api->list(new WP_REST_Request(['collection' => 'team-members'], []));

check(
    'a site that asks for a max-age gets one',
    'public, max-age=300, must-revalidate',
    $aged->get_headers()['Cache-Control']
);

check('which is capped', 86400, Settings::normalize(['apiCacheMaxAge' => 999999])['apiCacheMaxAge']);

// --- routing -----------------------------------------------------------------

echo "\nAdmin routes\n";

require_once SCHEMAPRESS_PATH . 'classes/class-rest.php';

$GLOBALS['wp_rest_routes'] = [];
(new SchemaPress\Rest())->routes();

/**
 * The route WordPress would dispatch a path to.
 *
 * WordPress walks its routes in the order they were registered and takes the
 * first whose pattern matches, anchored at both ends. That is the behavior
 * that made `/entries/bulk` a bug: `bulk` fits the entry-identifier pattern,
 * and the entry route was registered first.
 *
 * @param string $path
 *
 * @return string|null the registered pattern, or null when nothing matches
 */
function lifecycle_dispatch($path)
{
    foreach ($GLOBALS['wp_rest_routes'] as $route) {
        if (preg_match('#^' . $route . '$#i', $path)) {
            return $route;
        }
    }

    return null;
}

$ns = SchemaPress\Rest::NAMESPACE;

check(
    'a bulk request reaches the bulk route',
    $ns . '/types/(?P<id>\d+)/bulk',
    lifecycle_dispatch($ns . '/types/12/bulk')
);

check(
    'rather than being read as an entry called "bulk"',
    false,
    lifecycle_dispatch($ns . '/types/12/bulk') === $ns . '/types/(?P<id>\d+)/entries/(?P<entry>[A-Za-z0-9-]+)'
);

check(
    'an entry still reaches the entry route',
    $ns . '/types/(?P<id>\d+)/entries/(?P<entry>[A-Za-z0-9-]+)',
    lifecycle_dispatch($ns . '/types/12/entries/9f2c-41ab')
);

check(
    'restoring reaches the trash route',
    $ns . '/types/(?P<id>\d+)/trash/(?P<entry>[A-Za-z0-9-]+)',
    lifecycle_dispatch($ns . '/types/12/trash/9f2c-41ab')
);

check(
    'duplicating reaches the transition route',
    $ns . '/types/(?P<id>\d+)/entries/(?P<entry>[A-Za-z0-9-]+)/(?P<action>publish|unpublish|discard|duplicate)',
    lifecycle_dispatch($ns . '/types/12/entries/9f2c-41ab/duplicate')
);

// every route is reachable: none is registered behind a broader pattern that
// swallows every path it would have answered. checked against a sample path
// built from each route's own pattern
$shadowed = [];

foreach ($GLOBALS['wp_rest_routes'] as $route) {
    $sample = preg_replace(
        ['/\(\?P<id>\\\\d\+\)/', '/\(\?P<entry>\[A-Za-z0-9-\]\+\)/', '/\(\?P<action>([a-z]+)[^)]*\)/'],
        ['12', '9f2c-41ab', '$1'],
        $route
    );

    if (lifecycle_dispatch($sample) !== $route) {
        $shadowed[] = $route;
    }
}

check('no route is shadowed by one registered before it', [], $shadowed);

// --- uninstall ---------------------------------------------------------------

echo "\nUninstall\n";

check('keeping the data is the default', false, Settings::normalize([])['deleteDataOnUninstall']);
check('and erasing it has to be asked for', true, Settings::normalize(['deleteDataOnUninstall' => true])['deleteDataOnUninstall']);

$uninstall = file_get_contents(SCHEMAPRESS_PATH . 'uninstall.php');

check(
    'the uninstaller refuses to run on its own',
    true,
    strpos($uninstall, "defined('WP_UNINSTALL_PLUGIN')") !== false
);

check(
    'and reads the setting before erasing anything',
    true,
    strpos($uninstall, "empty(\$settings['deleteDataOnUninstall'])") !== false
);

// --- result ------------------------------------------------------------------

echo "\n{$passed} passed, {$failed} failed\n";

exit($failed ? 1 : 0);
