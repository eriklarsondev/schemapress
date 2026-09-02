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
     * a collection, by either of its machine names.
     *
     *   Content::collection('team_member')    // the singular key
     *   Content::collection('team_members')   // the plural reads better in a loop
     *
     * both work on purpose. the singular is the identity and the plural is how
     * you address a list of them, and which one a template author reaches for
     * depends on the sentence they are writing. refusing one of them would only
     * produce an empty loop and no explanation.
     *
     * an unknown name still returns a Collection rather than null: templates
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
     * every collection's singular key, for discovery.
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
     * resolves a collection name — singular or plural — to its type id.
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
