/**
 * Repeater and group controls — the two nesting field types.
 *
 * A repeater renders as a grid of tiles by default, laid out to match the
 * section's column setting, so a row of three cards looks like a row of three
 * cards. Each tile opens its own fields in a dialog. That keeps the shape of
 * the page visible while editing it, instead of presenting a stack of
 * identical forms.
 *
 * Rows carry their own id, so the React key and the row identity the server
 * stores are the same value — reordering never re-keys a row or drops focus.
 */

import { useState } from '@wordpress/element'
import { __, sprintf } from '@wordpress/i18n'
import {
  ChevronUp,
  ChevronDown,
  Trash2,
  Plus,
  GripVertical,
  Pencil,
  ArrowLeft
} from 'lucide-react'
import { Field, Button, Empty, Badge, cn } from '../../ui'
import { nodeId, move, removeAt, replaceAt, emptyValues } from '../utils'
import { FieldList } from './index'

const COLUMN_CLASSES = {
  1: 'sm:grid-cols-1',
  2: 'sm:grid-cols-2',
  3: 'sm:grid-cols-2 lg:grid-cols-3',
  4: 'sm:grid-cols-2 lg:grid-cols-4'
}

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
 * Ordered list of repeatable rows.
 *
 * @param {Object} props
 * @return {JSX.Element} The control.
 */
export function RepeaterField({ field, value, onChange, context }) {
  const rows = Array.isArray(value) ? value : []
  const [editing, setEditing] = useState(null)

  const max = field.config?.max || 0
  const min = field.config?.min || 0
  const grid = (field.config?.display || 'grid') === 'grid'

  // the section's column setting is what makes a row of three cards look like
  // one; without it the tiles fall back to a sensible three-up
  const columns = Number(context?.columns) || 3

  /**
   * Appends an empty row and returns its index.
   *
   * @return {number} The new row's index.
   */
  const addRow = () => {
    onChange([...rows, { id: nodeId('r'), values: emptyValues(field.fields) }])

    return rows.length
  }

  /**
   * Replaces one row's values.
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

  // placeholders make the configured shape visible before anything is filled
  // in: a three-column section shows three slots, not an empty box
  const placeholders = grid ? Math.max(0, Math.min(columns, max || columns) - rows.length) : 0

  const current = editing !== null && rows[editing] ? rows[editing] : null

  // a row opens in place rather than in a dialog. the repeater is already
  // inside one, and a dialog on top of a dialog leaves no obvious way back
  if (current) {
    return (
      <Field label={field.label} help={field.help} required={field.required}>
        <div className="rounded-lg border border-border">
          <div className="flex items-center gap-2 border-b border-border bg-muted/40 px-2 py-1.5">
            <Button
              size="icon-sm"
              variant="ghost"
              aria-label={__('Back', 'schemapress')}
              onClick={() => setEditing(null)}
            >
              <ArrowLeft />
            </Button>

            <span className="min-w-0 flex-1 truncate text-[13px] font-medium">
              {rowLabel(field, current, editing)}
            </span>

            <Badge variant="outline">
              {sprintf(
                /* translators: 1: current item number, 2: total items */
                __('%1$d of %2$d', 'schemapress'),
                editing + 1,
                rows.length
              )}
            </Badge>

            <Button
              size="icon-sm"
              variant="ghost"
              aria-label={__('Previous', 'schemapress')}
              disabled={editing === 0}
              onClick={() => setEditing(editing - 1)}
            >
              <ChevronUp />
            </Button>
            <Button
              size="icon-sm"
              variant="ghost"
              aria-label={__('Next', 'schemapress')}
              disabled={editing === rows.length - 1}
              onClick={() => setEditing(editing + 1)}
            >
              <ChevronDown />
            </Button>
          </div>

          <div className="p-3">
            <FieldList
              fields={field.fields}
              values={current.values}
              onChange={(key, next) => updateRow(editing, key, next)}
            />
          </div>
        </div>
      </Field>
    )
  }

  return (
    <Field label={field.label} help={field.help} required={field.required}>
      <div className="flex flex-col gap-3">
        {grid ? (
          <div className={cn('grid grid-cols-1 gap-2', COLUMN_CLASSES[columns] || COLUMN_CLASSES[3])}>
            {rows.map((row, index) => (
              <Tile
                key={row.id}
                label={rowLabel(field, row, index)}
                index={index}
                total={rows.length}
                filled={Object.values(row.values || {}).some(
                  (entry) => entry !== '' && entry !== null && entry !== undefined
                )}
                onOpen={() => setEditing(index)}
                onMove={(to) => onChange(move(rows, index, to))}
                onRemove={rows.length > min ? () => onChange(removeAt(rows, index)) : undefined}
              />
            ))}

            {Array.from({ length: placeholders }).map((_, offset) => (
              <button
                key={`placeholder-${offset}`}
                type="button"
                onClick={() => setEditing(addRow())}
                className="flex min-h-[92px] flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-border text-muted-foreground transition-colors hover:border-ring/40 hover:bg-accent/40"
              >
                <Plus className="size-4" />
                <span className="text-[12px] font-medium">
                  {__('Add', 'schemapress')} {field.label}
                </span>
              </button>
            ))}
          </div>
        ) : (
          <div className="flex flex-col gap-2">
            {rows.map((row, index) => (
              <Row
                key={row.id}
                label={rowLabel(field, row, index)}
                index={index}
                total={rows.length}
                onOpen={() => setEditing(index)}
                onMove={(to) => onChange(move(rows, index, to))}
                onRemove={rows.length > min ? () => onChange(removeAt(rows, index)) : undefined}
              />
            ))}
          </div>
        )}

        {rows.length === 0 && placeholders === 0 ? (
          <Empty title={__('Nothing here yet', 'schemapress')} className="py-6" />
        ) : null}

        <div>
          <Button
            size="sm"
            variant="outline"
            disabled={max > 0 && rows.length >= max}
            onClick={() => setEditing(addRow())}
          >
            <Plus />
            {field.config?.button_label || __('Add item', 'schemapress')}
          </Button>
        </div>
      </div>
    </Field>
  )
}

