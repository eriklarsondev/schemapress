<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * the component library.
 *
 * a preset is a ready-made section type: its fields, and the layout options
 * that make sense for it. picking one places a working component in a single
 * click, which is the difference between assembling a page and defining a
 * schema field by field before any page can exist.
 *
 * presets are only a starting point. once placed, a component is an ordinary
 * section type and can be renamed, re-fielded or removed like any other.
 */
class Presets
{
    /**
     * every available preset.
     *
     * @return array
     */
    public static function all()
    {
        $presets = [
            [
                'id' => 'columns',
                'label' => __('Columns', 'schemapress'),
                'icon' => 'columns',
                'description' => __('A row holding other components side by side.', 'schemapress'),
                'container' => true,
                'layout_defaults' => ['columns' => '2'],
                'fields' => [],
            ],
            [
                'id' => 'container',
                'label' => __('Container', 'schemapress'),
                'icon' => 'container',
                'description' => __('Groups components under one width and background.', 'schemapress'),
                'container' => true,
                'fields' => [],
            ],
            [
                'id' => 'heading',
                'label' => __('Heading', 'schemapress'),
                'icon' => 'heading',
                'description' => __('A title, with an optional standfirst.', 'schemapress'),
                'fields' => [
                    ['label' => __('Heading', 'schemapress'), 'type' => 'text'],
                    ['label' => __('Subheading', 'schemapress'), 'type' => 'textarea'],
                ],
            ],
            [
                'id' => 'content',
                'label' => __('Content', 'schemapress'),
                'icon' => 'text',
                'description' => __('A body of formatted text.', 'schemapress'),
                'fields' => [
                    ['label' => __('Content', 'schemapress'), 'type' => 'wysiwyg'],
                ],
            ],
            [
                'id' => 'cards',
                'label' => __('Cards', 'schemapress'),
                'icon' => 'cards',
                'description' => __('A repeatable row of cards.', 'schemapress'),
                'fields' => [
                    ['label' => __('Heading', 'schemapress'), 'type' => 'text'],
                    [
                        'label' => __('Cards', 'schemapress'),
                        'type' => 'repeater',
                        'config' => [
                            'row_label' => 'title',
                            'display' => 'grid',
                            'button_label' => __('Add card', 'schemapress'),
                        ],
                        'fields' => [
                            ['label' => __('Image', 'schemapress'), 'type' => 'image'],
                            ['label' => __('Title', 'schemapress'), 'type' => 'text'],
                            ['label' => __('Text', 'schemapress'), 'type' => 'textarea'],
                            ['label' => __('Link', 'schemapress'), 'type' => 'link'],
                        ],
                    ],
                ],
            ],
            [
                'id' => 'hero',
                'label' => __('Hero', 'schemapress'),
                'icon' => 'hero',
                'description' => __('A headline over a background image.', 'schemapress'),
                // a hero spans the viewport by default; a contained one is the
                // exception, not the starting point
                'layout_defaults' => ['width' => 'full'],
                'fields' => [
                    [
                        'label' => __('Background', 'schemapress'),
                        'type' => 'image',
                        'role' => 'background',
                    ],
                    ['label' => __('Heading', 'schemapress'), 'type' => 'text', 'role' => 'heading'],
                    ['label' => __('Subheading', 'schemapress'), 'type' => 'textarea'],
                    ['label' => __('Button', 'schemapress'), 'type' => 'link', 'role' => 'action'],
                ],
            ],
            [
                'id' => 'media',
                'label' => __('Image', 'schemapress'),
                'icon' => 'image',
                'description' => __('A single image with a caption.', 'schemapress'),
                'fields' => [
                    ['label' => __('Image', 'schemapress'), 'type' => 'image'],
                    ['label' => __('Caption', 'schemapress'), 'type' => 'text'],
                ],
            ],
            [
                'id' => 'cta',
                'label' => __('Call to action', 'schemapress'),
                'icon' => 'cta',
                'description' => __('A prompt with a button.', 'schemapress'),
                'fields' => [
                    ['label' => __('Heading', 'schemapress'), 'type' => 'text'],
                    ['label' => __('Text', 'schemapress'), 'type' => 'textarea'],
                    ['label' => __('Button', 'schemapress'), 'type' => 'link'],
                ],
            ],
            [
                'id' => 'quote',
                'label' => __('Quote', 'schemapress'),
                'icon' => 'quote',
                'description' => __('A pull quote with attribution.', 'schemapress'),
                'fields' => [
                    ['label' => __('Quote', 'schemapress'), 'type' => 'textarea'],
                    ['label' => __('Attribution', 'schemapress'), 'type' => 'text'],
                    ['label' => __('Portrait', 'schemapress'), 'type' => 'image'],
                ],
            ],
            [
                'id' => 'list',
                'label' => __('List', 'schemapress'),
                'icon' => 'list',
                'description' => __('Repeatable rows of text: features, steps, FAQs.', 'schemapress'),
                'fields' => [
                    ['label' => __('Heading', 'schemapress'), 'type' => 'text'],
                    [
                        'label' => __('Items', 'schemapress'),
                        'type' => 'repeater',
                        'config' => [
                            'row_label' => 'title',
                            'display' => 'list',
                            'button_label' => __('Add item', 'schemapress'),
                        ],
                        'fields' => [
                            ['label' => __('Title', 'schemapress'), 'type' => 'text'],
                            ['label' => __('Text', 'schemapress'), 'type' => 'textarea'],
                        ],
                    ],
                ],
            ],
        ];

        /**
         * filters the component presets offered when adding a section.
         *
         * @param array $presets
         */
        return apply_filters('schemapress/presets', $presets);
    }
}
