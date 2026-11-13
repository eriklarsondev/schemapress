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
     * @param integer $depth   how many relation hops have been followed to get
     *                         here. See Relations::DEPTH
     * @param array   $context view (published or draft) and entry (the uuid of
     *                         the entry being read). Both are what a relation
     *                         needs and nothing else does: which copy of the
     *                         entry on the other end to show, and — for the
     *                         reverse direction — who is asking
     *
     * @return array
     */
    public static function values($values, array $fields, $depth = 0, array $context = [])
    {
        $values = is_array($values) ? $values : [];
        $resolved = [];

        foreach ($fields as $field) {
            $key = $field['key'];
            $resolved[$key] = self::value(
                array_key_exists($key, $values) ? $values[$key] : null,
                $field,
                $depth,
                $context
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
     * @param array   $context
     *
     * @return mixed
     */
    public static function value($value, array $field, $depth = 0, array $context = [])
    {
        // The nesting types are asked by capability rather than by name, so a
        // type registered through schemapress/field_types that declares
        // `repeatable` or `children` is walked into the way the built-in pair
        // is — ContentSanitizer already stores one that way, and reading it
        // back by name left a custom nesting type delivered as its raw bag.
        //
        // Repeatable first: a repeater has children too, and the other order
        // would read its list of rows as a single row.
        if (FieldTypes::isRepeatable($field['type'])) {
            return self::rows($value, $field, $depth, $context);
        }

        if (FieldTypes::hasChildren($field['type'])) {
            return self::values(
                is_array($value) ? $value : [],
                isset($field['fields']) ? $field['fields'] : [],
                $depth,
                $context
            );
        }

        switch ($field['type']) {
            case 'wysiwyg':
                return self::richText($value);

            case 'image':
            case 'file':
                return self::attachment($value);

            case 'gallery':
                return self::gallery($value);

            case 'link':
                return self::link($value);

            case 'relation':
                // a relation is the only type that needs to know the view,
                // because it is a read of OTHER entries rather than an
                // expansion of this one's value: a draft pointing at a draft
                // shows it in the builder and nothing on the front end.
                //
                // Facing back it is a read of the index instead, and what it
                // needs to know is who is being pointed AT
                return Relations::isInverse($field)
                    ? Relations::inverse(
                        $field,
                        $depth,
                        self::view($context),
                        (string) ($context['entry'] ?? '')
                    )
                    : Relations::resolve($value, $field, $depth, self::view($context));

            default:
                return $value;
        }
    }

    /**
     * Which copy of a related entry to read.
     *
     * Published unless told otherwise, the same default every read path in this
     * plugin takes: a caller that forgot to say would otherwise publish
     * unfinished work by omission.
     *
     * @param array $context
     *
     * @return string
     */
    private static function view(array $context)
    {
        return ($context['view'] ?? '') === Entries::DRAFT ? Entries::DRAFT : Entries::PUBLISHED;
    }

    /**
     * Resolves repeater rows, preserving order and row identity.
     *
     * @param mixed   $value
     * @param array   $field
     * @param integer $depth
     * @param array   $context
     *
     * @return array
     */
    private static function rows($value, array $field, $depth = 0, array $context = [])
    {
        $rows = [];

        $fields = isset($field['fields']) ? $field['fields'] : [];

        foreach (is_array($value) ? $value : [] as $row) {
            $values = isset($row['values']) && is_array($row['values']) ? $row['values'] : [];

            $rows[] = [
                'id' => isset($row['id']) ? $row['id'] : ContentSanitizer::id(),
                'data' => self::values($values, $fields, $depth, $context),
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
