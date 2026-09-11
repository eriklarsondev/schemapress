<?php

/**
 * End-to-end checks for collection types.
 *
 * These run the plugin's real classes against an in-memory WordPress, so a test
 * failing means the product is broken rather than a mock having drifted. The
 * shape of the suite follows the shape of the work: define a type, define its
 * fields, save entries, read them back through the public API.
 *
 * Run: php tests/collections.php
 *
 * @package SchemaPress
 */

require __DIR__ . '/stubs.php';

use SchemaPress\SchemaModel;
use SchemaPress\ContentSanitizer;
use SchemaPress\Validator;
use SchemaPress\Dates;
use SchemaPress\ContentType;
use SchemaPress\Entries;
use SchemaPress\Content;
use SchemaPress\Entry;
use SchemaPress\Inflector;
use SchemaPress\SchemaRepository;

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

// --- the definition ----------------------------------------------------------

echo "Definitions\n";

$definition = SchemaModel::normalize([
    'fields' => [
        ['label' => 'Name', 'type' => 'text', 'required' => true],
        ['label' => 'Name', 'type' => 'text'],                 // duplicate key
        ['label' => 'Mystery', 'type' => 'not_a_type'],        // unknown type
        ['label' => 'Bio', 'type' => 'wysiwyg', 'config' => ['width' => 'half']],
        ['label' => 'Photo', 'type' => 'image'],
        [
            'label' => 'Links',
            'type' => 'repeater',
            'config' => ['min' => 1, 'max' => 2, 'junk' => 'x'],
            'fields' => [
                ['label' => 'Label', 'type' => 'text'],
                ['label' => 'URL', 'type' => 'link'],
            ],
        ],
    ],
]);

$fields = $definition['fields'];

check('slugifies a label into a key', 'name', $fields[0]['key']);
check('deduplicates sibling keys', 'name_2', $fields[1]['key']);
check('drops fields of an unknown type', 5, count($fields));
check('keeps required', true, $fields[0]['required']);
check('keeps the form width', 'half', $fields[2]['config']['width']);
check('defaults the form width to the type\'s own', 'half', $fields[0]['config']['width']);
check('defaults to no offset', 0, $fields[0]['config']['offset']);

// a deliberate gap before a control has to be stated, because a grid flows its
// items together — but it cannot push the control off the end of its row
$offsets = SchemaModel::normalize([
    'fields' => [
        ['label' => 'Right half', 'type' => 'text', 'config' => ['width' => 'half', 'offset' => 6]],
        ['label' => 'Too far', 'type' => 'text', 'config' => ['width' => 'half', 'offset' => 11]],
        ['label' => 'Full width', 'type' => 'text', 'config' => ['width' => 'full', 'offset' => 4]],
        ['label' => 'Negative', 'type' => 'text', 'config' => ['width' => 'third', 'offset' => -3]],
    ],
])['fields'];

check('keeps an offset that fits', 6, $offsets[0]['config']['offset']);
check('clamps one that would overflow the row', 6, $offsets[1]['config']['offset']);
check('a full-width control cannot be offset', 0, $offsets[2]['config']['offset']);

$widths = SchemaModel::normalize([
    'fields' => [
        ['label' => 'Photo', 'type' => 'image'],
        ['label' => 'Active', 'type' => 'toggle'],
        ['label' => 'Brand', 'type' => 'color'],
        ['label' => 'Payload', 'type' => 'json'],
        ['label' => 'Body', 'type' => 'wysiwyg'],
        ['label' => 'Hero', 'type' => 'image', 'config' => ['width' => 'full']],
        ['label' => 'Odd', 'type' => 'image', 'config' => ['width' => 'enormous']],
    ],
])['fields'];

check('an image starts at a third', 'third', $widths[0]['config']['width']);
check('so does a toggle', 'third', $widths[1]['config']['width']);
check('and a color', 'third', $widths[2]['config']['width']);
check('JSON starts at half', 'half', $widths[3]['config']['width']);
check('rich text still starts full', 'full', $widths[4]['config']['width']);
// the default is only a starting point: every field can be any width, and
// full is a choice like any other rather than the absence of one
check('a width somebody chose is kept, full included', 'full', $widths[5]['config']['width']);
check('an unreadable width falls back to the type\'s', 'third', $widths[6]['config']['width']);
check('refuses a negative offset', 0, $offsets[3]['config']['offset']);
check('keeps whitelisted repeater config', 2, $fields[4]['config']['max']);
check('drops unknown config keys', false, array_key_exists('junk', $fields[4]['config']));
check('recurses into repeater children', 'link', $fields[4]['fields'][1]['type']);

// a definition describes data; nothing about the delivered page belongs in it
$legacy = SchemaModel::normalize([
    'kind' => 'single',
    'sections' => [['label' => 'Hero']],
    'fields' => [['label' => 'Body', 'type' => 'text', 'classes' => 'text-2xl', 'role' => 'heading']],
]);

check('drops sections', false, array_key_exists('sections', $legacy));
check('drops kind', false, array_key_exists('kind', $legacy));
check('drops CSS classes', false, array_key_exists('classes', $legacy['fields'][0]));
check('drops roles', false, array_key_exists('role', $legacy['fields'][0]));

// --- sanitizing values -------------------------------------------------------

echo "\nValues\n";

$values = ContentSanitizer::values([
    'name' => '  <b>Ada</b>  ',
    'undeclared' => 'vanishes',
    'links' => [
        ['id' => 'r_one', 'values' => ['label' => 'A']],
        ['id' => 'r_two', 'values' => ['label' => 'B']],
        ['id' => 'r_three', 'values' => ['label' => 'C']],  // beyond max of 2
    ],
], $fields);

check('sanitizes scalars', 'Ada', $values['name']);
check('drops undeclared keys', false, array_key_exists('undeclared', $values));
check('fills declared-but-missing fields', '', $values['name_2']);
check('defaults an image to null', null, $values['photo']);
check('enforces the repeater max', 2, count($values['links']));
check('preserves row identity', 'r_two', $values['links'][1]['id']);
check(
    'defaults a link to its empty shape',
    ['url' => '', 'label' => '', 'target' => ''],
    $values['links'][0]['values']['url']
);

// padding to the minimum means a template can rely on the count
$padded = ContentSanitizer::values([], $fields);

check('pads to the repeater min', 1, count($padded['links']));
check('a padded row still gets an id', true, !empty($padded['links'][0]['id']));

// --- content types -----------------------------------------------------------

echo "\nContent types\n";

sp_test_reset();

$team = sp_test_type('Team Member', [
    ['label' => 'Name', 'type' => 'text'],
    ['label' => 'Role', 'type' => 'text'],
]);

$news = sp_test_type('News Article', [['label' => 'Headline', 'type' => 'text']]);

// this is the call every screen begins with; it used to recurse until the
// stack gave out, because counting entries reads back through it
$types = ContentType::all();

check('lists every type', 2, count($types));
check('derives a machine key', 'news_article', $types[0]['key']);
check('names a post type from the key', 'spc_news_article', $types[0]['postType']);
check('counts fields', 2, $types[1]['fields']);
check('counts entries', 0, $types[1]['entries']);
check('finds a type by id', 'Team Member', ContentType::get($team)['label']);
check('registers the post type', true, post_type_exists('spc_team_member'));

// a key is claimed once and never follows a rename, or its entries orphan
wp_update_post(['ID' => $team, 'post_title' => 'Renamed Entirely']);
ContentType::flush();

check('the key survives a rename', 'team_member', ContentType::key($team));

// --- entries -----------------------------------------------------------------

echo "\nEntries\n";

sp_test_reset();

$team = sp_test_type('Team Member', [
    ['label' => 'Name', 'type' => 'text'],
    ['label' => 'Role', 'type' => 'text'],
    ['label' => 'Photo', 'type' => 'image'],
]);

$ada = Entries::save($team, null, [
    'title' => 'Ada Lovelace',
    'publish' => true,
    'values' => ['name' => 'Ada Lovelace', 'role' => 'Engineer'],
]);

check('creates an entry', 'Ada Lovelace', $ada['title']);
check('stores its values', 'Engineer', $ada['values']['role']);
check('publishes when asked', 'published', $ada['state']);

// the identifier is a uuid, not the row number: nothing outside the plugin
// should be able to read "this is the fourth entry ever made" off a url
check('identifies an entry by uuid', 1, preg_match('/^[0-9a-f-]{36}$/', $ada['id']));
check('and not by post id', false, is_numeric($ada['id']));

// an untitled entry is named from its own content, not left blank in the list
$grace = Entries::save($team, null, ['publish' => true, 'values' => ['name' => 'Grace Hopper']]);

check('derives a title when none is given', 'Grace Hopper', $grace['title']);

// the entry form does not ask for a title, so the derived one has to keep
// following the content — otherwise the listing shows a stale name forever
$renamed = Entries::save($team, $grace['id'], ['values' => ['name' => 'Grace B. Hopper']]);

