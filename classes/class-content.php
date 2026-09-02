<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * the public API.
 *
 * aliased to the global `Content`, so a theme reaches it with no import:
 *
 *   Content::collection('team_members')->get();
 *   Content::collection('team_members')->find(12);
 *
 * the point of it is that nothing above this line knows how WordPress stores
 * any of this. entries happen to be posts and values happen to be one meta row,
 * and neither fact is visible from here — which is what lets the storage change
 * without every template changing with it.
 */
class Content
{
    /**
     * a collection, by its machine key.
     *
     *   Content::collection('team_members')
     *
     * an unknown key still returns a Collection rather than null: templates
     * iterate what they are given, and one that renders nothing beats one that
     * fatals on a typo.
     *
     * @param string $key
     *
     * @return Collection
     */
    public static function collection($key)
    {
        return new Collection(self::idFor($key));
    }

    /**
     * every collection's key, for discovery.
     *
     * @return string[]
     */
    public static function collections()
    {
        return array_column(ContentType::collections(), 'key');
    }

    /**
     * whether a collection exists.
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
     * resolves a collection key to its content type id.
     *
     * @param string $key
     *
     * @return integer 0 when nothing matches
     */
    private static function idFor($key)
    {
        foreach (ContentType::collections() as $type) {
            if ($type['key'] === $key) {
                return $type['id'];
            }
        }

        return 0;
    }
}
