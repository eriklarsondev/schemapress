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
 */
class Fields
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
            $values = isset($row['values']) && is_array($row['values']) ? $row['values'] : [];
            $bags[] = new self($values, $field['fields']);
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
}
