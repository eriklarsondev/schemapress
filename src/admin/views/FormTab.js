/**
 * Arranging the entry form.
 *
 * A canvas, not a settings table. Each field is drawn roughly as its control
 * will be drawn — a label over a box the shape of the input — laid on the same
 * twelve columns the entry form uses. So this screen looks like the thing it
 * configures, and "half width, third from the top" is something you see rather
 * than something you read off a row of dropdowns and assemble in your head.
 *
 * Two kinds of empty space, and both are drop targets:
 *
 *   leftover  what a row has spare after its fields
 *   new row   a full-width strip under the layout, and between rows while
 *             dragging, so a field always has somewhere to land
 *
 * Dragging across a target previews the width the field would take, because at
 * a third of the way in it becomes a third — the position IS the width, and
 * asking for it separately afterwards would be asking twice.
 *
 * Nothing here reaches the front end. It is presentation of the admin screen,
 * which is this plugin's own to arrange.
 */

import { useEffect, useRef, useState } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { Save, LayoutList, GripVertical } from 'lucide-react'
import { Card, CardBody, Button, Alert, Badge, Empty, cn } from '../../ui'
import { move } from '../../shared/utils'

/** The widths a control may take, in twelfths. */
const WIDTHS = [
  { value: 'third', span: 4, label: '⅓' },
  { value: 'half', span: 6, label: '½' },
  { value: 'two-thirds', span: 8, label: '⅔' },
  { value: 'full', span: 12, label: 'Full' }
]

/**
 * Tailwind cannot see a computed class name, so every span is written out.
 * Leftovers take whatever a row has spare, so all twelve can occur.
 */
const SPANS = {
  1: 'sm:col-span-1',
  2: 'sm:col-span-2',
  3: 'sm:col-span-3',
  4: 'sm:col-span-4',
  5: 'sm:col-span-5',
  6: 'sm:col-span-6',
  7: 'sm:col-span-7',
  8: 'sm:col-span-8',
  9: 'sm:col-span-9',
  10: 'sm:col-span-10',
  11: 'sm:col-span-11',
  12: 'sm:col-span-12'
}

/**
 * The height a type's control roughly occupies, so the canvas reads like the
 * form rather than like a stack of identical boxes.
 */
const HEIGHTS = {
  textarea: 'h-14',
  wysiwyg: 'h-16',
  image: 'h-16',
  file: 'h-14',
  repeater: 'h-14',
  group: 'h-14'
}

/**
 * The width a field is set to, defaulting to full.
 *
 * @param {Object} field
 * @return {string} The width token.
 */
function widthOf(field) {
  const width = field.config?.width

  return WIDTHS.some((option) => option.value === width) ? width : 'full'
}

/**
 * How many twelfths a field takes.
 *
 * @param {Object} field
 * @return {number} The span.
 */
function spanOf(field) {
  return WIDTHS.find((option) => option.value === widthOf(field)).span
}

/**
 * The width option nearest a span, for snapping an edge resize.
 *
 * @param {number} span
 * @return {Object} The width option.
 */
function nearest(span) {
  return WIDTHS.reduce((best, option) =>
    Math.abs(option.span - span) < Math.abs(best.span - span) ? option : best
  )
}

/**
 * The widths that fit a gap this wide, narrowest first.
 *
 * A gap narrower than a third can hold nothing properly, so it still offers the
 * third — the field wraps to its own row, which is the honest outcome rather
 * than a drop that silently does nothing.
 *
 * @param {number} span
 * @return {Array} The width options.
 */
function fitting(span) {
  const fits = WIDTHS.filter((option) => option.span <= span)

  return fits.length > 0 ? fits : [WIDTHS[0]]
}

/**
 * Packs fields into rows of twelve, marking every place something could go.
 *
 * @param {Array} fields
 * @return {Array} Cells, in order.
 */
function pack(fields) {
  const cells = []
  let used = 0

  fields.forEach((field, index) => {
    const span = spanOf(field)

    if (used > 0 && used + span > 12) {
      cells.push({ kind: 'gap', span: 12 - used, at: index })
      used = 0
    }

    // between two rows, while dragging: without it a field can only be dropped
    // where there is spare room, and a tidy layout has none
    if (used === 0 && index > 0) {
      cells.push({ kind: 'row', at: index })
    }

    cells.push({ kind: 'field', field, index, span })
    used = (used + span) % 12
  })

  // the spare part of the last row, if it has any
  if (used > 0) {
    cells.push({ kind: 'gap', span: 12 - used, at: fields.length })
  }

  // and a full-width strip under everything, so there is always somewhere to
  // put a field that should span the row
  cells.push({ kind: 'row', at: fields.length, last: true })

  return cells
}

