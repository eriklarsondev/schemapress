<?php

/**
 * An in-memory WordPress, big enough to run the plugin's real code paths.
 *
 * Not a mock of the plugin — the plugin's own classes run unmodified. This is
 * the slice of WordPress underneath them: a post table, a meta table, and the
 * handful of functions they call. That is what lets a test create a content
 * type, save an entry and read it back through the public API, which is the
 * only kind of test that would have caught the failures this plugin has had.
 *
 * @package SchemaPress
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

if (!defined('SCHEMAPRESS_PATH')) {
    define('SCHEMAPRESS_PATH', dirname(__DIR__) . '/');
}

if (!defined('SCHEMAPRESS_VERSION')) {
    define('SCHEMAPRESS_VERSION', 'test');
}

// --- the store ---------------------------------------------------------------

$GLOBALS['wp_posts'] = [];
$GLOBALS['wp_meta'] = [];
$GLOBALS['wp_post_types'] = ['page' => true, 'attachment' => true];
$GLOBALS['wp_next_id'] = 100;
$GLOBALS['wp_filters'] = [];
$GLOBALS['wp_actions'] = [];
$GLOBALS['wp_options'] = [];
$GLOBALS['wp_rest_routes'] = [];

/**
 * Empties the store between tests.
 *
 * @return void
 */
function sp_test_reset()
{
    $GLOBALS['wp_posts'] = [];
    $GLOBALS['wp_meta'] = [];
    $GLOBALS['wp_post_types'] = ['page' => true, 'attachment' => true];
    $GLOBALS['wp_next_id'] = 100;
    $GLOBALS['wp_next_stamp'] = 0;
    $GLOBALS['sp_test_undeletable'] = [];
    $GLOBALS['wp_actions'] = [];
    $GLOBALS['wp_options'] = [];
    $GLOBALS['wp_rest_routes'] = [];

    SchemaPress\ContentType::flush();
    SchemaPress\SchemaRepository::flush();
    SchemaPress\Settings::flush();
}

// --- escaping and sanitizing -------------------------------------------------

function sanitize_text_field($value)
{
    return trim(strip_tags((string) $value));
}
function sanitize_textarea_field($value)
{
    return trim(strip_tags((string) $value));
}
function sanitize_key($value)
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value));
}
function sanitize_title($value)
{
    return sanitize_key(str_replace(' ', '-', (string) $value));
}
function wp_kses_post($value)
{
    return (string) $value;
}
function sanitize_email($value)
{
    return trim((string) $value);
}
function is_email($value)
{
    return (bool) filter_var((string) $value, FILTER_VALIDATE_EMAIL);
}

function esc_url_raw($value)
{
    $value = trim((string) $value);

    // enough of WordPress's behavior to matter here: a scheme it does not
    // allow yields an empty string rather than a stored javascript: payload
    if ($value !== '' && preg_match('#^\s*(javascript|data|vbscript):#i', $value)) {
        return '';
    }

    return $value;
}

function esc_url($value)
{
    return (string) $value;
}
function esc_attr($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES);
}
function esc_html($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES);
}
function absint($value)
{
    return abs((int) $value);
}
function wp_rand($min = 0, $max = PHP_INT_MAX)
{
    return random_int($min, $max);
}
function wp_slash($value)
{
    return $value;
}
function wp_unslash($value)
{
    return $value;
}
function __($text, $domain = null)
{
    return $text;
}
function esc_html__($text, $domain = null)
{
    return $text;
}
function esc_attr__($text, $domain = null)
{
    return htmlspecialchars((string) $text, ENT_QUOTES);
}
function wp_strip_all_tags($text)
{
    return strip_tags((string) $text);
}

// WordPress polyfills this in wp-includes/compat.php when the mbstring
// extension is absent, so plugin code may call it unguarded. this CLI has no
// mbstring, which is exactly the case that polyfill exists for
if (!function_exists('mb_strlen')) {
    function mb_strlen($text, $encoding = null)
    {
        preg_match_all('/./us', (string) $text, $matches);

        return count($matches[0]);
    }
}
function wpautop($value)
{
    return '<p>' . $value . '</p>';
}
function do_shortcode($value)
{
    return $value;
}

function wp_trim_words($text, $count = 55, $more = null)
{
    $words = preg_split('/\s+/', trim(strip_tags((string) $text)));

    return implode(' ', array_slice($words, 0, $count));
}

// --- hooks -------------------------------------------------------------------

