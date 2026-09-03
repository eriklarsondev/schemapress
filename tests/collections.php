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
check('defaults the form width', 'full', $fields[0]['config']['width']);
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
    function ($option) { return $option['value'] === 'KR'; }
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
// neighbouring entry
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

// --- result ------------------------------------------------------------------

echo "\n{$passed} passed, {$failed} failed\n";

exit($failed === 0 ? 0 : 1);
