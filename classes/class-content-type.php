<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The collections a site is made of — Team Members, News Articles, Grants. Each
 * is a named shape holding many entries of that shape.
 *
 * A type registers a post type of its own, so its entries inherit ids,
 * capabilities, search and trash from WordPress rather than from a table this
 * plugin would have to maintain. Not revisions: an entry's values are post meta,
 * and WordPress does not revision meta — see registerPostType.
 */
class ContentType
{
    public const META_KEY = '_schemapress_key';
    public const META_PLURAL = '_schemapress_plural';

    /**
     * post types are capped at 20 characters, and the prefix takes four.
     */
    public const POST_TYPE_PREFIX = 'spc_';
    public const KEY_LIMIT = 16;

    /**
     * @var array<int, array>|null
     */
    private static $cache = null;

    /**
     * Whether the entry counts in the cache are real, as opposed to not yet
     * knowable. See fillCounts().
     *
     * @var boolean
     */
    private static $counted = false;

    /**
     * Whether fillCounts() is already running. Counting an entry reads back
     * through get() and so through all(), which would otherwise re-enter it.
     *
     * @var boolean
     */
    private static $counting = false;

    /**
     * registers every type's post type.
     */
    public function __construct()
    {
        // late enough that the schema post type exists to be queried, early
        // enough that entries resolve on the same request
        add_action('init', [$this, 'registerAll'], 20);
    }

    /**
     * A type's machine key: the singular form, derived from its title on first use
     * and stable afterwards — it names the post type entries are stored against,
     * so it cannot follow a later rename.
     *
     * @param integer $type_id
     *
     * @return string
     */
    public static function key($type_id)
    {
        $type_id = absint($type_id);
        $stored = get_post_meta($type_id, self::META_KEY, true);

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $key = self::deriveKey(get_the_title($type_id), $type_id);

        update_post_meta($type_id, self::META_KEY, $key);

        return $key;
    }

    /**
     * A type's plural key — the sidebar, a listing heading, a REST route. Derived
     * from the singular key rather than the title, so the two can never disagree
     * about what the noun is.
     *
     * @param integer $type_id
     *
     * @return string
     */
    public static function plural($type_id)
    {
        $type_id = absint($type_id);
        $stored = get_post_meta($type_id, self::META_PLURAL, true);

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $plural = self::uniquePlural(
            Inflector::lastWord(self::key($type_id), [Inflector::class, 'pluralize']),
            $type_id
        );

        update_post_meta($type_id, self::META_PLURAL, $plural);

        return $plural;
    }

    /**
     * The human labels. These follow the title, not the frozen key, so renaming a
     * type renames what people read while leaving what the database uses alone.
     *
     * @param integer $type_id
     *
     * @return array{singular: string, plural: string}
     */
    public static function labels($type_id)
    {
        $title = get_the_title($type_id);

        return [
            'singular' => Inflector::lastWord($title, [Inflector::class, 'singularize']),
            'plural' => Inflector::lastWord($title, [Inflector::class, 'pluralize']),
        ];
    }

    /**
     * Slugifies a title into a singular key unique among content types.
     *
     * @param string  $title
     * @param integer $type_id the type being keyed, excluded from the clash check
     *
     * @return string
     */
    private static function deriveKey($title, $type_id = 0)
    {
        // "Team Members" typed where "Team Member" was meant would otherwise
        // name the post type spc_team_members and every button after it
        $singular = Inflector::lastWord((string) $title, [Inflector::class, 'singularize']);

        $base = substr(sanitize_key(str_replace([' ', '-'], '_', $singular)), 0, self::KEY_LIMIT);

        if ($base === '') {
            $base = 'type';
        }

        $taken = [];

        foreach (SchemaRepository::all() as $post) {
            if ((int) $post->ID === absint($type_id)) {
                continue;
            }

            $key = get_post_meta($post->ID, self::META_KEY, true);

            if (is_string($key) && $key !== '') {
                $taken[] = $key;
            }
        }

        $key = $base;
        $suffix = 2;

        while (in_array($key, $taken, true)) {
            $key = substr($base, 0, self::KEY_LIMIT - 2) . '_' . $suffix;
            $suffix++;
        }

        return $key;
    }

    /**
     * Two types named Person and People would otherwise pluralize to the same
     * thing, and a route keyed on the plural would be ambiguous.
     *
     * @param string  $base
     * @param integer $type_id
     *
     * @return string
     */
    private static function uniquePlural($base, $type_id)
    {
        $taken = [];

        foreach (SchemaRepository::all() as $post) {
            if ((int) $post->ID === absint($type_id)) {
                continue;
            }

            $plural = get_post_meta($post->ID, self::META_PLURAL, true);

            if (is_string($plural) && $plural !== '') {
                $taken[] = $plural;
            }
        }

        $plural = $base;
        $suffix = 2;

        while (in_array($plural, $taken, true)) {
            $plural = $base . '_' . $suffix;
            $suffix++;
        }

        return $plural;
    }

    /**
     * The post type a collection's entries are stored as.
     *
     * @param integer $type_id
     *
     * @return string
     */
    public static function postType($type_id)
    {
        return self::POST_TYPE_PREFIX . self::key($type_id);
    }