/**
 * Actions really dispatch, because the lifecycle hooks are a feature now: a
 * test that "fires entry_published" has to be able to hear it.
 */
function add_action($hook, $callback = null, $priority = 10, $args = 1)
{
    $GLOBALS['wp_actions'][$hook][] = $callback;
}

function do_action($hook, ...$args)
{
    foreach ($GLOBALS['wp_actions'][$hook] ?? [] as $callback) {
        call_user_func_array($callback, $args);
    }
}

/**
 * Filters that actually run.
 *
 * These were no-ops — add_filter stored nothing and apply_filters handed the
 * value straight back — which meant any behavior the plugin achieves THROUGH a
 * filter was invisible to the suite. Entries::restore() is the case that
 * surfaced it: it relies on `wp_untrash_post_status`, and with a filter that
 * never ran, the stub's own untrash quietly did the plugin's job for it and the
 * test passed for a reason real WordPress would not reproduce.
 *
 * Priority-ordered and argument-counted the way WordPress does it. With nothing
 * registered, apply_filters still returns the value unchanged, so every existing
 * call is unaffected.
 */
$GLOBALS['wp_filters'] = [];

function add_filter($hook, $callback = null, $priority = 10, $args = 1)
{
    $GLOBALS['wp_filters'][$hook][$priority][] = ['callback' => $callback, 'args' => (int) $args];

    return true;
}

function remove_filter($hook, $callback, $priority = 10)
{
    foreach ($GLOBALS['wp_filters'][$hook][$priority] ?? [] as $index => $registered) {
        if ($registered['callback'] === $callback) {
            unset($GLOBALS['wp_filters'][$hook][$priority][$index]);

            return true;
        }
    }

    return false;
}

function apply_filters($hook, $value, ...$extra)
{
    $byPriority = $GLOBALS['wp_filters'][$hook] ?? [];
    ksort($byPriority);

    foreach ($byPriority as $callbacks) {
        foreach ($callbacks as $registered) {
            $args = array_slice(array_merge([$value], $extra), 0, max(1, $registered['args']));
            $value = call_user_func_array($registered['callback'], $args);
        }
    }

    return $value;
}
/**
 * Everything is permitted unless a test says otherwise.
 *
 * Most of the suite is not about permissions and should not have to grant
 * itself any. A test that IS about them sets $GLOBALS['sp_test_caps'] to the
 * map it wants and puts it back afterwards.
 */
function current_user_can($cap, $id = null)
{
    $caps = $GLOBALS['sp_test_caps'] ?? null;

    return is_array($caps) ? !empty($caps[$cap]) : true;
}
function add_menu_page()
{
    return 'toplevel_page_schemapress';
}
function add_submenu_page()
{
    return 'schemapress_page_docs';
}
function admin_url($path = '')
{
    return 'http://example.test/wp-admin/' . $path;
}
function rest_url($path = '')
{
    return 'http://example.test/wp-json/' . $path;
}
function wp_json_encode($value)
{
    return json_encode($value);
}

// --- options -----------------------------------------------------------------

function get_option($name, $default = false)
{
    return array_key_exists($name, $GLOBALS['wp_options']) ? $GLOBALS['wp_options'][$name] : $default;
}

function update_option($name, $value)
{
    $GLOBALS['wp_options'][$name] = $value;

    return true;
}

// false when the row already exists, which is the whole reason anything calls
// this instead of update_option — see the upgrade lock.
function add_option($name, $value = '', $deprecated = '', $autoload = true)
{
    if (array_key_exists($name, $GLOBALS['wp_options'])) {
        return false;
    }

    $GLOBALS['wp_options'][$name] = $value;

    return true;
}

function delete_option($name)
{
    unset($GLOBALS['wp_options'][$name]);

    return true;
}

function wp_list_pluck($list, $field)
{
    return array_map(function ($item) use ($field) {
        return is_object($item) ? ($item->$field ?? null) : ($item[$field] ?? null);
    }, (array) $list);
}

function wp_generate_uuid4()
{
    return sprintf(
        '%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff),
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff)
    );
}
function is_wp_error($value)
{
    return $value instanceof WP_Error;
}

// --- REST --------------------------------------------------------------------

// recorded rather than discarded: whether the content API's namespace is
// registered AT ALL is the master switch's whole behavior, so a test has to be
// able to see what was registered
function register_rest_route($namespace, $route, $args = [])
{
    $GLOBALS['wp_rest_routes'][] = $namespace . $route;

    return true;
}

