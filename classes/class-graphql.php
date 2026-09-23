<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The WPGraphQL integration: every collection registered as a type, so a site
 * already serving GraphQL serves its structured content through the same schema
 * rather than through a second endpoint beside it.
 *
 *   query {
 *     teamMembers(where: { role: { eq: "Engineer" } }, limit: 20) {
 *       nodes { fullName role avatar { url alt } }
 *       total
 *     }
 *   }
 *
 * WPGraphQL is optional and not bundled, for the reason Timber is not: a site
 * that wants GraphQL already runs it, and a second copy on the autoloader would
 * make load order decide which version the schema was built against. Without it
 * nothing here runs and nothing else changes — this file costs one
 * function_exists() per request.
 *
 * WHAT IT EXPOSES IS WHAT REST EXPOSES, exactly. The site's Content API switch
 * and each collection's own Read many / Read one pair govern both, so a
 * collection closed to REST is absent from the GraphQL schema too — not present
 * and refusing, absent, the same bargain the REST namespace makes. Adding a
 * query language must not widen what is public, and the only way to be sure of
 * that is to have one answer to "is this readable" rather than two.
 *
 * It is not a Relay connection. A connection needs a cursor — a stable position
 * in an ordering — and this is a read API over content that is ordered by
 * whatever you sorted it by, with no cursor to hand out. `nodes`, `total` and
 * `count` say the same things `data` and `meta.pagination` say over HTTP, which
 * is the shape this plugin already promises.
 */
class Graphql
{
    /**
     * Prefixes the types that are not one collection's own, so a schema shared
     * with every other plugin on the site has one obvious owner for them.
     */
    public const PREFIX = 'SchemaPress';

    /**
     * The scalar each field type resolves as. Absent means the type needs an
     * object of its own — image, file, link, group, repeater, gallery — or is a
     * dropdown, whose shape depends on whether it takes several values.
     *
     * @var array<string, string>
     */
    public const SCALARS = [
        'text' => 'String',
        'textarea' => 'String',
        'email' => 'String',
        'url' => 'String',
        'phone' => 'String',
        'wysiwyg' => 'String',
        'color' => 'String',
        'date' => 'String',
        'datetime' => 'String',
        'time' => 'String',
        // a shape this collection does not describe, so there is no type to give
        // it. serialized, which is what a client would do with it anyway
        'json' => 'String',
        'number' => 'Float',
        'toggle' => 'Boolean',
    ];

    /**
     * GraphQL argument name to the operator Query speaks. `isNull` is the pair
     * `$null`/`$notNull` as one boolean, because `isNull: false` is what a
     * GraphQL author writes and two operators for one question is not.
     *
     * @var array<string, string>
     */
    public const OPERATORS = [
        'eq' => '$eq',
        'ne' => '$ne',
        'lt' => '$lt',
        'lte' => '$lte',
        'gt' => '$gt',
        'gte' => '$gte',
        'in' => '$in',
        'notIn' => '$notIn',
        'contains' => '$contains',
        'notContains' => '$notContains',
        'startsWith' => '$startsWith',
        'endsWith' => '$endsWith',
        'between' => '$between',
    ];

    /**
     * Sort keys that belong to the entry rather than to its fields, as the enum
     * values they are offered under. The same five `?sort=` accepts.
     *
     * @var array<string, string>
     */
    public const RESERVED_SORT = [
        'TITLE' => 'title',
        'SLUG' => 'slug',
        'CREATED_AT' => 'createdAt',
        'UPDATED_AT' => 'updatedAt',
        'PUBLISHED_AT' => 'publishedAt',
    ];

    /**
     * Hooks the schema registration.
     */
    public function __construct()
    {
        add_action('graphql_register_types', [$this, 'register']);
    }

    /**
     * Whether WPGraphQL is loaded and this plugin can register against it.
     *
     * @return boolean
     */
    public static function available()
    {
        return function_exists('register_graphql_object_type')
            && function_exists('register_graphql_input_type')
            && function_exists('register_graphql_enum_type')
            && function_exists('register_graphql_field');
    }

