<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The element palette. An element is a field expressed as something an author
 * recognizes: "Heading" is a text field, "Button" is a link field. Picking one
 * is the same operation as choosing a field type, minus the decision that only
 * makes sense if you already know how the schema works.
 *
 * The result is an ordinary field, so nothing downstream needs to know an element
 * was involved.
 */
class Elements
{
    /**
     * every available element.
     *
     * @return array
     */
    public static function all()
    {
        $elements = [
            [
                'id' => 'heading',
                'label' => __('Heading', 'schemapress'),
                'icon' => 'heading',
                'field' => [
                    'label' => __('Heading', 'schemapress'),
                    'type' => 'text',
                ],
            ],
            [
                'id' => 'eyebrow',
                'label' => __('Eyebrow', 'schemapress'),
                'icon' => 'eyebrow',
                'field' => [
                    'label' => __('Eyebrow', 'schemapress'),
                    'type' => 'text',
                ],
            ],
            [
                'id' => 'text',
                'label' => __('Text', 'schemapress'),
                'icon' => 'text',
                'field' => [
                    'label' => __('Text', 'schemapress'),
                    'type' => 'textarea',
                ],
            ],
            [
                'id' => 'richtext',
                'label' => __('Rich Text', 'schemapress'),
                'icon' => 'richtext',
                'field' => [
                    'label' => __('Content', 'schemapress'),
                    'type' => 'wysiwyg',
                ],
            ],
            [
                'id' => 'image',
                'label' => __('Image', 'schemapress'),
                'icon' => 'image',
                'field' => [
                    'label' => __('Image', 'schemapress'),
                    'type' => 'image',
                ],
            ],
            [
                'id' => 'background',
                'label' => __('Background', 'schemapress'),
                'icon' => 'background',
                'field' => [
                    'label' => __('Background', 'schemapress'),
                    'type' => 'image',
                ],
            ],
            [
                'id' => 'button',
                'label' => __('Button', 'schemapress'),
                'icon' => 'button',
                'field' => [
                    'label' => __('Button', 'schemapress'),
                    'type' => 'link',
                ],
            ],
            [
                'id' => 'link',
                'label' => __('Link', 'schemapress'),
                'icon' => 'link',
                'field' => [
                    'label' => __('Link', 'schemapress'),
                    'type' => 'link',
                ],
            ],
            [
                'id' => 'repeater',
                'label' => __('Repeater', 'schemapress'),
                'icon' => 'repeater',
                'field' => [
                    'label' => __('Items', 'schemapress'),
                    'type' => 'repeater',
                    'config' => ['button_label' => __('Add item', 'schemapress')],
                    'fields' => [
                        ['label' => __('Title', 'schemapress'), 'type' => 'text'],
                        ['label' => __('Text', 'schemapress'), 'type' => 'textarea'],
                    ],
                ],
            ],
            [
                'id' => 'group',
                'label' => __('Group', 'schemapress'),
                'icon' => 'group',
                'field' => [
                    'label' => __('Group', 'schemapress'),
                    'type' => 'group',
                    'fields' => [],
                ],
            ],
            [
                'id' => 'toggle',
                'label' => __('Toggle', 'schemapress'),
                'icon' => 'toggle',
                'field' => [
                    'label' => __('Toggle', 'schemapress'),
                    'type' => 'toggle',
                ],
            ],
            [
                'id' => 'select',
                'label' => __('Choice', 'schemapress'),
                'icon' => 'select',
                'field' => [
                    'label' => __('Choice', 'schemapress'),
                    'type' => 'select',
                    'config' => ['options' => []],
                ],
            ],
            [
                'id' => 'number',
                'label' => __('Number', 'schemapress'),
                'icon' => 'number',
                'field' => [
                    'label' => __('Number', 'schemapress'),
                    'type' => 'number',
                ],
            ],
        ];

        /**
         * filters the element palette.
         *
         * @param array $elements
         */
        return apply_filters('schemapress/elements', $elements);
    }
}
