/**
 * Arranging the entry form.
 *
 * A canvas, not a settings table. Each field is drawn as a card on the same
 * twelve columns the entry form uses, so this screen looks like the thing it
 * configures and "half width, third from the top" is something you see rather
 * than something you read off a row of dropdowns and assemble in your head.
 *
 * This tab owns how the form BEHAVES, the Schema tab owns what the data is.
 * Nothing set here changes a stored value:
 *
 *   order   drag a card onto another and they swap places
 *   width   the badge on the card, or drop into a row's leftover space and the
 *           field takes exactly that width
 *   rows    drop onto the strip between two rows and the field starts a row of
 *           its own, at the width it already had. a grid packs its items
 *           together, so where a row ENDS is the one thing widths cannot say —
 *           without stating it, a half-width field dragged below a third-width
 *           one simply floats back up into the space beside it
 *   rest    click the card — placeholder, help text, required, when it shows
 *
 * The previous version made width a consequence of how far across a target you
 * released, which meant every drop was a guess and you could not move a field
 * without also resizing it. A gap, by contrast, has one sensible size: a third
 * of a row left over fits a third. That is a rule you can predict.
 *
 * Those gaps appear ONLY while dragging. Standing there permanently they read
 * as empty content rather than as targets, which is what made the first version
 * of this screen confusing.
 *
 * Nothing here reaches the front end. It is presentation of the admin screen,
 * which is this plugin's own to arrange.
 */

import { Fragment, useEffect, useState } from '@wordpress/element'
import { __, sprintf } from '@wordpress/i18n'
import { Save, LayoutList, Pencil } from 'lucide-react'
import {
  Card,
  CardBody,
  Button,
  Alert,
  Badge,
  Empty,
  Segmented,
  Dialog,
  Field,
  Input,
  Select,
  Switch,
  Popover,
  cn
} from '../../ui'
import { move } from '../../shared/utils'
import { conditionTargets } from '../../shared/conditions'
// the canvas must break its rows exactly the way the entry form does, or it is
// a picture of a layout rather than the layout
import { breakBefore, rowBreakClass, startsRow } from '../../shared/layout'

/** The types whose control takes a placeholder, mirroring SchemaModel. */
const PLACEHOLDER_TYPES = ['text', 'textarea', 'email', 'url', 'phone']

/** The widths a control may take, in twelfths. */
const WIDTHS = [
  { value: 'third', span: 4, label: __('⅓', 'schemapress') },
  { value: 'half', span: 6, label: __('½', 'schemapress') },
  { value: 'two-thirds', span: 8, label: __('⅔', 'schemapress') },
  { value: 'full', span: 12, label: __('Full', 'schemapress') }
]

/**
 * Tailwind cannot see a computed class name, so every span is written out. A
 * leftover gap takes whatever a row has spare, so all twelve can occur.
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
 * Where a control starts, when it is not simply next in the flow. Written out
 * for the same reason as SPANS: Tailwind cannot see a computed class name.
 */