    /**
     * every content type, as the admin lists them.
     *
     * @return array
     */
    public static function all()
    {
        if (self::$cache !== null) {
            self::fillCounts();

            return self::$cache;
        }

        $types = [];

        foreach (SchemaRepository::all() as $post) {
            $definition = SchemaRepository::definition($post->ID);
            $labels = self::labels($post->ID);
            // read once: it is two fields below, and each call is a meta read
            $plural = self::plural($post->ID);

            $types[] = [
                'id' => (int) $post->ID,
                // what was typed, and the two forms read from it
                'label' => get_the_title($post),
                'singularLabel' => $labels['singular'],
                'pluralLabel' => $labels['plural'],
                // what this collection is for, in the author's own words
                'description' => (string) $post->post_excerpt,
                // the machine names: singular identifies, plural addresses
                'key' => self::key($post->ID),
                'plural' => $plural,
                // Plural because a route returns many of the thing, hyphenated
                // because that is what a URL is written with — Api::idFor reads a
                // hyphen as an underscore. Derived here rather than in the admin
                // so there is one answer to "what is this collection's address"
                'apiSlug' => str_replace('_', '-', $plural),
                'postType' => self::postType($post->ID),
                // whether entries here have a working copy, or saving is
                // publishing — the entry screen is a different screen either way
                'draftAndPublish' => !empty($definition['settings']['draftAndPublish']),
                // which shapes of read this collection publishes. the site
                // settings screen names the collections a change would affect,
                // which it can only do if the listing says which they are
                'publicApi' => $definition['settings']['publicApi'],
                // which roles own this collection's entries, empty for "anyone
                // who may edit content" — see Capabilities::canEditCollection
                'editRoles' => $definition['settings']['editRoles'],
                'fields' => count($definition['fields']),
                'entries' => null,
                // the version a save is made against. the builder sends this
                // back when it replaces the field list, so a definition that
                // moved underneath it is refused rather than overwritten — see
                // Rest::staleDefinition
                'modified' => Dates::iso($post->post_modified_gmt),
            ];
        }

        // the cache is filled before the counts are, and deliberately: counting
        // a collection's entries reads back through get() and so through this
        // method. with the cache still null at that point it would recurse
        // until the stack gave out, on the one request every screen begins with
        self::$cache = $types;

        self::fillCounts();

        return self::$cache;
    }

    /**
     * Fills in how many entries each collection holds, once that is knowable.
     *
     * registerAll() reaches all() on `init` BEFORE it has registered the post
     * types, and wp_count_posts() answers with an empty object for a post type
     * that does not exist yet — so counting there reports nothing for every
     * collection and then caches that for the whole request. `wp schemapress
     * list` showed every collection holding zero entries.
     *
     * So the counts are filled on the first call that can actually see the post
     * types, and only once. Until then they stay null, which is what an unknown
     * count should look like rather than a confident zero.
     *
     * @return void
     */
    private static function fillCounts()
    {
        // the re-entrancy guard is the point: counting reads back through get()
        // and so through all(), which calls this again
        if (self::$counted || self::$counting || self::$cache === null) {
            return;
        }

        foreach (self::$cache as $type) {
            if (!post_type_exists($type['postType'])) {
                return;
            }
        }

        self::$counting = true;

        foreach (self::$cache as $index => $type) {
            self::$cache[$index]['entries'] = Entries::count($type['id']);
        }

        self::$counting = false;
        self::$counted = true;
    }

    /**
     * Finds a type by id.
     *
     * @param integer $type_id
     *
     * @return array|null
     */
    public static function get($type_id)
    {
        foreach (self::all() as $type) {
            if ($type['id'] === absint($type_id)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Every collection, for the reading API. Its own method because Content asks
     * for collections by intent, not by reaching into the admin's listing shape.
     *
     * @return array
     */
    public static function collections()
    {
        return self::all();
    }

    /**
     * registers every type's post type.
     *
     * @return void
     */
    public function registerAll()
    {
        foreach (self::all() as $type) {
            self::registerPostType($type);
        }
    }

    /**
     * Called when a type is created, because the init hook has already run by
     * then — without this, its first entry is stored against a post type nothing
     * has declared and does not come back until the next request.
     *
     * @param integer $type_id
     *
     * @return void
     */
    public static function register($type_id)
    {
        self::flush();

        $type = self::get($type_id);

        if ($type) {
            self::registerPostType($type);
        }
    }

    /**
     * Entries are private storage: read through this plugin's API and rendered by
     * whatever template asks, so they have no archive, no permalink and no admin
     * screen competing with the builder.
     *
     * @param array $type
     *
     * @return void
     */
    private static function registerPostType(array $type)
    {
        if (post_type_exists($type['postType'])) {
            return;
        }

        register_post_type($type['postType'], [
            'labels' => [
                'name' => $type['label'],
                'singular_name' => $type['label'],
            ],
            'public' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'show_in_rest' => false,
            'hierarchical' => false,
            // title only. `revisions` was here and was a claim this plugin
            // could not keep: an entry's values live in post meta, which
            // WordPress does not revision, so what it actually stored was a
            // history of the derived title and a revision row per save. the
            // draft/published pair is the versioning that does work — see
            // class-entries.php
            'supports' => ['title'],
            'capability_type' => 'page',
            'map_meta_cap' => true,
            'rewrite' => false,
            'query_var' => false,
        ]);
    }

    /**
     * clears the type cache.
     *
     * @return void
     */
    public static function flush()
    {
        self::$cache = null;
        self::$counted = false;
    }
}