/**
 * The response is the payload here, so a test can read it as the array the
 * handler built rather than unwrapping an object that does nothing else.
 */
/**
 * A response that still reads as the array it wraps.
 *
 * The content API sets headers on what it returns — an ETag, a Cache-Control —
 * so a bare array is no longer enough. Making this ArrayAccess means every
 * assertion written as `$response['data']` goes on meaning what it did, and a
 * test that wants to check a header can ask for one.
 */
class WP_REST_Response implements ArrayAccess
{
    public $data;
    public $status;
    public $headers = [];

    public function __construct($data = null, $status = 200)
    {
        $this->data = $data;
        $this->status = $status;
    }

    public function header($name, $value)
    {
        $this->headers[$name] = $value;
    }
    public function get_headers()
    {
        return $this->headers;
    }
    public function get_status()
    {
        return $this->status;
    }
    public function get_data()
    {
        return $this->data;
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->data[$offset] ?? null;
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return isset($this->data[$offset]);
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        $this->data[$offset] = $value;
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        unset($this->data[$offset]);
    }
}

function rest_ensure_response($data)
{
    return $data instanceof WP_REST_Response ? $data : new WP_REST_Response($data);
}

/**
 * Enough of a request for the content API: the path parts it matches on, and
 * the query string it filters by.
 */
class WP_REST_Request implements ArrayAccess
{
    private $params;
    private $query;
    private $headers;

    public function __construct(array $params = [], array $query = [], array $headers = [])
    {
        $this->params = $params;
        $this->query = $query;
        $this->headers = $headers;
    }

    public function get_query_params()
    {
        return $this->query;
    }
    public function get_param($key)
    {
        return $this->params[$key] ?? null;
    }

    // WordPress normalizes a header name to lowercase with underscores, which
    // is why the API asks for `if_none_match` rather than `If-None-Match`
    public function get_header($name)
    {
        return $this->headers[$name] ?? null;
    }

    public function get_json_params()
    {
        return $this->params['__json'] ?? [];
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->params[$offset] ?? null;
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return isset($this->params[$offset]);
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        $this->params[$offset] = $value;
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        unset($this->params[$offset]);
    }
}

class WP_Error
{
    public $code;
    public $message;
    public $data;

    public function __construct($code = '', $message = '', $data = [])
    {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }

    public function get_error_code()
    {
        return $this->code;
    }
    public function get_error_message()
    {
        return $this->message;
    }
    public function get_error_data()
    {
        return $this->data;
    }
}

// --- post types --------------------------------------------------------------

function register_post_type($type, $args = [])
{
    $GLOBALS['wp_post_types'][$type] = true;
}
function post_type_exists($type)
{
    return isset($GLOBALS['wp_post_types'][$type]);
}

// --- posts -------------------------------------------------------------------

/**
 * WordPress uniquifies a post_name within its post type, appending -2, -3 and
 * so on. The plugin leans on that for slugs, so the stub has to do it too —
 * without it two entries called the same thing would silently share an address
 * and the test proving they do not would pass for the wrong reason.
 *
 * @param string  $slug
 * @param string  $type
 * @param integer $exclude the post being named, which cannot clash with itself
 *
 * @return string
 */
function sp_test_unique_slug($slug, $type, $exclude = 0)
{
    if ($slug === '') {
        return '';
    }

    $taken = [];

    foreach ($GLOBALS['wp_posts'] as $post) {
        if ($post->post_type === $type && (int) $post->ID !== (int) $exclude) {
            $taken[] = $post->post_name;
        }
    }

    $unique = $slug;
    $suffix = 2;

    while (in_array($unique, $taken, true)) {
        $unique = $slug . '-' . $suffix;
        $suffix++;
    }

    return $unique;
}

/**
 * The next modified stamp, one second on from the last.
 *
 * WordPress sets post_modified on every insert AND every update, and stamping
 * everything with one fixed time here hid a real bug for a release: nothing in
 * the plugin moved a schema's stamp when its definition was saved, and the
 * conflict check that reads it could not have failed under a stub where the
 * stamp never moved either.
 *
 * A counter rather than the wall clock, because two writes in the same second
 * would tie and the suite has to be able to tell "before" from "after".
 *
 * @return string
 */
function sp_test_stamp()
{
    return gmdate('Y-m-d H:i:s', strtotime('2026-09-02 00:00:00') + $GLOBALS['wp_next_stamp']++);
}