/**
 * One row as a grid tile.
 *
 * @param {Object} props
 * @return {JSX.Element} The tile.
 */
function Tile({ label, index, total, filled, onOpen, onMove, onRemove }) {
  return (
    <div className="group relative flex min-h-[92px] flex-col rounded-lg border border-border bg-background p-3 transition-colors hover:border-ring/40">
      <button type="button" onClick={onOpen} className="flex flex-1 flex-col items-start gap-1 text-left">
        <span className="inline-flex size-5 items-center justify-center rounded-full bg-muted text-[10px] text-muted-foreground">
          {index + 1}
        </span>
        <span
          className={cn(
            'line-clamp-2 text-[13px] font-medium',
            !filled && 'italic text-muted-foreground'
          )}
        >
          {label}
        </span>
      </button>

      <div className="mt-2 flex items-center gap-0.5 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100">
        <Button size="icon-sm" variant="ghost" aria-label={__('Edit', 'schemapress')} onClick={onOpen}>
          <Pencil />
        </Button>
        <Button
          size="icon-sm"
          variant="ghost"
          aria-label={__('Move earlier', 'schemapress')}
          disabled={index === 0}
          onClick={() => onMove(index - 1)}
        >
          <ChevronUp />
        </Button>
        <Button
          size="icon-sm"
          variant="ghost"
          aria-label={__('Move later', 'schemapress')}
          disabled={index === total - 1}
          onClick={() => onMove(index + 1)}
        >
          <ChevronDown />
        </Button>
        {onRemove ? (
          <Button
            size="icon-sm"
            variant="destructive-ghost"
            aria-label={__('Remove', 'schemapress')}
            onClick={onRemove}
          >
            <Trash2 />
          </Button>
        ) : null}
      </div>
    </div>
  )
}

/**
 * One row as a list item, for repeaters that are not card-shaped.
 *
 * @param {Object} props
 * @return {JSX.Element} The row.
 */
function Row({ label, index, total, onOpen, onMove, onRemove }) {
  return (
    <div className="flex items-center gap-1 rounded-md border border-border bg-background px-2 py-1.5">
      <GripVertical className="size-3.5 shrink-0 text-muted-foreground/40" />
      <button type="button" onClick={onOpen} className="flex min-w-0 flex-1 items-center gap-2 text-left">
        <span className="inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-muted text-[10px] text-muted-foreground">
          {index + 1}
        </span>
        <span className="truncate text-[13px]">{label}</span>
      </button>

      <Button
        size="icon-sm"
        variant="ghost"
        aria-label={__('Move up', 'schemapress')}
        disabled={index === 0}
        onClick={() => onMove(index - 1)}
      >
        <ChevronUp />
      </Button>
      <Button
        size="icon-sm"
        variant="ghost"
        aria-label={__('Move down', 'schemapress')}
        disabled={index === total - 1}
        onClick={() => onMove(index + 1)}
      >
        <ChevronDown />
      </Button>
      {onRemove ? (
        <Button
          size="icon-sm"
          variant="destructive-ghost"
          aria-label={__('Remove', 'schemapress')}
          onClick={onRemove}
        >
          <Trash2 />
        </Button>
      ) : null}
    </div>
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
      <div className="rounded-md border-l-2 border-border bg-muted/30 p-3 pl-4">
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