const STARTS = {
  1: 'sm:col-start-1',
  2: 'sm:col-start-2',
  3: 'sm:col-start-3',
  4: 'sm:col-start-4',
  5: 'sm:col-start-5',
  6: 'sm:col-start-6',
  7: 'sm:col-start-7',
  8: 'sm:col-start-8',
  9: 'sm:col-start-9'
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
 * How much blank space sits before a field on its row.
 *
 * @param {Object} field
 * @return {number} The offset in twelfths.
 */
function offsetOf(field) {
  const offset = Number(field.config?.offset) || 0

  return Math.max(0, Math.min(offset, 12 - spanOf(field)))
}

/**
 * The widest width that fits a gap, or null if nothing does.
 *
 * @param {number} span
 * @return {Object|null} The width option.
 */
function fits(span) {
  const options = WIDTHS.filter((option) => option.span <= span)

  return options.length ? options[options.length - 1] : null
}

/**
 * Packs fields into rows of twelve, noting the space each row has left over
 * and where one row gives way to the next.
 *
 * Both only matter while something is being dragged, which is when they become
 * the answer to "where can this go". A leftover has exactly one sensible size,
 * so dropping into one sets the width rather than asking afterwards. A row
 * boundary has no size at all — dropping there keeps the width the field
 * already had, because starting a row is a decision about position.
 *
 * @param {Array} fields
 * @return {Array} Cells, in order.
 */
function pack(fields) {
  const cells = []
  let used = 0
  let row = []

  /**
   * Ends the current row: offers what is left of it, then offers the boundary
   * underneath as a row of its own.
   *
   * @param {number} at Where in the order a field dropped here would land.
   * @return {void}
   */
  const close = (at) => {
    if (used > 0 && used < 12) {
      cells.push({ gap: 12 - used, at, start: used, row })
    }

    cells.push({ gap: 12, at, start: 0, row: [], newRow: true })

    used = 0
    row = []
  }

  // the boundary above the first row, so a field can be given a row at the top
  // as readily as anywhere else
  if (fields.length > 0) {
    cells.push({ gap: 12, at: 0, start: 0, row: [], newRow: true })
  }

  fields.forEach((field, index) => {
    const offset = offsetOf(field)
    const span = spanOf(field) + offset

    // a field that starts a row ends the one above it, whether or not what it
    // holds would have fitted — which is the whole point of saying so
    if (used > 0 && (startsRow(field) || used + span > 12)) {
      close(index)
    }

    // blank space a field's own offset put in front of it. it is as droppable
    // as any other gap, and without this it was the one hole on the screen
    // with nothing offering to fill it
    if (offset > 0) {
      cells.push({ gap: offset, at: index, start: used, row })
    }

    cells.push({ field, index })
    used += span
    row = [...row, index]

    if (used >= 12) {
      close(index + 1)
    }
  })

  // a row filled to the twelfth has already offered the boundary under it
  if (used > 0 || fields.length === 0) {
    close(fields.length)
  }

  return cells
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
  const [dragging, setDragging] = useState(-1)
  const [editing, setEditing] = useState(-1)

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
   * Swaps one field for an edited copy of it, and stores the result.
   *
   * The dialog has its own confirm button, and a confirm button that only
   * confirms into another unsaved pile is a confirm button that lied. So
   * pressing Save there saves the layout — the drag-and-drop on the canvas
   * still batches behind Save layout, because a drag is exploratory in a way
   * that filling in a form is not.
   *
   * @param {number} index
   * @param {Object} next
   * @return {void}
   */
  const replaceAndSave = (index, next) => {
    const updated = draft.map((field, i) => (i === index ? next : field))

    setDraft(updated)
    persist(updated)
  }

  /**
   * Reorders as a dragged card passes over another, rather than on drop.
   *
   * The layout rearranges under the pointer, so what is on screen mid-drag is
   * what you will get — including how the rows re-wrap, which is the part that
   * was hardest to predict before.
   *
   * @param {number} over
   * @return {void}
   */
  const dragOver = (over) => {
    if (dragging === -1 || dragging === over) {
      return
    }

    setDraft((current) => move(current, dragging, over))
    setDragging(over)
  }

  /**
   * Drops the dragged field onto a row boundary, giving it a row of its own.
   *
   * It keeps the width it had: a row of its own is where the field sits, not
   * how wide it is, and a field that jumped to full width every time it was
   * moved down would be a field you cannot move down.
   *
   * The field after it is pinned to a new row too. Without that it flows up
   * into whatever the new row has spare — which is the collapse this whole
   * mechanism exists to stop, only one field further along.
   *
   * @param {Object} cell
   * @return {void}
   */
  const dropInNewRow = (cell) => {
    setDraft((current) => {
      const marked = current.map((field, i) =>
        i === dragging
          ? { ...field, config: { ...field.config, offset: 0, new_row: true } }
          : field
      )

      // moving to a later index counts the field being moved, so the boundary
      // it was dropped on has already shifted up by one
      const to = cell.at > dragging ? cell.at - 1 : cell.at

      // only when the field actually came from elsewhere. dropping on the
      // boundary it already sits against moves nothing, and pinning a
      // neighbour there would rearrange a row nobody touched
      if (to === dragging) {
        return marked
      }

      return move(marked, dragging, to).map((field, i) =>
        i === to + 1 ? { ...field, config: { ...field.config, new_row: true } } : field
      )
    })

    setDragging(-1)
  }

  /**
   * Drops the dragged field into a row's leftover space, sizing it to fill.
   *
   * @param {Object} cell
   * @return {void}
   */
  const dropInGap = (cell) => {
    if (dragging === -1) {
      return
    }

    if (cell.newRow) {
      dropInNewRow(cell)

      return
    }

    const width = fits(cell.gap)

    if (!width) {
      return
    }

    setDraft((current) => {
      // the field lands where the gap is, not merely after whatever preceded
      // it. usually the row's other fields push it there on their own; but if
      // the only thing before the gap was the field being moved, it leaves as
      // it arrives, and the space it should sit in has to be stated
      const before = cell.row
        .filter((index) => index !== dragging)
        .reduce((sum, index) => sum + spanOf(current[index]) + offsetOf(current[index]), 0)

      const sized = current.map((field, i) =>
        i === dragging
          ? {
              ...field,
              config: {
                ...field.config,
                width: width.value,
                offset: Math.max(0, cell.start - before),
                // it is joining a row, so it is no longer starting one
                new_row: false
              }
            }
          : field
      )

      // moving to a later index counts the field being moved, so the gap it
      // was dropped into has already shifted left by one
      return move(sized, dragging, cell.at > dragging ? cell.at - 1 : cell.at)
    })

    setDragging(-1)
  }

  /**
   * Stores a field list.
   *
   * @param {Array} list
   * @return {void}
   */
  const persist = (list) => {
    setSaving(true)
    Promise.resolve(onChange(list)).finally(() => setSaving(false))
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
          'Drag a field onto another to reorder, into a row’s spare space to fill it, or onto a New row strip to give it a row of its own. Click a card for its placeholder, help text and whether it is required.',
          'schemapress'
        )}
      </Alert>

      <Card>
        <CardBody>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-12">
            {pack(draft).map((cell) =>
              cell.field ? (
                <Fragment key={cell.field.key}>
                  {/* the break is what actually holds the row open once the
                      drop targets are gone: without it the card flows straight
                      back up into the space left on the row above. mid-drag the
                      New row strip sitting here is already full width and ends
                      the row on its own, so the break would only add air */}
                  {dragging === -1 && breakBefore(cell.field, cell.index) ? (
                    <div aria-hidden="true" className={rowBreakClass()} />
                  ) : null}

                  <FieldCard
                    field={cell.field}
                    index={cell.index}
                    dragging={dragging === cell.index}
                    onDragStart={() => setDragging(cell.index)}
                    onDragOver={() => dragOver(cell.index)}
                    onDragEnd={() => setDragging(-1)}
                    onWidth={(width) => setWidth(cell.index, width)}
                    onEdit={() => setEditing(cell.index)}
                  />
                </Fragment>
              ) : (
                <Gap
                  key={`gap-${cell.at}-${cell.start}-${cell.gap}${cell.newRow ? '-new' : ''}`}
                  span={cell.gap}
                  start={cell.start}
                  newRow={cell.newRow}
                  dragging={dragging !== -1}
                  onDrop={() => dropInGap(cell)}
                />
              )
            )}
          </div>
        </CardBody>
      </Card>

      {draft[editing] ? (
        <FieldDialog
          field={draft[editing]}
          siblings={draft}
          onClose={() => setEditing(-1)}
          onSave={(next) => {
            replaceAndSave(editing, next)
            setEditing(-1)
          }}
        />
      ) : null}

      <div className="flex items-center gap-3">
        <Button disabled={!dirty || saving} onClick={() => persist(draft)}>
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

/**
 * Somewhere to drop: a row's leftover space, or the boundary between two rows.
 *
 * Only while dragging. Standing on screen the rest of the time, these read as
 * content — empty boxes in a form — rather than as targets, which is what the
 * first version of this tab got wrong.
 *
 * A leftover is labelled with the width the field will become, because that is
 * the whole bargain: the gap is this wide, so the field will be too. A boundary
 * makes no such bargain — it is about which row the field is on, and the field
 * arrives at the width it left with.
 *
 * @param {Object} props
 * @return {JSX.Element|null} The target.
 */
function Gap({ span, start, newRow, dragging, onDrop }) {
  const [over, setOver] = useState(false)

  const width = fits(span)

  // a sliver narrower than a third can hold nothing, so it is not offered. a
  // row boundary holds anything, whatever its width
  if (!dragging || (!newRow && !width)) {
    return null
  }

  // a strip between two rows, not a hole in one: shallower, and it says what
  // it does rather than what the field will become — which is nothing, since
  // dropping here leaves the width alone
  if (newRow) {
    return (
      <div
        onDragOver={(event) => {
          event.preventDefault()
          event.dataTransfer.dropEffect = 'move'
          setOver(true)
        }}
        onDragLeave={() => setOver(false)}
        onDrop={(event) => {
          event.preventDefault()
          setOver(false)
          onDrop()
        }}
        className={cn(
          'flex min-h-[2.5rem] items-center justify-center rounded-lg border-2 border-dashed text-[12px] font-medium transition-colors sm:col-span-12',
          over
            ? 'border-primary bg-primary/10 text-primary'
            : 'border-ring/25 bg-accent/10 text-muted-foreground/80'
        )}
      >
        <span>{__('New row', 'schemapress')}</span>
      </div>
    )
  }

  return (
    <div
      onDragOver={(event) => {
        event.preventDefault()
        event.dataTransfer.dropEffect = 'move'
        setOver(true)
      }}
      onDragLeave={() => setOver(false)}
      onDrop={(event) => {
        event.preventDefault()
        setOver(false)
        onDrop()
      }}
      className={cn(
        'flex min-h-[5rem] items-center justify-center gap-1.5 rounded-lg border-2 border-dashed text-[12px] font-medium transition-colors',
        SPANS[span],
        start > 0 && STARTS[start + 1],
        over ? 'border-primary bg-primary/10 text-primary' : 'border-ring/40 bg-accent/20 text-muted-foreground'
      )}
    >
      <span>{__('Fill this space', 'schemapress')}</span>
      <Badge variant="outline">{width.label}</Badge>
    </div>
  )
}

/**
 * One field, drawn roughly as its control.
 *
 * @param {Object} props
 * @return {JSX.Element} The card.
 */
function FieldCard({
  field,
  index,
  dragging,
  onDragStart,
  onDragOver,
  onDragEnd,
  onWidth,
  onEdit
}) {
  const [sizing, setSizing] = useState(false)

  const width = widthOf(field)
  const option = WIDTHS.find((candidate) => candidate.value === width)
  const offset = offsetOf(field)

  return (
    <div
      draggable
      // the whole card opens the settings. a drag only fires on movement, so
      // the two gestures do not collide, and there is no small target to find
      onClick={onEdit}
      onDragStart={(event) => {
        event.dataTransfer.effectAllowed = 'move'
        // Firefox refuses to start a drag without payload
        event.dataTransfer.setData('text/plain', field.key || String(index))
        onDragStart()
      }}
      onDragOver={(event) => {
        event.preventDefault()
        event.dataTransfer.dropEffect = 'move'
        onDragOver()
      }}
      onDrop={(event) => {
        event.preventDefault()
        onDragEnd()
      }}
      onDragEnd={onDragEnd}
      className={cn(
        'group relative flex cursor-grab flex-col rounded-lg bg-background p-3 shadow-sm transition-colors',
        SPANS[option.span],
        offset > 0 && STARTS[offset + 1],
        dragging
          ? // the card being dragged reads as the gap it left behind, so the
            // destination is a shape on screen rather than a guess. thicker
            // than a resting card on purpose: it is a target now, not content
            'cursor-grabbing items-center justify-center border-2 border-dashed border-ring/60 bg-accent/40'
          : 'border border-border hover:border-primary/40'
      )}
    >
      {/* while it is being dragged the card IS a drop target — put it back
          here — so it says so, like every other target on the screen. hiding
          its contents and leaving a blank dashed box was the one unlabelled
          shape in a row of labelled ones */}
      {dragging ? (
        <span className="flex items-center gap-1.5 text-[12px] font-medium text-muted-foreground">
          {__('Fill this space', 'schemapress')}
          <Badge variant="outline">{option.label}</Badge>
        </span>
      ) : null}

      <div className={cn('mb-2 flex min-w-0 items-center gap-1.5', dragging && 'hidden')}>
        <span className="min-w-0 flex-1 truncate text-[13px] font-semibold">{field.label}</span>

        <Badge variant="outline">{field.type}</Badge>

        {/* width is the one setting worth changing without opening anything,
            because it is the whole point of this screen — so it is a popover
            on the card rather than a row of buttons under every field */}
        <Popover
          open={sizing}
          onOpenChange={setSizing}
          align="end"
          className="p-2"
          trigger={
            <button
              type="button"
              onClick={(event) => event.stopPropagation()}
              aria-label={sprintf(
                /* translators: %s: the field's label */
                __('Width of %s', 'schemapress'),
                field.label
              )}
              className="flex h-5 min-w-[1.75rem] items-center justify-center rounded border border-border px-1 text-[11px] font-medium text-muted-foreground transition-colors hover:border-primary/50 hover:text-foreground"
            >
              {option.label}
            </button>
          }
        >
          <div onClick={(event) => event.stopPropagation()}>
            <Segmented
              value={width}
              onChange={(next) => {
                onWidth(next)
                setSizing(false)
              }}
              options={WIDTHS.map((candidate) => ({
                value: candidate.value,
                label: candidate.label
              }))}
            />
          </div>
        </Popover>
      </div>

      {/* the control, roughly. every one is the same height on purpose: this
          screen arranges fields across the row, and a textarea drawn taller
          than its neighbour only makes the cards in a row line up badly while
          saying nothing about the layout being set */}
      <div
        className={cn(
          'relative flex h-9 w-full items-center rounded-md border border-input bg-muted px-2.5 text-[12px] text-muted-foreground transition-colors group-hover:border-primary/40',
          dragging && 'hidden'
        )}
      >
        <span className="min-w-0 flex-1 truncate">{field.config?.placeholder || ''}</span>

        <Pencil className="size-3.5 shrink-0 opacity-0 transition-opacity group-hover:opacity-60" />
      </div>
    </div>
  )
}


/**
 * One field's presentation, as a dialog.
 *
 * Everything here is about how the entry form BEHAVES — the text it shows, how
 * wide the control is, whether it can be left blank, when it appears at all.
 * None of it changes what the field stores, which is why none of it is on the
 * Schema tab. That tab answers "what is an entry made of"; this one answers
 * "what does filling one in look like".
 *
 * Edits are held here until Done. Writing them straight through meant the card
 * behind the dialog jumped to a new width the instant you touched the control,
 * with no way back — a dialog with a confirm button should not have already
 * happened by the time you press it.
 *
 * @param {Object} props
 * @return {JSX.Element} The dialog.
 */
function FieldDialog({ field, siblings, onClose, onSave }) {
  const [draft, setDraft] = useState(field)

  const dirty = JSON.stringify(draft) !== JSON.stringify(field)
  const takesPlaceholder = PLACEHOLDER_TYPES.includes(draft.type)

  /**
   * Merges changes into the local copy, config included.
   *
   * @param {Object} changes
   * @return {void}
   */
  const update = (changes) =>
    setDraft((current) => ({
      ...current,
      ...changes,
      config: { ...current.config, ...changes.config }
    }))

  return (
    <Dialog
      open
      size="md"
      onOpenChange={(next) => !next && onClose()}
      title={draft.label || __('Field', 'schemapress')}
      description={__('How this field appears on the entry form.', 'schemapress')}
      badge={<Badge variant="outline">{draft.type}</Badge>}
      footer={
        <>
          <Button variant="outline" onClick={onClose}>
            {__('Cancel', 'schemapress')}
          </Button>
          <Button disabled={!dirty} onClick={() => onSave(draft)}>
            <Save />
            {__('Save field', 'schemapress')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <Field
          label={__('Width', 'schemapress')}
          help={__('How much of the row the control takes.', 'schemapress')}
        >
          {(id) => (
            <Segmented
              id={id}
              stretch
              value={widthOf(draft)}
              onChange={(width) => update({ config: { width } })}
              options={WIDTHS.map((option) => ({ value: option.value, label: option.label }))}
            />
          )}
        </Field>

        {takesPlaceholder ? (
          <Field
            label={__('Placeholder', 'schemapress')}
            hint={__('Optional', 'schemapress')}
            help={__('Greyed-out text inside the empty control.', 'schemapress')}
          >
            {(id) => (
              <Input
                id={id}
                value={draft.config?.placeholder || ''}
                onChange={(event) => update({ config: { placeholder: event.target.value } })}
              />
            )}
          </Field>
        ) : null}

        <Field
          label={__('Help text', 'schemapress')}
          hint={__('Optional', 'schemapress')}
          help={__('Shown under the control.', 'schemapress')}
        >
          {(id) => (
            <Input
              id={id}
              value={draft.help || ''}
              onChange={(event) => update({ help: event.target.value })}
            />
          )}
        </Field>

        <Switch
          label={__('Start a new row', 'schemapress')}
          help={__(
            'Keeps this field at the start of its own row instead of filling the space left over above it.',
            'schemapress'
          )}
          checked={startsRow(draft)}
          onChange={(next) => update({ config: { new_row: next } })}
        />

        <div className="flex flex-col gap-3 rounded-md border border-border bg-muted/30 p-3">
          <Switch
            label={__('Required', 'schemapress')}
            help={__('An entry cannot be saved without it.', 'schemapress')}
            checked={Boolean(draft.required)}
            onChange={(required) => update({ required })}
          />

          <ConditionSettings
            field={draft}
            siblings={siblings}
            onChange={(condition) => update({ config: { condition } })}
          />
        </div>
      </div>
    </Dialog>
  )
}

/**
 * When a field appears on the entry form.
 *
 * The rule reads as a sentence — "show when Contactable is filled in" — so it
 * is laid out as one: the lead-in is a label over the row rather than a word
 * wedged beside the first select, which left the two controls sitting at
 * different heights with nothing lining up.
 *
 * @param {Object} props
 * @return {JSX.Element|null} The settings, or null when nothing could gate it.
 */
function ConditionSettings({ field, siblings, onChange }) {
  const condition = field.config?.condition || { field: '', operator: 'filled', value: '' }
  const targets = conditionTargets(siblings, field.key)
  const on = Boolean(condition.field)

  const needsValue = ['equals', 'not_equals'].includes(condition.operator)

  if (targets.length === 0) {
    return null
  }

  return (
    <div className="flex flex-col gap-3 border-t border-border/70 pt-3">
      <Switch
        label={__('Only show this field sometimes', 'schemapress')}
        help={__(
          'A hidden field keeps whatever was already in it, and still delivers it.',
          'schemapress'
        )}
        checked={on}
        onChange={(next) =>
          onChange(
            next
              ? { field: targets[0].key, operator: 'filled', value: '' }
              : { field: '', operator: 'filled', value: '' }
          )
        }
      />

      {on ? (
        <div className="flex flex-col gap-1.5 rounded-md border border-border bg-background p-2.5">
          <Badge variant="outline" className="w-fit uppercase tracking-wide">
            {__('Show when', 'schemapress')}
          </Badge>

          {/* the field being tested is the part you read, so it gets two
              thirds; the comparison is a short closed list and gets one */}
          <div className="grid grid-cols-3 gap-2">
            <Select
              className="col-span-2"
              aria-label={__('Field', 'schemapress')}
              value={condition.field}
              options={targets.map((target) => ({
                value: target.key,
                label: target.label || target.key
              }))}
              onChange={(next) => onChange({ ...condition, field: next })}
            />

            <Select
              aria-label={__('Is', 'schemapress')}
              value={condition.operator}
              options={[
                { value: 'filled', label: __('filled in', 'schemapress') },
                { value: 'empty', label: __('empty', 'schemapress') },
                { value: 'equals', label: __('exactly', 'schemapress') },
                { value: 'not_equals', label: __('anything but', 'schemapress') }
              ]}
              onChange={(next) => onChange({ ...condition, operator: next })}
            />
          </div>

          {needsValue ? (
            <Input
              aria-label={__('Value', 'schemapress')}
              className="mt-0.5"
              placeholder={__('the value to compare against', 'schemapress')}
              value={condition.value || ''}
              onChange={(event) => onChange({ ...condition, value: event.target.value })}
            />
          ) : null}
        </div>
      ) : null}
    </div>
  )
}