function wp_insert_post($data, $wp_error = false)
{
    $id = $GLOBALS['wp_next_id']++;
    $type = $data['post_type'] ?? 'post';

    $GLOBALS['wp_posts'][$id] = (object) [
        'ID' => $id,
        'post_type' => $type,
        'post_title' => $data['post_title'] ?? '',
        'post_content' => $data['post_content'] ?? '',
        'post_excerpt' => $data['post_excerpt'] ?? '',
        'post_name' => sp_test_unique_slug(
            $data['post_name'] ?? sanitize_title($data['post_title'] ?? ''),
            $type,
            $id
        ),
        'post_status' => $data['post_status'] ?? 'publish',
        'post_modified_gmt' => sp_test_stamp(),
    ];

    return $id;
}

function wp_update_post($data, $wp_error = false)
{
    $id = absint($data['ID'] ?? 0);

    if (!isset($GLOBALS['wp_posts'][$id])) {
        return new WP_Error('invalid_post', 'No such post');
    }

    foreach (['post_title', 'post_content', 'post_excerpt', 'post_status', 'post_type'] as $key) {
        if (isset($data[$key])) {
            $GLOBALS['wp_posts'][$id]->$key = $data[$key];
        }
    }

    if (isset($data['post_name'])) {
        $GLOBALS['wp_posts'][$id]->post_name = sp_test_unique_slug(
            $data['post_name'],
            $GLOBALS['wp_posts'][$id]->post_type,
            $id
        );
    }

    // as WordPress does, and unconditionally: wp_insert_post overwrites
    // post_modified on every update, which is what makes the stamp a version
    // rather than a description of the last edit somebody typed
    $GLOBALS['wp_posts'][$id]->post_modified_gmt = sp_test_stamp();

    return $id;
}

function get_post($id)
{
    $id = absint($id);

    return isset($GLOBALS['wp_posts'][$id]) ? $GLOBALS['wp_posts'][$id] : null;
}

function get_post_type($id)
{
    $post = get_post($id);

    return $post ? $post->post_type : false;
}

function get_post_status($id)
{
    $post = get_post($id);

    return $post ? $post->post_status : false;
}

function get_the_title($post)
{
    $post = is_object($post) ? $post : get_post($post);

    return $post ? $post->post_title : '';
}

function wp_trash_post($id)
{
    $post = get_post($id);

    if (!$post) {
        return false;
    }

    // WordPress records the status it is coming from and the moment it went, and
    // the plugin reads both — one to restore to, one to say how long is left
    update_post_meta($id, '_wp_trash_meta_status', $post->post_status);
    update_post_meta($id, '_wp_trash_meta_time', time());

    $post->post_status = 'trash';

    return true;
}

function wp_untrash_post($id)
{
    $post = get_post($id);

    if (!$post || $post->post_status !== 'trash') {
        return false;
    }

    $was = (string) get_post_meta($id, '_wp_trash_meta_status', true);

    // WordPress 5.6+: back to draft, whatever it was, unless a filter says
    // otherwise. the previous status is handed to the filter precisely so a
    // caller can choose it. this used to restore the previous status on its
    // own, which is kinder than WordPress and let a plugin bug pass the suite
    $post->post_status = apply_filters('wp_untrash_post_status', 'draft', $id, $was);

    delete_post_meta($id, '_wp_trash_meta_status');
    delete_post_meta($id, '_wp_trash_meta_time');

    return true;
}

function wp_delete_post($id, $force = false)
{
    $id = absint($id);

    // stands in for `pre_delete_post`, which lets any other plugin on the site
    // veto a deletion. WordPress returns false and leaves the post where it is,
    // which is the case that used to make Entries::emptyTrash() spin forever:
    // it reads the front of the trash and deletes what it read, so a page that
    // survives its own deletion is read again, vetoed again, and the request
    // never returns
    if (in_array($id, (array) ($GLOBALS['sp_test_undeletable'] ?? []), true)) {
        return false;
    }

    unset($GLOBALS['wp_posts'][$id]);

    return true;
}

