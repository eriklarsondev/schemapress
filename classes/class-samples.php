<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * stand-in content for empty fields, in the editor only.
 *
 * a section with nothing written in it renders as nothing, which makes the
 * layout impossible to judge at the moment you are deciding what the layout
 * should be. sample content gives the section its shape back.
 *
 * it is never stored and never delivered. it exists for the length of one
 * render, and only when that render is for the builder.
 */
class Samples
{
    /**
     * sample content for one empty field, shaped like the type it stands in
     * for so the template that would have rendered the real value still
     * renders this.
     *
     * @param string $key
     * @param string $role
     * @param array  $types field key => field type
     *
     * @return mixed
     */
    public static function forField($key, $role, array $types)
    {
        $type = isset($types[$key]) ? $types[$key] : 'text';

        $sample = self::byRole($role);

        if ($sample !== null) {
            return $sample;
        }

        return self::byType($type, $key);
    }

    /**
     * a role knows what a field is for, which beats guessing from its type.
     *
     * @param string $role
     *
     * @return mixed|null
     */
    private static function byRole($role)
    {
        switch ($role) {
            case 'background':
                return self::image(self::gradient());

            case 'heading':
                return __('Your heading goes here', 'schemapress');

            case 'eyebrow':
                return __('Eyebrow', 'schemapress');

            case 'action':
                return ['url' => '#', 'label' => __('Button', 'schemapress'), 'target' => ''];
        }

        return null;
    }

    /**
     * sample content by field type.
     *
     * @param string $type
     * @param string $key
     *
     * @return mixed
     */
    private static function byType($type, $key)
    {
        switch ($type) {
            case 'textarea':
                return __(
                    'Sample copy so the layout reads before anything is written. Replace it by clicking here.',
                    'schemapress'
                );

            case 'wysiwyg':
                return '<p>' . esc_html__(
                    'Sample copy so the layout reads before anything is written.',
                    'schemapress'
                ) . '</p>';

            case 'image':
                return self::image(self::placeholder());

            case 'file':
                return [
                    'url' => '#',
                    'mime' => 'application/octet-stream',
                    'title' => __('Attachment', 'schemapress'),
                    'sizes' => [],
                ];

            case 'link':
                return ['url' => '#', 'label' => __('Link', 'schemapress'), 'target' => ''];

            case 'post':
                return ['permalink' => '#', 'title' => __('A related page', 'schemapress')];

            case 'repeater':
                // three, because a repeater is nearly always laid out in a row
                // and one card does not show a row
                return [
                    ['id' => 'sample_1', 'data' => []],
                    ['id' => 'sample_2', 'data' => []],
                    ['id' => 'sample_3', 'data' => []],
                ];

            case 'group':
                return [];

            default:
                return self::humanize($key);
        }
    }

    /**
     * a resolved-attachment shape wrapping an inline image.
     *
     * @param string $url
     *
     * @return array
     */
    private static function image($url)
    {
        return [
            'id' => 0,
            'url' => $url,
            'mime' => 'image/svg+xml',
            'alt' => '',
            'title' => '',
            'caption' => '',
            'width' => 1600,
            'height' => 900,
            'sizes' => [],
            'srcset' => '',
        ];
    }

    /**
     * a neutral placeholder image, inline so it needs no request and no file.
     *
     * @return string
     */
    private static function placeholder()
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 9">'
            . '<rect width="16" height="9" fill="#e6e8ec"/>'
            . '<path d="M5 6l1.6-2 1.4 1.7L9.6 3.6 11.6 6z" fill="#c2c6cf"/>'
            . '<circle cx="5.4" cy="3.2" r=".8" fill="#c2c6cf"/>'
            . '</svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * a soft gradient, for a backdrop that has no image yet.
     *
     * @return string
     */
    private static function gradient()
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 9">'
            . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
            . '<stop offset="0" stop-color="#4a5568"/><stop offset="1" stop-color="#1a202c"/>'
            . '</linearGradient></defs>'
            . '<rect width="16" height="9" fill="url(#g)"/></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * turns a field key into readable stand-in text.
     *
     * @param string $key
     *
     * @return string
     */
    private static function humanize($key)
    {
        return ucwords(str_replace('_', ' ', $key));
    }
}
