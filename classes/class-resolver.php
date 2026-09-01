<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * expands stored content into the payload a headless client consumes.
 *
 * stored values are deliberately thin — an image is an attachment id, a
 * relationship is a post id, rich text is raw post content. none of that is
 * usable by a front-end on its own, so this class dereferences every one of
 * them at delivery time. keeping the expansion here (rather than at save time)
 * means a resized image or a renamed post is reflected immediately, without
 * re-saving every page that references it.
 *
 * the emitted shape is uniform: any node carrying identity is
 * { "id": …, "data": { … } }. a field key can therefore never collide with
 * structural keys, whatever an author names it.
 */
class Resolver
{
    /**
     * the full delivery payload for a post.
     *
     * @param integer $post_id
     *
     * @return array|null null when the post does not exist
     */
    public static function page($post_id)
    {
        $post = get_post(absint($post_id));

        if (!$post) {
            return null;
        }

        $schema_id = Binding::schemaId($post->ID);

        $payload = [
            'id' => (int) $post->ID,
            'slug' => $post->post_name,
            'title' => get_the_title($post),
            'status' => $post->post_status,
            'type' => $post->post_type,
            'template' => Binding::template($post->ID),
            'schema' => $schema_id ? get_the_title($schema_id) : null,
            'permalink' => get_permalink($post),
            'modified' => $post->post_modified_gmt,
            'excerpt' => $post->post_excerpt,
            'featured_image' => self::attachment(get_post_thumbnail_id($post->ID)),
            'sections' => self::sections($post->ID),
        ];

        /**
         * filters the delivered page payload.
         *
         * @param array    $payload
         * @param \WP_Post $post
         */
        return apply_filters('schemapress/page', $payload, $post);
    }

    /**
     * the resolved sections for a post.
     *
     * @param integer $post_id
     *
     * @return array
     */
    public static function sections($post_id)
    {
        return self::resolve(Content::get($post_id), Binding::definition($post_id));
    }

    /**
     * resolves arbitrary content against a definition, without reading or
     * writing anything stored.
     *
     * this is what lets the admin preview unsaved edits through exactly the
     * same path a delivered page takes — the preview cannot drift from the
     * payload, because it is the payload.
     *
     * @param mixed $content
     * @param array $definition
     *
     * @return array
     */
    public static function resolve($content, array $definition)
    {
        $clean = ContentSanitizer::sanitize(Content::shape($content), $definition);

        return self::resolveSections($clean['sections'], $definition);
    }

    /**
     * resolves a list of sanitized sections, recursing into containers.
     *
     * @param array $sections
     * @param array $definition
     *
     * @return array
     */
    private static function resolveSections(array $sections, array $definition)
    {
        $resolved = [];

        foreach ($sections as $placed) {
            $type = SchemaModel::section($definition, $placed['type']);

            if (!$type) {
                continue;
            }

            $resolved[] = [
                'id' => $placed['id'],
                'type' => $placed['type'],
                // tokens, not classes — the client maps them to its own markup
                'layout' => $placed['layout'],
                // role => field key, so a client can compose without walking
                // the schema to find which field is the backdrop
                'roles' => self::roles($type['fields']),
                // field key => field type. a filled value announces its own
                // shape, but an empty one does not - and the editor still has
                // to know whether the gap is a heading or an image
                'types' => self::types($type['fields']),
                // field key => class string, so a client can apply the same
                // classes the reference renderer does
                'classes' => self::classes($type['fields']),
                'data' => self::values($placed['values'], $type['fields']),
                'children' => self::resolveSections(
                    $placed['children'] ?? [],
                    $definition
                ),
            ];
        }

        return $resolved;
    }

    /**
     * maps each declared role to the field key that carries it.
     *
     * only the first field claiming a role wins - two backgrounds would leave
     * a renderer with no way to choose, so the schema order decides.
     *
     * @param array $fields
     *
     * @return array<string, string>
     */
    public static function roles(array $fields)
    {
        $roles = [];

        foreach ($fields as $field) {
            $role = $field['role'] ?? '';

            if ($role !== '' && !isset($roles[$role])) {
                $roles[$role] = $field['key'];
            }
        }

        return $roles;
    }

    /**
     * maps field keys to their declared type, at any depth.
     *
     * @param array $fields
     *
     * @return array<string, string>
     */
    public static function types(array $fields)
    {
        $types = [];

        foreach ($fields as $field) {
            $types[$field['key']] = $field['type'];

            if (!empty($field['fields'])) {
                $types += self::types($field['fields']);
            }
        }

        return $types;
    }

    /**
     * maps field keys to their author-defined classes, skipping fields that
     * have none so the payload stays small.
     *
     * @param array $fields
     *
     * @return array<string, string>
     */
    public static function classes(array $fields)
    {
        $classes = [];

        foreach ($fields as $field) {
            if (!empty($field['classes'])) {
                $classes[$field['key']] = $field['classes'];
            }

            if (!empty($field['fields'])) {
                $classes += self::classes($field['fields']);
            }
        }

        return $classes;
    }