check('the derived title follows an edit', 'Grace B. Hopper', $renamed['title']);

$reloaded = Entries::get($team, $ada['id']);

check('reads an entry back', 'Ada Lovelace', $reloaded['values']['name']);

$updated = Entries::save($team, $ada['id'], [
    'title' => 'Ada Lovelace',
    'values' => ['name' => 'Ada Lovelace', 'role' => 'Mathematician'],
]);

check('updates in place', $ada['id'], $updated['id']);
check('updates the draft', 'Mathematician', $updated['values']['role']);

$listing = Entries::all($team, ['view' => Entries::DRAFT]);

check('lists entries', 2, count($listing['entries']));
check('reports the total', 2, $listing['total']);
check('counts them', 2, Entries::count($team));

$searched = Entries::all($team, ['search' => 'Grace', 'view' => Entries::DRAFT]);

check('searches by title', 1, count($searched['entries']));

check('deletes an entry', true, Entries::delete($team, $grace['id']));
check('and it goes', 1, Entries::count($team));

// an entry of another type is not reachable through this one
$other = Entries::save($news = sp_test_type('News', [['label' => 'Headline', 'type' => 'text']]), null, [
    'title' => 'A headline',
]);

check('refuses an entry from another collection', null, Entries::get($team, $other['id']));
check('refuses to delete across collections', false, Entries::delete($team, $other['id']));

// --- schema drift ------------------------------------------------------------

echo "\nSchema drift\n";

// a field added after an entry was saved must read as its default, not vanish
SchemaRepository::saveDefinition($team, [
    'fields' => [
        ['label' => 'Name', 'type' => 'text'],
        ['label' => 'Email', 'type' => 'text'],
    ],
]);

// read the draft, because the edits above were never published and drift is
// what is being tested here rather than publication
$drifted = Entries::get($team, $ada['id'], 0, Entries::DRAFT);

check('keeps values still declared', 'Ada Lovelace', $drifted['values']['name']);
check('defaults fields added since the save', '', $drifted['values']['email']);
check('drops values no longer declared', false, array_key_exists('role', $drifted['values']));

// --- the reading API ---------------------------------------------------------

echo "\nThe Content API\n";

sp_test_reset();

$team = sp_test_type('Team Member', [
    ['label' => 'Name', 'type' => 'text'],
    ['label' => 'Role', 'type' => 'text'],
]);

Entries::save($team, null, [
    'title' => 'Ada',
    'publish' => true,
    'values' => ['name' => 'Ada', 'role' => 'Engineer'],
]);
Entries::save($team, null, [
    'title' => 'Grace',
    'publish' => true,
    'values' => ['name' => 'Grace', 'role' => 'Admiral'],
]);

$collection = Content::collection('team_member');

check('finds a collection by key', 2, count($collection->get()));
check('is countable', 2, count($collection));
check('reports the total', 2, $collection->total());
check('is not empty', false, $collection->isEmpty());
check('lists collection keys', ['team_member'], Content::collections());
check('reports a known collection', true, Content::has('team_member'));
check('reports an unknown one', false, Content::has('nope'));

// a typo must not fatal a template
$missing = Content::collection('does_not_exist');

check('an unknown collection is empty, not null', [], $missing->get());
check('and counts zero', 0, count($missing));
check('and finds nothing', null, $missing->find(1));

$entries = $collection->get();

check('yields Entry objects', true, $entries[0] instanceof Entry);
check('reads a field as a property', 'Grace', $entries[0]->name);
check('reads a field with get()', 'Admiral', $entries[0]->get('role'));
check('exposes the title', 'Grace', $entries[0]->title());
check('exposes the state', 'published', $entries[0]->state());
check('knows it is published', true, $entries[0]->isPublished());
check('knows it has no pending edits', false, $entries[0]->hasUnpublishedChanges());
check('exposes a uuid id', 1, preg_match('/^[0-9a-f-]{36}$/', $entries[0]->id()));

// a query is immutable, so holding one and reading it twice is safe
$limited = $collection->limit(1);

check('limit returns a new query', 1, count($limited->get()));
check('and leaves the original alone', 2, count($collection->get()));
check('first() returns one entry', true, $collection->first() instanceof Entry);

$found = $collection->find($entries[0]->id());

check('finds one by id', 'Grace', $found->name);

// iteration is what a Twig for-loop does
$names = [];

foreach ($collection as $person) {
    $names[] = $person->name;
}

check('iterates', ['Grace', 'Ada'], $names);

// --- contact field types -----------------------------------------------------

echo "\nEmail, URL and phone\n";

$contact = SchemaModel::normalize([
    'fields' => [
        ['label' => 'Email', 'type' => 'email'],
        ['label' => 'Website', 'type' => 'url'],
        ['label' => 'Phone', 'type' => 'phone'],
    ],
])['fields'];

check('registers email', 'email', $contact[0]['type']);
check('registers url', 'url', $contact[1]['type']);
check('registers phone', 'phone', $contact[2]['type']);
check('gives them a placeholder setting', '', $contact[0]['config']['placeholder']);

$contactValues = ContentSanitizer::values([
    'email' => '  ada@example.com  ',
    'website' => 'https://example.com/x',
    'phone' => '+1 (555) 010-0100',
], $contact);

check('trims an email', 'ada@example.com', $contactValues['email']);
check('keeps a url', 'https://example.com/x', $contactValues['website']);
check('keeps phone punctuation', '+1 (555) 010-0100', $contactValues['phone']);

// an address that will not validate is stored as nothing rather than as text
// that only looks like an address
$rejected = ContentSanitizer::values([
    'email' => 'not an address',
    'website' => 'javascript:alert(1)',
    'phone' => 'call me maybe 555',
], $contact);

check('drops an invalid email', '', $rejected['email']);
check('drops a dangerous url scheme', '', $rejected['website']);
check('strips letters from a phone', '555', $rejected['phone']);

// they default to empty strings, like any other single line of text
$emptyContact = ContentSanitizer::values([], $contact);

check('defaults email to empty', '', $emptyContact['email']);
check('defaults url to empty', '', $emptyContact['website']);
check('defaults phone to empty', '', $emptyContact['phone']);

// --- ready-made option lists --------------------------------------------------

echo "\nDatasets\n";

check('lists countries', true, count(SchemaPress\Datasets::options('countries')) > 200);
check('lists US states and territories', 56, count(SchemaPress\Datasets::options('us_states')));

// several country labels contain a comma of their own, and the packed list is
// comma-separated — so this is the case a naive split silently mangles
$korea = array_values(array_filter(
    SchemaPress\Datasets::options('countries'),
    function ($option) {
        return $option['value'] === 'KR';
    }
));

check('keeps a comma inside a label', 'Korea, Republic of', $korea[0]['label']);
check('knows a dataset', true, SchemaPress\Datasets::exists('countries'));
check('and refuses one it does not have', false, SchemaPress\Datasets::exists('planets'));

$sourced = SchemaModel::normalize([
    'fields' => [
        ['label' => 'Country', 'type' => 'select', 'config' => ['source' => 'countries']],
        ['label' => 'Nowhere', 'type' => 'select', 'config' => ['source' => 'planets']],
        [
            'label' => 'Size',
            'type' => 'select',
            'config' => ['options' => [['value' => 's', 'label' => 'Small']]],
        ],
    ],
])['fields'];

check('stores the dataset by name', 'countries', $sourced[0]['config']['source']);
check('and not a copy of the list', [], $sourced[0]['config']['options']);
check('drops a dataset that does not exist', '', $sourced[1]['config']['source']);
check('leaves a hand-written list alone', 1, count($sourced[2]['config']['options']));

// the sanitizer has to accept what the control offered, from either source
$picked = ContentSanitizer::values(
    ['country' => 'GB', 'nowhere' => 'x', 'size' => 's'],
    $sourced
);

check('accepts a value from the dataset', 'GB', $picked['country']);
check('accepts one from a hand-written list', 's', $picked['size']);

$rejected = ContentSanitizer::values(['country' => 'ZZ'], $sourced);

check('refuses a value the dataset does not hold', '', $rejected['country']);

// --- conditions --------------------------------------------------------------

echo "\nConditional fields\n";

$conditional = SchemaModel::normalize([
    'fields' => [
        ['label' => 'Contactable', 'type' => 'toggle'],
        [
            'label' => 'Phone',
            'type' => 'phone',
            'config' => ['condition' => ['field' => 'contactable', 'operator' => 'filled']],
        ],
        [
            'label' => 'Reason',
            'type' => 'text',
            'config' => [
                'condition' => ['field' => 'contactable', 'operator' => 'nonsense', 'value' => 'x'],
            ],
        ],
    ],
])['fields'];

