<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * read-only accessor over a value bag paired with its field definitions.
 *
 * this is what templates actually touch. it always answers — a key the schema
 * does not declare returns the supplied default rather than a notice — so
 * markup never needs isset() guards around content access.
 *
 * values are resolved, not stored: an image is the attachment array, not its
 * id. that is what lets `{{ hero.image.url }}` work in Twig without the
 * template knowing anything about how the value was persisted.
 *
 * field keys are readable as properties, which is the form Twig reaches for
 * first:
 *
 *   {{ hero.heading }}          {# same as hero.get('heading') #}
 *   {{ hero.image.url }}
 *
 * a key that collides with one of this class's own methods — `type`, `layout`,
 * `rows` — resolves to the method, so `{{ section.type }}` is always the
 * section type. reach a field of that name with `get('type')`.
 */
class Fields implements \ArrayAccess, \IteratorAggregate, \Countable
{
    /**
     * @var array
     */
    protected $values;

    /**
     * @var array
     */
    protected $fields;

    /**
     * @param array $values
     * @param array $fields
     */
    public function __construct(array $values, array $fields)
    {
        $this->values = $values;
        $this->fields = $fields;
    }

    /**
     * reads a value by dot path, descending through group fields.
     *
     *   $section->get('heading')
     *   $section->get('cta.link.url')
     *
     * @param string $path
     * @param mixed  $default
     *
     * @return mixed
     */
    public function get($path, $default = null)
    {
        $segments = explode('.', (string) $path);
        $values = $this->values;
        $fields = $this->fields;

        foreach ($segments as $segment) {
            $field = SchemaModel::field($fields, $segment);

            if (!$field || !is_array($values) || !array_key_exists($segment, $values)) {
                return $default;
            }

            $values = $values[$segment];
            $fields = isset($field['fields']) ? $field['fields'] : [];
        }

        if ($values === null || $values === '') {
            return $default;
        }

        return $values;
    }

    /**
     * whether a path holds a non-empty value.
     *
     * @param string $path
     *
     * @return boolean
     */
    public function has($path)
    {
        $value = $this->get($path);

        return !($value === null || $value === '' || $value === []);
    }

    /**
     * repeater rows at a path, as iterable Fields instances.
     *
     *   foreach ($section->rows('cards') as $card) {
     *     echo $card->get('title');
     *   }
     *
     * a row arrives as { id, data } once resolved and { id, values } when it
     * came straight from storage. both are accepted so the same accessor works
     * either side of resolution.
     *
     * @param string $path
     *
     * @return Fields[]
     */
    public function rows($path)
    {
        $field = $this->definition($path);

        if (!$field || !FieldTypes::isRepeatable($field['type'])) {
            return [];
        }

        $rows = $this->get($path, []);
        $bags = [];

        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach (['data', 'values'] as $key) {
                if (isset($row[$key]) && is_array($row[$key])) {
                    $bags[] = new self($row[$key], $field['fields']);

                    continue 2;
                }
            }

            $bags[] = new self([], $field['fields']);
        }

        return $bags;
    }

    /**
     * a nested group at a path, as a Fields instance.
     *
     * @param string $path
     *
     * @return Fields|null
     */
    public function group($path)
    {
        $field = $this->definition($path);

        if (!$field || !FieldTypes::hasChildren($field['type'])) {
            return null;
        }

        return new self((array) $this->get($path, []), $field['fields']);
    }

    /**
     * resolves the field definition at a dot path.
     *
     * @param string $path
     *
     * @return array|null
     */
    public function definition($path)
    {
        $segments = explode('.', (string) $path);
        $fields = $this->fields;
        $field = null;

        foreach ($segments as $segment) {
            $field = SchemaModel::field($fields, $segment);

            if (!$field) {
                return null;
            }

            $fields = isset($field['fields']) ? $field['fields'] : [];
        }

        return $field;
    }

    /**
     * the raw value bag.
     *
     * @return array
     */
    public function all()
    {
        return $this->values;
    }

    /**
     * the field definitions this bag was built against.
     *
     * @return array
     */
    public function schema()
    {
        return $this->fields;
    }

    /**
     * the keys this bag declares, in schema order.
     *
     * @return string[]
     */
    public function keys()
    {
        return array_column($this->fields, 'key');
    }

    // --- property access -----------------------------------------------------

    /**
     * reads a field as a property, which is the form Twig tries first.
     *
     * @param string $key
     *
     * @return mixed
     */
    public function __get($key)
    {
        return $this->get($key);
    }

    /**
     * whether a field is readable as a property.
     *
     * a key naming one of this class's own methods is deliberately reported as
     * unset, so `{{ section.type }}` reaches type() rather than a field that
     * happens to be called `type`. such a field is still readable through
     * get('type').
     *
     * @param string $key
     *
     * @return boolean
     */
    public function __isset($key)
    {
        return !method_exists($this, $key) && $this->has($key);
    }

    // --- ArrayAccess ---------------------------------------------------------

    /**
     * @param mixed $offset
     *
     * @return boolean
     */
    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return $this->has($offset);
    }

    /**
     * @param mixed $offset
     *
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->get($offset);
    }

    /**
     * content is read-only at render time.
     *
     * @param mixed $offset
     * @param mixed $value
     *
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
    }

    /**
     * @param mixed $offset
     *
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
    }

    // --- iteration -----------------------------------------------------------

    /**
     * iterates declared fields as key => value, in schema order.
     *
     * @return \ArrayIterator
     */
    #[\ReturnTypeWillChange]
    public function getIterator()
    {
        $pairs = [];

        foreach ($this->keys() as $key) {
            $pairs[$key] = $this->get($key);
        }

        return new \ArrayIterator($pairs);
    }

    /**
     * how many fields the schema declares.
     *
     * @return integer
     */
    #[\ReturnTypeWillChange]
    public function count()
    {
        return count($this->fields);
    }
}
