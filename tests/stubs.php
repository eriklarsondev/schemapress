<?php
/**
 * The slice of WordPress the pure classes touch, and nothing more.
 *
 * Every test file loads this, so the stubs exist once. They were briefly
 * duplicated between pipeline.php and here, which is the kind of thing that
 * works until someone fixes a stub in one copy and spends an afternoon on why
 * two suites disagree.
 *
 * @package SchemaPress
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

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
function __($text, $domain = null) { return $text; }
function esc_html__($text, $domain = null) { return $text; }
function apply_filters($hook, $value) { return $value; }
function add_filter() {}
function add_action() {}
function do_action() {}

function wp_list_pluck($list, $field, $index_key = null)
{
    $out = [];

    foreach ($list as $row) {
        $row = (array) $row;

        if ($index_key === null) {
            $out[] = $row[$field] ?? null;
        } else {
            $out[$row[$index_key]] = $row[$field] ?? null;
        }
    }

    return $out;
}

function wpautop($value) { return '<p>' . $value . '</p>'; }
function do_shortcode($value) { return $value; }

// attachments 42 and 43 exist; 42 is an image
function get_post_type($id) { return in_array((int) $id, [42, 43], true) ? 'attachment' : false; }
function wp_attachment_is_image($id) { return (int) $id === 42; }
function get_post_status($id) { return in_array((int) $id, [7, 8], true) ? 'publish' : false; }

$GLOBALS['sp_options'] = [];
function get_option($name, $default = false) { return $GLOBALS['sp_options'][$name] ?? $default; }
function update_option($name, $value) { $GLOBALS['sp_options'][$name] = $value; return true; }

require_once __DIR__ . '/../classes/class-field-types.php';
require_once __DIR__ . '/../classes/class-layout.php';
require_once __DIR__ . '/../classes/class-roles.php';
require_once __DIR__ . '/../classes/class-presets.php';
require_once __DIR__ . '/../classes/class-elements.php';
require_once __DIR__ . '/../classes/class-settings.php';
require_once __DIR__ . '/../classes/class-samples.php';
require_once __DIR__ . '/../classes/class-view-model.php';
require_once __DIR__ . '/../classes/class-schema-model.php';
require_once __DIR__ . '/../classes/class-content.php';
require_once __DIR__ . '/../classes/class-content-sanitizer.php';
require_once __DIR__ . '/../classes/class-fields.php';
require_once __DIR__ . '/../classes/class-resolver.php';

new SchemaPress\FieldTypes();