    /**
     * Builds the schema: the shared types once, then every collection that is
     * open for at least one shape of read.
     *
     * @return void
     */
    public function register()
    {
        if (!self::available() || !Settings::restEnabled()) {
            return;
        }

        self::shared();

        foreach (ContentType::all() as $type) {
            $open = [
                'list' => !empty($type['publicApi']['list']),
                'single' => !empty($type['publicApi']['single']),
            ];

            // closed to REST is absent from the schema. a type nothing can
            // reach would still be introspectable, which is how a schema tells
            // you what a site holds without letting you read it
            if ($open['list'] || $open['single']) {
                self::collection($type, $open);
            }
        }
    }

    // --- naming --------------------------------------------------------------

    /**
     * A machine key as a GraphQL type name: `team_member` becomes `TeamMember`.
     *
     * @param string $key
     * @param string $suffix
     *
     * @return string
     */
    public static function typeName($key, $suffix = '')
    {
        $parts = array_filter(explode('_', (string) $key));

        return implode('', array_map('ucfirst', $parts)) . $suffix;
    }

    /**
     * A machine key as a GraphQL field name: `full_name` becomes `fullName`.
     *
     * Field keys are already legal GraphQL names — they are `[a-z0-9_]` and
     * nothing else — so this is convention rather than necessity. It is worth
     * the mapping: WPGraphQL's own schema is camelCase throughout, and one
     * snake_case field in the middle of it reads as something that got away
     * rather than something that was chosen.
     *
     * @param string $key
     *
     * @return string
     */
    public static function fieldName($key)
    {
        $parts = array_filter(explode('_', (string) $key));
        $name = array_shift($parts);

        return $name . implode('', array_map('ucfirst', $parts));
    }

    /**
     * A field key as the enum value a sort is asked for by: `full_name` becomes
     * `FULL_NAME`, which is the GraphQL convention for an enum.
     *
     * @param string $key
     *
     * @return string
     */
    public static function enumName($key)
    {
        return strtoupper((string) $key);
    }

    /**
     * Every field of a definition as `graphql name => field`, with a collision
     * settled rather than silently dropped.
     *
     * Two keys can want one camelCase name — `full_name` and `fullname` both
     * ask for `fullName`. The first in the schema keeps it and the rest fall
     * back to their key verbatim, which is legal and unique by construction. A
     * collision is rare and a field vanishing from an API is not something to
     * discover in production.
     *
     * @param array $fields
     *
     * @return array<string, array>
     */
    public static function named(array $fields)
    {
        $named = [];

        foreach ($fields as $field) {
            if (empty($field['key'])) {
                continue;
            }

            $name = self::fieldName($field['key']);

            if (isset($named[$name])) {
                $name = $field['key'];
            }

            $named[$name] = $field;
        }

        return $named;
    }

    // --- the types every collection borrows ----------------------------------

