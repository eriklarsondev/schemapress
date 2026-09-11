<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Strapi's query grammar, translated to WP_Query. One parser, three surfaces:
 * the REST endpoints hand it `$_GET`, and Collection::where() and ::sort() build
 * the same structures by hand.
 *
 *   ?role=Engineer&sort=name&limit=50
 *   SchemaPress::collection('team_member')->where('role', 'Engineer')->sort('name')
 *
 * Filters run against the Index, not the stored JSON — see class-index.php.
 *
 * A filter naming a field that does not exist, or one that cannot be indexed, is
 * dropped rather than obeyed. The alternative is a query that silently matches
 * everything, which is the wrong way for an access-controlled list to fail.
 */
class Query
{
    /**
     * how many entries a request gets when it does not say.
     */
    public const PAGE_SIZE = 25;

    /**
     * the most any single request can ask for.
     */
    public const MAX_PAGE_SIZE = 100;

    /**
     * Strapi's operators, and the SQL comparison each becomes.
     *
     * $contains and $containsi are both LIKE: WordPress's collation is
     * case-insensitive, so the two are the same here. Both are accepted so a
     * query written against Strapi still runs.
     *
     * @var array<string, string>
     */
    public const OPERATORS = [
        '$eq' => '=',
        '$ne' => '!=',
        '$lt' => '<',
        '$lte' => '<=',
        '$gt' => '>',
        '$gte' => '>=',
        '$in' => 'IN',
        '$notIn' => 'NOT IN',
        '$contains' => 'LIKE',
        '$containsi' => 'LIKE',
        '$notContains' => 'NOT LIKE',
        '$notContainsi' => 'NOT LIKE',
        '$startsWith' => 'REGEXP',
        '$endsWith' => 'REGEXP',
        '$between' => 'BETWEEN',
        '$null' => 'NULL',
        '$notNull' => 'NOT NULL',
    ];

    /**
     * sort keys that are not fields: they live on the post row itself.
     *
     * @var array<string, string>
     */
    public const RESERVED_SORT = [
        'title' => 'title',
        'slug' => 'name',
        'createdAt' => 'date',
        'updatedAt' => 'modified',
        'publishedAt' => 'date',
    ];

    /**
     * Reads a request's parameters into a query spec.
     *
     * @param array $params $_GET, or anything shaped like it
     *
     * @return array {filters: array, sort: array, pagination: array}
     */
    public static function parse(array $params)
    {
        $explicit = is_array($params['filters'] ?? null) ? $params['filters'] : [];

        return [
            // the long form wins where both name the same field, because it is
            // the one that was spelled out on purpose
            'filters' => array_merge(self::shorthand($params), $explicit),
            'sort' => self::parseSort($params['sort'] ?? null),
            'search' => is_string($params['search'] ?? null) ? trim($params['search']) : '',
            'pagination' => self::parsePagination($params),
        ];
    }

    /**
     * Parameters outside the query's own vocabulary, read as filters — because
     * most filtering is one field equalling one value:
     *
     *   ?role=Engineer                    filters[role][$eq]=Engineer
     *   ?role[]=Design&role[]=Eng         filters[role][$in][…]
     *   ?headcount[$gte]=10               filters[headcount][$gte]=10
     *
     * The long form still wins, so a client written against Strapi is unaffected.
     * A parameter naming something that is not a field is dropped downstream, so
     * a cache-buster or an analytics tag cannot filter anything.
     *
     * @param array $params
     *
     * @return array
     */
    private static function shorthand(array $params)
    {
        $reserved = [
            'filters', 'sort', 'search', 'pagination', 'page', 'pageSize', 'limit',
            'start', 'fields', 'populate', 'status', 'locale',
        ];

        $filters = [];

        foreach ($params as $key => $value) {
            if (in_array($key, $reserved, true)) {
                continue;
            }

            if (is_array($value)) {
                // ?filters[…] shaped, or ?role[]=a&role[]=b
                $filters[$key] = self::operators($value)
                    ? $value
                    : ['$in' => array_values($value)];

                continue;
            }

            // a comma is the only punctuation a parameter carries: it means
            // "any of these". everything else about a value is taken literally
            $filters[$key] = strpos((string) $value, ',') !== false
                ? ['$in' => array_map('trim', explode(',', (string) $value))]
                : ['$eq' => $value];
        }

        return $filters;
    }

