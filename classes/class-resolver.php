<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * expands stored values into the payload a template or a client consumes.
 *
 * stored values are deliberately thin — an image is an attachment id and rich
 * text is raw content. neither is usable on its own, so this class dereferences
 * them at read time. keeping
 * the expansion here (rather than at save time) means a resized image or a
 * renamed entry is reflected immediately, without re-saving everything that
 * refers to it.
 */
class Resolver
{
    /**
     * resolves a value bag against its field definitions.
     *
     * @param mixed   $values
     * @param array   $fields
     * @param integer $depth
     *
     * @return array
     */
    public static function values($values, array $fields, $depth = 0)
    {
        $values = is_array($values) ? $values : [];
        $resolved = [];

        foreach ($fields as $field) {
            $key = $field['key'];
            $resolved[$key] = self::value(
                array_key_exists($key, $values) ? $values[$key] : null,
                $field,
                $depth
            );
        }

        return $resolved;
    }

    /**
     * resolves one value according to its field type.
     *
     * @param mixed   $value
     * @param array   $field
     * @param integer $depth
     *
     * @return mixed
     */
    public static function value($value, array $field, $depth = 0)
    {
        switch ($field['type']) {
            case 'repeater':
                return self::rows($value, $field, $depth);

            case 'group':
                return self::values(is_array($value) ? $value : [], $field['fields'], $depth);

            case 'wysiwyg':
                return self::richText($value);

            case 'image':
            case 'file':
                return self::attachment($value);

            case 'link':
                return self::link($value);

            default:
                return $value;
        }
    }

    /**
     * resolves repeater rows, preserving order and row identity.
     *
     * @param mixed   $value
     * @param array   $field
     * @param integer $depth
     *
     * @return array
     */
    private static function rows($value, array $field, $depth = 0)
    {
        $rows = [];

        foreach (is_array($value) ? $value : [] as $row) {
            $values = isset($row['values']) && is_array($row['values']) ? $row['values'] : [];

            $rows[] = [
                'id' => isset($row['id']) ? $row['id'] : ContentSanitizer::id(),
                'data' => self::values($values, $field['fields'], $depth),
            ];
        }

        return $rows;
    }

    /**
     * runs stored rich text through shortcodes and paragraph formatting so the
     * client receives display-ready HTML. the_content is deliberately not
     * applied — it invites unrelated plugins to inject markup into a response.
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