check('stores the condition', 'contactable', $conditional[1]['config']['condition']['field']);
check('keeps a known operator', 'filled', $conditional[1]['config']['condition']['operator']);
check('falls back on an unknown operator', 'filled', $conditional[2]['config']['condition']['operator']);
check('defaults to no condition', '', $conditional[0]['config']['condition']['field']);

// a hidden field keeps its value: hiding a control says something about the
// form, not about the data
$kept = ContentSanitizer::values(
    ['contactable' => false, 'phone' => '555 0100'],
    $conditional
);

check('keeps the value of a field whose condition is unmet', '555 0100', $kept['phone']);

// --- publication --------------------------------------------------------------

echo "\nDrafts stay unpublished\n";

sp_test_reset();

$notes = sp_test_type('Note', [['label' => 'Body', 'type' => 'text']]);

$live = Entries::save($notes, null, [
    'title' => 'Published',
    'publish' => true,
    'values' => ['body' => 'live'],
]);
$draft = Entries::save($notes, null, ['title' => 'Draft', 'values' => ['body' => 'wip']]);

check('a save without publish is a draft', 'draft', $draft['state']);
check('and is not published', false, $draft['isPublished']);

// the reading API is the public view
check('a listing shows published only', 1, count(Entries::all($notes)['entries']));
check('and totals published only', 1, Entries::all($notes)['total']);
check('reading one draft by id gives nothing', null, Entries::get($notes, $draft['id']));
check('reading a published one works', 'Published', Entries::get($notes, $live['id'])['title']);

// the admin asks for drafts explicitly
$admin = Entries::all($notes, ['view' => Entries::DRAFT]);

check('the admin sees both', 2, count($admin['entries']));
check(
    'and can open a draft',
    'Draft',
    Entries::get($notes, $draft['id'], 0, Entries::DRAFT)['title']
);

// the front-end facade must not leak one either
check('the Content API hides drafts', 1, count(Content::collection('note')->get()));
check('and will not find one by id', null, Content::collection('note')->find($draft['id']));

// --- the draft branch ---------------------------------------------------------

echo "\nThe draft branches off published\n";

// editing something live must not take it off the site half-written: the save
// lands on the draft and the published copy stays where it was
$edited = Entries::save($notes, $live['id'], ['values' => ['body' => 'rewritten']]);

check('an edit to a live entry keeps it live', true, $edited['isPublished']);
check('and marks it as having moved on', 'modified', $edited['state']);
check('and counts one change ahead', 1, $edited['ahead']);
check('the draft holds the new text', 'rewritten', $edited['values']['body']);
check(
    'the front end still serves the old one',
    'live',
    Entries::get($notes, $live['id'])['values']['body']
);

$twice = Entries::save($notes, $live['id'], ['values' => ['body' => 'rewritten twice']]);

check('a second edit is two ahead', 2, $twice['ahead']);

// the count is of changes, not of saves: pressing save again on text that has
// not moved is not a third change
$again = Entries::save($notes, $live['id'], ['values' => ['body' => 'rewritten twice']]);

check('re-saving identical text is not a new change', 2, $again['ahead']);

// discarding throws the branch away and returns to what is live
$discarded = Entries::discard($notes, $live['id']);

check('discarding restores the published text', 'live', $discarded['values']['body']);
check('and resets the count', 0, $discarded['ahead']);
check('and it is simply published again', 'published', $discarded['state']);

// the post row's title belongs to the PUBLISHED copy. it used to follow every
// save, which put unpublished text on the live site under a heading nobody had
// approved — and left it there permanently, because discard never took it back
$secret = Entries::save($notes, $live['id'], ['values' => ['body' => 'SECRET headline']]);

check('the draft carries its own name', 'SECRET headline', $secret['title']);
check(
    'the front end keeps the published name',
    'Published',
    Entries::get($notes, $live['id'])['title']
);
check(
    'and searching the front end cannot find the draft',
    0,
    count(Content::collection('note')->search('SECRET')->get())
);

$reverted = Entries::discard($notes, $live['id']);

check('discarding takes the draft name back too', 'Published', $reverted['title']);
check(
    'and the front end is unchanged',
    'Published',
    Entries::get($notes, $live['id'])['title']
);

// publishing is what moves the published name
Entries::save($notes, $live['id'], ['values' => ['body' => 'a new name']]);
$renamedLive = Entries::publish($notes, $live['id']);

check('publishing moves the name', 'a new name', $renamedLive['title']);
check(
    'and the front end follows',
    'a new name',
    Entries::get($notes, $live['id'])['title']
);

// put it back for the checks that follow
Entries::save($notes, $live['id'], ['values' => ['body' => 'live'], 'publish' => true]);
Entries::save($notes, $live['id'], ['values' => ['body' => 'rewritten twice']]);

// text identical to what is live is not ahead of it at all. this is the one
// that bit: publish, then press save, and the entry claimed to be a change
// ahead of a copy it matched character for character
$backToLive = Entries::save($notes, $live['id'], ['values' => ['body' => 'live']]);

check('saving what is already published is not ahead', 0, $backToLive['ahead']);
check('and it reads as simply published', 'published', $backToLive['state']);

// publishing fast-forwards: the draft becomes what the front end serves
Entries::save($notes, $live['id'], ['values' => ['body' => 'the new version']]);
$published = Entries::publish($notes, $live['id']);

check('publishing resets the count', 0, $published['ahead']);
check(
    'and moves the published copy up',
    'the new version',
    Entries::get($notes, $live['id'])['values']['body']
);
check('and records when', true, $published['publishedAt'] !== '');

// the reported bug, end to end: publish, then press save without touching a
// thing. the entry must still read as published
$untouched = Entries::save($notes, $live['id'], ['values' => ['body' => 'the new version']]);

check('saving straight after publishing changes nothing', 0, $untouched['ahead']);
check('and leaves it published', 'published', $untouched['state']);
check('and it is still what the front end serves', true, $untouched['isPublished']);

// publishing a never-published entry is the same act
$promoted = Entries::publish($notes, $draft['id']);

check('a draft can be published', 'published', $promoted['state']);
check('and then the front end can see it', 2, count(Entries::all($notes)['entries']));

// unpublishing takes it off the site without destroying the work
$pulled = Entries::unpublish($notes, $draft['id']);

check('unpublishing hides it again', 'draft', $pulled['state']);
check('but keeps the draft', 'wip', $pulled['values']['body']);
check('and the front end loses it', 1, count(Entries::all($notes)['entries']));
check('discarding an unpublished entry does nothing', null, Entries::discard($notes, $draft['id']));

// every transition is addressed by uuid, so a bad one is a miss, not a
// neighboring entry
check('an unknown uuid publishes nothing', null, Entries::publish($notes, 'not-a-real-uuid'));
check('and reads as nothing', null, Entries::get($notes, 'not-a-real-uuid', 0, Entries::DRAFT));

// --- draft and publish, turned off --------------------------------------------

echo "\nA collection without drafts\n";

// no reset: the Note collection above stays, so the two workflows are exercised
// side by side in one store, which is how a real site has them
$facts = sp_test_type(
    'Fact',
    [['label' => 'Body', 'type' => 'text']],
    ['draftAndPublish' => false],
    'Numbers we quote on the site.'
);

check('the setting survives a round trip', false, SchemaRepository::definition($facts)['settings']['draftAndPublish']);
check('and defaults to on when unset', true, SchemaRepository::definition($notes)['settings']['draftAndPublish']);

// an ordinary save with no publish flag: there is only one copy, so it is live
$fact = Entries::save($facts, null, ['values' => ['body' => 'first']]);

check('saving publishes', 'published', $fact['state']);
check('and the front end has it at once', 1, count(Entries::all($facts)['entries']));

$changed = Entries::save($facts, $fact['id'], ['values' => ['body' => 'second']]);

check('an edit never runs ahead', 0, $changed['ahead']);
check('and the front end sees it', 'second', Entries::get($facts, $fact['id'])['values']['body']);

// the draft-workflow transitions are meaningless here, and refuse rather than
// half-apply: unpublishing would take an entry off the site with no control
// left on the screen to put it back
check('publish is refused', null, Entries::publish($facts, $fact['id']));
check('unpublish is refused', null, Entries::unpublish($facts, $fact['id']));
check('discard is refused', null, Entries::discard($facts, $fact['id']));

// the type payload carries both, because the entry screen is a different
// screen depending on them
$type = ContentType::get($facts);

// --- table columns ------------------------------------------------------------

echo "\nChosen table columns\n";

$listed = sp_test_type('Listing', [
    ['label' => 'Name', 'type' => 'text'],
    ['label' => 'Role', 'type' => 'text'],
    ['label' => 'Bio', 'type' => 'textarea'],
]);

// nobody has chosen: null, so the table can pick and a field added later shows
check('columns start unchosen', null, SchemaRepository::definition($listed)['settings']['listColumns']);

