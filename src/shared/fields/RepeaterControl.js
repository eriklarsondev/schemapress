/**
 * Repeater and group controls — the two nesting field types.
 *
 * Both render their children on the same twelve columns the rest of the form
 * uses, so a component or a repeater row keeps whatever layout it was given.
 */

import { useState } from '@wordpress/element'
import { __, sprintf } from '@wordpress/i18n'
import { ChevronRight, Trash2, Plus } from 'lucide-react'
import { Field, Button, cn } from '../../ui'
import { nodeId, move, removeAt, replaceAt, emptyValues } from '../utils'
import { FieldList } from './index'

/**
 * Derives a row's heading. When the repeater configures a row_label naming one
 * of its subfields, that field's value is used so rows stay identifiable.
 *
 * @param {Object} field
 * @param {Object} row
 * @param {number} index
 * @return {string} The row heading.
 */
function rowLabel(field, row, index) {
  const key = field.config?.row_label
  const explicit = key ? row.values?.[key] : ''

  if (typeof explicit === 'string' && explicit.trim() !== '') {
    return explicit
  }

  // fall back to the first filled text field, so an unconfigured repeater
  // still shows something recognisable
  const readable = (field.fields || []).find(
    (child) => ['text', 'textarea', 'wysiwyg'].includes(child.type) && row.values?.[child.key]
  )

  if (readable) {
    const text = String(row.values[readable.key]).replace(/<[^>]*>/g, '').trim()

    return text.length > 60 ? `${text.slice(0, 60)}…` : text
  }

  return sprintf(
    /* translators: %d: row number */
    __('Item %d', 'schemapress'),
    index + 1
  )
}

/**
 * A repeater: an ordered list of rows, each a small form of its own.
 *
 * Rows stack vertically and open in place. The previous version drew them as a
 * grid of tiles sized to a SECTION COLUMN COUNT — a page-builder idea that no
 * longer exists here — and padded the grid with "Add" placeholders, so an empty
 * repeater showed three dashed boxes offering to add the same thing three
 * times. Nothing chose that shape; it was left over.
 *
 * A row carries its own id, so the React key and the identity the server stores
 * are the same value: reordering never re-keys a row or drops a caret.
 *
 * @param {Object} props
 * @return {JSX.Element} The control.
 */
export function RepeaterField({ field, value, onChange, context }) {
  const rows = Array.isArray(value) ? value : []

  const [open, setOpen] = useState(() => new Set())
  const [dragging, setDragging] = useState(-1)

  const max = field.config?.max || 0
  const min = field.config?.min || 0

  /**
   * Appends an empty row and opens it.
   *
   * @return {void}
   */
  const addRow = () => {
    const row = { id: nodeId('r'), values: emptyValues(field.fields) }

    onChange([...rows, row])
    setOpen((current) => new Set(current).add(row.id))
  }

  /**
   * Shows or hides one row's fields.
   *
   * @param {string} id
   * @return {void}
   */
  const toggle = (id) =>
    setOpen((current) => {
      const next = new Set(current)

      if (!next.delete(id)) {
        next.add(id)
      }

      return next
    })

  /**
   * Replaces one value inside a row.
   *
   * @param {number} index
   * @param {string} key
   * @param {*}      next
   * @return {void}
   */
  const updateRow = (index, key, next) =>
    onChange(
      replaceAt(rows, index, {
        ...rows[index],
        values: { ...rows[index].values, [key]: next }
      })
    )

  /**
   * Reorders as a dragged row passes over another.
   *
   * @param {number} over
   * @return {void}
   */
  const dragOver = (over) => {
    if (dragging === -1 || dragging === over) {
      return
    }

    onChange(move(rows, dragging, over))
    setDragging(over)
  }

  return (
    <Field label={field.label} help={field.help} required={field.required}>
      <div className="flex flex-col gap-2">
        {rows.length === 0 ? (
          <p className="rounded-md border border-dashed border-border px-3 py-6 text-center text-[12px] text-muted-foreground">
            {__('No rows yet.', 'schemapress')}
          </p>
        ) : null}

        {rows.map((row, index) => {
          const expanded = open.has(row.id)

          return (
            <div
              key={row.id}
              draggable={!expanded}
              onDragStart={(event) => {
                event.stopPropagation()
                event.dataTransfer.effectAllowed = 'move'
                event.dataTransfer.setData('text/plain', row.id)
                setDragging(index)
              }}
              onDragOver={(event) => {
                event.preventDefault()
                event.stopPropagation()
                event.dataTransfer.dropEffect = 'move'
                dragOver(index)
              }}
              onDrop={(event) => {
                event.preventDefault()
                event.stopPropagation()
                setDragging(-1)
              }}
              onDragEnd={() => setDragging(-1)}
              className={cn(
                'overflow-hidden rounded-md border transition-colors',
                expanded ? 'border-ring/40 bg-background' : 'border-border bg-background',
                !expanded && 'cursor-grab hover:border-ring/30',
                dragging === index &&
                  'cursor-grabbing border-2 border-dashed border-ring/60 bg-accent/40 [&_*]:invisible'
              )}
            >
              <div
                className={cn(
                  'flex items-center gap-2 px-2 py-1.5 transition-colors',
                  expanded && 'border-b border-border bg-muted/60'
                )}
              >
                <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-muted text-[10px] font-medium text-muted-foreground">
                  {index + 1}
                </span>

                <button
                  type="button"
                  aria-expanded={expanded}
                  onClick={() => toggle(row.id)}
                  className="min-w-0 flex-1 truncate text-left text-[13px] font-medium"
                >
                  {rowLabel(field, row, index)}
                </button>

                {rows.length > min ? (
                  <Button
                    size="icon-sm"
                    variant="destructive-ghost"
                    aria-label={__('Remove row', 'schemapress')}
                    onClick={() => onChange(removeAt(rows, index))}
                  >
                    <Trash2 />
                  </Button>
                ) : null}

                <ChevronRight
                  aria-hidden="true"
                  className={cn(
                    'size-4 text-muted-foreground/40 transition-transform',
                    expanded && 'rotate-90'
                  )}
                />
              </div>

              {expanded ? (
                <div className="p-3">
                  <FieldList
                    fields={field.fields}
                    values={row.values}
                    context={context}
                    onChange={(key, next) => updateRow(index, key, next)}
                  />
                </div>
              ) : null}
            </div>
          )
        })}

        <div>
          <Button
            size="sm"
            variant="outline"
            disabled={max > 0 && rows.length >= max}
            onClick={addRow}
          >
            <Plus />
            {field.config?.button_label || __('Add row', 'schemapress')}
          </Button>
        </div>
      </div>
    </Field>
  )
}

/**
 * A fixed set of nested fields under one label.
 *
 * @param {Object} props
 * @return {JSX.Element} The control.
 */
export function GroupField({ field, value, onChange, context }) {
  const values = value && typeof value === 'object' && !Array.isArray(value) ? value : {}

  return (
    <Field label={field.label} help={field.help} required={field.required}>
      {/* the rule down the left is a rounded bar, not a border: a 2px border on
          a rounded box curves around corners it does not belong on, which is
          what made it read as a broken outline rather than as an indent. it
          sits a pixel outside so it reads as the edge of the block rather than
          as something drawn inside it */}
      <div className="relative rounded-md bg-muted/30 p-3 pl-4">
        <span
          aria-hidden="true"
          className="absolute inset-y-0 -left-px w-[3px] rounded-full bg-border"
        />

        <FieldList
          fields={field.fields}
          values={values}
          context={context}
          onChange={(key, next) => onChange({ ...values, [key]: next })}
        />
      </div>
    </Field>
  )
}
