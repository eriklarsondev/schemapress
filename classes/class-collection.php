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
        return $this->with(['orderby' => $field, 'order' => $direction]);
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
     * @param integer $id
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
    private function with(array $args)
    {
        $next = new self($this->typeId);
        $next->args = array_merge($this->args, $args);

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

        $result = Entries::all($this->typeId, $this->args);
        $fields = $this->fields();

        $this->entries = array_map(function ($entry) use ($fields) {
            return new Entry($entry, $fields);
        }, $result['entries']);

        $this->total = $result['total'];
    }
}
