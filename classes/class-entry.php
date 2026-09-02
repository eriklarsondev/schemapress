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
 * values arrive resolved — an image is its attachment array, a link is its url
 * and label — so a template never handles an id.
 *
 * a field key that collides with one of this class's own methods (`id`,
 * `title`, `slug`, `state`, `rows`) resolves to the method, so `{{ entry.id }}`
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
     * the entry's identifier.
     *
     * a uuid, not a number — see Entries::META_UID for why. templates that put
     * this in a url or a data attribute get something stable that says nothing
     * about the database behind it.
     *
     * @return string
     */
    public function id()
    {
        return (string) $this->entry['id'];
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
     * where this entry stands: published, modified or draft.
     *
     * a template reading a collection normally only ever sees `published`,
     * because the delivery API serves the published view. the other two are
     * visible to code that asked for the draft view on purpose.
     *
     * @return string
     */
    public function state()
    {
        return (string) $this->entry['state'];
    }

    /**
     * whether the entry is live on the site.
     *
     * @return boolean
     */
    public function isPublished()
    {
        return !empty($this->entry['isPublished']);
    }

    /**
     * whether the entry has saved edits that have not been published.
     *
     * @return boolean
     */
    public function hasUnpublishedChanges()
    {
        return (int) ($this->entry['ahead'] ?? 0) > 0;
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