$chosen = SchemaRepository::saveDefinition($listed, [
    'fields' => [
        ['label' => 'Name', 'type' => 'text'],
        ['label' => 'Role', 'type' => 'text'],
        ['label' => 'Bio', 'type' => 'textarea'],
    ],
    // out of order on purpose: the order given is the order shown
    'settings' => ['listColumns' => ['role', 'name', 'ghost', 'role']],
]);

check('keeps the chosen order', ['role', 'name'], $chosen['settings']['listColumns']);
check('drops a column that names no field', false, in_array('ghost', $chosen['settings']['listColumns'], true));
check('drops a repeat', 2, count($chosen['settings']['listColumns']));

// an empty list is a real choice — no field columns — and not the same as
// never having chosen
$none = SchemaRepository::saveDefinition($listed, [
    'fields' => [['label' => 'Name', 'type' => 'text']],
    'settings' => ['listColumns' => []],
]);

check('an empty choice stays empty', [], $none['settings']['listColumns']);

// deleting a field takes its column with it, rather than leaving a blank one
$pruned = SchemaRepository::saveDefinition($listed, [
    'fields' => [['label' => 'Name', 'type' => 'text']],
    'settings' => ['listColumns' => ['name', 'role']],
]);

check('a column follows its field out', ['name'], $pruned['settings']['listColumns']);

check('the type reports the setting', false, $type['draftAndPublish']);
check('and carries its description', 'Numbers we quote on the site.', $type['description']);
check('a collection with drafts says so', true, ContentType::get($notes)['draftAndPublish']);

// --- naming ------------------------------------------------------------------

echo "\nNaming\n";

// a collection is named for one of the things in it, so the key is singular
check('pluralizes a simple word', 'articles', Inflector::pluralize('article'));
check('pluralizes a word ending in y', 'stories', Inflector::pluralize('story'));
check('leaves a vowel + y alone', 'days', Inflector::pluralize('day'));
check('pluralizes a sibilant', 'boxes', Inflector::pluralize('box'));
check('pluralizes an f ending', 'shelves', Inflector::pluralize('shelf'));
check('handles an irregular', 'people', Inflector::pluralize('person'));
check('leaves an uncountable alone', 'news', Inflector::pluralize('news'));
check('does not double-pluralize', 'articles', Inflector::pluralize('articles'));

check('singularizes a simple word', 'article', Inflector::singularize('articles'));
check('singularizes a y plural', 'story', Inflector::singularize('stories'));
check('singularizes a sibilant', 'box', Inflector::singularize('boxes'));
check('singularizes an irregular', 'person', Inflector::singularize('people'));
check('leaves status alone', 'status', Inflector::singularize('status'));

check('spots a plural', true, Inflector::isPlural('members'));
check('spots a singular', false, Inflector::isPlural('member'));
check('does not call status plural', false, Inflector::isPlural('status'));

// only the head noun changes in a multi-word name
check(
    'pluralizes the last word only',
    'Team Members',
    Inflector::lastWord('Team Member', [Inflector::class, 'pluralize'])
);
check(
    'singularizes the last word only',
    'News Article',
    Inflector::lastWord('News Articles', [Inflector::class, 'singularize'])
);

sp_test_reset();

// typing the plural must not name the post type after it
$plural = sp_test_type('Team Members', [['label' => 'Name', 'type' => 'text']]);

check('stores a singular key from a plural name', 'team_member', ContentType::key($plural));
check('derives the plural key', 'team_members', ContentType::plural($plural));
check('names the post type singularly', 'spc_team_member', ContentType::postType($plural));

$labels = ContentType::labels($plural);

check('offers a singular label', 'Team Member', $labels['singular']);
check('offers a plural label', 'Team Members', $labels['plural']);

// both machine names reach the same collection: which one a template author
// reaches for depends on the sentence they are writing
check('finds it by the singular key', 1, count(Content::collection('team_member')->fields()));
check('finds it by the plural key', 1, count(Content::collection('team_members')->fields()));
check('still misses a real typo', 0, count(Content::collection('team_membrs')->fields()));

// two types whose plurals would collide must stay distinct
$person = sp_test_type('Person', []);
$people = sp_test_type('People', []);

check(
    'keeps colliding plurals distinct',
    true,
    ContentType::plural($person) !== ContentType::plural($people)
);

// --- dates -------------------------------------------------------------------

echo "\nDates\n";

check('keeps a real date', '2026-09-08', Dates::date('2026-09-08'));
// rolled forward rather than refused is the failure worth naming: nothing about
// 2026-03-03 looks wrong three months later
check('refuses a day that does not exist', '', Dates::date('2026-02-31'));
check('accepts a leap day', '2024-02-29', Dates::date('2024-02-29'));
check('refuses a non-leap February 29', '', Dates::date('2026-02-29'));
check('refuses a date-shaped phrase', '', Dates::date('tomorrow'));
check('refuses a partial date', '', Dates::date('2026-09'));

check('fills in missing seconds', '19:00:00', Dates::time('19:00'));
check('keeps given seconds', '19:00:30', Dates::time('19:00:30'));
check('refuses an impossible hour', '', Dates::time('25:00'));
check('refuses an impossible minute', '', Dates::time('19:60'));

check('reads what datetime-local sends', '2026-09-08T19:00:00', Dates::datetime('2026-09-08T19:00'));
check('accepts a space separator', '2026-09-08T19:00:00', Dates::datetime('2026-09-08 19:00:00'));
// the wall clock is the value, so an offset is read and then dropped: a client
// saying 19:00 means seven in the evening whichever way it spells it
check('keeps the wall clock through a Z', '2026-09-08T19:00:00', Dates::datetime('2026-09-08T19:00:00Z'));
check('keeps the wall clock through an offset', '2026-09-08T19:00:00', Dates::datetime('2026-09-08T19:00:00+02:00'));
check('refuses a date where a datetime belongs', '', Dates::datetime('2026-09-08'));

// through the registry, which is how a value actually arrives
$dated = SchemaModel::normalize([
    'fields' => [
        ['label' => 'Starts', 'type' => 'date'],
        ['label' => 'Doors', 'type' => 'time'],
        ['label' => 'Announced', 'type' => 'datetime'],
    ],
])['fields'];

$datedValues = ContentSanitizer::values([
    'starts' => '2026-09-08',
    'doors' => '18:30',
    'announced' => 'whenever',
], $dated);

check('sanitizes a date field', '2026-09-08', $datedValues['starts']);
check('sanitizes a time field', '18:30:00', $datedValues['doors']);
check('stores an unparseable datetime as nothing', '', $datedValues['announced']);

check('indexes a date', true, SchemaPress\Index::indexable($dated[0]));
// CHAR, so the string order is the date order — which is the whole reason for
// storing them in these shapes
check('compares a date as text', 'CHAR', SchemaPress\Index::compareAs($dated[0]));

// an INSTANT, unlike everything above it. WordPress keeps these as
// "Y-m-d H:i:s" in UTC, which new Date() reads as an Invalid Date in Safari and
// as local time everywhere else — the same response telling two browsers
// different things
check('makes a WordPress stamp readable', '2026-09-08T09:35:00Z', Dates::iso('2026-09-08 09:35:00'));
check('leaves one that already says Z alone', '2026-09-08T09:35:00Z', Dates::iso('2026-09-08T09:35:00Z'));
check('keeps an explicit offset', '2026-09-08T09:35:00+02:00', Dates::iso('2026-09-08 09:35:00+02:00'));
check('reads no date as no moment', '', Dates::iso(''));
check('and WordPress\'s empty date as none either', '', Dates::iso('0000-00-00 00:00:00'));

check('lets a date name its entries', 'starts', SchemaModel::normalize([
    'settings' => ['titleField' => 'starts'],
    'fields' => [['label' => 'Starts', 'type' => 'date']],
])['settings']['titleField']);

// --- validation --------------------------------------------------------------

echo "\nValidation\n";

$rules = SchemaModel::normalize([
    'fields' => [
        ['label' => 'Name', 'type' => 'text', 'required' => true, 'config' => ['maxlength' => 5]],
        ['label' => 'Agreed', 'type' => 'toggle', 'required' => true],
        ['label' => 'Headcount', 'type' => 'number', 'config' => ['min' => 0, 'max' => 10]],
        [
            'label' => 'Contactable',
            'type' => 'toggle',
        ],
        [
            'label' => 'Phone',
            'type' => 'phone',
            'required' => true,
            'config' => ['condition' => ['field' => 'contactable', 'operator' => 'filled']],
        ],
        [
            'label' => 'Address',
            'type' => 'group',
            'fields' => [['label' => 'City', 'type' => 'text', 'required' => true]],
        ],
        [
            'label' => 'Links',
            'type' => 'repeater',
            'required' => true,
            'fields' => [['label' => 'Label', 'type' => 'text', 'required' => true]],
        ],
    ],
])['fields'];

/**
 * Sanitizes a payload and reports what it still breaks, the way a save does.
 *
 * @param array $values
 * @param array $fields
 *
 * @return array The messages, keyed by field.
 */
