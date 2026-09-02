<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * one entry of a collection.
 *
 * a value bag with identity. field values are read as properties, which is the
 * form Twig reaches for first:
 *
 *   {{ person.name }}
 *   {{ person.photo.url }}
 *   {{ person.title }}
 *
 * values arrive resolved — an image is its attachment array, a relation is the
 * entries it points at — so a template never handles an id.
 *
 * a field key that collides with one of this class's own methods (`id`,
 * `title`, `slug`, `status`, `rows`) resolves to the method, so `{{ entry.id }}`
 * is always the entry's id. Reach a field of that name with `get('id')`.
 */
class Entry extends Fields
{
    /**
     * @var array
     */
    private $entry;

    /**
     * @param array $entry  the shape Entries returns
     * @param array $fields the collection's field definitions
     */
    public function __construct(array $entry, array $fields)
    {
        parent::__construct($entry['data'], $fields);

        $this->entry = $entry;
    }

    /**
     * the entry's post id.
     *
     * @return integer
     */
    public function id()
    {
        return (int) $this->entry['id'];
    }

    /**
     * the entry's title.
     *
     * @return string
     */
    public function title()
    {
        return (string) $this->entry['title'];
    }

    /**
     * the entry's slug.
     *
     * @return string
     */
    public function slug()
    {
        return (string) $this->entry['slug'];
    }

    /**
     * publish or draft.
     *
     * @return string
     */
    public function status()
    {
        return (string) $this->entry['status'];
    }

    /**
     * whether the entry is published.
     *
     * @return boolean
     */
    public function isPublished()
    {
        return $this->status() === 'publish';
    }

    /**
     * when the entry last changed, GMT.
     *
     * @return string
     */
    public function modified()
    {
        return (string) $this->entry['modified'];
    }
}
