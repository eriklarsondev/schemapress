<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * one placed section on a page: its identity plus its value bag.
 *
 * templates receive these from Render and dispatch on type():
 *
 *   foreach (sp_sections() as $section) {
 *     get_template_part('sections/' . $section->type(), null, ['section' => $section]);
 *   }
 */
class Section extends Fields
{
    /**
     * @var string
     */
    private $id;

    /**
     * @var string
     */
    private $type;

    /**
     * @var string
     */
    private $label;

    /**
     * @var array
     */
    private $layout;

    /**
     * @var integer
     */
    private $index;

    /**
     * @param string  $id
     * @param array   $definition  the section type definition
     * @param array   $values
     * @param array   $layout      resolved layout option values
     * @param integer $index       zero-based position on the page
     */
    public function __construct($id, array $definition, array $values, array $layout = [], $index = 0)
    {
        parent::__construct($values, $definition['fields']);

        $this->id = $id;
        $this->type = $definition['key'];
        $this->label = $definition['label'];
        $this->layout = $layout;
        $this->index = $index;
    }

    /**
     * the section's layout values, or one of them by key.
     *
     * values are tokens ('3', 'wide', 'md'), not classes — mapping them to
     * markup is the presentation layer's job.
     *
     *   $section->layout('columns')   // '3'
     *   $section->layout()            // ['columns' => '3', 'gap' => 'md']
     *
     * @param string|null $key
     * @param mixed       $default
     *
     * @return mixed
     */
    public function layout($key = null, $default = null)
    {
        if ($key === null) {
            return $this->layout;
        }

        return array_key_exists($key, $this->layout) ? $this->layout[$key] : $default;
    }

    /**
     * the section instance's unique id, stable across saves. useful as an
     * anchor target or DOM id.
     *
     * @return string
     */
    public function id()
    {
        return $this->id;
    }

    /**
     * the section type key, e.g. 'hero'.
     *
     * @return string
     */
    public function type()
    {
        return $this->type;
    }

    /**
     * the human label from the schema.
     *
     * @return string
     */
    public function label()
    {
        return $this->label;
    }

    /**
     * zero-based position of this section on the page.
     *
     * @return integer
     */
    public function index()
    {
        return $this->index;
    }

    /**
     * whether this is the first section on the page — commonly used to decide
     * top spacing or heading level.
     *
     * @return boolean
     */
    public function isFirst()
    {
        return $this->index === 0;
    }
}