    /**
     * resolves a value bag against its field definitions.
     *
     * @param array $values
     * @param array $fields
     *
     * @return array
     */
    public static function values($values, array $fields)
    {
        $resolved = [];

        foreach ($fields as $field) {
            $key = $field['key'];
            $resolved[$key] = self::value(
                array_key_exists($key, $values) ? $values[$key] : null,
                $field
            );
        }

        return $resolved;
    }

    /**
     * resolves one value according to its field type.
     *
     * @param mixed $value
     * @param array $field
     *
     * @return mixed
     */
    public static function value($value, array $field)
    {
        switch ($field['type']) {
            case 'repeater':
                return self::rows($value, $field);

            case 'group':
                return self::values(is_array($value) ? $value : [], $field['fields']);

            case 'wysiwyg':
                return self::richText($value);

            case 'image':
            case 'file':
                return self::attachment($value);

            case 'post':
                return self::relationship($value, $field);

            case 'link':
                return self::link($value);

            default:
                return $value;
        }
    }

    /**
     * resolves repeater rows, preserving order and row identity.
     *
     * @param mixed $value
     * @param array $field
     *
     * @return array
     */
    private static function rows($value, array $field)
    {
        $rows = [];

        foreach (is_array($value) ? $value : [] as $row) {
            $values = isset($row['values']) && is_array($row['values']) ? $row['values'] : [];

            $rows[] = [
                'id' => isset($row['id']) ? $row['id'] : Content::id('r'),
                'data' => self::values($values, $field['fields']),
            ];
        }

        return $rows;
    }

    /**
     * runs stored rich text through shortcodes and paragraph formatting so the
     * client receives display-ready HTML. the_content is deliberately not
     * applied — it invites unrelated plugins to inject markup into an API
     * response.
     *
     * @param mixed $value
     *
     * @return string
     */
    private static function richText($value)
    {
        if (!is_string($value) || $value === '') {
            return '';
        }

        return wpautop(do_shortcode($value));
    }

    /**
     * expands an attachment id into url, dimensions, alt text and every
     * registered size.
     *
     * @param mixed $value
     *
     * @return array|null
     */
    public static function attachment($value)
    {
        $id = absint(is_array($value) ? ($value['id'] ?? 0) : $value);

        if (!$id || get_post_type($id) !== 'attachment') {
            return null;
        }

        $meta = wp_get_attachment_metadata($id);

        $attachment = [
            'id' => $id,
            'url' => wp_get_attachment_url($id),
            'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
            'title' => get_the_title($id),
            'caption' => wp_get_attachment_caption($id) ?: '',
            'mime' => get_post_mime_type($id),
            'width' => isset($meta['width']) ? (int) $meta['width'] : null,
            'height' => isset($meta['height']) ? (int) $meta['height'] : null,
            'sizes' => [],
        ];

        if (wp_attachment_is_image($id)) {
            foreach (get_intermediate_image_sizes() as $size) {
                $src = wp_get_attachment_image_src($id, $size);

                if ($src) {
                    $attachment['sizes'][$size] = [
                        'url' => $src[0],
                        'width' => (int) $src[1],
                        'height' => (int) $src[2],
                    ];
                }
            }

            $attachment['srcset'] = wp_get_attachment_image_srcset($id, 'full') ?: '';
        }

        return $attachment;
    }

    /**
     * expands post ids into linkable references.
     *
     * @param mixed $value
     * @param array $field
     *
     * @return array|null
     */
    private static function relationship($value, array $field)
    {
        $multiple = !empty($field['config']['multiple']);
        $ids = array_filter(array_map('absint', (array) $value));

        $posts = [];

        foreach ($ids as $id) {
            $post = get_post($id);

            if (!$post || $post->post_status !== 'publish') {
                continue;
            }

            $posts[] = [
                'id' => (int) $post->ID,
                'title' => get_the_title($post),
                'slug' => $post->post_name,
                'type' => $post->post_type,
                'permalink' => get_permalink($post),
            ];
        }

        if ($multiple) {
            return $posts;
        }

        return isset($posts[0]) ? $posts[0] : null;
    }

    /**
     * normalizes a link value, dropping it entirely when no url is set so the
     * client can test for presence rather than for an empty string.
     *
     * @param mixed $value
     *
     * @return array|null
     */
    private static function link($value)
    {
        $link = is_array($value) ? $value : [];
        $url = isset($link['url']) ? $link['url'] : '';

        if ($url === '') {
            return null;
        }

        return [
            'url' => $url,
            'label' => isset($link['label']) ? $link['label'] : '',
            'target' => isset($link['target']) ? $link['target'] : '',
        ];
    }
}
