<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One entry of a collection: a value bag with identity.
 *
 * Field values are read as properties, which is the form Twig reaches for
 * first, and arrive resolved — an image is its attachment array, a link is its
 * url and label — so a template never handles an id.
 *
 *   {{ person.name }}
 *   {{ person.photo.url }}
 *
 * A field key colliding with one of this class's own methods (`id`, `title`,
 * `slug`, `state`, `rows`) resolves to the method. Reach a field of that name
 * with `get('id')`.
 */
class Entry extends Fields
{
    /**
     * @var array
     */
    private $entry;

    /**
     * Wraps a stored entry and the definition it was saved against.
     *
     * @param array $entry  the shape Entries returns
     * @param array $fields the collection's field definitions
     */
    public function __construct(array $entry, array $fields)
    {
        parent::__construct($entry['data'], $fields);

        $this->entry = $entry;
    }

    /**
     * A uuid, not a number — see Entries::META_UID. Templates that put this in a
     * url get something stable that says nothing about the database behind it.
     *
     * @return string
     */
    public function id()
    {
        return (string) $this->entry['id'];
    }

    /**
     * The entry's title.
     *
     * @return string
     */
    public function title()
    {
        return (string) $this->entry['title'];
    }

    /**
     * The entry's slug.
     *
     * @return string
     */
    public function slug()
    {
        return (string) $this->entry['slug'];
    }

    /**
     * Published, modified or draft. A template normally only ever sees
     * `published`; the other two are visible to code that asked for the draft
     * view on purpose.
     *
     * @return string
     */
    public function state()
    {
        return (string) $this->entry['state'];
    }

    /**
     * Whether the entry is live on the site.
     *
     * @return boolean
     */
    public function isPublished()
    {
        return !empty($this->entry['isPublished']);
    }

    /**
     * Whether the entry has saved edits that have not been published.
     *
     * @return boolean
     */
    public function hasUnpublishedChanges()
    {
        return (int) ($this->entry['ahead'] ?? 0) > 0;
    }

    /**
     * When the entry last changed, GMT.
     *
     * @return string
     */
    public function modified()
    {
        return (string) $this->entry['modified'];
    }
}
