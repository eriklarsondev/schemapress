<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The reading API, aliased to the global `SchemaPress` so a theme reaches it
 * with no import:
 *
 *   SchemaPress::collection('team_members')->get();
 *   SchemaPress::collection('team_members')->find(12);
 *
 * Nothing above this line knows how WordPress stores any of it, which is what
 * lets the storage change without every template changing with it.
 */
class Content
{
    /**
     * A collection, by either of its machine names — `team_member` or
     * `team_members`.
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
        return new Collection(self::idFor($name));
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
        return self::idFor($key) > 0;
    }

    /**
     * Resolves a collection name — singular or plural — to its type id.
     *
     * @param string $name
     *
     * @return integer 0 when nothing matches
     */
    private static function idFor($name)
    {
        $name = sanitize_key(str_replace([' ', '-'], '_', (string) $name));

        foreach (ContentType::collections() as $type) {
            if ($type['key'] === $name || $type['plural'] === $name) {
                return $type['id'];
            }
        }

        return 0;
    }
}
