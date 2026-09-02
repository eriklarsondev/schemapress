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
    'values' => ['name' => 'Ada Lovelace', 'role' => 'Engineer'],
]);

check('creates an entry', 'Ada Lovelace', $ada['title']);
check('stores its values', 'Engineer', $ada['values']['role']);
check('publishes by default', 'publish', $ada['status']);

// an untitled entry is named from its own content, not left blank in the list
$grace = Entries::save($team, null, ['values' => ['name' => 'Grace Hopper']]);

check('derives a title when none is given', 'Grace Hopper', $grace['title']);

$reloaded = Entries::get($team, $ada['id']);

check('reads an entry back', 'Ada Lovelace', $reloaded['values']['name']);

$updated = Entries::save($team, $ada['id'], [
    'title' => 'Ada Lovelace',
    'status' => 'draft',
    'values' => ['name' => 'Ada Lovelace', 'role' => 'Mathematician'],
]);

check('updates in place', $ada['id'], $updated['id']);
check('updates values', 'Mathematician', $updated['values']['role']);
check('updates status', 'draft', $updated['status']);

$listing = Entries::all($team, []);

check('lists entries', 2, count($listing['entries']));
check('reports the total', 2, $listing['total']);
check('counts them', 2, Entries::count($team));

$searched = Entries::all($team, ['search' => 'Grace']);

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

$drifted = Entries::get($team, $ada['id']);

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

Entries::save($team, null, ['title' => 'Ada', 'values' => ['name' => 'Ada', 'role' => 'Engineer']]);
Entries::save($team, null, ['title' => 'Grace', 'values' => ['name' => 'Grace', 'role' => 'Admiral']]);

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
check('exposes the status', 'publish', $entries[0]->status());
check('knows it is published', true, $entries[0]->isPublished());

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

// --- relations ---------------------------------------------------------------

echo "\nRelations\n";

sp_test_reset();

$team = sp_test_type('Team Member', [['label' => 'Name', 'type' => 'text']]);
$ada = Entries::save($team, null, ['title' => 'Ada', 'values' => ['name' => 'Ada']]);

$page = sp_test_type('Story', [
    ['label' => 'Headline', 'type' => 'text'],
    [
        'label' => 'Author',
        'type' => 'relation',
        'config' => ['collection' => $team, 'multiple' => false],
    ],
]);

$story = Entries::save($page, null, [
    'title' => 'A story',
    'values' => ['headline' => 'A story', 'author' => $ada['id']],
]);

check('stores a relation as an id', $ada['id'], $story['values']['author']);
check('resolves it to the entry', 'Ada', $story['data']['author']['data']['name']);

$reloadedStory = Entries::get($page, $story['id']);

check('resolves on read too', 'Ada', $reloadedStory['data']['author']['data']['name']);

// a relation pointing nowhere must not fatal
$orphan = Entries::save($page, null, ['title' => 'Orphan', 'values' => ['author' => 999999]]);

check('a dangling relation resolves to null', null, $orphan['data']['author']);

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
