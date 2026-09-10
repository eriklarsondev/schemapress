<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * a query against one collection.
 *
 * built by Content::collection() and read by a template:
 *
 *   {% for person in sp_collection('team_members') %}
 *     {{ person.name }}
 *   {% endfor %}
 *
 * it is iterable and countable, so Twig and foreach both treat it as the list
 * it represents. the query runs once, on first read, and is remembered — a
 * template that counts a collection and then loops it makes one query.
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
     * filters and sort, in the shape Query understands. the same structures the
     * REST endpoints build from a query string, so a template and an HTTP
     * client asking the same question ask it the same way.
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
     * @param integer $type_id 0 for a collection that does not exist
     */
    public function __construct($type_id)
    {
        $this->typeId = absint($type_id);
    }

    /**
     * limits how many entries come back.
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
     * which page of results to read.
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
     * orders the results.
     *
     * @param string $field one of title, date, modified
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
     * orders by any indexable field.
     *
     *   ->sort('name')            // ascending
     *   ->sort('joined', 'desc')
     *
     * a field that cannot be indexed — a repeater, a group, rich text — is
     * ignored rather than obeyed: see class-index.php for which those are.
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
     * filters by a field's value.
     *
     *   ->where('role', 'Engineer')            // equals
     *   ->where('age', '>=', 30)
     *   ->where('role', '$in', ['Design', 'Eng'])
     *
     * the operators are Strapi's — `$eq`, `$ne`, `$lt`, `$lte`, `$gt`, `$gte`,
     * `$in`, `$notIn`, `$contains`, `$startsWith`, `$endsWith`, `$null`,
     * `$notNull`, `$between` — and the plain comparisons are accepted as
     * aliases, because `>=` is what a PHP author reaches for first.
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
     * a whole filter tree at once, in Strapi's shape.
     *
     * for the queries `where()` cannot spell — anything using `$and` or `$or`:
     *
     *   ->filter(['$or' => [['role' => ['$eq' => 'Design']], ['lead' => ['$eq' => true]]]])
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
     * normalizes an operator to the `$`-prefixed form Query speaks.
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
     * filters by a search term.
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
     * the entries.
     *
     * @return Entry[]
     */
    public function get()
    {
        $this->run();

        return $this->entries;
    }

    /**
     * the first entry, or null.
     *
     * @return Entry|null
     */
    public function first()
    {
        $entries = $this->limit(1)->get();

        return isset($entries[0]) ? $entries[0] : null;
    }

    /**
     * one entry by id, or null when it does not belong to this collection.
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
     * how many entries the collection holds in total, ignoring paging.
     *
     * @return integer
     */
    public function total()
    {
        $this->run();

        return $this->total;
    }

    /**
     * whether the collection has any entries.
     *
     * @return boolean
     */
    public function isEmpty()
    {
        return $this->count() === 0;
    }

    /**
     * the field definitions entries of this collection are built from.
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
     * @return \ArrayIterator
     */
    #[\ReturnTypeWillChange]
    public function getIterator()
    {
        return new \ArrayIterator($this->get());
    }

    /**
     * how many entries this query returned.
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
     * a copy of this query with extra arguments.
     *
     * queries are immutable so that a collection held in a variable can be
     * read more than once without one read reshaping the next.
     *
     * @param array $args
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
     * runs the query once.
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