    /**
     * Whether every key of an array is an operator.
     *
     * @param array $value
     *
     * @return boolean
     */
    private static function operators(array $value)
    {
        if (!$value) {
            return false;
        }

        foreach (array_keys($value) as $key) {
            if (!isset(self::OPERATORS[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * `sort=name:asc`, `sort[0]=name:asc&sort[1]=role:desc`, or `sort=name`.
     *
     * @param mixed $sort
     *
     * @return array<array{field: string, direction: string}>
     */
    private static function parseSort($sort)
    {
        if ($sort === null || $sort === '') {
            return [];
        }

        $clauses = [];

        foreach ((array) $sort as $one) {
            if (!is_string($one) || $one === '') {
                continue;
            }

            // a comma-separated list is accepted too: sort=name:asc,role:desc
            foreach (explode(',', $one) as $part) {
                $bits = explode(':', trim($part));
                $field = trim($bits[0]);
                $direction = strtolower($bits[1] ?? 'asc');

                // `-full_name` is the same as `full_name:desc`, for a client
                // that would rather not spell it out
                if (strpos($field, '-') === 0) {
                    $field = substr($field, 1);
                    $direction = 'desc';
                }

                if ($field === '') {
                    continue;
                }

                $clauses[] = [
                    'field' => $field,
                    'direction' => $direction === 'desc' ? 'DESC' : 'ASC',
                ];
            }
        }

        return $clauses;
    }

    /**
     * `pagination[page]` + `[pageSize]`, or `pagination[start]` + `[limit]` —
     * both of Strapi's styles, so a client that counts in offsets does not have
     * to convert to pages.
     *
     * @param array $params the request's parameters
     *
     * @return array
     */
    private static function parsePagination(array $params)
    {
        $pagination = is_array($params['pagination'] ?? null) ? $params['pagination'] : [];

        // ?page=2&limit=50 and ?start=50&limit=25, without the wrapper. the
        // wrapper still wins, so both spellings can appear and the explicit one
        // is the one that counts
        foreach (['page', 'pageSize', 'limit', 'start'] as $key) {
            if (isset($params[$key]) && !isset($pagination[$key])) {
                $pagination[$key] = $params[$key];
            }
        }

        // a bare `limit` alongside a bare `page` is a page size, not an offset
        // window — offsets are what `start` means, and it has to be present for
        // the pair to be read that way
        if (isset($pagination['limit']) && !isset($pagination['start'])) {
            $pagination['pageSize'] = $pagination['pageSize'] ?? $pagination['limit'];
            unset($pagination['limit']);
        }

        if (isset($pagination['start']) || isset($pagination['limit'])) {
            $limit = self::size($pagination['limit'] ?? self::PAGE_SIZE);

            return [
                'mode' => 'offset',
                'start' => max(0, (int) ($pagination['start'] ?? 0)),
                'limit' => $limit,
            ];
        }

        return [
            'mode' => 'page',
            'page' => max(1, (int) ($pagination['page'] ?? 1)),
            'pageSize' => self::size($pagination['pageSize'] ?? self::PAGE_SIZE),
        ];
    }

    /**
     * Clamps a requested size.
     *
     * @param mixed $size
     *
     * @return integer
     */
    private static function size($size)
    {
        return min(self::MAX_PAGE_SIZE, max(1, (int) $size));
    }

    /**
     * Turns a spec into WP_Query arguments.
     *
     * @param array   $spec   from parse(), or built by Collection
     * @param array   $fields the collection's field definitions
     * @param boolean $draft  read the draft index rather than the published one
     *
     * @return array
     */
    public static function args(array $spec, array $fields, $draft = false)
    {
        $indexable = Index::fields($fields);
        $args = [];

        $meta = self::metaQuery($spec['filters'] ?? [], $indexable, 'AND', $draft);

        if ($meta) {
            $args['meta_query'] = $meta;
        }

        // only when the caller actually asked. a spec carrying filters and sort
        // alone must not quietly reset the paging its caller already set
        $paging = empty($spec['pagination']) ? [] : self::pageArgs($spec['pagination']);

        if (!empty($spec['search'])) {
            $args['s'] = $spec['search'];
        }

        return array_merge(
            $args,
            self::orderArgs($spec['sort'] ?? [], $indexable, $draft),
            $paging
        );
    }

    // --- filters -------------------------------------------------------------

    /**
     * A filter tree as a meta_query.
     *
     * @param array   $filters
     * @param array    $indexable
     * @param string   $relation  AND or OR
     * @param boolean  $draft     read the draft index rather than the published one
     *
     * @return array empty when nothing survived
     */
    private static function metaQuery(
        array $filters,
        array $indexable,
        $relation = 'AND',
        $draft = false
    ) {
        $clauses = [];

        foreach ($filters as $key => $value) {
            // $and / $or take a list of filter objects and nest
            if ($key === '$and' || $key === '$or') {
                $nested = self::metaQuery(
                    self::flatten(is_array($value) ? $value : []),
                    $indexable,
                    $key === '$or' ? 'OR' : 'AND',
                    $draft
                );

                if ($nested) {
                    $clauses[] = $nested;
                }

                continue;
            }

            if (!isset($indexable[$key]) || !is_array($value)) {
                continue;
            }

            foreach ($value as $operator => $operand) {
                $clause = self::clause($key, $indexable[$key], $operator, $operand, $draft);

                if ($clause) {
                    $clauses[] = $clause;
                }
            }
        }

        if (!$clauses) {
            return [];
        }

        return array_merge(['relation' => $relation], $clauses);
    }

    /**
     * Merges a list of filter objects into one, so `$or: [{a}, {b}]` reads as a
     * single set of clauses joined by OR.
     *
     * @param array $list
     *
     * @return array
     */
    private static function flatten(array $list)
    {
        $merged = [];

        foreach ($list as $entry) {
            if (is_array($entry)) {
                foreach ($entry as $key => $value) {
                    $merged[$key] = $value;
                }
            }
        }

        return $merged;
    }

    /**
     * One meta_query clause.
     *
     * @param string  $key
     * @param array   $field
     * @param string  $operator
     * @param mixed   $operand
     * @param boolean $draft    read the draft index rather than the published one
     *
     * @return array|null null when the operator is not one we have
     */
    private static function clause($key, array $field, $operator, $operand, $draft = false)
    {
        if (!isset(self::OPERATORS[$operator])) {
            return null;
        }

        $compare = self::OPERATORS[$operator];
        $meta = Index::key($key, $draft);
        $type = Index::compareAs($field);

        // an entry always has a row for an indexable field, so "is it set" is a
        // question about the value being empty rather than the row existing
        if ($compare === 'NULL' || $compare === 'NOT NULL') {
            $wantsEmpty = $compare === 'NULL' ? self::truthy($operand) : !self::truthy($operand);

            return [
                'key' => $meta,
                'value' => '',
                'compare' => $wantsEmpty ? '=' : '!=',
            ];
        }

        if ($compare === 'IN' || $compare === 'NOT IN') {
            return [
                'key' => $meta,
                'value' => array_map('strval', (array) $operand),
                'compare' => $compare,
                'type' => $type,
            ];
        }

        if ($compare === 'BETWEEN') {
            $range = array_values((array) $operand);

            if (count($range) < 2) {
                return null;
            }

            return [
                'key' => $meta,
                'value' => [$range[0], $range[1]],
                'compare' => 'BETWEEN',
                'type' => $type,
            ];
        }

        return [
            'key' => $meta,
            'value' => self::likeValue($operator, $operand),
            'compare' => $compare,
            'type' => $type,
        ];
    }

    /**
     * The value side of a clause.
     *
     * The anchored operators are REGEXP rather than LIKE, and have to be:
     * WP_Meta_Query runs esc_like() over any LIKE value and wraps it in %…% of
     * its own, so a trailing % added here comes out as a literal per-cent sign.
     * `$contains` stays LIKE precisely because that wrapping is what it means.
     *
     * @param string $operator
     * @param mixed  $operand
     *
     * @return string
     */
    private static function likeValue($operator, $operand)
    {
        $value = is_scalar($operand) ? (string) $operand : '';

        if ($operator === '$startsWith') {
            return '^' . self::escapeRegex($value);
        }

        if ($operator === '$endsWith') {
            return self::escapeRegex($value) . '$';
        }

        return $value;
    }

    /**
     * A literal string, safe to drop into a REGEXP.
     *
     * @param string $value
     *
     * @return string
     */
    private static function escapeRegex($value)
    {
        // str_replace rather than a pattern, because this is the escaping for a
        // pattern and a character class holding a literal backslash is its own
        // small trap — one that got past a lint and only showed up as a filter
        // matching every row, which is the worst way for a filter to be wrong.
        // the backslash goes first so the escapes it adds are not escaped again
        $specials = ['\\', '.', '*', '+', '?', '(', ')', '[', ']', '{', '}', '|', '^', '$'];
        $escaped = (string) $value;

        foreach ($specials as $char) {
            $escaped = str_replace($char, '\\' . $char, $escaped);
        }

        return $escaped;
    }

    /**
     * `$null=false` arrives as the string "false", which PHP would otherwise read
     * as true and invert the filter.
     *
     * @param mixed $value
     *
     * @return boolean
     */
    private static function truthy($value)
    {
        return !in_array($value, [false, 0, '0', 'false', '', null], true);
    }

    // --- ordering and paging -------------------------------------------------

    /**
     * Sort clauses as WP_Query ordering. WP_Query takes one meta key to order by,
     * so the first field-based clause wins and any after it are dropped; reserved
     * keys live on the post row and combine freely.
     *
     * @param array   $sort
     * @param array   $indexable
     * @param boolean $draft     order by the draft index rather than the published one
     *
     * @return array
     */
    private static function orderArgs(array $sort, array $indexable, $draft = false)
    {
        $orderby = [];
        $metaKey = '';
        $metaType = 'CHAR';

        foreach ($sort as $clause) {
            $field = $clause['field'];

            if (isset(self::RESERVED_SORT[$field])) {
                $orderby[self::RESERVED_SORT[$field]] = $clause['direction'];

                continue;
            }

            if (!isset($indexable[$field]) || $metaKey !== '') {
                continue;
            }

            $metaKey = Index::key($field, $draft);
            $metaType = Index::compareAs($indexable[$field]);
            $orderby[$metaType === 'NUMERIC' ? 'meta_value_num' : 'meta_value'] =
                $clause['direction'];
        }

        if (!$orderby) {
            return [];
        }

        $args = ['orderby' => $orderby];

        if ($metaKey !== '') {
            $args['meta_key'] = $metaKey;
            $args['meta_type'] = $metaType;
        }

        return $args;
    }

    /**
     * Pagination as WP_Query arguments.
     *
     * @param array $pagination
     *
     * @return array
     */
    private static function pageArgs(array $pagination)
    {
        if (($pagination['mode'] ?? 'page') === 'offset') {
            return [
                'offset' => $pagination['start'] ?? 0,
                'posts_per_page' => $pagination['limit'] ?? self::PAGE_SIZE,
            ];
        }

        return [
            'paged' => $pagination['page'] ?? 1,
            'posts_per_page' => $pagination['pageSize'] ?? self::PAGE_SIZE,
        ];
    }

    /**
     * The meta block a list response carries, in Strapi's shape.
     *
     * @param array   $pagination
     * @param integer $total
     *
     * @return array
     */
    public static function meta(array $pagination, $total)
    {
        $total = (int) $total;

        if (($pagination['mode'] ?? 'page') === 'offset') {
            return [
                'pagination' => [
                    'start' => (int) ($pagination['start'] ?? 0),
                    'limit' => (int) ($pagination['limit'] ?? self::PAGE_SIZE),
                    'total' => $total,
                ],
            ];
        }

        $size = (int) ($pagination['pageSize'] ?? self::PAGE_SIZE);

        return [
            'pagination' => [
                'page' => (int) ($pagination['page'] ?? 1),
                'pageSize' => $size,
                'pageCount' => $size > 0 ? (int) ceil($total / $size) : 0,
                'total' => $total,
            ],
        ];
    }
}