function sp_test_problems(array $values, array $fields)
{
    $problems = Validator::check(ContentSanitizer::values($values, $fields), $fields);

    return array_column($problems, 'message', 'key');
}

$complete = [
    'name' => 'Ada',
    'agreed' => false,
    'address' => ['city' => 'London'],
    'links' => [['values' => ['label' => 'Home']]],
];

check('passes a complete entry', [], sp_test_problems($complete, $rules));

// a toggle is answered by being off as much as by being on. treating false as
// missing would make a required toggle a box you cannot save without ticking
check('counts an untouched toggle as answered', false, isset(sp_test_problems($complete, $rules)['agreed']));

$empty = sp_test_problems(['links' => [['values' => ['label' => 'Home']]], 'address' => ['city' => 'X']], $rules);

check('catches an empty required field', 'Name is required.', $empty['name'] ?? '');

// the form hides this field until Contactable is ticked, so the server cannot
// ask for it either — the two have to agree or one of them is unusable
check('does not ask for a hidden field', false, isset($empty['phone']));

$shown = sp_test_problems(
    ['name' => 'Ada', 'contactable' => true, 'address' => ['city' => 'X'], 'links' => [['values' => ['label' => 'H']]]],
    $rules
);

check('asks for the field once its condition is met', true, isset($shown['phone']));

$long = sp_test_problems(array_merge($complete, ['name' => 'Ada Lovelace']), $rules);

check('catches an over-long value', 'Name must be 5 characters or fewer.', $long['name'] ?? '');

check(
    'catches a number under its minimum',
    true,
    isset(sp_test_problems(array_merge($complete, ['headcount' => -1]), $rules)['headcount'])
);

check(
    'catches a number over its maximum',
    true,
    isset(sp_test_problems(array_merge($complete, ['headcount' => 11]), $rules)['headcount'])
);

// zero is a number, not an absence — and a minimum of zero is a real bound
check(
    'accepts zero against a minimum of zero',
    false,
    isset(sp_test_problems(array_merge($complete, ['headcount' => 0]), $rules)['headcount'])
);

check(
    'catches an empty required group field',
    true,
    isset(sp_test_problems(array_merge($complete, ['address' => ['city' => '']]), $rules)['city'])
);

check(
    'catches a required repeater with no rows',
    'Links needs at least one row.',
    sp_test_problems(array_merge($complete, ['links' => []]), $rules)['links'] ?? ''
);

// the row number is in the message, because "Label is required" about a list of
// six rows is not an answer to which one
$rows = Validator::check(
    ContentSanitizer::values(
        array_merge($complete, ['links' => [['values' => ['label' => 'Home']], ['values' => ['label' => '']]]]),
        $rules
    ),
    $rules
);

check('names the row a missing value is in', 'Links 2 → Label is required.', $rows[0]['message'] ?? '');

// --- validation on the way in ------------------------------------------------

echo "\nRefusing a save\n";

sp_test_reset();

$guarded = sp_test_type('Member', [
    ['label' => 'Email', 'type' => 'email', 'required' => true, 'unique' => true],
    ['label' => 'Note', 'type' => 'text'],
]);

$refused = Entries::save($guarded, null, ['values' => ['note' => 'no address']]);

check('refuses a save that breaks a rule', true, is_wp_error($refused));
check('says which rule', 'schemapress_invalid_entry', $refused->get_error_code());
check('answers 400', 400, $refused->get_error_data()['status']);
check('names the field', 'email', $refused->get_error_data()['fields'][0]['key']);

// a rejected save must leave nothing behind: creating the post and then
// refusing its values would put an empty entry in the collection as the price
// of a typo
check('creates nothing when it refuses', 0, Entries::count($guarded));

$first = Entries::save($guarded, null, ['values' => ['email' => 'ada@example.com']]);

check('accepts the same save once it is complete', false, is_wp_error($first));
check('stores it', 1, Entries::count($guarded));

$clash = Entries::save($guarded, null, ['values' => ['email' => 'ada@example.com']]);

check('refuses a duplicate of a unique field', true, is_wp_error($clash));
check('still has only the first', 1, Entries::count($guarded));

// an entry is not a duplicate of itself, which is what makes a unique field
// editable at all
$again = Entries::save($guarded, $first['id'], [
    'values' => ['email' => 'ada@example.com', 'note' => 'edited'],
]);

check('lets an entry keep its own value', false, is_wp_error($again));
check('and actually saved the edit', 'edited', $again['values']['note']);

// three entries that have not filled in a reference number yet are not three
// entries with the same reference number
$blank = sp_test_type('Ticket', [['label' => 'Ref', 'type' => 'text', 'unique' => true]]);

Entries::save($blank, null, ['values' => ['ref' => '']]);
$second = Entries::save($blank, null, ['values' => ['ref' => '']]);

check('does not collide two empty values', false, is_wp_error($second));

// --- slugs -------------------------------------------------------------------

echo "\nSlugs\n";

sp_test_reset();

// nobody has chosen, so one is picked: the first field that could carry a name
check('defaults to the first field', 'full_name', SchemaModel::normalize([
    'fields' => [
        ['label' => 'Full Name', 'type' => 'text'],
        ['label' => 'Company', 'type' => 'text'],
    ],
])['settings']['slugField']);

// a field the collection already refuses duplicates in is the one that makes
// slugs which do not collide, so it wins wherever it sits
check('prefers a unique field', 'company', SchemaModel::normalize([
    'fields' => [
        ['label' => 'Full Name', 'type' => 'text'],
        ['label' => 'Company', 'type' => 'text', 'unique' => true],
    ],
])['settings']['slugField']);

// an address made of an email reads as ada-example-com, and one made of a
// phone number is a run of digits. both are fine names and neither is an address
check('skips types that slugify badly', 'name', SchemaModel::normalize([
    'fields' => [
        ['label' => 'Email', 'type' => 'email'],
        ['label' => 'Name', 'type' => 'text'],
    ],
])['settings']['slugField']);

// present-but-empty is a real choice and means the uuid, which is the only way
// to say "no field" once one has been picked for you
check('an explicit none stays none', '', SchemaModel::normalize([
    'settings' => ['slugField' => ''],
    'fields' => [['label' => 'Full Name', 'type' => 'text']],
])['settings']['slugField']);

check('a chosen field is kept', 'company', SchemaModel::normalize([
    'settings' => ['slugField' => 'company'],
    'fields' => [
        ['label' => 'Full Name', 'type' => 'text'],
        ['label' => 'Company', 'type' => 'text'],
    ],
])['settings']['slugField']);

// deleting the field a collection was addressed by falls back to the uuid
// rather than leaving its entries unaddressable
check('a deleted field falls back to the id', '', SchemaModel::normalize([
    'settings' => ['slugField' => 'gone'],
    'fields' => [['label' => 'Bio', 'type' => 'wysiwyg']],
])['settings']['slugField']);

// --- building one ------------------------------------------------------------

// the content API reads slugs too, so it is exercised here as well as in its
// own section further down
$api = new SchemaPress\Api();

$team = sp_test_type('Member', [
    ['label' => 'Full Name', 'type' => 'text'],
], ['titleField' => 'full_name', 'publicApi' => ['list' => true, 'single' => true]]);

$ada = Entries::save($team, null, ['publish' => true, 'values' => ['full_name' => 'Ada Lovelace']]);

check('builds the slug from the chosen field', 'ada-lovelace', $ada['slug']);

// WordPress uniquifies within the post type, so two entries of the same name
// get distinct addresses rather than silently sharing one
$other = Entries::save($team, null, ['publish' => true, 'values' => ['full_name' => 'Ada Lovelace']]);

check('two entries of the same name do not share an address', 'ada-lovelace-2', $other['slug']);

// the point of having one: a front end routing /team/ada-lovelace holds the
// slug and not the uuid, and would otherwise list the collection to translate
check('finds an entry by its slug', $ada['id'], Entries::get($team, 'ada-lovelace')['id']);
check('and still by its uuid', 'ada-lovelace', Entries::get($team, $ada['id'])['slug']);
check('and by neither for a stranger', null, Entries::get($team, 'nobody-at-all'));

check('the API answers on a slug', 'Ada Lovelace', sp_test_single('members', 'ada-lovelace')['data']['full_name']);
check('and reports it', 'ada-lovelace', sp_test_single('members', 'ada-lovelace')['data']['slug']);

// settled on first publish and frozen after, so a link to a published entry
// keeps working when somebody corrects the name
Entries::save($team, $ada['id'], ['values' => ['full_name' => 'Ada King'], 'publish' => true]);

check('a published entry keeps its address when renamed', 'ada-lovelace', Entries::get($team, $ada['id'])['slug']);

