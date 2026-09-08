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
 * The dragging is done with pointer events rather than HTML5 drag-and-drop.
 * That API hands the browser a drag session of its own, and this screen is the
 * worst case for it: the list rearranges live, so the node being dragged and
 * the targets around it are moved, mounted and unmounted throughout. Chrome
 * came out of that with a session it never closed — the layout could be
 * rearranged exactly once, and every press afterwards was swallowed with
 * nothing in the console to say so. A pointer gesture has no such session:
 * a press, some movement, a release, all of it ours.
 *
 * Nothing here reaches the front end. It is presentation of the admin screen,
 * which is this plugin's own to arrange.
 */

import { Fragment, useEffect, useRef, useState } from '@wordpress/element'
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
  cn,
} from '../../ui'
import { move } from '../../shared/utils'
import { conditionTargets } from '../../shared/conditions'
// the canvas must break its rows exactly the way the entry form does, or it is
// a picture of a layout rather than the layout
import { breakBefore, rowBreakClass, startsRow } from '../../shared/layout'

/** The types whose control takes a placeholder, mirroring SchemaModel. */
const PLACEHOLDER_TYPES = ['text', 'textarea', 'email', 'url', 'phone', 'number']

/** The widths a control may take, in twelfths. */
const WIDTHS = [
  { value: 'third', span: 4, label: __('⅓', 'schemapress') },
  { value: 'half', span: 6, label: __('½', 'schemapress') },
  { value: 'two-thirds', span: 8, label: __('⅔', 'schemapress') },
  { value: 'full', span: 12, label: __('Full', 'schemapress') },
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
  12: 'sm:col-span-12',
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
  9: 'sm:col-start-9',
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
 * Which is why the field being dragged is passed in: a boundary is only worth
 * offering when dropping on it would move something. A field already sitting at
 * the start of its own row is in exactly the state the strip promises, so the
 * two strips it lies between are no-ops — and being full width they are the
 * likeliest thing under the pointer the instant a drag begins, which made such
 * a card look like one that could not be dragged at all.
 *
 * @param {Array}  fields
 * @param {number} dragging Index of the field being dragged, or -1.
 * @return {Array} Cells, in order.
 */
function pack(fields, dragging = -1) {
  const cells = []
  let used = 0
  let row = []
  // whether the dragged field already begins a row, flush to the left edge
  let settled = false

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
      // `lead` marks it as this field's own leading space rather than what a
      // row had left over: it is part of the layout and has to be drawn even
      // when nothing is being dragged
      cells.push({ gap: offset, at: index, start: used, row, lead: true })
    }

    // read before the field is accounted for, while `used` is still the column
    // it starts in
    if (index === dragging) {
      settled = used === 0 && offset === 0
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

  if (!settled) {
    return cells
  }

  // the boundary just above the dragged field and the one just below it both
  // land it back where it started
  return cells.filter((cell) => !cell.newRow || (cell.at !== dragging && cell.at !== dragging + 1))
}

/**
 * Where every field currently sits: which row, and which column it begins in.
 *
 * The same walk `pack` does, reporting positions instead of drop targets.
 *
 * @param {Array} fields
 * @return {Array<{row: number, start: number}>}
 */
function positions(fields) {
  const at = []
  let row = 0
  let used = 0

  fields.forEach((field) => {
    const offset = offsetOf(field)
    const span = spanOf(field)

    if (used > 0 && (startsRow(field) || used + offset + span > 12)) {
      row += 1
      used = 0
    }

    at.push({ row: row, start: used + offset })
    used += offset + span
  })

  return at
}

/**
 * Writes the arrangement that is on screen into the fields themselves.
 *
 * A width is stored; a position was not. Everything else about the layout was
 * inferred from widths at render time, which is why changing one field moved
 * the others — the grid simply re-packed, and there was nothing recorded to say
 * that Full Name had been put where it was on purpose.
 *
 * So before a width changes, every field is pinned to the column and row it is
 * already in. The field being resized changes; the rest stay where they were
 * put, and a gap opens where the width was given back rather than the next
 * field sliding into it.
 *
 * @param {Array} fields    the fields as they will be, with the new width
 * @param {Array} reference the fields as they are, whose layout to keep
 * @return {Array} The fields, with row and offset written in.
 */
function pin(fields, reference) {
  const target = positions(reference)

  let row = -1
  let used = 0

  return fields.map((field, index) => {
    const want = target[index]

    if (!want) {
      return field
    }

    const span = spanOf(field)
    const breaks = want.row !== row

    if (breaks) {
      row = want.row
      used = 0
    }

    // as far along the row as it used to be, as far as the new width allows
    const offset = Math.max(0, Math.min(want.start - used, 12 - span))

    used = used + offset + span

    return {
      ...field,
      config: {
        ...field.config,
        // the first field cannot begin a row: there is none above it to end
        new_row: index > 0 && breaks,
        offset,
      },
    }
  })
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
  const [over, setOver] = useState(-1)
  const [editing, setEditing] = useState(-1)

  // the gesture is followed from listeners bound once to the window, so what
  // they need is held in refs rather than closed over from a render that will
  // have been replaced several times before the pointer comes back up
  const press = useRef(null)
  const draggingRef = useRef(-1)
  const overRef = useRef(-1)
  const cellsRef = useRef([])

  useEffect(() => {
    setDraft(fields)
  }, [fields])

  const dirty = JSON.stringify(draft) !== JSON.stringify(fields)

  const cells = pack(draft, dragging)

  // what the listeners hit-test against: the cells as the DOM currently has
  // them, looked up by the index stamped on each target
  cellsRef.current = cells

  /**
   * Sets the field being dragged, keeping the ref the listeners read in step.
   *
   * @param {number} value
   * @return {void}
   */
  const setDrag = (value) => {
    draggingRef.current = value
    setDragging(value)
  }

  /**
   * Sets which drop target the pointer is inside, if any.
   *
   * @param {number} value Index into the packed cells, or -1.
   * @return {void}
   */
  const setHover = (value) => {
    if (overRef.current === value) {
      return
    }

    overRef.current = value
    setOver(value)
  }

  /**
   * Sets one field's width.
   *
   * @param {number} index
   * @param {string} width
   * @return {void}
   */
  /**
   * Marks one field required, or not.
   *
   * @param {number}  index
   * @param {boolean} required
   * @return {void}
   */
  const setRequired = (index, required) =>
    setDraft((current) => current.map((field, i) => (i === index ? { ...field, required } : field)))

  const setWidth = (index, width) =>
    setDraft((current) =>
      pin(
        current.map((field, i) =>
          i === index ? { ...field, config: { ...field.config, width } } : field,
        ),
        current,
      ),
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
  const dragOver = (onto) => {
    const from = draggingRef.current

    if (from === -1 || from === onto) {
      return
    }

    setDraft((current) => move(current, from, onto))
    setDrag(onto)
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
    const from = draggingRef.current

    setDraft((current) => {
      const marked = current.map((field, i) =>
        i === from ? { ...field, config: { ...field.config, offset: 0, new_row: true } } : field,
      )

      // moving to a later index counts the field being moved, so the boundary
      // it was dropped on has already shifted up by one
      const to = cell.at > from ? cell.at - 1 : cell.at

      // only when the field actually came from elsewhere. dropping on the
      // boundary it already sits against moves nothing, and pinning a
      // neighbour there would rearrange a row nobody touched
      if (to === from) {
        return marked
      }

      return move(marked, from, to).map((field, i) =>
        i === to + 1 ? { ...field, config: { ...field.config, new_row: true } } : field,
      )
    })

    setDrag(-1)
  }

  /**
   * Drops the dragged field into a row's leftover space, sizing it to fill.
   *
   * @param {Object} cell
   * @return {void}
   */
  const dropInGap = (cell) => {
    const from = draggingRef.current

    if (from === -1) {
      return
    }

    if (cell.newRow) {
      dropInNewRow(cell)

      return
    }

    const width = fits(cell.gap)

    if (!width) {
      setDrag(-1)

      return
    }

    setDraft((current) => {
      // the field lands where the gap is, not merely after whatever preceded
      // it. usually the row's other fields push it there on their own; but if
      // the only thing before the gap was the field being moved, it leaves as
      // it arrives, and the space it should sit in has to be stated
      const before = cell.row
        .filter((index) => index !== from)
        .reduce((sum, index) => sum + spanOf(current[index]) + offsetOf(current[index]), 0)

      const sized = current.map((field, i) =>
        i === from
          ? {
              ...field,
              config: {
                ...field.config,
                width: width.value,
                offset: Math.max(0, cell.start - before),
                // it is joining a row, so it is no longer starting one
                new_row: false,
              },
            }
          : field,
      )

      // moving to a later index counts the field being moved, so the gap it
      // was dropped into has already shifted left by one
      return move(sized, from, cell.at > from ? cell.at - 1 : cell.at)
    })

    setDrag(-1)
  }

  /**
   * Begins a press on a card. Not yet a drag — a press that never travels is
   * a click, and clicking a card is how its settings open.
   *
   * @param {number} index
   * @param {Object} event
   * @return {void}
   */
  const beginPress = (index, event) => {
    // the width badge is a control of its own, and pressing it is not a grab
    if (event.button !== 0 || (event.target.closest && event.target.closest('button'))) {
      return
    }

    // a press that is about to become a drag should not also start selecting
    // the text it passes over
    event.preventDefault()

    press.current = {
      index,
      x: event.clientX,
      y: event.clientY,
      moved: false,
      // what to put back if the gesture is abandoned, since the rearranging
      // happens live rather than on release
      order: draft,
    }
  }

  /**
   * The rest of the gesture, followed on the window.
   *
   * On the window rather than on the cards because the pointer does not stay
   * over the card it started on — that is the entire point — and because a
   * release outside the grid has to end the drag as reliably as one inside it.
   *
   * Bound once. Everything these read is a ref or a functional setter, so the
   * first render's copies behave the same as any later one.
   */
  useEffect(() => {
    /**
     * Promotes a press into a drag once it has travelled far enough to mean
     * it, then keeps the layout rearranged under the pointer.
     *
     * @param {Object} event
     * @return {void}
     */
    const onMove = (event) => {
      const gesture = press.current

      if (!gesture) {
        return
      }

      if (!gesture.moved) {
        const travelled = Math.abs(event.clientX - gesture.x) + Math.abs(event.clientY - gesture.y)

        if (travelled < 4) {
          return
        }

        gesture.moved = true
        setDrag(gesture.index)
      }

      // hit-testing the document rather than tracking enter and leave on every
      // target: the targets appear, move and vanish as the layout rearranges,
      // and a target that unmounts under the pointer never sends its leave
      const under = document.elementFromPoint(event.clientX, event.clientY)
      const card = under && under.closest('[data-sp-card]')

      if (card) {
        setHover(-1)
        dragOver(Number(card.dataset.spCard))

        return
      }

      const gap = under && under.closest('[data-sp-gap]')

      setHover(gap ? Number(gap.dataset.spGap) : -1)
    }

    /**
     * Ends the gesture: a drop into whatever is under the pointer, or — if it
     * never travelled — the click that opens the card.
     *
     * @return {void}
     */
    const onUp = () => {
      const gesture = press.current

      press.current = null

      if (!gesture) {
        return
      }

      if (!gesture.moved) {
        setEditing(gesture.index)

        return
      }

      const cell = overRef.current === -1 ? null : cellsRef.current[overRef.current]

      setHover(-1)

      // released over a card, or over nothing: the passing-over already put it
      // where it is, so there is nothing left to apply
      if (cell && cell.gap) {
        dropInGap(cell)
      } else {
        setDrag(-1)
      }
    }

    /**
     * Abandons the gesture, putting the order back as it was.
     *
     * @param {Object} event
     * @return {void}
     */
    const onKey = (event) => {
      const gesture = press.current

      if (event.key !== 'Escape' || !gesture) {
        return
      }

      press.current = null
      setDraft(gesture.order)
      setHover(-1)
      setDrag(-1)
    }

    window.addEventListener('pointermove', onMove)
    window.addEventListener('pointerup', onUp)
    window.addEventListener('pointercancel', onUp)
    window.addEventListener('keydown', onKey)

    return () => {
      window.removeEventListener('pointermove', onMove)
      window.removeEventListener('pointerup', onUp)
      window.removeEventListener('pointercancel', onUp)
      window.removeEventListener('keydown', onKey)
    }
  }, [])

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
          'schemapress',
        )}
      </Alert>

      <Card>
        <CardBody>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-12">
            {cells.map((cell, at) =>
              cell.field ? (
                // keyed by field, not by position. the list reorders under a
                // pointer that is still holding one of these cards, and it is
                // the card that has to travel with it — key by position and the
                // node under the pointer is suddenly a different field, which
                // hovering then swaps straight back
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
                    onPointerDown={(event) => beginPress(cell.index, event)}
                    onWidth={(width) => setWidth(cell.index, width)}
                    onRequired={(required) => setRequired(cell.index, required)}
                  />
                </Fragment>
              ) : cell.lead && dragging === -1 ? (
                <div
                  key={`lead-${cell.at}-${cell.start}`}
                  aria-hidden="true"
                  className={cn('hidden sm:block', SPANS[cell.gap])}
                />
              ) : (
                <Gap
                  key={`gap-${cell.at}-${cell.start}-${cell.gap}${cell.newRow ? '-new' : ''}`}
                  at={at}
                  span={cell.gap}
                  start={cell.start}
                  newRow={cell.newRow}
                  dragging={dragging !== -1}
                  over={over === at}
                />
              ),
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
 * Which boundaries exist at all is `pack`'s decision, and it offers only the
 * ones that would move the field: a strip saying New row that leaves the field
 * exactly where it was is worse than no strip, because it is full width and
 * therefore the easiest thing on the screen to drop on by accident.
 *
 * @param {Object} props
 * @return {JSX.Element|null} The target.
 */
function Gap({ at, span, start, newRow, dragging, over }) {
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
        data-sp-gap={at}
        className={cn(
          'flex min-h-[2.5rem] items-center justify-center rounded-lg border-2 border-dashed text-[12px] font-medium transition-colors sm:col-span-12',
          over
            ? 'border-primary bg-primary/10 text-primary'
            : 'border-ring/25 bg-accent/10 text-muted-foreground/80',
        )}
      >
        <span>{__('New row', 'schemapress')}</span>
      </div>
    )
  }

  return (
    <div
      data-sp-gap={at}
      className={cn(
        'flex min-h-[5rem] items-center justify-center gap-1.5 rounded-lg border-2 border-dashed text-[12px] font-medium transition-colors',
        SPANS[span],
        start > 0 && STARTS[start + 1],
        over
          ? 'border-primary bg-primary/10 text-primary'
          : 'border-ring/40 bg-accent/20 text-muted-foreground',
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
function FieldCard({ field, index, dragging, onPointerDown, onWidth, onRequired }) {
  const [sizing, setSizing] = useState(false)

  const width = widthOf(field)
  const option = WIDTHS.find((candidate) => candidate.value === width)
  const offset = offsetOf(field)

  return (
    <div
      // the whole card both drags and opens the settings: a press that travels
      // is a grab, one that does not is a click. which is decided on release,
      // by the window listener, so there is no small target to find here
      data-sp-card={index}
      onPointerDown={onPointerDown}
      className={cn(
        // min-w-0 so a long label can never push the card wider than its
        // column and over the top of its neighbour; select-none so pressing on
        // the label starts the drag rather than a text selection
        'group relative flex min-w-0 cursor-grab select-none flex-col overflow-hidden rounded-lg bg-background p-3 shadow-sm transition-colors',
        SPANS[option.span],
        dragging
          ? // the card being dragged reads as the gap it left behind, so the
            // destination is a shape on screen rather than a guess. thicker
            // than a resting card on purpose: it is a target now, not content
            'cursor-grabbing items-center justify-center border-2 border-dashed border-ring/60 bg-accent/40'
          : 'border border-border hover:border-primary/40',
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

        <Badge variant="outline" className="min-w-0 truncate">
          {field.type}
        </Badge>

        {/* required is the other setting worth a click rather than a dialog,
            and it is the one you want to SEE without opening anything — an
            asterisk that is on or off says the state of every field on the
            screen at once, which a switch buried in a dialog never can */}
        <button
          type="button"
          aria-pressed={Boolean(field.required)}
          aria-label={sprintf(
            /* translators: %s: the field's label */
            __('Required: %s', 'schemapress'),
            field.label,
          )}
          title={
            field.required
              ? __('Required — click to make optional', 'schemapress')
              : __('Optional — click to make required', 'schemapress')
          }
          onClick={(event) => {
            event.stopPropagation()
            onRequired(!field.required)
          }}
          className={cn(
            'flex size-5 shrink-0 items-center justify-center rounded border text-[13px] font-semibold leading-none transition-colors',
            field.required
              ? 'border-destructive/40 bg-destructive/10 text-destructive'
              : 'border-border text-muted-foreground/50 hover:border-primary/50 hover:text-foreground',
          )}
        >
          <span aria-hidden="true">*</span>
        </button>

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
                field.label,
              )}
              className="flex h-5 min-w-[1.75rem] shrink-0 items-center justify-center rounded border border-border px-1 text-[11px] font-medium text-muted-foreground transition-colors hover:border-primary/50 hover:text-foreground"
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
                label: candidate.label,
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
          'relative flex h-9 w-full items-center rounded-md border border-input bg-input-fill px-2.5 text-[12px] text-muted-foreground transition-colors group-hover:border-primary/40',
          dragging && 'hidden',
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
      config: { ...current.config, ...changes.config },
    }))

  return (
    <Dialog
      open
      size="md"
      onOpenChange={(next) => !next && onClose()}
      title={draft.label || __('Field', 'schemapress')}
      description={__(
        'What this field asks for. Where it sits is set by dragging it on the canvas.',
        'schemapress',
      )}
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
          'schemapress',
        )}
        checked={on}
        onChange={(next) =>
          onChange(
            next
              ? { field: targets[0].key, operator: 'filled', value: '' }
              : { field: '', operator: 'filled', value: '' },
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
                label: target.label || target.key,
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
                { value: 'not_equals', label: __('anything but', 'schemapress') },
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