/**
 * A drop target that also decides a width.
 *
 * @param {Object} props
 * @return {JSX.Element} The target.
 */
function Target({ span, at, last, dragging, onDrop }) {
  const [preview, setPreview] = useState(null)

  const options = fitting(span)

  /**
   * The width the pointer is currently choosing.
   *
   * @param {Object} event
   * @return {Object} The width option.
   */
  const widthAt = (event) => {
    const box = event.currentTarget.getBoundingClientRect()
    const columns = Math.max(1, Math.round(((event.clientX - box.left) / box.width) * span))

    return [...options].reverse().find((option) => option.span <= columns) || options[0]
  }

  return (
    <div
      onDragOver={(event) => {
        event.preventDefault()
        setPreview(widthAt(event))
      }}
      onDragLeave={() => setPreview(null)}
      onDrop={(event) => {
        event.preventDefault()

        const chosen = preview || options[0]

        setPreview(null)
        onDrop(at, chosen.value)
      }}
      className={cn(
        'relative rounded-lg border-2 border-dashed transition-all',
        SPANS[span],
        // a target you are dragging over is tall enough to aim at; one you are
        // not is still visible, so you know it is there before you start
        dragging ? 'min-h-[4.5rem]' : last ? 'min-h-[3rem]' : 'min-h-[3.5rem]',
        preview
          ? 'border-primary bg-primary/5'
          : dragging
            ? 'border-ring/50 bg-accent/20'
            : 'border-border bg-muted/30'
      )}
    >
      {preview ? (
        <div
          className="absolute inset-y-0 left-0 flex items-center justify-center rounded-lg bg-primary/15 text-[13px] font-semibold text-primary transition-[width]"
          style={{ width: `${(preview.span / span) * 100}%` }}
        >
          {preview.label}
        </div>
      ) : (
        <span className="flex h-full items-center justify-center text-[12px] text-muted-foreground">
          {dragging
            ? __('Drop here', 'schemapress')
            : last
              ? __('Drop a field here for a new row', 'schemapress')
              : __('Empty', 'schemapress')}
        </span>
      )}
    </div>
  )
}

/**
 * The entry form arranger.
 *
 * @param {Object} props
 * @return {JSX.Element} The tab.
 */
