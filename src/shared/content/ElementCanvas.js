/**
 * A component's insides, as a list of elements.
 *
 * This replaces choosing field types from a dropdown. Elements come from a
 * palette, land in order, and each one opens for editing on click - so
 * building a card is arranging its parts rather than filling in a form about
 * them.
 *
 * Structure and content are edited from the same place but stored apart: the
 * element list belongs to the schema and is shared by every instance, while
 * the values belong to the one instance being edited.
 */

import { useState, useEffect } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { Trash2, ChevronUp, ChevronDown, GripVertical, Pencil } from 'lucide-react'
import { move, removeAt, replaceAt, toKey, uniqueKey, elementToField } from '../utils'
import { Button, Card, Empty, cn } from '../../ui'
import { ElementPalette, iconForField } from './ElementPalette'
import { ElementDialog } from './ElementDialog'
import { useDrag } from './dnd'
import { useDesign } from './mode'

/**
 * Summarises a field's current value for its collapsed row.
 *
 * @param {Object} field
 * @param {*}      value
 * @return {string} The summary.
 */
function summarize(field, value) {
  if (value === null || value === undefined || value === '' || value === []) {
    return ''
  }

  switch (field.type) {
    case 'repeater':
      return Array.isArray(value)
        ? `${value.length} ${value.length === 1 ? __('item', 'schemapress') : __('items', 'schemapress')}`
        : ''
    case 'image':
    case 'file':
      return __('Set', 'schemapress')
    case 'link':
      return value.label || value.url || ''
    case 'group':
      return ''
    case 'toggle':
      return value ? __('On', 'schemapress') : __('Off', 'schemapress')
    default: {
      const text = String(value).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim()

      return text.length > 70 ? `${text.slice(0, 70)}...` : text
    }
  }
}

/**
 * The element list for one component.
 *
 * @param {Object} props
 * @return {JSX.Element} The canvas.
 */