    /**
     * The types that are not one collection's own: media, links, and the filter
     * inputs a `where` argument is built from.
     *
     * @return void
     */
    private static function shared()
    {
        register_graphql_object_type(self::PREFIX . 'ImageSize', [
            'description' => __('One registered size of an image.', 'schemapress'),
            'fields' => [
                'name' => ['type' => 'String'],
                'url' => ['type' => 'String'],
                'width' => ['type' => 'Int'],
                'height' => ['type' => 'Int'],
            ],
        ]);

        register_graphql_object_type(self::PREFIX . 'Media', [
            'description' => __('An image or a file, already resolved.', 'schemapress'),
            'fields' => [
                'id' => ['type' => 'Int'],
                'url' => ['type' => 'String'],
                'alt' => ['type' => 'String'],
                'title' => ['type' => 'String'],
                'caption' => ['type' => 'String'],
                'mime' => ['type' => 'String'],
                'width' => ['type' => 'Int'],
                'height' => ['type' => 'Int'],
                'srcset' => ['type' => 'String'],
                // a list and not a map: `sizes` is keyed by size name over HTTP
                // and GraphQL has no type for that, so the name comes inside
                'sizes' => [
                    'type' => ['list_of' => self::PREFIX . 'ImageSize'],
                    'resolve' => function ($source) {
                        $sizes = is_array($source['sizes'] ?? null) ? $source['sizes'] : [];
                        $out = [];

                        foreach ($sizes as $name => $size) {
                            $out[] = array_merge(['name' => $name], (array) $size);
                        }

                        return $out;
                    },
                ],
            ],
        ]);

        register_graphql_object_type(self::PREFIX . 'Link', [
            'description' => __('An address, its label and how it opens.', 'schemapress'),
            'fields' => [
                'url' => ['type' => 'String'],
                'label' => ['type' => 'String'],
                'target' => ['type' => 'String'],
            ],
        ]);

        register_graphql_enum_type(self::PREFIX . 'SortDirection', [
            'description' => __('Which way a sort runs.', 'schemapress'),
            'values' => [
                'ASC' => ['value' => 'ASC'],
                'DESC' => ['value' => 'DESC'],
            ],
        ]);

        // one input per comparable shape rather than per field type: what a
        // filter can ask depends on whether the value is text, a number or a
        // flag, and nothing finer than that
        $null = [
            'isNull' => [
                'type' => 'Boolean',
                'description' => __('true for empty, false for filled in.', 'schemapress'),
            ],
        ];

        register_graphql_input_type(self::PREFIX . 'StringFilter', [
            'description' => __('How a text value may be compared.', 'schemapress'),
            'fields' => array_merge([
                'eq' => ['type' => 'String'],
                'ne' => ['type' => 'String'],
                'contains' => ['type' => 'String'],
                'notContains' => ['type' => 'String'],
                'startsWith' => ['type' => 'String'],
                'endsWith' => ['type' => 'String'],
                'lt' => ['type' => 'String'],
                'lte' => ['type' => 'String'],
                'gt' => ['type' => 'String'],
                'gte' => ['type' => 'String'],
                'in' => ['type' => ['list_of' => 'String']],
                'notIn' => ['type' => ['list_of' => 'String']],
                'between' => ['type' => ['list_of' => 'String']],
            ], $null),
        ]);

        register_graphql_input_type(self::PREFIX . 'NumberFilter', [
            'description' => __('How a number may be compared.', 'schemapress'),
            'fields' => array_merge([
                'eq' => ['type' => 'Float'],
                'ne' => ['type' => 'Float'],
                'lt' => ['type' => 'Float'],
                'lte' => ['type' => 'Float'],
                'gt' => ['type' => 'Float'],
                'gte' => ['type' => 'Float'],
                'in' => ['type' => ['list_of' => 'Float']],
                'notIn' => ['type' => ['list_of' => 'Float']],
                'between' => ['type' => ['list_of' => 'Float']],
            ], $null),
        ]);

        register_graphql_input_type(self::PREFIX . 'BooleanFilter', [
            'description' => __('How a toggle may be compared.', 'schemapress'),
            'fields' => array_merge([
                'eq' => ['type' => 'Boolean'],
                'ne' => ['type' => 'Boolean'],
            ], $null),
        ]);
    }

    // --- one collection ------------------------------------------------------

    /**
     * Every type one collection needs, and the root fields that reach them.
     *
     * @param array $type the collection, from ContentType::all()
     * @param array $open which shapes of read it is open for
     *
     * @return void
     */
    private static function collection(array $type, array $open)
    {
        $definition = SchemaRepository::definition($type['id']);
        $fields = $definition['fields'];
        $name = self::typeName($type['key']);

        self::objectType($name, $fields, $type['label']);
        self::whereInput($name, $fields);
        self::sortInput($name, $fields);

        register_graphql_object_type($name . 'List', [
            'description' => sprintf(
                /* translators: %s: a collection's plural name */
                __('A page of %s, and how many there are in all.', 'schemapress'),
                $type['pluralLabel'] ?: $type['label']
            ),
            'fields' => [
                'nodes' => ['type' => ['list_of' => $name]],
                'total' => [
                    'type' => 'Int',
                    'description' => __('Everything that matched, ignoring paging.', 'schemapress'),
                ],
                'count' => [
                    'type' => 'Int',
                    'description' => __('How many this page returned.', 'schemapress'),
                ],
            ],
        ]);

        if ($open['list']) {
            self::listField($type, $name);
        }

        if ($open['single']) {
            self::singleField($type, $name);
        }
    }