export function FormTab({ fields, onChange }) {
  const [draft, setDraft] = useState(fields)
  const [saving, setSaving] = useState(false)
  const [dragging, setDragging] = useState(null)
  const [resizing, setResizing] = useState(null)

  const grid = useRef(null)

  useEffect(() => {
    setDraft(fields)
  }, [fields])

  const dirty = JSON.stringify(draft) !== JSON.stringify(fields)

  /**
   * Sets one field's width.
   *
   * @param {number} index
   * @param {string} width
   * @return {void}
   */
  const setWidth = (index, width) =>
    setDraft((current) =>
      current.map((field, i) =>
        i === index ? { ...field, config: { ...field.config, width } } : field
      )
    )

  /**
   * Begins an edge resize, following the pointer until it is released.
   *
   * The column width is measured from the live grid rather than assumed, so the
   * snap points line up with what is on screen at any window size.
   *
   * @param {Object} event
   * @param {number} index
   * @return {void}
   */
  const startResize = (event, index) => {
    event.preventDefault()
    event.stopPropagation()

    const cell = event.currentTarget.parentElement
    const box = grid.current?.getBoundingClientRect()

    if (!box) {
      return
    }

    const left = cell.getBoundingClientRect().left
    const column = box.width / 12

    setResizing(index)

    /**
     * @param {PointerEvent} moved
     * @return {void}
     */
    const onMove = (moved) => {
      const span = Math.round((moved.clientX - left) / column)

      setWidth(index, nearest(Math.min(12, Math.max(1, span))).value)
    }

    /**
     * @return {void}
     */
    const onUp = () => {
      setResizing(null)
      window.removeEventListener('pointermove', onMove)
      window.removeEventListener('pointerup', onUp)
    }

    window.addEventListener('pointermove', onMove)
    window.addEventListener('pointerup', onUp)
  }

  /**
   * Moves the dragged field to a position, resizing it to whatever the drop
   * position chose.
   *
   * @param {number}      to
   * @param {string|null} width
   * @return {void}
   */
  const drop = (to, width = null) => {
    if (dragging === null) {
      return
    }

    setDraft((current) => {
      const sized = width
        ? current.map((field, i) =>
            i === dragging ? { ...field, config: { ...field.config, width } } : field
          )
        : current

      // a move to a later index counts the field being moved, so the target it
      // was dropped on has already shifted left by one
      const target = to > dragging ? to - 1 : to

      return dragging === target ? sized : move(sized, dragging, target)
    })

    setDragging(null)
  }

  /**
   * Stores the draft.
   *
   * @return {void}
   */
  const save = () => {
    setSaving(true)
    Promise.resolve(onChange(draft)).finally(() => setSaving(false))
  }

  if (fields.length === 0) {
    return (
      <Empty
        icon={LayoutList}
        title={__('Nothing to arrange yet', 'schemapress')}
        description={__('Add some fields first, then come back.', 'schemapress')}
        className="py-16"
      />
    )
  }

  return (
    <div className="flex flex-col gap-3">
      <Alert variant="info">
        {__(
          'Drag a field onto an empty area to move it — how far across you drop it sets how wide it becomes. Or drag its right edge to resize in place.',
          'schemapress'
        )}
      </Alert>

      <Card>
        <CardBody>
          <div ref={grid} className="grid grid-cols-1 gap-3 sm:grid-cols-12">
            {pack(draft).map((cell) => {
              if (cell.kind === 'gap' || cell.kind === 'row') {
                return (
                  <Target
                    key={`${cell.kind}-${cell.at}-${cell.span || 12}`}
                    span={cell.span || 12}
                    at={cell.at}
                    last={cell.last}
                    dragging={dragging !== null}
                    onDrop={drop}
                  />
                )
              }

              const { field, index, span } = cell
              const isDragging = dragging === index
              const width = WIDTHS.find((option) => option.span === span)

              return (
                <div
                  key={field.key}
                  draggable={resizing === null}
                  onDragStart={(event) => {
                    event.dataTransfer.effectAllowed = 'move'
                    setDragging(index)
                  }}
                  onDragEnd={() => setDragging(null)}
                  className={cn(
                    'group relative rounded-lg border-2 bg-background p-3 shadow-sm transition-colors',
                    SPANS[span],
                    resizing === index
                      ? 'border-primary'
                      : isDragging
                        ? 'border-primary opacity-40'
                        : 'border-border hover:border-primary/40',
                    resizing === null && 'cursor-grab active:cursor-grabbing'
                  )}
                >
                  <div className="mb-2 flex min-w-0 items-center gap-1.5">
                    <GripVertical className="size-3.5 shrink-0 text-muted-foreground/50 transition-colors group-hover:text-foreground" />

                    <span className="min-w-0 flex-1 truncate text-[13px] font-semibold">
                      {field.label}
                    </span>

                    <Badge variant="outline">{field.type}</Badge>
                    <Badge variant="mono">{width?.label}</Badge>
                  </div>

                  {/* the control, roughly — enough that the canvas reads as the
                      form and not as a list of identical boxes */}
                  <div
                    aria-hidden="true"
                    className={cn(
                      'rounded-md border border-input bg-muted',
                      HEIGHTS[field.type] || 'h-9'
                    )}
                  />

                  {/* the right edge is the resize grip */}
                  <span
                    role="separator"
                    aria-orientation="vertical"
                    aria-label={__('Resize', 'schemapress')}
                    onPointerDown={(event) => startResize(event, index)}
                    className={cn(
                      'absolute inset-y-3 right-0 flex w-3 cursor-col-resize items-center justify-center rounded-r-lg transition-opacity',
                      resizing === index ? 'opacity-100' : 'opacity-0 group-hover:opacity-100'
                    )}
                  >
                    <span className="h-8 w-1 rounded-full bg-primary" />
                  </span>
                </div>
              )
            })}
          </div>
        </CardBody>
      </Card>

      <div className="flex items-center gap-3">
        <Button disabled={!dirty || saving} onClick={save}>
          <Save />
          {saving ? __('Saving…', 'schemapress') : __('Save layout', 'schemapress')}
        </Button>

        {dirty && !saving ? (
          <span className="text-[12px] text-muted-foreground">
            {__('Unsaved changes', 'schemapress')}
          </span>
        ) : null}
      </div>
    </div>
  )
}