function get_posts($args = [])
{
    $types = (array) ($args['post_type'] ?? 'post');
    $statuses = (array) ($args['post_status'] ?? ['publish']);

    $found = array_values(array_filter($GLOBALS['wp_posts'], function ($post) use ($types, $statuses) {
        return in_array($post->post_type, $types, true)
            && (in_array('any', $statuses, true) || in_array($post->post_status, $statuses, true));
    }));

    if (!empty($args['name'])) {
        $name = (string) $args['name'];

        $found = array_values(array_filter($found, function ($post) use ($name) {
            return $post->post_name === $name;
        }));
    }

    if (!empty($args['meta_key'])) {
        $key = $args['meta_key'];
        $want = $args['meta_value'] ?? '';

        $found = array_values(array_filter($found, function ($post) use ($key, $want) {
            // one meta key may hold many values — which is how Index stores a
            // multi-select — so the question is membership, not equality
            $stored = get_post_meta($post->ID, $key, true);

            return in_array((string) $want, array_map('strval', (array) $stored), true);
        }));
    }

    usort($found, function ($a, $b) {
        return strcmp($a->post_title, $b->post_title);
    });

    if (!empty($args['numberposts']) && $args['numberposts'] > 0) {
        $found = array_slice($found, 0, (int) $args['numberposts']);
    }

    if (($args['fields'] ?? '') === 'ids') {
        return array_map(function ($post) {
            return $post->ID;
        }, $found);
    }

    return $found;
}

// --- meta_query ---------------------------------------------------------------

/**
 * Whether one post satisfies a meta_query tree.
 *
 * This exists because without it the suite could not test filtering AT ALL. A
 * WP_Query that ignored meta_query returned every row whatever was asked of it,
 * so a filter test passed whether the filter worked or not — which is how a
 * check on a stale index came to pass while the index was still stale.
 *
 * Nested groups and `relation` are supported, because Query::metaQuery emits
 * them for `$and` / `$or`.
 *
 * @param integer $post_id
 * @param mixed   $query
 *
 * @return boolean
 */
function sp_test_meta_matches($post_id, $query)
{
    if (!is_array($query) || !$query) {
        return true;
    }

    $relation = strtoupper((string) ($query['relation'] ?? 'AND'));

    unset($query['relation']);

    $results = [];

    foreach ($query as $clause) {
        if (!is_array($clause)) {
            continue;
        }

        $results[] = isset($clause['key'])
            ? sp_test_meta_clause($post_id, $clause)
            : sp_test_meta_matches($post_id, $clause);
    }

    if (!$results) {
        return true;
    }

    return $relation === 'OR'
        ? in_array(true, $results, true)
        : !in_array(false, $results, true);
}

/**
 * Whether one clause holds for a post.
 *
 * A key may hold several values — which is how Index stores a multi-select —
 * and the clause holds when ANY of them satisfies it, as it does in SQL.
 *
 * @param integer $post_id
 * @param array   $clause
 *
 * @return boolean
 */
function sp_test_meta_clause($post_id, array $clause)
{
    $stored = get_post_meta($post_id, $clause['key'], true);
    $values = is_array($stored) ? $stored : [$stored];

    foreach ($values as $value) {
        if (sp_test_compare(
            $value,
            strtoupper((string) ($clause['compare'] ?? '=')),
            $clause['value'] ?? '',
            strtoupper((string) ($clause['type'] ?? 'CHAR'))
        )) {
            return true;
        }
    }

    return false;
}

/**
 * One comparison, as MySQL would make it.
 *
 * Text compares case-insensitively, because WordPress's collation does and the
 * plugin relies on it — Query::OPERATORS maps $contains and $containsi to the
 * same LIKE for exactly that reason.
 *
 * @param mixed  $value
 * @param string $compare
 * @param mixed  $want
 * @param string $type    NUMERIC or CHAR
 *
 * @return boolean
 */
function sp_test_compare($value, $compare, $want, $type)
{
    $numeric = $type === 'NUMERIC';

    $cast = function ($one) use ($numeric) {
        return $numeric ? (float) $one : (string) $one;
    };

    $same = function ($one, $two) use ($numeric) {
        return $numeric
            ? (float) $one === (float) $two
            : strcasecmp((string) $one, (string) $two) === 0;
    };

    switch ($compare) {
        case '=':
            return $same($value, $want);

        case '!=':
            return !$same($value, $want);

        case '>':
            return $cast($value) > $cast($want);

        case '>=':
            return $cast($value) >= $cast($want);

        case '<':
            return $cast($value) < $cast($want);

        case '<=':
            return $cast($value) <= $cast($want);

        case 'IN':
        case 'NOT IN':
            $in = false;

            foreach ((array) $want as $one) {
                if ($same($value, $one)) {
                    $in = true;
                    break;
                }
            }

            return $compare === 'IN' ? $in : !$in;

        case 'LIKE':
            return stripos((string) $value, (string) $want) !== false;

        case 'NOT LIKE':
            return stripos((string) $value, (string) $want) === false;

        case 'REGEXP':
            return (bool) preg_match('/' . str_replace('/', '\\/', (string) $want) . '/i', (string) $value);

        case 'BETWEEN':
            $range = array_values((array) $want);

            return count($range) >= 2
                && $cast($value) >= $cast($range[0])
                && $cast($value) <= $cast($range[1]);
    }

    return false;
}

