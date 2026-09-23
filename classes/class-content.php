<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The reading API, aliased to the global `SchemaPress` so a theme reaches it
 * with no import:
 *
 *   SchemaPress::collection('team-members')->get();
 *   SchemaPress::collection('team-members')->find($uuid);
 *
 * Nothing above this line knows how WordPress stores any of it, which is what
 * lets the storage change without every template changing with it.
 */
class Content
{
    /**
     * A collection, by any of its machine names. `team-members` is the form to
     * reach for — the plural, hyphenated, the same string a URL uses — and
     * `team_members`, `team-member` and `team_member` all answer too. See
     * ContentType::find().
     *
     * An unknown name still returns a Collection rather than null: templates
     * iterate what they are given, and one that renders nothing beats one that
     * fatals on a typo.
     *
     * @param string $name
     *
     * @return Collection
     */
    public static function collection($name)
    {
        return new Collection(ContentType::idFor($name));
    }

    /**
     * Every collection's singular key, for discovery.
     *
     * @return string[]
     */
    public static function collections()
    {
        return array_column(ContentType::collections(), 'key');
    }

    /**
     * Whether a collection exists.
     *
     * @param string $key
     *
     * @return boolean
     */
    public static function has($key)
    {
        return ContentType::idFor($key) > 0;
    }
}
