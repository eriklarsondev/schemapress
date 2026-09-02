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

    SchemaPress\ContentType::flush();
    SchemaPress\SchemaRepository::flush();
}

// --- escaping and sanitizing -------------------------------------------------

function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_textarea_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function sanitize_title($value) { return sanitize_key(str_replace(' ', '-', (string) $value)); }
function wp_kses_post($value) { return (string) $value; }
function esc_url_raw($value) { return (string) $value; }
function esc_url($value) { return (string) $value; }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function absint($value) { return abs((int) $value); }
function wp_rand($min = 0, $max = PHP_INT_MAX) { return random_int($min, $max); }
function wp_slash($value) { return $value; }
function wp_unslash($value) { return $value; }
function __($text, $domain = null) { return $text; }
function esc_html__($text, $domain = null) { return $text; }
function esc_attr__($text, $domain = null) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function wp_strip_all_tags($text) { return strip_tags((string) $text); }
function wpautop($value) { return '<p>' . $value . '</p>'; }
function do_shortcode($value) { return $value; }

function wp_trim_words($text, $count = 55, $more = null)
{
    $words = preg_split('/\s+/', trim(strip_tags((string) $text)));

    return implode(' ', array_slice($words, 0, $count));
}

// --- hooks -------------------------------------------------------------------

function add_action($hook, $callback = null, $priority = 10, $args = 1) {}
function do_action($hook, ...$args) {}
function add_filter($hook, $callback = null, $priority = 10, $args = 1) {}
function apply_filters($hook, $value) { return $value; }
function current_user_can($cap, $id = null) { return true; }
function add_menu_page() { return 'toplevel_page_schemapress'; }
function add_submenu_page() { return 'schemapress_page_docs'; }
function admin_url($path = '') { return 'http://example.test/wp-admin/' . $path; }
function rest_url($path = '') { return 'http://example.test/wp-json/' . $path; }
function wp_json_encode($value) { return json_encode($value); }
function is_wp_error($value) { return $value instanceof WP_Error; }

class WP_Error
{
    public $code;
    public $message;

    public function __construct($code = '', $message = '', $data = [])
    {
        $this->code = $code;
        $this->message = $message;
    }
}

// --- post types --------------------------------------------------------------

function register_post_type($type, $args = []) { $GLOBALS['wp_post_types'][$type] = true; }
function post_type_exists($type) { return isset($GLOBALS['wp_post_types'][$type]); }

// --- posts -------------------------------------------------------------------

function wp_insert_post($data, $wp_error = false)
{
    $id = $GLOBALS['wp_next_id']++;

    $GLOBALS['wp_posts'][$id] = (object) [
        'ID' => $id,
        'post_type' => $data['post_type'] ?? 'post',
        'post_title' => $data['post_title'] ?? '',
        'post_name' => sanitize_title($data['post_title'] ?? ''),
        'post_status' => $data['post_status'] ?? 'publish',
        'post_modified_gmt' => '2026-09-02 00:00:00',
    ];

    return $id;
}

function wp_update_post($data, $wp_error = false)
{
    $id = absint($data['ID'] ?? 0);

    if (!isset($GLOBALS['wp_posts'][$id])) {
        return new WP_Error('invalid_post', 'No such post');
    }

    foreach (['post_title', 'post_status', 'post_type'] as $key) {
        if (isset($data[$key])) {
            $GLOBALS['wp_posts'][$id]->$key = $data[$key];
        }
    }

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

    if ($post) {
        $post->post_status = 'trash';
    }

    return (bool) $post;
}

function wp_delete_post($id, $force = false)
{
    unset($GLOBALS['wp_posts'][absint($id)]);

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

    usort($found, function ($a, $b) { return strcmp($a->post_title, $b->post_title); });

    if (($args['fields'] ?? '') === 'ids') {
        return array_map(function ($post) { return $post->ID; }, $found);
    }

    return $found;
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

        $all = array_values(array_filter($GLOBALS['wp_posts'], function ($post) use ($types, $statuses, $search) {
            if (!in_array($post->post_type, $types, true)) {
                return false;
            }

            if (!in_array($post->post_status, $statuses, true)) {
                return false;
            }

            return $search === '' || stripos($post->post_title, $search) !== false;
        }));

        usort($all, function ($a, $b) { return $b->ID <=> $a->ID; });

        $perPage = max(1, (int) ($args['posts_per_page'] ?? 10));
        $page = max(1, (int) ($args['paged'] ?? 1));

        $this->found_posts = count($all);
        $this->max_num_pages = (int) ceil($this->found_posts / $perPage);
        $this->posts = array_slice($all, ($page - 1) * $perPage, $perPage);
    }
}

function wp_count_posts($type)
{
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

function delete_post_meta($id, $key)
{
    unset($GLOBALS['wp_meta'][absint($id)][$key]);

    return true;
}

// --- attachments -------------------------------------------------------------

function wp_attachment_is_image($id) { return get_post_type($id) === 'attachment'; }
function wp_get_attachment_url($id) { return 'http://example.test/uploads/' . $id . '.jpg'; }
function wp_get_attachment_metadata($id) { return ['width' => 800, 'height' => 600]; }
function wp_get_attachment_caption($id) { return ''; }
function get_post_mime_type($id) { return 'image/jpeg'; }
function get_intermediate_image_sizes() { return ['thumbnail']; }
function wp_get_attachment_image_src($id, $size) { return ['http://example.test/uploads/' . $id . '-t.jpg', 150, 150]; }
function wp_get_attachment_image_srcset($id, $size) { return ''; }
function get_permalink($post) { return 'http://example.test/?p=' . (is_object($post) ? $post->ID : $post); }

// --- the plugin --------------------------------------------------------------

require_once SCHEMAPRESS_PATH . 'classes/class-field-types.php';
require_once SCHEMAPRESS_PATH . 'classes/class-schema-model.php';
require_once SCHEMAPRESS_PATH . 'classes/class-schema.php';
require_once SCHEMAPRESS_PATH . 'classes/class-schema-repository.php';
require_once SCHEMAPRESS_PATH . 'classes/class-content-sanitizer.php';
require_once SCHEMAPRESS_PATH . 'classes/class-resolver.php';
require_once SCHEMAPRESS_PATH . 'classes/class-fields.php';
require_once SCHEMAPRESS_PATH . 'classes/class-entry.php';
require_once SCHEMAPRESS_PATH . 'classes/class-entries.php';
require_once SCHEMAPRESS_PATH . 'classes/class-content-type.php';
require_once SCHEMAPRESS_PATH . 'classes/class-collection.php';
require_once SCHEMAPRESS_PATH . 'classes/class-content.php';

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
function sp_test_type($title, array $fields = [])
{
    $id = wp_insert_post([
        'post_type' => SchemaPress\Schema::POST_TYPE,
        'post_title' => $title,
        'post_status' => 'publish',
    ]);

    SchemaPress\SchemaRepository::saveDefinition($id, ['fields' => $fields]);
    SchemaPress\ContentType::key($id);
    SchemaPress\ContentType::register($id);

    return $id;
}
