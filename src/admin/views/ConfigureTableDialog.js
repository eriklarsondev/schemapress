/**
 * Choosing what the entries table shows.
 *
 * A collection can have thirty fields and a table can usefully show four. Which
 * four depends entirely on the collection — for staff it is name and role, for
 * events it is the date — and only the person who built it knows. So they pick.
 *
 * Order matters as much as inclusion, so rows are dragged into the order they
 * should appear in.
 *
 * The first chosen field is the link into the entry, so it wants to be the one
 * that identifies a row — a name, not a date. Updated and Status are not in
 * this list: they are not fields, they are true of every entry.
 *
 * Reordering is by drag alone. That does mean it cannot be done from the
 * keyboard, which is worth knowing.
 */

import { useState } from '@wordpress/element'
import { __, sprintf } from '@wordpress/i18n'
import { Dialog, Button, Checkbox, Alert, cn } from '../../ui'
import { move } from '../../shared/utils'

/**
 * Builds the working list: chosen fields first in their chosen order, then
 * everything else in the order the form has them.
 *
 * @param {Array}      fields
 * @param {Array|null} chosen
 * @param {number}     fallback How many lead if nothing is chosen yet.
 * @return {Array} Rows of {key, label, type, on}.
 */
function rows(fields, chosen, fallback) {
  if (!Array.isArray(chosen)) {
    return fields.map((field, index) => ({ ...field, on: index < fallback }))
  }

  const byKey = new Map(fields.map((field) => [field.key, field]))

  const picked = chosen
    .filter((key) => byKey.has(key))
    .map((key) => ({ ...byKey.get(key), on: true }))

  const rest = fields
    .filter((field) => !chosen.includes(field.key))
    .map((field) => ({ ...field, on: false }))

  return [...picked, ...rest]
}

/**
 * The configure dialog.
 *
 * @param {Object} props
 * @return {JSX.Element} The dialog.
 */
export function ConfigureTableDialog({ fields, columns, fallback = 4, onClose, onSave }) {
  const [items, setItems] = useState(() => rows(fields, columns, fallback))
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [dragging, setDragging] = useState(-1)

  const chosen = items.filter((item) => item.on)

  /**
   * Reorders as the pointer passes over a row, rather than on drop.
   *
   * Moving the list under the cursor means what you see while dragging is the
   * order you will get, so there is nothing to aim at and no drop indicator to
   * interpret — the row is already where it is going.
   *
   * @param {number} over The index being dragged over.
   * @return {void}
   */
  const dragOver = (over) => {
    if (dragging === -1 || dragging === over) {
      return
    }

    setItems((current) => move(current, dragging, over))
    setDragging(over)
  }

  /**
   * Stores the choice.
   *
   * @return {void}
   */
  const save = () => {
    setSaving(true)
    setError('')

    Promise.resolve(onSave(chosen.map((item) => item.key)))
      .then(onClose)
      .catch((failure) => {
        setSaving(false)
        setError(failure.message)
      })
  }

  return (
    <Dialog
      open
      size="md"
      onOpenChange={(next) => !next && onClose()}
      title={__('Configure the table', 'schemapress')}
      description={__(
        'Which fields appear as columns, and in what order. The first one is the link into the entry.',
        'schemapress'
      )}
      footer={
        <>
          <Button variant="outline" onClick={onClose}>
            {__('Cancel', 'schemapress')}
          </Button>
          <Button disabled={saving} onClick={save}>
            {saving ? __('Saving…', 'schemapress') : __('Save', 'schemapress')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        {error ? <Alert variant="warning">{error}</Alert> : null}

        <div className="flex flex-col divide-y divide-border overflow-hidden rounded-md border border-border">
          {items.map((item, index) => (
            <div
              key={item.key}
              draggable
              onDragStart={(event) => {
                setDragging(index)
                event.dataTransfer.effectAllowed = 'move'
                // Firefox refuses to start a drag without payload
                event.dataTransfer.setData('text/plain', item.key)
              }}
              onDragOver={(event) => {
                event.preventDefault()
                event.dataTransfer.dropEffect = 'move'
                dragOver(index)
              }}
              onDrop={(event) => {
                event.preventDefault()
                setDragging(-1)
              }}
              onDragEnd={() => setDragging(-1)}
              className={cn(
                'flex cursor-grab items-center gap-3 px-3 py-3 transition-colors',
                item.on ? 'bg-background' : 'bg-muted/40',
                // the row being dragged becomes the slot it will land in:
                // an outlined gap in the list, so the destination is a shape
                // on screen rather than something to infer from the cursor
                dragging === index &&
                  'cursor-grabbing rounded-md bg-accent/40 text-transparent outline-dashed outline-2 -outline-offset-2 outline-ring/50'
              )}
            >
              <Checkbox
                checked={item.on}
                aria-label={item.label}
                onChange={(next) =>
                  setItems((current) =>
                    current.map((row, i) => (i === index ? { ...row, on: !!next } : row))
                  )
                }
              />

              <span className="min-w-0 flex-1 truncate text-[13px]">
                {item.label}
                <span className="ml-1.5 text-[11px] text-muted-foreground">{item.type}</span>
              </span>

            </div>
          ))}
        </div>

        <p className="text-[12px] text-muted-foreground">
          {chosen.length === 0
            ? __(
                'No field columns — the table falls back to each entry’s own name.',
                'schemapress'
              )
            : sprintf(
                /* translators: %d: number of chosen columns */
                __('%d field columns.', 'schemapress'),
                chosen.length
              )}
        </p>
      </div>
    </Dialog>
  )
}