class WP_Query
{
    public $posts = [];
    public $found_posts = 0;
    public $max_num_pages = 0;

    public function __construct($args = [])
    {
        $types = (array) ($args['post_type'] ?? 'post');
        $statuses = (array) ($args['post_status'] ?? ['publish']);
        $search = (string) ($args['s'] ?? '');
        $meta = $args['meta_query'] ?? [];

        $all = array_values(array_filter(
            $GLOBALS['wp_posts'],
            function ($post) use ($types, $statuses, $search, $meta) {
                if (!in_array($post->post_type, $types, true)) {
                    return false;
                }

                if (!in_array($post->post_status, $statuses, true)) {
                    return false;
                }

                // title AND content, as WordPress searches — the plugin mirrors
                // an entry's searchable text into post_content on publish
                if ($search !== ''
                    && stripos($post->post_title, $search) === false
                    && stripos((string) ($post->post_content ?? ''), $search) === false) {
                    return false;
                }

                return sp_test_meta_matches($post->ID, $meta);
            }
        ));

        $this->order($all, $args);

        $perPage = max(1, (int) ($args['posts_per_page'] ?? 10));
        $page = max(1, (int) ($args['paged'] ?? 1));
        $offset = isset($args['offset']) ? (int) $args['offset'] : ($page - 1) * $perPage;

        $this->found_posts = count($all);
        $this->max_num_pages = (int) ceil($this->found_posts / $perPage);
        $this->posts = array_slice($all, $offset, $perPage);
    }

    /**
     * Applies the ordering arguments.
     *
     * `orderby` arrives as a string from the admin listing and as an ordered map
     * of field => direction from Query::orderArgs, so both are accepted.
     *
     * @param array $posts by reference
     * @param array $args
     *
     * @return void
     */
    private function order(array &$posts, array $args)
    {
        $orderby = $args['orderby'] ?? 'date';
        $order = strtoupper((string) ($args['order'] ?? 'DESC'));
        $clauses = is_array($orderby) ? $orderby : [$orderby => $order];

        $key = (string) ($args['meta_key'] ?? '');
        $numeric = strtoupper((string) ($args['meta_type'] ?? 'CHAR')) === 'NUMERIC';

        usort($posts, function ($a, $b) use ($clauses, $key, $numeric) {
            foreach ($clauses as $field => $direction) {
                $result = $this->compare($a, $b, (string) $field, $key, $numeric);

                if ($result !== 0) {
                    return strtoupper((string) $direction) === 'DESC' ? -$result : $result;
                }
            }

            // newest first, which is what an unordered WP_Query gives
            return $b->ID <=> $a->ID;
        });
    }

    /**
     * Compares two posts on one ordering field.
     *
     * @param object  $a
     * @param object  $b
     * @param string  $field
     * @param string  $key     the meta key, when ordering by one
     * @param boolean $numeric
     *
     * @return integer
     */
    private function compare($a, $b, $field, $key, $numeric)
    {
        if ($field === 'meta_value' || $field === 'meta_value_num') {
            $one = get_post_meta($a->ID, $key, true);
            $two = get_post_meta($b->ID, $key, true);

            // a multi-value row orders by its first value, as MySQL would order
            // by whichever row the join produced
            $one = is_array($one) ? reset($one) : $one;
            $two = is_array($two) ? reset($two) : $two;

            return $numeric || $field === 'meta_value_num'
                ? ((float) $one <=> (float) $two)
                : strcasecmp((string) $one, (string) $two);
        }

        if ($field === 'title') {
            return strcasecmp($a->post_title, $b->post_title);
        }

        if ($field === 'name') {
            return strcasecmp($a->post_name, $b->post_name);
        }

        if ($field === 'modified') {
            return strcmp((string) $a->post_modified_gmt, (string) $b->post_modified_gmt);
        }

        // the store has no post_date, and ids are handed out in order, so the id
        // is the creation order this is asking about
        return $a->ID <=> $b->ID;
    }
}