    /**
     * The object type for one collection or one nested group of fields.
     *
     * @param string $name   the GraphQL type name
     * @param array  $fields
     * @param string $label
     * @param string $source where a value is read from: an entry, or a bare bag
     *
     * @return void
     */
    private static function objectType($name, array $fields, $label, $source = 'entry')
    {
        $registered = $source === 'entry'
            ? [
                'id' => [
                    'type' => ['non_null' => 'ID'],
                    'description' => __('The uuid this entry is addressed by.', 'schemapress'),
                    'resolve' => function ($entry) {
                        return $entry['id'] ?? '';
                    },
                ],
                'slug' => [
                    'type' => 'String',
                    'resolve' => function ($entry) {
                        return $entry['slug'] ?? '';
                    },
                ],
                'createdAt' => [
                    'type' => 'String',
                    'description' => __('When the entry was first created.', 'schemapress'),
                    'resolve' => function ($entry) {
                        return $entry['createdAt'] ?? '';
                    },
                ],
                'updatedAt' => [
                    'type' => 'String',
                    'resolve' => function ($entry) {
                        return $entry['modified'] ?? '';
                    },
                ],
                'publishedAt' => [
                    'type' => 'String',
                    'resolve' => function ($entry) {
                        return $entry['publishedAt'] ?? '';
                    },
                ],
            ]
            : [];

        foreach (self::named($fields) as $field_name => $field) {
            // The entry's own names are not a field's to take. A collection with
            // a field labelled "ID" keys to `id`, and registering it here
            // replaced the uuid the rest of the API addresses the entry by —
            // with a String resolver, on a field declared non-null ID.
            //
            // Same rule named() already uses among fields, extended to the names
            // the entry brought with it: the holder keeps it and the newcomer
            // moves. Suffixing rather than dropping, because a field nobody can
            // query is worse than one under a name they have to introspect for.
            while (isset($registered[$field_name])) {
                $field_name .= 'Field';
            }

            $declared = self::field($name, $field_name, $field, $source);

            if ($declared) {
                $registered[$field_name] = $declared;
            }
        }

        register_graphql_object_type($name, [
            'description' => $label,
            'fields' => $registered,
        ]);
    }

    /**
     * One field of an object type, registering a nested type first when the
     * field needs one.
     *
     * @param string $owner      the type this field is on
     * @param string $field_name its GraphQL name
     * @param array  $field
     * @param string $source     entry or bag
     *
     * @return array|null null when the type cannot be expressed
     */
    private static function field($owner, $field_name, array $field, $source)
    {
        $key = $field['key'];
        $type = $field['type'] ?? '';
        $read = self::reader($key, $source);

        if (isset(self::SCALARS[$type])) {
            return ['type' => self::SCALARS[$type], 'resolve' => $read];
        }

        if ($type === 'select') {
            return [
                // several values or one, decided by the field rather than by
                // the value — a list-valued dropdown that happens to hold one
                // must not change shape in the schema
                'type' => empty($field['config']['multiple'])
                    ? 'String'
                    : ['list_of' => 'String'],
                'resolve' => $read,
            ];
        }

        if ($type === 'image' || $type === 'file') {
            return ['type' => self::PREFIX . 'Media', 'resolve' => $read];
        }

        if ($type === 'gallery') {
            return ['type' => ['list_of' => self::PREFIX . 'Media'], 'resolve' => $read];
        }

        if ($type === 'link') {
            return ['type' => self::PREFIX . 'Link', 'resolve' => $read];
        }

        if ($type === 'group' || $type === 'repeater') {
            $nested = $owner . ucfirst($field_name);

            self::objectType($nested, $field['fields'] ?? [], $field['label'] ?? '', 'bag');

            if ($type === 'group') {
                return ['type' => $nested, 'resolve' => $read];
            }

            // a repeater row arrives as {id, data}, so the rows are unwrapped
            // here and the row type reads a bare bag like a group does
            return [
                'type' => ['list_of' => $nested],
                'resolve' => function ($source_value) use ($read) {
                    $rows = $read($source_value);

                    return array_map(function ($row) {
                        return is_array($row) && isset($row['data']) ? $row['data'] : $row;
                    }, is_array($rows) ? $rows : []);
                },
            ];
        }

        return null;
    }