// while it is still a draft the address is not settled, so filling in a name
// after creating an empty entry does not leave it addressed as untitled
$draft = Entries::save($team, null, ['values' => ['full_name' => '']]);
$named = Entries::save($team, $draft['id'], ['values' => ['full_name' => 'Grace Hopper']]);

check('an unpublished entry follows its field', 'grace-hopper', $named['slug']);

// --- addressed by id ---------------------------------------------------------

$anon = sp_test_type('Setting', [['label' => 'Body', 'type' => 'wysiwyg']], ['slugField' => '']);
$row = Entries::save($anon, null, ['publish' => true, 'values' => ['body' => '<p>x</p>']]);

// an entry always has an address. a collection with nothing name-shaped in it
// is addressed by the uuid, which is unique without anything being filled in
check('a collection with no slug field uses the id', $row['id'], $row['slug']);
check('and is reachable by it', $row['id'], Entries::get($anon, $row['slug'])['id']);

// --- attachments -------------------------------------------------------------

echo "\nAttachments\n";

sp_test_reset();

$photo = wp_insert_post(['post_type' => 'attachment', 'post_title' => 'Portrait']);
$image = SchemaPress\Resolver::attachment($photo);

check('expands an id into the attachment', $photo, $image['id']);
check('with its dimensions', 800, $image['width']);

// read from the attachment's own metadata rather than by asking WordPress for
// each registered size in turn — that was a filtered call per size per image
// per entry, and a page of results made thousands of them
check('reports a generated size', true, isset($image['sizes']['thumbnail']));
check(
    'built from the file beside the full-size one',
    'http://example.test/uploads/' . $photo . '-150x150.jpg',
    $image['sizes']['thumbnail']['url']
);
check('with that size\'s own dimensions', 150, $image['sizes']['thumbnail']['width']);

check('a value that is not an attachment resolves to nothing', null, SchemaPress\Resolver::attachment(999999));

// --- identifiers and upgrading -----------------------------------------------

echo "\nIdentifiers\n";

sp_test_reset();

$legacy = sp_test_type('Record', [['label' => 'Body', 'type' => 'text']]);
$record = Entries::save($legacy, null, ['publish' => true, 'values' => ['body' => 'kept']]);

$recordId = get_posts([
    'post_type' => ContentType::postType($legacy),
    'post_status' => ['publish', 'draft'],
    'numberposts' => 1,
])[0]->ID;

check('a new entry is given an identifier when it is created', true, $record['id'] !== '');

// reading must not write. an entry delivered over the public API used to mint
// its identifier on the way out, which made an unauthenticated GET take a row
// lock and fail outright on a read replica
$before = $GLOBALS['wp_meta'][$recordId];
Entries::get($legacy, $record['id']);

check('reading an entry writes nothing', $before, $GLOBALS['wp_meta'][$recordId]);

// an entry from before identifiers existed. the upgrade is what gives it one,
// rather than the next read
delete_post_meta($recordId, '_schemapress_uid');

check('the backfill mints what is missing', 1, Entries::backfill($legacy));
check('and is idempotent', 0, Entries::backfill($legacy));

$upgrade = new SchemaPress\Upgrade();

delete_post_meta($recordId, '_schemapress_uid');
update_option(SchemaPress\Upgrade::OPTION, 'older');
$upgrade->run();

check('an upgrade backfills every collection', true, Entries::get($legacy, $recordId ? Entries::uid($recordId) : '') !== null);
check('and records the version it ran for', SCHEMAPRESS_VERSION, get_option(SchemaPress\Upgrade::OPTION));

// once per release, not once per request
delete_post_meta($recordId, '_schemapress_uid');
$upgrade->run();

check('and does nothing on the next request', '', (string) get_post_meta($recordId, '_schemapress_uid', true));

// the lock is released whatever happens. it used to be deleted on the last line
// of run(), so a run that died in the backfill left it behind forever — the
// version had already been recorded, so no later request reached the delete
check('a finished upgrade leaves no lock behind', false, get_option(SchemaPress\Upgrade::LOCK));

// two requests arriving together must not both upgrade. the lock was a
// get-then-update, so both could read "no lock" and both proceed
update_option(SchemaPress\Upgrade::OPTION, 'older');
update_option(SchemaPress\Upgrade::LOCK, time());
delete_post_meta($recordId, '_schemapress_uid');
$upgrade->run();

check('a held lock turns the second request away', '', (string) get_post_meta($recordId, '_schemapress_uid', true));

// unless it is old enough to be a crashed run rather than one in flight
update_option(SchemaPress\Upgrade::LOCK, time() - (SchemaPress\Upgrade::LOCK_TTL + 1));
$upgrade->run();

check('a stale lock is taken over', true, (string) get_post_meta($recordId, '_schemapress_uid', true) !== '');

// --- entry counts -------------------------------------------------------------

echo "\nEntry counts\n";

sp_test_reset();

$counted = sp_test_type('Widget', [['label' => 'Name', 'type' => 'text']]);

foreach (['one', 'two', 'three'] as $name) {
    Entries::save($counted, null, ['values' => ['name' => $name], 'publish' => true]);
}

Entries::save($counted, null, ['values' => ['name' => 'draft']]);

ContentType::flush();

$listed = ContentType::all()[0];

check('a collection reports how many entries it holds', 4, $listed['entries']);

// ContentType::registerAll() reaches all() on `init` BEFORE it registers the
// post types, and wp_count_posts() answers with an empty object for one that
// does not exist yet. Counting there reported nothing for every collection and
// cached that for the whole request — `wp schemapress list` showed every
// collection empty. The counts now wait until the post types are visible.
ContentType::flush();
unset($GLOBALS['wp_post_types'][ContentType::postType($counted)]);

$early = ContentType::all()[0];

check('an unregistered post type is not counted as zero', null, $early['entries']);

ContentType::register($counted);

check('and the count arrives once it is registered', 4, ContentType::all()[0]['entries']);

// --- lifecycle ---------------------------------------------------------------

echo "\nLifecycle\n";

sp_test_reset();

$heard = [];

foreach (['saved', 'published', 'unpublished', 'discarded', 'deleted'] as $event) {
    add_action('schemapress/entry_' . $event, function () use ($event, &$heard) {
        $heard[] = $event;
    });
}

$watched = sp_test_type('Note', [['label' => 'Body', 'type' => 'text']]);

$created = null;
add_action('schemapress/entry_saved', function ($id, $values, $type_id, $new) use (&$created) {
    $created = $new;
});

$note = Entries::save($watched, null, ['values' => ['body' => 'first']]);
$id = $note['id'];

check('announces a save', ['saved'], $heard);
check('says a save created the entry', true, $created);

Entries::save($watched, $id, ['values' => ['body' => 'second']]);

check('says a later save did not', false, $created);

$heard = [];
Entries::publish($watched, $id);

check('announces going live', ['published'], $heard);

$heard = [];
Entries::save($watched, $id, ['values' => ['body' => 'third']]);
Entries::discard($watched, $id);

check('announces a discard', ['saved', 'discarded'], $heard);

$heard = [];
Entries::unpublish($watched, $id);

check('announces coming down', ['unpublished'], $heard);

$heard = [];
Entries::delete($watched, $id);

check('announces a delete', ['deleted'], $heard);

// publishing through a save on a collection that keeps no drafts still counts
// as going live — it is the same event, reached another way
sp_test_reset();

$heard = [];
add_action('schemapress/entry_published', function () use (&$heard) {
    $heard[] = 'published';
});

$direct = sp_test_type('Fact', [['label' => 'Body', 'type' => 'text']], ['draftAndPublish' => false]);
Entries::save($direct, null, ['values' => ['body' => 'immediate']]);

check('announces a draftless save as going live', ['published'], $heard);

// --- the content API ---------------------------------------------------------

echo "\nThe content API\n";

sp_test_reset();

$api = new SchemaPress\Api();

/**
 * Reads a collection through the API, the way a URL would.
 *
 * @param string $collection
 * @param array  $query
 *
 * @return mixed
 */
function sp_test_list($collection, array $query = [])
{
    global $api;

    return $api->list(new WP_REST_Request(['collection' => $collection], $query));
}

/**
 * Reads one entry through the API.
 *
 * @param string $collection
 * @param mixed  $id
 *
 * @return mixed
 */
function sp_test_single($collection, $id)
{
    global $api;

    return $api->single(new WP_REST_Request(['collection' => $collection, 'id' => $id]));
}

$books = sp_test_type('Book', [['label' => 'Title', 'type' => 'text']], ['titleField' => 'title']);
$book = Entries::save($books, null, ['publish' => true, 'values' => ['title' => 'Dune']]);

// nothing is published to the API until a collection says so
check('a collection is closed until it opts in', true, is_wp_error(sp_test_list('books')));
check('and says which switch', 'schemapress_api_disabled', sp_test_list('books')->get_error_code());
check('an unknown collection is a 404', 404, sp_test_list('nothing')->get_error_data()['status']);