function wp_count_posts($type)
{
    // WordPress returns a bare stdClass for a post type that is not registered,
    // so every status reads as absent rather than as zero. The stub said zero,
    // which hid a real bug: ContentType::all() counted entries while its own
    // registerAll() was still mid-registration, and cached nothing for all of
    // them — `wp schemapress list` reported every collection empty.
    if (!post_type_exists($type)) {
        return new stdClass();
    }

    $counts = ['publish' => 0, 'draft' => 0];

    foreach ($GLOBALS['wp_posts'] as $post) {
        if ($post->post_type === $type && isset($counts[$post->post_status])) {
            $counts[$post->post_status]++;
        }
    }

    return (object) $counts;
}

// --- meta --------------------------------------------------------------------

function update_post_meta($id, $key, $value)
{
    $GLOBALS['wp_meta'][absint($id)][$key] = $value;

    return true;
}

function get_post_meta($id, $key = '', $single = false)
{
    $value = $GLOBALS['wp_meta'][absint($id)][$key] ?? '';

    return $single ? $value : $value;
}

function add_post_meta($id, $key, $value)
{
    $existing = $GLOBALS['wp_meta'][absint($id)][$key] ?? null;

    // one key, many values — which is how Index stores a multi-select
    $GLOBALS['wp_meta'][absint($id)][$key] = $existing === null
        ? $value
        : array_merge((array) $existing, [$value]);

    return true;
}

function delete_post_meta($id, $key)
{
    unset($GLOBALS['wp_meta'][absint($id)][$key]);

    return true;
}

// --- attachments -------------------------------------------------------------

function wp_attachment_is_image($id)
{
    return get_post_type($id) === 'attachment';
}
function wp_get_attachment_url($id)
{
    return 'http://example.test/uploads/' . $id . '.jpg';
}
function wp_get_attachment_metadata($id)
{
    // the sizes are in here, which is the point: the resolver reads them from
    // the metadata rather than asking WordPress for each one separately
    return [
        'width' => 800,
        'height' => 600,
        'file' => $id . '.jpg',
        'sizes' => [
            'thumbnail' => ['file' => $id . '-150x150.jpg', 'width' => 150, 'height' => 150],
        ],
    ];
}
function wp_get_attachment_caption($id)
{
    return '';
}
function get_post_mime_type($id)
{
    return 'image/jpeg';
}
function get_intermediate_image_sizes()
{
    return ['thumbnail'];
}
function wp_get_attachment_image_src($id, $size)
{
    return ['http://example.test/uploads/' . $id . '-t.jpg', 150, 150];
}
function wp_get_attachment_image_srcset($id, $size)
{
    return '';
}
function get_permalink($post)
{
    return 'http://example.test/?p=' . (is_object($post) ? $post->ID : $post);
}

// --- the plugin --------------------------------------------------------------

/**
 * Just enough $wpdb for Index::clear(), which finds an entry's index rows by
 * key prefix — the one thing in the plugin that cannot be expressed with the
 * post meta functions, since they have no wildcard.
 */
class SP_Test_Wpdb
{
    public $postmeta = 'wp_postmeta';

    public function esc_like($text)
    {
        return addcslashes($text, '_%\\');
    }

    public function prepare($query, ...$args)
    {
        return [$query, $args];
    }

    public function get_col($prepared)
    {
        list($query, $args) = $prepared;

        if (strpos($query, 'meta_key') === false) {
            return [];
        }

        $id = absint($args[0]);
        $prefix = rtrim(str_replace('\\', '', (string) $args[1]), '%');

        $keys = array_keys($GLOBALS['wp_meta'][$id] ?? []);

        return array_values(array_filter($keys, function ($key) use ($prefix) {
            return strpos($key, $prefix) === 0;
        }));
    }
}

$GLOBALS['wpdb'] = new SP_Test_Wpdb();

// --- scheduling and roles ----------------------------------------------------

/**
 * Just enough WP-Cron for Batch, which asks whether the drain is already
 * scheduled before scheduling it. Nothing here runs anything: a test that wants
 * a job finished calls Batch::finish(), which is what WP-CLI does too.
 */
$GLOBALS['wp_cron'] = [];

function wp_next_scheduled($hook)
{
    return $GLOBALS['wp_cron'][$hook] ?? false;
}

function wp_schedule_single_event($when, $hook)
{
    $GLOBALS['wp_cron'][$hook] = (int) $when;

    return true;
}

function wp_clear_scheduled_hook($hook)
{
    unset($GLOBALS['wp_cron'][$hook]);
}

/**
 * Roles, as much of them as Capabilities needs: enough to grant a capability,
 * read it back, and ask which roles a user holds.
 */