    /**
     * Reads one field's resolved value, from an entry or from a bare bag.
     *
     * @param string $key
     * @param string $source
     *
     * @return callable
     */
    private static function reader($key, $source)
    {
        if ($source === 'entry') {
            return function ($entry) use ($key) {
                return $entry['data'][$key] ?? null;
            };
        }

        return function ($bag) use ($key) {
            return is_array($bag) ? ($bag[$key] ?? null) : null;
        };
    }

    // --- arguments -----------------------------------------------------------

    /**
     * The `where` input for one collection: one filter per field that can be
     * filtered, and `and`/`or` for the trees `where` alone cannot spell.
     *
     * @param string $name
     * @param array  $fields
     *
     * @return void
     */
    private static function whereInput($name, array $fields)
    {
        $inputs = [
            'and' => [
                'type' => ['list_of' => $name . 'Where'],
                'description' => __('Every one of these must hold.', 'schemapress'),
            ],
            'or' => [
                'type' => ['list_of' => $name . 'Where'],
                'description' => __('Any one of these must hold.', 'schemapress'),
            ],
        ];

        foreach (self::named(Index::fields($fields)) as $field_name => $field) {
            $inputs[$field_name] = [
                'type' => self::PREFIX . self::filterFor($field),
                'description' => $field['label'] ?? '',
            ];
        }

        register_graphql_input_type($name . 'Where', [
            'description' => sprintf(
                /* translators: %s: a GraphQL type name */
                __('Which %s to return.', 'schemapress'),
                $name
            ),
            'fields' => $inputs,
        ]);
    }

    /**
     * Which filter input a field is compared through.
     *
     * @param array $field
     *
     * @return string
     */
    private static function filterFor(array $field)
    {
        if (($field['type'] ?? '') === 'toggle') {
            return 'BooleanFilter';
        }

        return Index::compareAs($field) === 'NUMERIC' ? 'NumberFilter' : 'StringFilter';
    }

    /**
     * The sort enum and input for one collection.
     *
     * @param string $name
     * @param array  $fields
     *
     * @return void
     */
    private static function sortInput($name, array $fields)
    {
        $values = [];

        foreach (self::RESERVED_SORT as $enum => $key) {
            $values[$enum] = ['value' => $key];
        }

        foreach (Index::fields($fields) as $key => $field) {
            $enum = self::enumName($key);

            // a field called `title` would want an enum value the entry's own
            // title already has. the entry wins, as it does everywhere else
            if (!isset($values[$enum])) {
                $values[$enum] = ['value' => $key, 'description' => $field['label'] ?? ''];
            }
        }

        register_graphql_enum_type($name . 'SortField', [
            'description' => __('What a list may be ordered by.', 'schemapress'),
            'values' => $values,
        ]);

        register_graphql_input_type($name . 'Sort', [
            'description' => __('One ordering clause.', 'schemapress'),
            'fields' => [
                'field' => ['type' => ['non_null' => $name . 'SortField']],
                'direction' => ['type' => self::PREFIX . 'SortDirection'],
            ],
        ]);
    }

    // --- root fields ---------------------------------------------------------

    /**
     * The list query for one collection.
     *
     * @param array  $type
     * @param string $name
     *
     * @return void
     */
    private static function listField(array $type, $name)
    {
        $id = (int) $type['id'];
        $fields = SchemaRepository::definition($id)['fields'];

        register_graphql_field('RootQuery', self::fieldName($type['plural']), [
            'type' => $name . 'List',
            'description' => sprintf(
                /* translators: %s: a collection's plural name */
                __('Published %s.', 'schemapress'),
                $type['pluralLabel'] ?: $type['label']
            ),
            'args' => [
                'where' => ['type' => $name . 'Where'],
                'sort' => ['type' => ['list_of' => $name . 'Sort']],
                'limit' => [
                    'type' => 'Int',
                    'description' => __('How many, up to 100.', 'schemapress'),
                ],
                'page' => ['type' => 'Int'],
                'offset' => [
                    'type' => 'Int',
                    'description' => __('Skip this many entries. Not with page.', 'schemapress'),
                ],
                'search' => ['type' => 'String'],
            ],
            'resolve' => function ($root, array $args) use ($id, $fields, $name) {
                return self::resolveList($id, $fields, $args, $name);
            },
        ]);
    }