// the two shapes of read are separate answers: readable by id without being
// walkable end to end
SchemaRepository::saveDefinition($books, [
    'settings' => ['titleField' => 'title', 'publicApi' => ['list' => false, 'single' => true]],
    'fields' => [['label' => 'Title', 'type' => 'text']],
]);

check('read many stays shut', true, is_wp_error(sp_test_list('books')));
check('while read one answers', 'Dune', sp_test_single('books', $book['id'])['data']['title']);

SchemaRepository::saveDefinition($books, [
    'settings' => ['titleField' => 'title', 'publicApi' => ['list' => true, 'single' => true]],
    'fields' => [['label' => 'Title', 'type' => 'text']],
]);

check('read many answers once turned on', 1, count(sp_test_list('books')['data']));

// THE ENUMERATION FIX. the uid is the only public reference an entry has; a
// post id is a row number, and accepting one let anybody read the collection by
// counting 1, 2, 3 without the listing route being open at all
check('refuses a post id', true, is_wp_error(sp_test_single('books', '100')));
check('refuses a post id even when it names a real entry', true, is_wp_error(sp_test_single('books', '101')));
check('answers the uid', 'Dune', sp_test_single('books', $book['id'])['data']['title']);

// THE MASTER SWITCH. off, the namespace is not registered at all rather than
// registered and refusing — so nothing under /wp-json/ advertises that this site
// has a content API, or which collections are in it
$GLOBALS['wp_rest_routes'] = [];
$api->register();

check('registers the content routes when the API is on', 2, count($GLOBALS['wp_rest_routes']));

SchemaPress\Settings::save(['restApi' => false]);

$GLOBALS['wp_rest_routes'] = [];
$api->register();

check('registers nothing when the API is off', [], $GLOBALS['wp_rest_routes']);

// what each collection chose is kept rather than cleared, so turning the API
// back on restores the site as it was instead of needing every collection edited
SchemaPress\Settings::save(['restApi' => true]);

$GLOBALS['wp_rest_routes'] = [];
$api->register();

check('and registers them again when it goes back on', 2, count($GLOBALS['wp_rest_routes']));
check('with what each collection had chosen intact', 1, count(sp_test_list('books')['data']));
check('for both shapes of read', 'Dune', sp_test_single('books', $book['id'])['data']['title']);

$hidden = Entries::save($books, null, ['values' => ['title' => 'Unpublished']]);

// the envelope timestamps are instants, and a client has to be able to read
// them without knowing which shape WordPress happened to store
$shaped = sp_test_single('books', $book['id'])['data'];

check(
    'reports publishedAt as an instant a client can parse',
    1,
    preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $shaped['publishedAt'])
);

check(
    'and updatedAt too',
    1,
    preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $shaped['updatedAt'])
);

check('an unpublished entry is not listed', 1, count(sp_test_list('books')['data']));
check('and is not readable by id', 404, sp_test_single('books', $hidden['id'])->get_error_data()['status']);

// --- filtering and sorting ---------------------------------------------------

echo "\nFiltering and sorting\n";

sp_test_reset();

$staff = sp_test_type('Person', [
    ['label' => 'Name', 'type' => 'text'],
    ['label' => 'Role', 'type' => 'text'],
    ['label' => 'Headcount', 'type' => 'number'],
    ['label' => 'Lead', 'type' => 'toggle'],
    ['label' => 'Joined', 'type' => 'date'],
    ['label' => 'Bio', 'type' => 'wysiwyg'],
], ['titleField' => 'name', 'publicApi' => ['list' => true, 'single' => true]]);

foreach ([
    ['name' => 'Ada', 'role' => 'Engineer', 'headcount' => 3, 'lead' => true, 'joined' => '2024-01-05'],
    ['name' => 'Grace', 'role' => 'Engineer', 'headcount' => 12, 'lead' => false, 'joined' => '2023-06-20'],
    ['name' => 'Barbara', 'role' => 'Designer', 'headcount' => 7, 'lead' => false, 'joined' => '2025-11-02'],
    ['name' => 'Katherine', 'role' => '', 'headcount' => 0, 'lead' => false, 'joined' => ''],
] as $person) {
    Entries::save($staff, null, ['publish' => true, 'values' => $person]);
}

/**
 * The names a filter spec selects, in the order it returns them.
 *
 * @param array $spec
 *
 * @return array
 */
function sp_test_names(array $spec)
{
    global $staff;

    $result = Entries::all($staff, ['view' => Entries::PUBLISHED, 'spec' => $spec, 'perPage' => 50]);

    return array_map(function ($entry) {
        return $entry['values']['name'];
    }, $result['entries']);
}

/**
 * The names a query string selects, through the same parser a URL goes through.
 *
 * @param array $params
 *
 * @return array
 */
function sp_test_query(array $params)
{
    return sp_test_names(SchemaPress\Query::parse($params));
}

// the harness can see meta_query now. before it could not, and every one of
// these passed whether the filter worked or not
check('filters on equality', ['Grace', 'Ada'], sp_test_query(['role' => 'Engineer']));
check('and on inequality', ['Katherine', 'Barbara'], sp_test_query(['role' => ['$ne' => 'Engineer']]));

check('reads a bare parameter as equals', ['Barbara'], sp_test_query(['role' => 'Designer']));
check('reads a comma as any of', ['Barbara', 'Ada'], sp_test_query(['name' => 'Ada,Barbara']));
check('reads a repeated parameter as any of', ['Grace', 'Ada'], sp_test_query(['role' => ['Engineer']]));

check('filters $in', ['Barbara', 'Ada'], sp_test_query(['role' => ['$in' => ['Engineer', 'Designer']], 'name' => ['$in' => ['Ada', 'Barbara']]]));
check('filters $notIn', ['Katherine'], sp_test_query(['name' => ['$notIn' => ['Ada', 'Grace', 'Barbara']]]));

// numbers compare as numbers: 12 is not less than 3 because "1" sorts first.
// unordered, these come back newest first, which is what an unordered listing
// gives — Barbara was created after Grace
check('compares numbers numerically', ['Barbara', 'Grace'], sp_test_query(['headcount' => ['$gte' => 7]]));
check('and the other way', ['Katherine', 'Ada'], sp_test_query(['headcount' => ['$lt' => 7]]));
check('filters $between', ['Barbara', 'Ada'], sp_test_query(['headcount' => ['$between' => [3, 7]]]));

check('filters $contains', ['Grace', 'Ada'], sp_test_query(['role' => ['$contains' => 'ngine']]));
check('filters $notContains', ['Katherine', 'Barbara'], sp_test_query(['role' => ['$notContains' => 'ngine']]));

// anchored operators are REGEXP, not LIKE — a trailing % does not survive
// WP_Meta_Query's own escaping, so $startsWith could only ever have matched a
// value ending in a per-cent sign
check('anchors $startsWith', ['Grace', 'Ada'], sp_test_query(['role' => ['$startsWith' => 'Eng']]));
check('and does not match mid-string', [], sp_test_query(['role' => ['$startsWith' => 'ngine']]));
check('anchors $endsWith', ['Grace', 'Ada'], sp_test_query(['role' => ['$endsWith' => 'neer']]));

// an entry always has a row for an indexable field, so "is it set" is a
// question about the value being empty rather than the row existing
check('filters $null', ['Katherine'], sp_test_query(['role' => ['$null' => 'true']]));
check('filters $notNull', ['Barbara', 'Grace', 'Ada'], sp_test_query(['role' => ['$notNull' => 'true']]));
// "false" arrives as a string, which PHP would otherwise read as true
check('reads $null=false as notNull', ['Barbara', 'Grace', 'Ada'], sp_test_query(['role' => ['$null' => 'false']]));

check('filters a toggle', ['Ada'], sp_test_query(['lead' => 1]));

// dates are stored so that string order is date order, which is what lets a
// CHAR column answer a range
check('compares dates', ['Barbara', 'Ada'], sp_test_query(['joined' => ['$gte' => '2024-01-01']]));

check(
    'combines filters with AND',
    ['Grace'],
    sp_test_query(['role' => 'Engineer', 'headcount' => ['$gt' => 5]])
);

check(
    'combines filters with $or',
    ['Barbara', 'Ada'],
    sp_test_query(['filters' => ['$or' => [['name' => ['$eq' => 'Ada']], ['role' => ['$eq' => 'Designer']]]]])
);

// a filter naming a field that does not exist, or one that cannot be indexed,
// is dropped rather than obeyed — which means it returns MORE than was asked
// for, so the test has to prove it did not quietly return nothing
check('drops a filter on an unknown field', 4, count(sp_test_query(['nonsense' => 'x'])));
check('drops a filter on rich text', 4, count(sp_test_query(['bio' => 'anything'])));

// --- ordering ----------------------------------------------------------------

check(
    'sorts by a field, ascending',
    ['Ada', 'Barbara', 'Grace', 'Katherine'],
    sp_test_query(['sort' => 'name:asc'])
);

