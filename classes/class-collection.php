<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A query against one collection, built by Content::collection().
 *
 * Iterable and countable, so Twig and foreach both treat it as the list it
 * represents. The query runs once, on first read, and is remembered — a template
 * that counts a collection and then loops it makes one query.
 */
class Collection implements \IteratorAggregate, \Countable
{
    /**
     * @var integer
     */
    private $typeId;

    /**
     * @var array
     */
    private $args = [];

    /**
     * Filters and sort, in the shape Query understands — the same structures the
     * REST endpoints build from a query string.
     *
     * @var array
     */
    private $spec = [];

    /**
     * @var Entry[]|null
     */
    private $entries = null;

    /**
     * @var integer|null
     */
    private $total = null;

    /**
     * Builds a query against one collection.
     *
     * @param integer $type_id 0 for a collection that does not exist
     */
    public function __construct($type_id)
    {
        $this->typeId = absint($type_id);
    }

    /**
     * Limits how many entries come back.
     *
     * @param integer $count
     *
     * @return Collection a new query; the original is unchanged
     */
    public function limit($count)
    {
        return $this->with(['perPage' => absint($count)]);
    }

    /**
     * Chooses which page of results to read.
     *
     * @param integer $page
     *
     * @return Collection
     */
    public function page($page)
    {
        return $this->with(['page' => max(1, absint($page))]);
    }

    /**
     * Orders the results.
     *
     * @param string $field     a collection field, or title, date or modified
     * @param string $direction asc or desc
     *
     * @return Collection
     */
    public function orderBy($field, $direction = 'asc')
    {
        // title, date and modified live on the post row and are ordered by
        // WP_Query directly. anything else is one of the collection's own
        // fields, which is a sort against the index
        if (in_array($field, ['title', 'date', 'modified'], true)) {
            return $this->with(['orderby' => $field, 'order' => $direction]);
        }

        return $this->sort($field, $direction);
    }

    /**
     * Orders by any indexable field. One that cannot be indexed — a repeater, a
     * group, rich text — is ignored rather than obeyed; see class-index.php.
     *
     * @param string $field
     * @param string $direction asc or desc
     *
     * @return Collection
     */
    public function sort($field, $direction = 'asc')
    {
        $sort = $this->spec['sort'] ?? [];
        $sort[] = [
            'field' => (string) $field,
            'direction' => strtolower($direction) === 'desc' ? 'DESC' : 'ASC',
        ];

        return $this->with([], ['sort' => $sort]);
    }

    /**
     * Filters by a field's value.
     *
     *   ->where('role', 'Engineer')
     *   ->where('age', '>=', 30)
     *   ->where('role', '$in', ['Design', 'Eng'])
     *
     * The operators are Strapi's; the plain comparisons are aliases, because
     * `>=` is what a PHP author reaches for first.
     *
     * @param string $field
     * @param mixed  $operator the operator, or the value when only two given
     * @param mixed  $value
     *
     * @return Collection
     */
    public function where($field, $operator, $value = null)
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '$eq';
        }

        $filters = $this->spec['filters'] ?? [];
        $filters[(string) $field][self::operator($operator)] = $value;

        return $this->with([], ['filters' => $filters]);
    }

    /**
     * A whole filter tree at once, for the queries `where()` cannot spell —
     * anything using `$and` or `$or`.
     *
     * @param array $filters
     *
     * @return Collection
     */
    public function filter(array $filters)
    {
        return $this->with([], [
            'filters' => array_merge($this->spec['filters'] ?? [], $filters),
        ]);
    }

    /**
     * Normalizes an operator to the `$`-prefixed form Query speaks.
     *
     * @param string $operator
     *
     * @return string
     */
    private static function operator($operator)
    {
        $aliases = [
            '=' => '$eq',
            '==' => '$eq',
            '!=' => '$ne',
            '<>' => '$ne',
            '<' => '$lt',
            '<=' => '$lte',
            '>' => '$gt',
            '>=' => '$gte',
            'in' => '$in',
            'not in' => '$notIn',
            'like' => '$contains',
        ];

        $operator = (string) $operator;

        return $aliases[strtolower($operator)] ?? $operator;
    }

    /**
     * Filters by a search term.
     *
     * @param string $term
     *
     * @return Collection
     */
    public function search($term)
    {
        return $this->with(['search' => $term]);
    }

    /**
     * Runs the query and returns the entries.
     *
     * @return Entry[]
     */
    public function get()
    {
        $this->run();

        return $this->entries;
    }

    /**
     * The first entry, or null when there are none.
     *
     * @return Entry|null
     */
    public function first()
    {
        $entries = $this->limit(1)->get();

        return isset($entries[0]) ? $entries[0] : null;
    }

    /**
     * One entry, or null when it does not belong to this collection.
     *
     * @param string $id the entry's uuid
     *
     * @return Entry|null
     */
    public function find($id)
    {
        if (!$this->typeId) {
            return null;
        }

        $entry = Entries::get($this->typeId, $id);

        return $entry ? new Entry($entry, $this->fields()) : null;
    }

    /**
     * How many entries the collection holds in total, ignoring paging.
     *
     * @return integer
     */
    public function total()
    {
        $this->run();

        return $this->total;
    }

    /**
     * Whether the collection has any entries.
     *
     * @return boolean
     */
    public function isEmpty()
    {
        return $this->count() === 0;
    }

    /**
     * The field definitions entries of this collection are built from.
     *
     * @return array
     */
    public function fields()
    {
        if (!$this->typeId) {
            return [];
        }

        $definition = SchemaRepository::definition($this->typeId);

        return $definition['fields'];
    }

    // --- iteration -----------------------------------------------------------

    /**
     * Iterates the entries, so a template can foreach the collection.
     *
     * @return \ArrayIterator
     */
    #[\ReturnTypeWillChange]
    public function getIterator()
    {
        return new \ArrayIterator($this->get());
    }

    /**
     * How many entries this query returned.
     *
     * @return integer
     */
    #[\ReturnTypeWillChange]
    public function count()
    {
        return count($this->get());
    }

    // --- internals -----------------------------------------------------------

    /**
     * A copy of this query with extra arguments. Queries are immutable so a
     * collection held in a variable can be read more than once without one read
     * reshaping the next.
     *
     * @param array $args
     * @param array $spec
     *
     * @return Collection
     */
    private function with(array $args, array $spec = [])
    {
        $next = new self($this->typeId);
        $next->args = array_merge($this->args, $args);
        $next->spec = array_merge($this->spec, $spec);

        return $next;
    }

    /**
     * Runs the query once, remembering the result.
     *
     * @return void
     */
    private function run()
    {
        if ($this->entries !== null) {
            return;
        }

        if (!$this->typeId) {
            $this->entries = [];
            $this->total = 0;

            return;
        }

        $result = Entries::all($this->typeId, $this->args + ['spec' => $this->spec]);
        $fields = $this->fields();

        $this->entries = array_map(function ($entry) use ($fields) {
            return new Entry($entry, $fields);
        }, $result['entries']);

        $this->total = $result['total'];
    }
}