    /**
     * The single-entry query for one collection.
     *
     * @param array  $type
     * @param string $name
     *
     * @return void
     */
    private static function singleField(array $type, $name)
    {
        $id = (int) $type['id'];

        register_graphql_field('RootQuery', self::fieldName($type['key']), [
            'type' => $name,
            'description' => sprintf(
                /* translators: %s: a collection's name */
                __('One published %s, by id or by slug.', 'schemapress'),
                $type['singularLabel'] ?: $type['label']
            ),
            'args' => [
                'id' => ['type' => 'ID'],
                'slug' => ['type' => 'String'],
            ],
            'resolve' => function ($root, array $args) use ($id) {
                $ref = $args['id'] ?? ($args['slug'] ?? '');

                if ($ref === '') {
                    return null;
                }

                $entry = Entries::get($id, $ref);

                // unpublished is not "yours once you know the id", here as over
                // HTTP: to this schema it does not exist
                return $entry && !empty($entry['isPublished']) ? $entry : null;
            },
        ]);
    }

    /**
     * Runs a list query and shapes it into the payload type.
     *
     * @param integer $id
     * @param array   $fields
     * @param array   $args
     * @param string  $name
     *
     * @return array
     */
    private static function resolveList($id, array $fields, array $args, $name)
    {
        $spec = [
            'filters' => self::filters($args['where'] ?? [], $fields),
            'sort' => self::sort($args['sort'] ?? []),
            'search' => isset($args['search']) ? (string) $args['search'] : '',
        ];

        $read = ['view' => Entries::PUBLISHED, 'spec' => $spec];

        if (isset($args['limit'])) {
            $read['perPage'] = (int) $args['limit'];
        }

        // an offset and a page are alternatives, and the offset is the one that
        // wins — the same rule the query object and `?start=` follow
        if (isset($args['offset'])) {
            $read['offset'] = (int) $args['offset'];
        } elseif (isset($args['page'])) {
            $read['page'] = (int) $args['page'];
        }

        $result = Entries::all($id, $read);

        return [
            'nodes' => $result['entries'],
            'total' => (int) $result['total'],
            'count' => count($result['entries']),
        ];
    }

    // --- translation ---------------------------------------------------------

    /**
     * A `where` input as the filter tree Query speaks.
     *
     * The grammar is the same one the REST parameters and `where()` build, so
     * this is a rename rather than a second implementation — which is what keeps
     * three surfaces answering one question the same way.
     *
     * @param mixed $where
     * @param array $fields the collection's definitions
     *
     * @return array
     */
    public static function filters($where, array $fields)
    {
        if (!is_array($where) || !$where) {
            return [];
        }

        $named = self::named(Index::fields($fields));
        $filters = [];

        foreach ($where as $name => $value) {
            if ($name === 'and' || $name === 'or') {
                $branches = [];

                foreach (is_array($value) ? $value : [] as $branch) {
                    $nested = self::filters($branch, $fields);

                    if ($nested) {
                        $branches[] = $nested;
                    }
                }

                if ($branches) {
                    $filters['$' . $name] = $branches;
                }

                continue;
            }

            if (!isset($named[$name]) || !is_array($value)) {
                continue;
            }

            $conditions = [];

            foreach ($value as $operator => $operand) {
                if ($operator === 'isNull') {
                    // one boolean rather than $null and $notNull, which is what
                    // Query reads it as anyway
                    $conditions['$null'] = (bool) $operand;

                    continue;
                }

                if (isset(self::OPERATORS[$operator])) {
                    $conditions[self::OPERATORS[$operator]] = $operand;
                }
            }

            if ($conditions) {
                $filters[$named[$name]['key']] = $conditions;
            }
        }

        return $filters;
    }

    /**
     * Sort inputs as the clauses Query speaks. The enum carries the field key as
     * its value, so there is nothing to translate but the direction.
     *
     * @param mixed $sort
     *
     * @return array
     */
    public static function sort($sort)
    {
        $clauses = [];

        foreach (is_array($sort) ? $sort : [] as $clause) {
            if (empty($clause['field'])) {
                continue;
            }

            $clauses[] = [
                'field' => (string) $clause['field'],
                'direction' => strtoupper((string) ($clause['direction'] ?? 'ASC')) === 'DESC'
                    ? 'DESC'
                    : 'ASC',
            ];
        }

        return $clauses;
    }
}
