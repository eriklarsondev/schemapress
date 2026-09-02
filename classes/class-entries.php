<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * entries: the rows of a collection type.
 *
 * one entry is one post of the collection's own post type, with its field
 * values in a single meta blob — the same envelope a page's sections use, and
 * sanitized against the type's definition by the same code.
 *
 * values are never trusted on read. the definition is reconciled at delivery
 * time, so a field added after an entry was saved resolves to its default
 * rather than being absent.
 */
class Entries
{
    const META_VALUES = '_schemapress_values';

    /**
     * how many entries a listing returns when nothing says otherwise.
     */
    const PER_PAGE = 20;

    /**
     * a page of entries, newest first.
     *
     * @param integer $type_id
     * @param array   $args    page, perPage, search, orderby, order
     *
     * @return array{entries: array, total: integer, pages: integer}
     */
    public static function all($type_id, array $args = [])
    {
        $type = ContentType::get($type_id);

        if (!$type) {
            return ['entries' => [], 'total' => 0, 'pages' => 0];
        }

        $page = max(1, (int) ($args['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($args['perPage'] ?? self::PER_PAGE)));

        $query = new \WP_Query([
            'post_type' => $type['postType'],
            'post_status' => ['publish', 'draft'],
            'posts_per_page' => $perPage,
            'paged' => $page,
            's' => isset($args['search']) ? sanitize_text_field($args['search']) : '',
            'orderby' => in_array($args['orderby'] ?? '', ['title', 'date', 'modified'], true)
                ? $args['orderby']
                : 'modified',
            'order' => strtoupper($args['order'] ?? '') === 'ASC' ? 'ASC' : 'DESC',
            'suppress_filters' => false,
        ]);

        $definition = SchemaRepository::definition($type_id);
        $entries = [];

        foreach ($query->posts as $post) {
            $entries[] = self::shape($post, $definition);
        }

        return [
            'entries' => $entries,
            'total' => (int) $query->found_posts,
            'pages' => (int) $query->max_num_pages,
        ];
    }

    /**
     * how many entries a collection holds.
     *
     * @param integer $type_id
     *
     * @return integer
     */
    public static function count($type_id)
    {
        $type = ContentType::get($type_id);

        if (!$type) {
            return 0;
        }

        $counts = wp_count_posts($type['postType']);

        return (int) ($counts->publish ?? 0) + (int) ($counts->draft ?? 0);
    }

    /**
     * one entry, with its values reconciled against the current definition.
     *
     * @param integer $type_id
     * @param integer $entry_id
     *
     * @return array|null
     */
    public static function get($type_id, $entry_id, $depth = 0)
    {
        $type = ContentType::get($type_id);
        $post = get_post(absint($entry_id));

        if (!$type || !$post || $post->post_type !== $type['postType']) {
            return null;
        }

        return self::shape($post, SchemaRepository::definition($type_id), $depth);
    }

    /**
     * creates or updates an entry.
     *
     * @param integer      $type_id
     * @param integer|null $entry_id null creates
     * @param array        $data     title, status, values
     *
     * @return array|null the stored entry
     */
    public static function save($type_id, $entry_id, array $data)
    {
        $type = ContentType::get($type_id);

        if (!$type) {
            return null;
        }

        $definition = SchemaRepository::definition($type_id);
        $values = ContentSanitizer::values($data['values'] ?? [], $definition['fields']);

        $post = [
            'post_type' => $type['postType'],
            'post_title' => sanitize_text_field($data['title'] ?? ''),
            'post_status' => ($data['status'] ?? '') === 'draft' ? 'draft' : 'publish',
        ];

        if ($entry_id) {
            $existing = get_post(absint($entry_id));

            if (!$existing || $existing->post_type !== $type['postType']) {
                return null;
            }

            $post['ID'] = absint($entry_id);
        }

        // a title is what the listing shows, so an untitled entry gets one
        // rather than appearing as a blank row nobody can identify
        if ($post['post_title'] === '') {
            $post['post_title'] = self::deriveTitle($values, $definition['fields']);
        }

        $id = $entry_id ? wp_update_post($post, true) : wp_insert_post($post, true);

        if (is_wp_error($id)) {
            return null;
        }

        update_post_meta($id, self::META_VALUES, wp_slash(wp_json_encode($values)));

        return self::get($type_id, $id);
    }

    /**
     * trashes an entry.
     *
     * @param integer $type_id
     * @param integer $entry_id
     *
     * @return boolean
     */
    public static function delete($type_id, $entry_id)
    {
        $type = ContentType::get($type_id);
        $post = get_post(absint($entry_id));

        if (!$type || !$post || $post->post_type !== $type['postType']) {
            return false;
        }

        return (bool) wp_trash_post($post->ID);
    }

    /**
     * the delivered shape of one entry: its post fields plus resolved values.
     *
     * @param \WP_Post $post
     * @param array    $definition
     *
     * @return array
     */
    private static function shape($post, array $definition, $depth = 0)
    {
        $raw = get_post_meta($post->ID, self::META_VALUES, true);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : $raw;

        $values = ContentSanitizer::values(
            is_array($decoded) ? $decoded : [],
            $definition['fields']
        );

        return [
            'id' => (int) $post->ID,
            'title' => get_the_title($post),
            'slug' => $post->post_name,
            'status' => $post->post_status,
            'modified' => $post->post_modified_gmt,
            // stored, for the editor to load back into its controls
            'values' => $values,
            // resolved, for a template or a client to render
            'data' => Resolver::values($values, $definition['fields'], $depth),
        ];
    }

    /**
     * names an untitled entry from the first text it carries.
     *
     * @param array $values
     * @param array $fields
     *
     * @return string
     */
    private static function deriveTitle(array $values, array $fields)
    {
        foreach ($fields as $field) {
            if (!in_array($field['type'], ['text', 'textarea'], true)) {
                continue;
            }

            $value = $values[$field['key']] ?? '';

            if (is_string($value) && trim($value) !== '') {
                return wp_trim_words($value, 8, '');
            }
        }

        return __('Untitled', 'schemapress');
    }
}
