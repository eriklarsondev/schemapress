<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * content types: the collections a site is made of.
 *
 * Team Members, News Articles, Grants. Each one is a named shape — a list of
 * fields — and holds many entries of that shape.
 *
 * a type registers a post type of its own, so its entries inherit ids,
 * capabilities, revisions, search and trash from WordPress rather than from a
 * table this plugin would have to maintain. the type itself is stored as an
 * `sp_schema` post, which is why its definition gets the same treatment.
 */
class ContentType
{
    const META_KEY = '_schemapress_key';

    /**
     * post types are capped at 20 characters, and the prefix takes four.
     */
    const POST_TYPE_PREFIX = 'spc_';
    const KEY_LIMIT = 16;

    /**
     * @var array<int, array>|null
     */
    private static $cache = null;

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
     * a type's machine key, derived from its title on first use and stable
     * afterwards — it names the post type entries are stored against.
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
     * slugifies a title into a key unique among content types.
     *
     * @param string  $title
     * @param integer $type_id the type being keyed, excluded from the clash check
     *
     * @return string
     */
    private static function deriveKey($title, $type_id = 0)
    {
        $base = substr(sanitize_key(str_replace([' ', '-'], '_', (string) $title)), 0, self::KEY_LIMIT);

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
     * the post type a collection's entries are stored as.
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
            return self::$cache;
        }

        $types = [];

        foreach (SchemaRepository::all() as $post) {
            $definition = SchemaRepository::definition($post->ID);

            $types[] = [
                'id' => (int) $post->ID,
                'label' => get_the_title($post),
                'key' => self::key($post->ID),
                'postType' => self::postType($post->ID),
                'fields' => count($definition['fields']),
                'entries' => null,
            ];
        }

        // the cache is filled before the counts are, and deliberately: counting
        // a collection's entries reads back through get() and so through this
        // method. with the cache still null at that point it would recurse
        // until the stack gave out, on the one request every screen begins with
        self::$cache = $types;

        foreach (self::$cache as $index => $type) {
            self::$cache[$index]['entries'] = Entries::count($type['id']);
        }

        return self::$cache;
    }

    /**
     * finds a type by id.
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
     * every collection, for the reading API.
     *
     * kept as its own method because Content asks for collections by intent,
     * not by reaching into the admin's listing shape.
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
     * registers one type's post type by id.
     *
     * called when a type is created, because the init hook has already run by
     * then — without this, its first entry would be stored against a post type
     * nothing has declared, and would not come back until the next request.
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
     * registers one type's post type.
     *
     * entries are private storage: they are read through this plugin's API and
     * rendered by whatever template asks for them, so they have no archive, no
     * permalink and no admin screen competing with the builder.
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
            'supports' => ['title', 'revisions'],
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
    }
}