export function ElementCanvas({
  fields,
  values,
  editable,
  context,
  openKey,
  onOpened,
  onFieldsChange,
  onChange
}) {
  const [editing, setEditing] = useState(null)
  const { dragging, setDragging } = useDrag()
  const [overIndex, setOverIndex] = useState(null)
  const design = useDesign()

  // in content mode the element list is a list of things to fill in, not a
  // structure to rearrange
  const structural = editable && design

  /**
   * Appends an element from the palette.
   *
   * @param {Object} element
   * @param {number} index
   * @return {void}
   */
  const addElement = (element, index = fields.length) => {
    const field = elementToField(element, fields.map((entry) => entry.key))
    const next = [...fields]
    next.splice(index, 0, field)

    onFieldsChange(next)
    setEditing(index)
  }

  /**
   * Accepts an element dropped at a position.
   *
   * @param {number} index
   * @param {Object} event
   * @return {void}
   */
  const handleDrop = (index, event) => {
    event.preventDefault()
    setOverIndex(null)

    if (dragging?.kind === 'element') {
      addElement(dragging.element, index)
    } else if (dragging?.kind === 'field') {
      const to = dragging.index < index ? index - 1 : index

      onFieldsChange(move(fields, dragging.index, to))
    }

    setDragging(null)
  }

  // clicking a region of the rendered layout opens that element here, which is
  // what makes the visual preview an editing surface rather than a picture
  useEffect(() => {
    if (!openKey) {
      return
    }

    const index = fields.findIndex((field) => field.key === openKey)

    if (index !== -1) {
      setEditing(index)
    }

    onOpened?.()
  }, [openKey, fields, onOpened])

  const accepting = dragging?.kind === 'element' || dragging?.kind === 'field'

  /**
   * A drop target between two elements.
   *
   * @param {number} index
   * @return {JSX.Element|null} The target.
   */
  const dropZone = (index) => {
    if (!structural || !accepting) {
      return null
    }

    return (
      <div
        onDragOver={(event) => {
          event.preventDefault()
          setOverIndex(index)
        }}
        onDragLeave={() => setOverIndex((current) => (current === index ? null : current))}
        onDrop={(event) => handleDrop(index, event)}
        className={cn(
          '-my-0.5 rounded transition-all',
          overIndex === index ? 'h-8 bg-primary/5 ring-2 ring-primary' : 'h-2'
        )}
      >
        {overIndex === index ? null : (
          <span className="block h-0.5 w-full rounded-full bg-primary/25" />
        )}
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-3">
      {structural ? <ElementPalette onAdd={(element) => addElement(element)} /> : null}

      <div className="flex flex-col gap-1">
        {fields.length === 0 ? (
          <Empty
            title={__('No elements yet', 'schemapress')}
            description={
              structural
                ? __('Drag one in from above, or click to add it.', 'schemapress')
                : __('This component has nothing to fill in.', 'schemapress')
            }
            className="py-8"
          />
        ) : null}

        {dropZone(0)}

        {fields.map((field, index) => {
          const Icon = iconForField(field)
          const preview = summarize(field, values?.[field.key])

          return (
            <div key={field.key}>
              <Card
                className={cn(
                  'group flex items-center gap-2 px-2.5 py-2 transition-colors hover:border-ring/30',
                  dragging?.kind === 'field' && dragging.index === index && 'opacity-40'
                )}
                {...(structural
                  ? {
                      draggable: true,
                      onDragStart: (event) => {
                        event.dataTransfer.effectAllowed = 'move'
                        setDragging({ kind: 'field', index })
                      },
                      onDragEnd: () => setDragging(null)
                    }
                  : {})}
              >
                {structural ? (
                  <GripVertical className="size-3.5 shrink-0 cursor-grab text-muted-foreground/40" />
                ) : null}

                <Icon className="size-3.5 shrink-0 text-muted-foreground" />

                <button
                  type="button"
                  onClick={() => setEditing(index)}
                  className="flex min-w-0 flex-1 items-baseline gap-2 text-left"
                >
                  <span className="shrink-0 text-[13px] font-medium">{field.label}</span>
                  <span className="truncate text-[12px] text-muted-foreground">
                    {preview || <em>{__('Empty', 'schemapress')}</em>}
                  </span>
                </button>

                {field.classes ? (
                  <code className="hidden shrink-0 rounded bg-muted px-1 py-0.5 text-[10px] text-muted-foreground sm:block">
                    {field.classes.split(' ').length} cls
                  </code>
                ) : null}

                <span
                  draggable={false}
                  onDragStart={(event) => event.preventDefault()}
                  className="flex shrink-0 items-center gap-0.5 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100"
                >
                  <Button
                    size="icon-sm"
                    variant="ghost"
                    aria-label={__('Edit', 'schemapress')}
                    onClick={() => setEditing(index)}
                  >
                    <Pencil />
                  </Button>

                  {structural ? (
                    <>
                      <Button
                        size="icon-sm"
                        variant="ghost"
                        aria-label={__('Move up', 'schemapress')}
                        disabled={index === 0}
                        onClick={() => onFieldsChange(move(fields, index, index - 1))}
                      >
                        <ChevronUp />
                      </Button>
                      <Button
                        size="icon-sm"
                        variant="ghost"
                        aria-label={__('Move down', 'schemapress')}
                        disabled={index === fields.length - 1}
                        onClick={() => onFieldsChange(move(fields, index, index + 1))}
                      >
                        <ChevronDown />
                      </Button>
                      <Button
                        size="icon-sm"
                        variant="destructive-ghost"
                        aria-label={__('Remove element', 'schemapress')}
                        onClick={() => onFieldsChange(removeAt(fields, index))}
                      >
                        <Trash2 />
                      </Button>
                    </>
                  ) : null}
                </span>
              </Card>

              {dropZone(index + 1)}
            </div>
          )
        })}
      </div>

      {editing !== null && fields[editing] ? (
        <ElementDialog
          field={fields[editing]}
          value={values?.[fields[editing].key]}
          editable={structural}
          context={context}
          siblingKeys={fields.filter((_, i) => i !== editing).map((entry) => entry.key)}
          onClose={() => setEditing(null)}
          onChange={(next) => onChange(fields[editing].key, next)}
          onFieldChange={(next) => onFieldsChange(replaceAt(fields, editing, next))}
        />
      ) : null}
    </div>
  )
}

/**
 * Derives a unique key for a renamed element.
 *
 * @param {string}   label
 * @param {string[]} taken
 * @return {string} The key.
 */
export function keyFor(label, taken) {
  return uniqueKey(toKey(label), taken)
}