class SP_Test_Role
{
    public $name;
    public $capabilities = [];

    public function __construct($name)
    {
        $this->name = $name;
    }
    public function add_cap($cap)
    {
        $this->capabilities[$cap] = true;
    }
    public function remove_cap($cap)
    {
        unset($this->capabilities[$cap]);
    }
}

class SP_Test_Roles
{
    public $roles = [];
    public $role_objects = [];

    public function __construct()
    {
        foreach (['administrator' => 'Administrator', 'editor' => 'Editor'] as $slug => $label) {
            $this->roles[$slug] = ['name' => $label];
            $this->role_objects[$slug] = new SP_Test_Role($slug);
        }
    }
}

function wp_roles()
{
    if (!isset($GLOBALS['wp_roles_instance'])) {
        $GLOBALS['wp_roles_instance'] = new SP_Test_Roles();
    }

    return $GLOBALS['wp_roles_instance'];
}

function get_role($name)
{
    return wp_roles()->role_objects[$name] ?? null;
}

function translate_user_role($name)
{
    return $name;
}

function wp_get_current_user()
{
    return (object) ['roles' => $GLOBALS['wp_current_roles'] ?? ['administrator']];
}

function home_url()
{
    return 'https://example.test';
}

if (!function_exists('_n')) {
    function _n($single, $plural, $number, $domain = 'default')
    {
        return (int) $number === 1 ? $single : $plural;
    }
}

function wp_parse_url($url, $component = -1)
{
    return parse_url($url, $component);
}

function sanitize_hex_color($color)
{
    $color = trim((string) $color);

    return preg_match('/^#([A-Fa-f0-9]{3}){1,2}$/', $color) ? $color : '';
}

require_once SCHEMAPRESS_PATH . 'classes/class-inflector.php';
require_once SCHEMAPRESS_PATH . 'classes/class-datasets.php';
require_once SCHEMAPRESS_PATH . 'classes/class-dates.php';
require_once SCHEMAPRESS_PATH . 'classes/class-field-types.php';
require_once SCHEMAPRESS_PATH . 'classes/class-schema-model.php';
require_once SCHEMAPRESS_PATH . 'classes/class-schema.php';
require_once SCHEMAPRESS_PATH . 'classes/class-schema-repository.php';
require_once SCHEMAPRESS_PATH . 'classes/class-settings.php';
require_once SCHEMAPRESS_PATH . 'classes/class-content-sanitizer.php';
require_once SCHEMAPRESS_PATH . 'classes/class-validator.php';
require_once SCHEMAPRESS_PATH . 'classes/class-resolver.php';
require_once SCHEMAPRESS_PATH . 'classes/class-fields.php';
require_once SCHEMAPRESS_PATH . 'classes/class-entry.php';
require_once SCHEMAPRESS_PATH . 'classes/class-query.php';
require_once SCHEMAPRESS_PATH . 'classes/class-batch.php';
require_once SCHEMAPRESS_PATH . 'classes/class-index.php';
require_once SCHEMAPRESS_PATH . 'classes/class-entries.php';
require_once SCHEMAPRESS_PATH . 'classes/class-content-type.php';
require_once SCHEMAPRESS_PATH . 'classes/class-collection.php';
require_once SCHEMAPRESS_PATH . 'classes/class-content.php';
require_once SCHEMAPRESS_PATH . 'classes/class-component.php';
require_once SCHEMAPRESS_PATH . 'classes/class-capabilities.php';
require_once SCHEMAPRESS_PATH . 'classes/class-portability.php';
require_once SCHEMAPRESS_PATH . 'classes/class-api.php';
require_once SCHEMAPRESS_PATH . 'classes/class-upgrade.php';

// field types register on construction
new SchemaPress\FieldTypes();

// --- helpers for tests -------------------------------------------------------

/**
 * Creates a content type with the given fields, the way the REST layer does.
 *
 * @param string $title
 * @param array  $fields
 *
 * @return integer The type id.
 */
function sp_test_type($title, array $fields = [], array $settings = [], $description = '')
{
    $id = wp_insert_post([
        'post_type' => SchemaPress\Schema::POST_TYPE,
        'post_title' => $title,
        'post_excerpt' => $description,
        'post_status' => 'publish',
    ]);

    SchemaPress\SchemaRepository::saveDefinition($id, [
        'fields' => $fields,
        'settings' => $settings,
    ]);
    SchemaPress\ContentType::key($id);
    SchemaPress\ContentType::register($id);

    return $id;
}