check(
    'sorts descending',
    ['Katherine', 'Grace', 'Barbara', 'Ada'],
    sp_test_query(['sort' => 'name:desc'])
);

check('reads -field as descending', ['Katherine', 'Grace', 'Barbara', 'Ada'], sp_test_query(['sort' => '-name']));

// numeric ordering, not lexicographic: 12 comes after 7
check(
    'sorts a number as a number',
    ['Katherine', 'Ada', 'Barbara', 'Grace'],
    sp_test_query(['sort' => 'headcount:asc'])
);

check('sorts by the reserved title key', ['Ada', 'Barbara', 'Grace', 'Katherine'], sp_test_query(['sort' => 'title:asc']));

// --- paging ------------------------------------------------------------------

check('pages', ['Ada', 'Barbara'], sp_test_query(['sort' => 'name:asc', 'pagination' => ['pageSize' => 2]]));
check(
    'and takes the second page',
    ['Grace', 'Katherine'],
    sp_test_query(['sort' => 'name:asc', 'pagination' => ['page' => 2, 'pageSize' => 2]])
);

// Strapi's other spelling, for a client that counts in offsets
check(
    'pages by offset',
    ['Barbara', 'Grace'],
    sp_test_query(['sort' => 'name:asc', 'pagination' => ['start' => 1, 'limit' => 2]])
);

check('a bare limit is a page size, not an offset window', 2, count(sp_test_query(['limit' => 2])));

// --- the same grammar from a template ----------------------------------------

$people = Content::collection('people');

check(
    'where() means what ?role= means',
    2,
    count($people->where('role', 'Engineer')->get())
);

check('where() takes an operator', 2, count($people->where('headcount', '>=', 7)->get()));
check('and a plain alias for it', 2, count($people->where('headcount', '$gte', 7)->get()));

check(
    'sort() orders by a field',
    'Ada',
    $people->sort('name')->first()->get('name')
);

check(
    'filter() spells $or',
    2,
    count($people->filter(['$or' => [['name' => ['$eq' => 'Ada']], ['role' => ['$eq' => 'Designer']]]])->get())
);

// a query is immutable, so holding one and reading it twice cannot have the
// first read reshape the second
$engineers = $people->where('role', 'Engineer');

check('a query does not reshape its parent', 4, count($people->get()));
check('and the derived one still filters', 2, count($engineers->get()));

// --- the draft index is a separate index -------------------------------------

$moved = Entries::save($staff, null, ['publish' => true, 'values' => ['name' => 'Mary', 'role' => 'Engineer']]);
Entries::save($staff, $moved['id'], ['values' => ['name' => 'Mary', 'role' => 'Designer']]);

check('the published index still says what is live', 3, count(sp_test_query(['role' => 'Engineer'])));

check(
    'while the draft index says what is being worked on',
    2,
    count(Entries::all($staff, [
        'view' => Entries::DRAFT,
        'spec' => ['filters' => ['role' => ['$eq' => 'Designer']]],
        'perPage' => 50,
    ])['entries'])
);

// --- searching ---------------------------------------------------------------

// an entry's values are a JSON blob, which WordPress search cannot see into, so
// the searchable text is mirrored into post_content. without that, search
// matched the derived title only — and a collection naming its entries by no
// field is a list of "Untitled" rows nothing could find
check('searches a field that is not the title', ['Barbara'], sp_test_names(['search' => 'Designer']));
check('and still matches the name', ['Ada'], sp_test_names(['search' => 'Ada']));
check('finds nothing for a word nobody wrote', [], sp_test_names(['search' => 'Plumber']));

// a live entry with unpublished edits must not be findable by words only its
// draft holds — searchable text is published exactly as the title is
$secretive = Entries::save($staff, null, ['publish' => true, 'values' => ['name' => 'Joan', 'role' => 'Engineer']]);
Entries::save($staff, $secretive['id'], ['values' => ['name' => 'Joan', 'role' => 'Cryptanalyst']]);

check('a draft-only word is not searchable', [], sp_test_names(['search' => 'Cryptanalyst']));

Entries::publish($staff, $secretive['id']);

check('and is once it is published', ['Joan'], sp_test_names(['search' => 'Cryptanalyst']));

// --- site settings -----------------------------------------------------------

echo "\nSite settings\n";

sp_test_reset();

// on by default: it cannot expose anything by itself, since a collection still
// has to publish itself, and a master switch defaulting to off would mean a
// collection whose own switch is ON quietly serving nothing
check('the API is on by default', true, SchemaPress\Settings::restEnabled());

check('stores what it is given', false, SchemaPress\Settings::save(['restApi' => false])['restApi']);
check('and reads it back', false, SchemaPress\Settings::restEnabled());

// a key that is absent means the default, not off — a settings row written by
// an older version must not read as the API switched off
check('a missing key defaults on', true, SchemaPress\Settings::normalize([])['restApi']);
check('junk normalizes to the default', true, SchemaPress\Settings::normalize('nonsense')['restApi']);

// the per-collection setting used to be one boolean covering both routes
check(
    'reads a legacy true as both',
    ['list' => true, 'single' => true],
    SchemaModel::normalize(['settings' => ['publicApi' => true]])['settings']['publicApi']
);

check(
    'reads a legacy false as neither',
    ['list' => false, 'single' => false],
    SchemaModel::normalize(['settings' => ['publicApi' => false]])['settings']['publicApi']
);

check(
    'defaults a collection to closed',
    ['list' => false, 'single' => false],
    SchemaModel::normalize([])['settings']['publicApi']
);

// the settings screen writes every collection's pair from one place, so the
// repository has to be able to change a setting without touching the schema —
// saveDefinition normalizes what it is handed, and a payload of settings alone
// would normalize the fields out of existence
sp_test_reset();

$kept = sp_test_type('Product', [
    ['label' => 'Name', 'type' => 'text'],
    ['label' => 'Price', 'type' => 'number'],
]);

SchemaRepository::saveSettings($kept, ['publicApi' => ['list' => true, 'single' => false]]);

check(
    'writes one setting',
    ['list' => true, 'single' => false],
    SchemaRepository::definition($kept)['settings']['publicApi']
);

check('and leaves the fields alone', 2, count(SchemaRepository::definition($kept)['fields']));

check(
    'and does not disturb the settings beside it',
    true,
    SchemaRepository::definition($kept)['settings']['draftAndPublish']
);

// each collection answers independently: opening one says nothing about the next
$other = sp_test_type('Supplier', [['label' => 'Name', 'type' => 'text']]);

check(
    'leaves another collection closed',
    ['list' => false, 'single' => false],
    SchemaRepository::definition($other)['settings']['publicApi']
);

// the listing carries them, which is what lets the settings screen draw a card
// per collection without a request each
check(
    'the listing reports what each publishes',
    ['list' => true, 'single' => false],
    ContentType::get($kept)['publicApi']
);

// --- discarding ---------------------------------------------------------------

echo "\nDiscarding\n";

sp_test_reset();

$roles = sp_test_type('Person', [['label' => 'Role', 'type' => 'text']]);
$person = Entries::save($roles, null, ['publish' => true, 'values' => ['role' => 'Engineer']]);

// the index rows are read directly rather than through a filtered query: the
// stub WP_Query does not implement meta_query, so a filter would come back
// matching everything and the check would pass whatever the index said
$personId = get_posts([
    'post_type' => ContentType::postType($roles),
    'post_status' => ['publish', 'draft'],
    'numberposts' => 1,
])[0]->ID;

/**
 * What one of an entry's draft index rows currently says.
 *
 * @param integer $id
 * @param string  $key
 *
 * @return string
 */
function sp_test_indexed($id, $key)
{
    return (string) get_post_meta($id, SchemaPress\Index::key($key, true), true);
}

check('the published index holds what went live', 'Engineer', (string) get_post_meta(
    $personId,
    SchemaPress\Index::key('role'),
    true
));

Entries::save($roles, $person['id'], ['values' => ['role' => 'Designer']]);

check('the draft index follows the draft', 'Designer', sp_test_indexed($personId, 'role'));
check('and the published index does not move', 'Engineer', (string) get_post_meta(
    $personId,
    SchemaPress\Index::key('role'),
    true
));

Entries::discard($roles, $person['id']);

// the draft index is derived from the draft, so throwing the draft away has to
// rebuild it. without this the builder's own table went on filtering and
// sorting the entry as a Designer, having reverted to Engineer everywhere else
check('a discarded draft takes its index with it', 'Engineer', sp_test_indexed($personId, 'role'));

check(
    'and the entry itself is back to what is published',
    'Engineer',
    Entries::get($roles, $person['id'], 0, Entries::DRAFT)['values']['role']
);

// --- result ------------------------------------------------------------------

echo "\n{$passed} passed, {$failed} failed\n";

exit($failed === 0 ? 0 : 1);
