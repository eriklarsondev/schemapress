<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Expands stored values into the payload a template or a client consumes.
 *
 * Stored values are deliberately thin — an image is an attachment id, rich text
 * is raw content — so this dereferences them at read time. Doing it here rather
 * than at save time means a resized image or a renamed entry is reflected
 * immediately, without re-saving everything that refers to it.
 */
class Resolver
{
    /**
     * Resolves a value bag against its field definitions.
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
     * Resolves one value according to its field type.
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

            case 'gallery':
                return self::gallery($value);

            case 'link':
                return self::link($value);

            default:
                return $value;
        }
    }

    /**
     * Resolves repeater rows, preserving order and row identity.
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
     * Shortcodes and paragraph formatting, so the client receives display-ready
     * HTML. the_content is deliberately not applied — it invites unrelated plugins
     * to inject markup into a response.
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
     * Expands an attachment id into url, dimensions, alt text and every size.
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

        // the same image appears on many entries — a shared placeholder, one
        // author's photo down a list of articles — and expanding it once per
        // appearance was the bulk of the cost on a page of results
        static $resolved = [];

        if (isset($resolved[$id])) {
            return $resolved[$id];
        }

        $meta = wp_get_attachment_metadata($id);
        $url = wp_get_attachment_url($id);

        $attachment = [
            'id' => $id,
            'url' => $url,
            'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
            'title' => get_the_title($id),
            'caption' => wp_get_attachment_caption($id) ?: '',
            'mime' => get_post_mime_type($id),
            'width' => isset($meta['width']) ? (int) $meta['width'] : null,
            'height' => isset($meta['height']) ? (int) $meta['height'] : null,
            'sizes' => [],
        ];

        if (wp_attachment_is_image($id)) {
            $attachment['sizes'] = self::sizes($meta, $url);
            $attachment['srcset'] = wp_get_attachment_image_srcset($id, 'full') ?: '';
        }

        $resolved[$id] = $attachment;

        return $attachment;
    }

    /**
     * Every generated size, read from the attachment's own metadata rather than by
     * calling wp_get_attachment_image_src() per size — which is a filtered call
     * per size per image per entry, two and a half thousand of them on a page of a
     * hundred entries.
     *
     * A size that was never generated is absent rather than reported at the wrong
     * dimensions.
     *
     * @param mixed  $meta the attachment metadata
     * @param string $url  the full-size URL
     *
     * @return array
     */
    private static function sizes($meta, $url)
    {
        if (!is_array($meta) || empty($meta['sizes']) || !is_array($meta['sizes'])) {
            return [];
        }

        $base = substr($url, 0, strrpos($url, '/') + 1);
        $sizes = [];

        foreach ($meta['sizes'] as $name => $size) {
            if (empty($size['file'])) {
                continue;
            }

            $sizes[$name] = [
                'url' => $base . $size['file'],
                'width' => isset($size['width']) ? (int) $size['width'] : null,
                'height' => isset($size['height']) ? (int) $size['height'] : null,
            ];
        }

        return $sizes;
    }

    /**
     * An id that no longer resolves is dropped rather than left as a null in the
     * middle of the list, so a template looping a gallery never has to test each
     * item.
     *
     * @param mixed $value
     *
     * @return array
     */
    private static function gallery($value)
    {
        $images = [];

        foreach (is_array($value) ? $value : [] as $id) {
            $image = self::attachment($id);

            if ($image) {
                $images[] = $image;
            }
        }

        return $images;
    }

    /**
     * Dropped entirely when no url is set, so the client can test for presence
     * rather than for an empty string.
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
