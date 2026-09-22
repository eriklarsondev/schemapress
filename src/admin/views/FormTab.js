/**
 * Arranging the entry form.
 *
 * A canvas, not a settings table: each field is a card on the same twelve columns
 * the entry form uses, so "half width, third from the top" is something you see
 * rather than read off a row of dropdowns. This tab owns how the form behaves;
 * the Schema tab owns what the data is. Nothing here changes a stored value.
 *
 *   order   drag a card onto another and they swap places
 *   width   the badge on the card, or drop into a row's leftover space — which
 *           offers every width that fits it, and takes the one the pointer has
 *           reached across it
 *   rows    drop onto the strip between two rows to start a row of its own. A
 *           grid packs its items together, so where a row ENDS is the one thing
 *           widths cannot say
 *   column  a collection's form has a sidebar beside it, under the entry's
 *           status: drag a card into it, or move it from the width badge
 *   rest    click the card — placeholder, help text, required, when it shows
 *
 * Drop targets appear only while dragging, and only the one nearest the pointer
 * is drawn: lit up all at once they bury the layout under the scaffolding for
 * changing it. Every target is still mounted and reachable — see `probable`.
 *
 * Pointer events rather than HTML5 drag-and-drop. That API hands the browser a
 * drag session of its own, and this screen rearranges live — so the node being
 * dragged and the targets around it are moved, mounted and unmounted throughout.
 * Chrome came out of that with a session it never closed: the layout could be
 * rearranged exactly once, and every press afterwards was swallowed silently.
 */

import { Fragment, useEffect, useRef, useState } from '@wordpress/element'
import { __, sprintf } from '@wordpress/i18n'
import { Save, LayoutList, Pencil, PanelRight, CircleDot } from 'lucide-react'
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
import {
  breakBefore,
  columnsClass,
  regionOf,
  rowBreakClass,
  sidebarClass,
  startsRow,
  widthOf as drawnWidth,
} from '../../shared/layout'

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
 * Through the shared layout helper, so this tab and the entry form agree about a
 * field nobody has sized yet.
 *
 * @param {Object} field
 * @return {string} The width token.
 */
function widthOf(field) {
  const width = drawnWidth(field)

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
 * The width a field dropped into a gap comes out at, from how far across the gap
 * the pointer has gone.
 *
 * Every width that fits is on offer, not only the widest: filling a row's spare
 * space was the one move that always resized the field, and always to as big as
 * it could be. Now the field stretches from the start of the gap to the pointer
 * — whichever width ends nearest to it — so moving across the space runs through
 * the sizes, and one that ends exactly where you are is the one you get. Level
 * between two, the field keeps the width it already has.
 *
 * @param {number} gap     How many columns are free.
 * @param {number} reach   How far into them the pointer is, in columns.
 * @param {string} current The dragged field's own width.
 * @return {Object|null} The width option, or null if nothing fits.
 */
function sizeFor(gap, reach, current) {
  const options = WIDTHS.filter((option) => option.span <= gap)

  if (options.length === 0) {
    return null
  }

  return options.reduce((best, option) => {
    const distance = Math.abs(option.span - reach)
    const leader = Math.abs(best.span - reach)

    return distance < leader || (distance === leader && option.value === current) ? option : best
  })
}

/**
 * The canvas grid's gutter, in pixels — `gap-3`. Drawing part of a gap at a
 * width means counting the gutters it spans as well as its columns.
 */
const GUTTER = 12

/**
 * How wide `span` columns are inside a box `of` columns wide, gutters included.
 * A plain percentage of the box comes out short by a gutter per column, and the
 * preview would not line up with the card it is promising.
 *
 * @param {number} span
 * @param {number} of
 * @return {string} A CSS width.
 */
function share(span, of) {
  return `calc((100% - ${(of - 1) * GUTTER}px) * ${span / of} + ${(span - 1) * GUTTER}px)`
}

/**
 * Packs fields into rows of twelve, noting each row's leftover space and where
 * one row gives way to the next — both of which are drop targets.
 *
 * Every target sizes what is dropped on it by how far across it the pointer goes
 * (see `sizeFor`): a leftover offers the widths that fit it, and a row boundary —
 * a whole empty row — offers all of them.
 *
 * The field being dragged is passed in because a boundary is only worth offering
 * when dropping on it would move something: a field already at the start of its
 * own row is already in the state the strip promises, and being full width those
 * strips are the likeliest thing under the pointer when a drag begins.
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
      // `own` when the field being dragged is the last on the row: the space is
      // then straight after it, and moving into it widens the field where it
      // stands rather than moving it along
      cells.push({ gap: 12 - used, at, start: used, row, own: row[row.length - 1] === dragging })
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
 * Which column a field is drawn in on this canvas. A form with no sidebar — a
 * component's, which is drawn inside a group wherever it is used — has only the
 * one, whatever a field says.
 *
 * @param {Object}  field
 * @param {boolean} sidebar Whether this form has a sidebar.
 * @return {string} main or sidebar.
 */
function columnOf(field, sidebar) {
  return sidebar ? regionOf(field) : 'main'
}

/**
 * A field moved into a column. The space in front of it and the row it began
 * described a place in the column it has left, so they go. Its width stays: a
 * field taken to the sidebar and brought back comes back the size it was.
 *
 * @param {Object} field
 * @param {string} region main or sidebar
 * @return {Object} The field.
 */
function into(field, region) {
  return { ...field, config: { ...field.config, offset: 0, new_row: false, region } }
}

/**
 * Applies a change to the main column's fields alone, leaving the sidebar's
 * where they are. `pin` walks a grid, and a sidebar field in the middle of the
 * list would be counted as taking up room on a row it is not on.
 *
 * @param {Array}    fields
 * @param {boolean}  sidebar
 * @param {Function} change Given the main column's fields, returns them changed.
 * @return {Array} The whole list.
 */
function inMain(fields, sidebar, change) {
  const main = (field) => columnOf(field, sidebar) === 'main'
  const changed = change(fields.filter(main))
  let next = 0

  return fields.map((field) => (main(field) ? changed[next++] : field))
}

/**
 * One field at a new width, with every other field held where it was — see
 * `pin`. The same whether the width came from the badge or from resizing the
 * field in place while holding it.
 *
 * @param {Array}   fields
 * @param {number}  index
 * @param {string}  width
 * @param {boolean} sidebar
 * @return {Array} The fields.
 */
function resized(fields, index, width, sidebar) {
  return inMain(
    fields.map((field, i) =>
      i === index ? { ...field, config: { ...field.config, width } } : field
    ),
    sidebar,
    (main) =>
      pin(
        main,
        fields.filter((field) => columnOf(field, sidebar) === 'main')
      )
  )
}

/**
 * What the pointer is aiming at when it is over the dragged card itself: its
 * own place, where moving across resizes it without moving it. Not a cell, so
 * a value no cell index can be.
 */
const OWN = -2

/**
 * Every cell on the canvas: the main column's, then the sidebar's.
 *
 * One list, because a drop target is found by its place in it (`data-sp-gap`)
 * and a field can be dragged from either column into the other. `pack` lays out
 * the main column on its own, so the positions it reports are translated back
 * into places in the whole list — the sidebar's fields sit in the same list,
 * wherever they were put, and a move has to count them.
 *
 * The sidebar is one column of whole-width cards, so there is nothing to pack.
 * Passing over a card already swaps with it; what the sidebar adds is somewhere
 * to drop at either end, and the empty sidebar as a target of its own.
 *
 * @param {Array}   fields
 * @param {number}  dragging Index of the field being dragged, or -1.
 * @param {boolean} sidebar  Whether this form has a sidebar.
 * @return {Array} Cells, in order, each saying which column it is in.
 */
function arrange(fields, dragging, sidebar) {
  const main = []
  const side = []

  fields.forEach((field, index) => {
    ;(columnOf(field, sidebar) === 'sidebar' ? side : main).push(index)
  })

  // a place in the main column as a place in the whole list: in front of the
  // main field that is there now, or just after the last one
  const place = (at) => {
    if (at < main.length) {
      return main[at]
    }

    return main.length > 0 ? main[main.length - 1] + 1 : 0
  }

  const cells = pack(
    main.map((index) => fields[index]),
    main.indexOf(dragging)
  ).map((cell) =>
    cell.field
      ? // `local` is its place among the main column's fields, which is what
        // decides whether it can begin a row: the first of them cannot
        { ...cell, index: main[cell.index], local: cell.index, region: 'main' }
      : { ...cell, at: place(cell.at), row: cell.row.map((index) => main[index]), region: 'main' }
  )

  if (!sidebar) {
    return cells
  }

  // an empty sidebar leaves the field where it is in the list: there is
  // nothing there yet for it to go in front of or after
  if (side.length === 0) {
    return [...cells, { gap: 12, at: dragging, region: 'sidebar', edge: 'empty' }]
  }

  const first = side[0]
  const last = side[side.length - 1]

  return [
    ...cells,
    // an end is only offered when dropping there would move something. the
    // field already at the top is where the top would put it
    ...(dragging === first ? [] : [{ gap: 12, at: first, region: 'sidebar', edge: 'start' }]),
    ...side.map((index) => ({ field: fields[index], index, region: 'sidebar' })),
    ...(dragging === last ? [] : [{ gap: 12, at: last + 1, region: 'sidebar', edge: 'end' }]),
  ]
}

/**
 * How far the pointer may be from a target and still be taken to mean it — about
 * a row's height. Beyond it there is no answer, so dragging out into the page and
 * letting go drops nothing rather than picking the last strip by default.
 */
const REACH = 96

/**
 * The one target a drag most likely means. All of them stay mounted and hittable;
 * only the likeliest is drawn.
 *
 * Nearest wins, measured to the rectangle rather than its middle, so a wide strip
 * is not beaten by a small gap that happens to be centred closer. A pointer
 * inside a target is at distance zero and wins outright.
 *
 * Measured rather than calculated because they are on screen already: the DOM
 * knows where the grid put them, and calculating would be a second implementation
 * of the same twelve columns, free to disagree with the first.
 *
 * Inside one column, only that column's targets count. The nearest strip in the
 * other can be closer as the crow flies — a gutter away — and it is never what
 * somebody dragging into a column is aiming at.
 *
 * @param {Element|null} canvas
 * @param {number}       x
 * @param {number}       y
 * @param {Element|null} under What the pointer is over.
 * @return {number} The target's index into the packed cells, or -1.
 */
function probable(canvas, x, y, under) {
  if (!canvas) {
    return -1
  }

  const column = under && under.closest('[data-sp-region]')
  const scope = column && canvas.contains(column) ? column : canvas

  let best = -1
  let nearest = Infinity

  scope.querySelectorAll('[data-sp-gap]').forEach((node) => {
    const rect = node.getBoundingClientRect()
    // zero on both axes when the pointer is inside
    const dx = Math.max(rect.left - x, 0, x - rect.right)
    const dy = Math.max(rect.top - y, 0, y - rect.bottom)
    const distance = Math.sqrt(dx * dx + dy * dy)

    if (distance < nearest) {
      nearest = distance
      best = Number(node.dataset.spGap)
    }
  })

  return nearest <= REACH ? best : -1
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
 * Writes the arrangement on screen into the fields themselves.
 *
 * Position is otherwise inferred from widths at render time, so changing one
 * field re-packs the grid and moves the others. Pinning every field to the column
 * and row it is already in before a width changes means the rest stay put, and a
 * gap opens where the width was given back.
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
 * A collection's form is drawn as its entry screen is — the form, and the
 * sidebar beside it with the cards every entry has there, as silhouettes — so a
 * field can be dragged across. A component's has only the form: it is drawn
 * inside a group wherever it is used, and a group has no sidebar.
 *
 * @param {Object}   props
 * @param {Array}    props.fields
 * @param {Function} props.onChange
 * @param {boolean}  props.sidebar Whether this form has a sidebar.
 * @param {boolean}  props.drafts  Whether its entries have a status card.
 * @return {JSX.Element} The tab.
 */
export function FormTab({ fields, onChange, sidebar = false, drafts = true }) {
  const [draft, setDraft] = useState(fields)
  const [saving, setSaving] = useState(false)
  const [dragging, setDragging] = useState(-1)
  const [over, setOver] = useState(-1)
  // how many columns the field would take where it is being aimed — a gap, a
  // new row, or its own place — read from the pointer; 0 when that sizes nothing
  const [fill, setFillState] = useState(0)
  const [editing, setEditing] = useState(-1)

  // the gesture is followed from listeners bound once to the window, so what
  // they need is held in refs rather than closed over from a render that will
  // have been replaced several times before the pointer comes back up
  const press = useRef(null)
  const draggingRef = useRef(-1)
  const overRef = useRef(-1)
  const fillRef = useRef(0)
  const cellsRef = useRef([])
  const draftRef = useRef(draft)
  const sidebarRef = useRef(sidebar)
  // the canvas itself, so measuring the targets cannot stray into another one —
  // the same tab is rendered for a collection and for a component
  const canvas = useRef(null)

  useEffect(() => {
    setDraft(fields)
  }, [fields])

  const dirty = JSON.stringify(draft) !== JSON.stringify(fields)

  const cells = arrange(draft, dragging, sidebar)

  // what the listeners hit-test against: the cells as the DOM currently has
  // them, looked up by the index stamped on each target
  cellsRef.current = cells
  draftRef.current = draft
  sidebarRef.current = sidebar

  // an empty main column is a state of its own: every field has gone to the
  // sidebar, and an empty card would read as a form that failed to load
  const bare = !cells.some((cell) => cell.field && cell.region === 'main')

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
   * Sets how wide the gap being aimed at would make the field.
   *
   * @param {number} value Columns, or 0.
   * @return {void}
   */
  const setFill = (value) => {
    if (fillRef.current === value) {
      return
    }

    fillRef.current = value
    setFillState(value)
  }

  /**
   * The width the field would take at a target, from where the pointer is.
   *
   * A gap, or a New row strip — which is a whole empty row, twelve columns of
   * it — is measured from the side the row starts on, which is the right in a
   * right-to-left admin: how far across it the pointer has gone is how wide the
   * field comes out. The space straight after the field's own place is part of
   * resizing it where it stands, so it is measured the way that is.
   *
   * @param {number} target Index into the cells.
   * @param {number} x
   * @return {number} Columns, or 0 when the target does not size anything.
   */
  const reachOf = (target, x) => {
    const cell = cellsRef.current[target]
    const node = canvas.current?.querySelector(`[data-sp-gap="${target}"]`)

    if (!cell || !node || cell.region !== 'main') {
      return 0
    }

    if (cell.own) {
      return resizeOf(x)
    }

    const rect = node.getBoundingClientRect()
    const across = getComputedStyle(node).direction === 'rtl' ? rect.right - x : x - rect.left
    const through = Math.min(1, Math.max(0, across / rect.width))
    const option = sizeFor(
      cell.gap,
      through * cell.gap,
      widthOf(draftRef.current[draggingRef.current])
    )

    return option ? option.span : 0
  }

  /**
   * The width a field comes out at when it is resized where it stands, by
   * moving across its own place while holding it.
   *
   * Measured from where the pointer was when the card got there rather than
   * from the card's edge, so the field starts at its own width whatever part of
   * it was grabbed — a wobble on the way to somewhere else changes nothing.
   * Towards the end of the row it widens, into whatever the row has spare
   * straight after it; back towards the start it narrows, down to a third.
   *
   * @param {number} x
   * @return {number} Columns, or 0 when it cannot be resized here.
   */
  const resizeOf = (x) => {
    const gesture = press.current
    const field = draftRef.current[draggingRef.current]
    const grid = canvas.current?.querySelector('[data-sp-grid]')

    if (!gesture || !field || !grid || columnOf(field, sidebarRef.current) !== 'main') {
      return 0
    }

    const rect = grid.getBoundingClientRect()
    // one column and the gutter after it: how far the pointer travels to add one
    const step = (rect.width + GUTTER) / 12
    const moved =
      (getComputedStyle(grid).direction === 'rtl' ? gesture.anchor - x : x - gesture.anchor) / step
    const current = spanOf(field)
    const room = current + (cellsRef.current.find((cell) => cell.own)?.gap || 0)
    const option = sizeFor(room, current + moved, widthOf(field))

    return option ? option.span : 0
  }

  /**
   * Marks one field required, or not.
   *
   * @param {number}  index
   * @param {boolean} required
   * @return {void}
   */
  const setRequired = (index, required) =>
    setDraft((current) => current.map((field, i) => (i === index ? { ...field, required } : field)))

  /**
   * Sets one field's width.
   *
   * @param {number} index
   * @param {string} width
   * @return {void}
   */
  const setWidth = (index, width) => setDraft((current) => resized(current, index, width, sidebar))

  /**
   * Moves one field to the other column from its badge — the way across that
   * needs no pointer. It keeps its place in the list, which is its place among
   * the fields of the column it arrives in.
   *
   * @param {number} index
   * @param {string} region main or sidebar
   * @param {string} width  the width to arrive at, when that is being chosen
   * @return {void}
   */
  const moveTo = (index, region, width) =>
    setDraft((current) =>
      current.map((field, i) => {
        if (i !== index) {
          return field
        }

        const moved = into(field, region)

        return width ? { ...moved, config: { ...moved.config, width } } : moved
      })
    )

  /**
   * Swaps one field for an edited copy and stores the result. The dialog's own
   * confirm saves immediately; drag-and-drop on the canvas still batches behind
   * Save layout, because a drag is exploratory in a way a form is not.
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
   * Reorders as a dragged card passes over another rather than on drop, so what is
   * on screen mid-drag is what you get — including how the rows re-wrap.
   *
   * @param {number} over
   * @return {void}
   */
  const dragOver = (onto) => {
    const from = draggingRef.current

    if (from === -1 || from === onto) {
      return
    }

    setDraft((current) => {
      // passing over a card in the other column takes the field across too
      const region = columnOf(current[onto], sidebarRef.current)
      const crossed =
        columnOf(current[from], sidebarRef.current) === region
          ? current
          : current.map((field, i) => (i === from ? into(field, region) : field))

      return move(crossed, from, onto)
    })
    setDrag(onto)
  }

  /**
   * Drops the dragged field onto a row boundary, giving it a row of its own at the
   * width it already had.
   *
   * The field after it is pinned to a new row too — without that it flows up into
   * whatever the new row has spare, which is the collapse this mechanism exists to
   * stop, one field further along.
   *
   * @param {Object} cell
   * @return {void}
   */
  const dropInNewRow = (cell) => {
    const from = draggingRef.current
    const sided = sidebarRef.current
    // the width the strip was showing: a new row is empty, so every width fits
    // it, full included. with no reading, the field keeps its own
    const width = WIDTHS.find((option) => option.span === fillRef.current)

    setDraft((current) => {
      // from the sidebar, the row is new to the form wherever the field sits
      // in the list — so the one after it has to be held back as well
      const arriving = columnOf(current[from], sided) !== 'main'

      const marked = current.map((field, i) =>
        i === from
          ? {
              ...field,
              config: {
                ...field.config,
                ...(width ? { width: width.value } : {}),
                offset: 0,
                new_row: true,
                region: 'main',
              },
            }
          : field
      )

      // moving to a later index counts the field being moved, so the boundary
      // it was dropped on has already shifted up by one
      const to = cell.at > from ? cell.at - 1 : cell.at

      // only when the field actually came from elsewhere. dropping on the
      // boundary it already sits against moves nothing, and pinning a
      // neighbor there would rearrange a row nobody touched
      if (to === from && !arriving) {
        return marked
      }

      const moved = move(marked, from, to)

      // the next field IN THE FORM, which is not always the next in the list:
      // a sidebar field can sit between the two
      const after = moved.findIndex((field, i) => i > to && columnOf(field, sided) === 'main')

      return moved.map((field, i) =>
        i === after ? { ...field, config: { ...field.config, new_row: true } } : field
      )
    })

    setDrag(-1)
  }

  /**
   * Lets go of the field where it already is, at the width it was resized to
   * there — with everything else held in place, as the badge does it.
   *
   * @return {void}
   */
  const resizeInPlace = () => {
    const from = draggingRef.current
    const width = WIDTHS.find((option) => option.span === fillRef.current)

    if (from !== -1 && width) {
      setDraft((current) => resized(current, from, width.value, sidebarRef.current))
    }

    setDrag(-1)
  }

  /**
   * Drops the dragged field into the sidebar, at whichever end of it was
   * nearest — or into an empty one, where it keeps its place in the list.
   *
   * @param {Object} cell
   * @return {void}
   */
  const dropInSidebar = (cell) => {
    const from = draggingRef.current

    setDraft((current) =>
      move(
        current.map((field, i) => (i === from ? into(field, 'sidebar') : field)),
        from,
        // counting the field being moved, as a drop anywhere else does
        cell.at > from ? cell.at - 1 : cell.at
      )
    )

    setDrag(-1)
  }

  /**
   * Drops the dragged field into a row's leftover space, at the width the
   * target was showing when it was let go.
   *
   * @param {Object} cell
   * @return {void}
   */
  const dropInGap = (cell) => {
    const from = draggingRef.current

    if (from === -1) {
      return
    }

    if (cell.region === 'sidebar') {
      dropInSidebar(cell)

      return
    }

    // the space straight after it: it was being widened, not moved along
    if (cell.own) {
      resizeInPlace()

      return
    }

    if (cell.newRow) {
      dropInNewRow(cell)

      return
    }

    // the width the pointer settled on. with no reading at all — a release
    // without a move over the gap first — the widest that fits, as before
    const width = WIDTHS.find((option) => option.span === fillRef.current) || fits(cell.gap)

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
                // and the row is in the form, wherever it came from
                region: 'main',
              },
            }
          : field
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
      // where resizing in place measures from: here, and wherever the card
      // has most recently been moved to
      anchor: event.clientX,
      moved: false,
      // what to put back if the gesture is abandoned, since the rearranging
      // happens live rather than on release
      order: draft,
    }
  }

  /**
   * The rest of the gesture, followed on the window: the pointer does not stay
   * over the card it started on, and a release outside the grid has to end the
   * drag as reliably as one inside it.
   *
   * Bound once — everything these read is a ref or a functional setter.
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

      // over a card the answer is already on screen: passing over one has
      // swapped the two, so there is nothing to offer and nothing to draw
      if (card) {
        const onto = Number(card.dataset.spCard)

        // except its own: holding a field over its own place and moving
        // across it resizes it there, both ways — the one way to size a field
        // in a row with no space to spare, and a full-width one never has any
        if (onto === draggingRef.current) {
          setHover(OWN)
          setFill(resizeOf(event.clientX))

          return
        }

        setHover(-1)
        setFill(0)
        dragOver(onto)
        // it has arrived somewhere new, and resizing it there starts from
        // its own width again
        gesture.anchor = event.clientX

        return
      }

      // NEAREST, not whatever the pointer is literally inside. a strip between
      // two rows is a dozen pixels tall and nobody lands on one on purpose;
      // asking which target is closest is the same question the person
      // dragging is answering, and it has one answer instead of a dozen
      const target = probable(canvas.current, event.clientX, event.clientY, under)

      setHover(target)
      // read on every move, not once on arrival: moving across the space is
      // how a width is picked, so the preview has to follow the pointer
      setFill(target === -1 ? 0 : reachOf(target, event.clientX))
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

      const own = overRef.current === OWN
      const cell = overRef.current < 0 ? null : cellsRef.current[overRef.current]

      setHover(-1)

      // released over its own place, it keeps the width it was resized to
      // there. over another card, or over nothing: the passing-over already
      // put it where it is, so there is nothing left to apply
      if (own) {
        resizeInPlace()
      } else if (cell && cell.gap) {
        dropInGap(cell)
      } else {
        setDrag(-1)
      }

      // after the drop, which reads it
      setFill(0)
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
      setFill(0)
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

  /**
   * One field's card, in whichever column it is drawn.
   *
   * @param {Object} cell
   * @return {JSX.Element} The card.
   */
  // the field is being resized where it stands, rather than moved: the pointer
  // is on its own place, or in the space straight after it
  const resizing = over === OWN || Boolean(cells[over]?.own)

  const card = (cell) => (
    <FieldCard
      field={cell.field}
      index={cell.index}
      region={cell.region}
      regions={sidebar}
      dragging={dragging === cell.index}
      resize={dragging === cell.index && resizing ? fill : 0}
      onPointerDown={(event) => beginPress(cell.index, event)}
      onWidth={(width) => setWidth(cell.index, width)}
      onRequired={(required) => setRequired(cell.index, required)}
      onRegion={(region, width) => moveTo(cell.index, region, width)}
    />
  )

  // every cell is rendered by its place in the one list, whichever column it
  // is drawn in, because that place is what its target is stamped with
  const main = (
    <Card data-sp-region="main">
      <CardBody>
        {bare ? (
          <p className="py-6 text-center text-[13px] text-muted-foreground">
            {__(
              'Every field is in the sidebar. Drag one back here to put it in the main column.',
              'schemapress'
            )}
          </p>
        ) : null}

        {/* marked so resizing a field in place can measure a column */}
        <div data-sp-grid className="grid grid-cols-1 gap-3 sm:grid-cols-12">
          {cells.map((cell, at) =>
            cell.region !== 'main' ? null : cell.field ? (
              // keyed by field, not by position. the list reorders under a
              // pointer that is still holding one of these cards, and it is the
              // card that has to travel with it — key by position and the node
              // under the pointer is suddenly a different field, which hovering
              // then swaps straight back
              <Fragment key={cell.field.key}>
                {/* the break is what actually holds the row open once the drop
                    targets are gone: without it the card flows straight back up
                    into the space left on the row above. mid-drag the New row
                    strip sitting here is already full width and ends the row on
                    its own, so the break would only add air */}
                {dragging === -1 && breakBefore(cell.field, cell.local) ? (
                  <div aria-hidden="true" className={rowBreakClass()} />
                ) : null}

                {card(cell)}
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
                own={cell.own}
                // what the held field already spans, which is how much of a
                // resize into the space after it that space has to draw
                base={cell.own ? spanOf(draft[dragging]) : 0}
                dragging={dragging !== -1}
                // the space after a field being resized draws its share of the
                // new width whether the pointer is in it or still on the card:
                // the field widens from its first column of growth, not from
                // the moment the pointer happens to cross the gutter
                over={over === at || (cell.own && resizing)}
                fill={over === at || (cell.own && resizing) ? fill : 0}
              />
            )
          )}
        </div>
      </CardBody>
    </Card>
  )

  const sided = cells.some((cell) => cell.region === 'sidebar' && cell.field)

  // the entry screen's sidebar, card for card: the ones every entry has are
  // silhouettes, and the fields put in it go under the status, which is where
  // the entry screen draws them
  const aside = sidebar ? (
    <aside data-sp-region="sidebar" className={sidebarClass()}>
      {drafts ? <StatusSilhouette /> : null}

      {sided ? (
        <Card>
          <CardBody className="relative flex flex-col gap-3">
            {cells.map((cell, at) =>
              cell.region !== 'sidebar' ? null : cell.field ? (
                <Fragment key={cell.field.key}>{card(cell)}</Fragment>
              ) : (
                <SideGap
                  key={`side-${cell.edge}`}
                  at={at}
                  edge={cell.edge}
                  dragging={dragging !== -1}
                  over={over === at}
                />
              )
            )}
          </CardBody>
        </Card>
      ) : (
        cells.map((cell, at) =>
          cell.region === 'sidebar' ? (
            <SideGap
              key="side-empty"
              at={at}
              edge="empty"
              dragging={dragging !== -1}
              over={over === at}
            />
          ) : null
        )
      )}

      <EndpointSilhouette />
      <DetailsSilhouette drafts={drafts} />
      <DangerSilhouette />
    </aside>
  ) : null

  return (
    <div className="flex flex-col gap-3">
      <Alert variant="info">
        {__(
          'Drag a field onto another to reorder, into a row’s spare space, or onto a New row strip to give it a row of its own — the further across you take it, the wider it comes out. Holding it, move across its own place to resize it where it stands.',
          'schemapress'
        )}{' '}
        {sidebar
          ? __('Drag it into the sidebar to sit beside the entry’s status.', 'schemapress') + ' '
          : null}
        {__(
          'Click a card for its placeholder, help text and whether it is required.',
          'schemapress'
        )}
      </Alert>

      <div ref={canvas} className={sidebar ? columnsClass() : undefined}>
        {main}
        {aside}
      </div>

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
 * Visible only while dragging — standing on screen the rest of the time they read
 * as empty boxes in a form rather than as targets.
 *
 * Every legal target stays mounted, which is what makes them measurable and so
 * reachable, but one nobody is aiming at draws nothing and holds its place.
 *
 * The drawn state keeps the same box as the undrawn one, border included, so
 * lighting up moves nothing. A target that resized as the pointer approached
 * would shift the row underneath, moving the target away from the pointer, which
 * un-picks it — and the two states flicker against each other forever.
 *
 * A leftover shows the field it would hold, at the width the pointer has reached
 * across it — see `sizeFor` — inside an outline of the whole space, so it is
 * plain how much more there is to take. The preview is drawn inside the target
 * rather than by resizing it, for the reason above. A boundary makes no such
 * bargain, so its label is drawn out of the flow and a strip a dozen pixels tall
 * can carry a word without becoming a box.
 *
 * @param {Object} props
 * @return {JSX.Element|null} The target.
 */
function Gap({ at, span, start, newRow, own, base, dragging, over, fill }) {
  const width = fits(span)

  // a sliver narrower than a third can hold nothing, so it is not offered — by
  // itself. straight after the field being held it is room for that field to
  // widen into, and any amount of that is worth having. a row boundary holds
  // anything, whatever its width
  if (!dragging || (!newRow && !own && !width)) {
    return null
  }

  // a strip between two rows, not a hole in one: a line rather than a box,
  // because it is a position and not a space. a whole empty row, so every width
  // fits it: the line is lit as far across as the field will reach, over a faint
  // track of the rest, and says so
  if (newRow) {
    const chosen = WIDTHS.find((option) => option.span === fill)

    return (
      <div
        data-sp-gap={at}
        className={cn(
          'relative flex h-3 rounded-full transition-colors sm:col-span-12',
          over ? 'bg-primary/15' : 'bg-transparent'
        )}
      >
        {over ? (
          <span
            className="relative flex items-center justify-center rounded-full bg-primary transition-[width] duration-100"
            style={{ width: chosen ? share(chosen.span, 12) : '100%' }}
          >
            {/* out of the flow and deaf to the pointer: it must not add height
                to the strip, and it must not be what elementFromPoint finds */}
            <span className="pointer-events-none absolute whitespace-nowrap rounded-full bg-primary px-2 py-0.5 text-[11px] font-medium leading-tight text-primary-foreground">
              {chosen
                ? sprintf(
                    /* translators: %s: a width, such as ½ or Full */
                    __('New row · %s', 'schemapress'),
                    chosen.label
                  )
                : __('New row', 'schemapress')}
            </span>
          </span>
        ) : null}
      </div>
    )
  }

  // straight after the held field: it is widening, and this draws the part of
  // it that reaches in here. the field's own card carries the label
  if (own) {
    const reach = Math.min(span, Math.max(0, fill - base))

    return (
      <div
        data-sp-gap={at}
        className={cn('relative flex', SPANS[span], start > 0 && STARTS[start + 1])}
      >
        {over && reach > 0 ? (
          <span
            aria-hidden="true"
            className="rounded-lg border-2 border-dashed border-primary bg-primary/10 transition-[width] duration-100"
            style={{ width: share(reach, span) }}
          />
        ) : null}
      </div>
    )
  }

  const chosen = WIDTHS.find((option) => option.span === fill) || width

  return (
    <div
      data-sp-gap={at}
      // no border or padding of its own, lit or not: the box has to be the same
      // size either way, and the preview measures its width against it
      className={cn('relative flex', SPANS[span], start > 0 && STARTS[start + 1])}
    >
      {over ? (
        <>
          <span
            aria-hidden="true"
            className="absolute inset-0 rounded-lg border-2 border-dashed border-primary/30"
          />

          {/* flush to where the row starts, which is where the field lands —
              the right-hand end in a right-to-left admin, which flex does on
              its own */}
          <span
            className="relative flex items-center justify-center gap-1.5 rounded-lg border-2 border-dashed border-primary bg-primary/10 text-[12px] font-medium text-primary transition-[width] duration-100"
            style={{ width: share(chosen.span, span) }}
          >
            {chosen.span === span ? <span>{__('Fill this space', 'schemapress')}</span> : null}
            <Badge variant="outline">{chosen.label}</Badge>
          </span>
        </>
      ) : null}
    </div>
  )
}

/**
 * Somewhere to drop in the sidebar: either end of its fields, or the whole of an
 * empty one.
 *
 * The ends sit in the card's own padding and are drawn only while aimed at, so
 * their appearing when a drag begins moves nothing — the card under the pointer
 * stays under it, which in a single column of cards is what stops the first
 * move from swapping two of them. The empty sidebar is drawn all the time: it is
 * the only thing on the screen saying a field can go there at all.
 *
 * @param {Object} props
 * @return {JSX.Element|null} The target.
 */
function SideGap({ at, edge, dragging, over }) {
  if (edge === 'empty') {
    return (
      <div
        data-sp-gap={at}
        className={cn(
          // one border and one sentence either way, so lighting up moves nothing
          'flex flex-col items-center justify-center gap-1.5 rounded-lg border-2 border-dashed px-4 py-6 text-center text-[12px] font-medium transition-colors',
          dragging && over
            ? 'border-primary bg-primary/10 text-primary'
            : 'border-border text-muted-foreground'
        )}
      >
        <PanelRight className="size-4" aria-hidden="true" />
        {__('Drag a field here to put it in the sidebar', 'schemapress')}
      </div>
    )
  }

  if (!dragging) {
    return null
  }

  return (
    <div
      data-sp-gap={at}
      className={cn(
        'absolute inset-x-4 flex h-1.5 items-center justify-center rounded-full transition-colors',
        edge === 'start' ? 'top-[5px]' : 'bottom-[5px]',
        over ? 'bg-primary' : 'bg-transparent'
      )}
    >
      {over ? (
        // out of the flow and deaf to the pointer, as the New row label is
        <span className="pointer-events-none absolute whitespace-nowrap rounded-full bg-primary px-2 py-0.5 text-[11px] font-medium leading-tight text-primary-foreground">
          {edge === 'start'
            ? __('Top of the sidebar', 'schemapress')
            : __('End of the sidebar', 'schemapress')}
        </span>
      ) : null}
    </div>
  )
}

/**
 * A card the entry screen always shows in the sidebar, drawn as a silhouette of
 * itself. The canvas is the screen it arranges, so they hold their places — and
 * nothing on this tab moves them, which is why they are dashed and have nothing
 * in them to press. Hidden from assistive technology: the note above the canvas
 * says what the sidebar is for, and a list of disabled buttons would not.
 *
 * @param {Object} props
 * @return {JSX.Element} The silhouette.
 */
function Silhouette({ label, children }) {
  return (
    <div
      aria-hidden="true"
      className="flex select-none flex-col gap-2 rounded-lg border border-dashed border-border bg-muted/40 p-4"
    >
      <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground/80">
        {label}
      </p>
      {children}
    </div>
  )
}

/**
 * A control, drawn as its outline.
 *
 * @param {Object} props
 * @return {JSX.Element} The outline.
 */
function Outline({ className, children }) {
  return (
    <span
      className={cn(
        'flex h-7 min-w-0 items-center justify-center truncate rounded-md border border-border/70 bg-background/60 px-2 text-[11px] text-muted-foreground/70',
        className
      )}
    >
      {children}
    </span>
  )
}

/**
 * The status card: what the entry is, and publishing it.
 *
 * @return {JSX.Element} The silhouette.
 */
function StatusSilhouette() {
  return (
    <Silhouette label={__('Status', 'schemapress')}>
      <p className="flex items-center gap-1.5 text-[12px] font-medium text-muted-foreground/80">
        <CircleDot className="size-3.5" />
        {__('Draft', 'schemapress')}
      </p>

      <Outline>{__('Publish', 'schemapress')}</Outline>

      <div className="grid grid-cols-2 gap-1.5">
        <Outline>{__('Discard', 'schemapress')}</Outline>
        <Outline>{__('Unpublish', 'schemapress')}</Outline>
      </div>
    </Silhouette>
  )
}

/**
 * The address the entry answers on.
 *
 * @return {JSX.Element} The silhouette.
 */
function EndpointSilhouette() {
  return (
    <Silhouette label={__('Endpoint', 'schemapress')}>
      <Outline className="justify-start font-mono">/wp-json/schemapress/api/…</Outline>
    </Silhouette>
  )
}

/**
 * When things happened.
 *
 * @param {Object} props
 * @return {JSX.Element} The silhouette.
 */
function DetailsSilhouette({ drafts }) {
  // when saving is publishing, the entry screen does not draw the second line
  const lines = drafts
    ? [__('Last edited', 'schemapress'), __('Published', 'schemapress')]
    : [__('Last edited', 'schemapress')]

  return (
    <Silhouette label={__('Details', 'schemapress')}>
      {lines.map((line) => (
        <span
          key={line}
          className="flex items-center justify-between gap-2 text-[12px] text-muted-foreground/80"
        >
          {line}
          <span className="h-1.5 w-12 rounded-full bg-muted-foreground/15" />
        </span>
      ))}
    </Silhouette>
  )
}

/**
 * Deleting the entry.
 *
 * @return {JSX.Element} The silhouette.
 */
function DangerSilhouette() {
  return (
    <Silhouette label={__('Danger zone', 'schemapress')}>
      <Outline className="border-destructive/25 text-destructive/60">
        {__('Delete entry', 'schemapress')}
      </Outline>
    </Silhouette>
  )
}

/**
 * One field, drawn roughly as its control.
 *
 * In the sidebar it takes the column's whole width, because the entry screen
 * draws it that way: the column is too narrow for the grid. It keeps the width
 * it had, and its badge is the way back to the form at that width or another.
 *
 * @param {Object} props
 * @return {JSX.Element} The card.
 */
function FieldCard({
  field,
  index,
  region,
  regions,
  dragging,
  resize,
  onPointerDown,
  onWidth,
  onRequired,
  onRegion,
}) {
  const [sizing, setSizing] = useState(false)

  const width = widthOf(field)
  const option = WIDTHS.find((candidate) => candidate.value === width)
  const aside = region === 'sidebar'
  // the width it is being resized to where it stands, while that is happening
  const target = WIDTHS.find((candidate) => candidate.span === resize)

  return (
    <div
      // the whole card both drags and opens the settings: a press that travels
      // is a grab, one that does not is a click. which is decided on release,
      // by the window listener, so there is no small target to find here
      data-sp-card={index}
      onPointerDown={onPointerDown}
      className={cn(
        // min-w-0 so a long label can never push the card wider than its
        // column and over the top of its neighbor; select-none so pressing on
        // the label starts the drag rather than a text selection
        'group relative flex min-w-0 cursor-grab select-none flex-col overflow-hidden rounded-lg bg-background p-3 shadow-sm transition-colors',
        !aside && SPANS[option.span],
        dragging
          ? // the card being dragged reads as the gap it left behind, so the
            // destination is a shape on screen rather than a guess. thicker
            // than a resting card on purpose: it is a target now, not content
            'cursor-grabbing border-2 border-dashed border-ring/60 bg-accent/40'
          : 'border border-border hover:border-primary/40'
      )}
    >
      {/* while it is being dragged the card IS a drop target — put it back
          here — so it says so, like every other target on the screen. hiding
          its contents and leaving a blank dashed box was the one unlabeled
          shape in a row of labeled ones.

          laid over the card, with what it covers made invisible rather than
          removed: the card keeps its height. collapsing it the moment a drag
          began moved everything under it up, and in the sidebar's single
          column that put a different card under the pointer, which hovering
          then swapped with */}
      {dragging ? (
        <span
          className={cn(
            'absolute inset-y-0 start-0 flex items-center justify-center gap-1.5 text-[12px] font-medium transition-[width] duration-100',
            // being resized: the width it will come out at, drawn from where
            // it starts — narrower than the card, or all of it and on into the
            // space after, which draws the rest
            target
              ? 'rounded-md bg-primary/10 text-primary outline-dashed outline-2 -outline-offset-2 outline-primary'
              : 'text-muted-foreground'
          )}
          style={{
            width: target ? share(Math.min(target.span, option.span), option.span) : '100%',
          }}
        >
          {__('Fill this space', 'schemapress')}
          {aside ? null : <Badge variant="outline">{(target || option).label}</Badge>}
        </span>
      ) : null}

      <div className={cn('mb-2 flex min-w-0 items-center gap-1.5', dragging && 'invisible')}>
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
            field.label
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
              : 'border-border text-muted-foreground/50 hover:border-primary/50 hover:text-foreground'
          )}
        >
          <span aria-hidden="true">*</span>
        </button>

        {/* width is the one setting worth changing without opening anything,
            because it is the whole point of this screen — so it is a popover
            on the card rather than a row of buttons under every field.

            it is also where a field changes column without being dragged,
            because where a field sits is the same question as how wide it is.
            in the sidebar the badge says so rather than naming a width the
            column does not draw */}
        <Popover
          open={sizing}
          onOpenChange={setSizing}
          align="end"
          className="p-2"
          trigger={
            <button
              type="button"
              onClick={(event) => event.stopPropagation()}
              aria-label={
                aside
                  ? sprintf(
                      /* translators: %s: the field's label */
                      __('Move %s to the main column', 'schemapress'),
                      field.label
                    )
                  : sprintf(
                      /* translators: %s: the field's label */
                      __('Width of %s', 'schemapress'),
                      field.label
                    )
              }
              title={aside ? __('In the sidebar', 'schemapress') : undefined}
              className="flex h-5 min-w-[1.75rem] shrink-0 items-center justify-center rounded border border-border px-1 text-[11px] font-medium text-muted-foreground transition-colors hover:border-primary/50 hover:text-foreground"
            >
              {aside ? <PanelRight className="size-3" aria-hidden="true" /> : option.label}
            </button>
          }
        >
          <div onClick={(event) => event.stopPropagation()} className="flex flex-col gap-1.5">
            {aside ? (
              <p className="px-0.5 text-[11px] font-medium text-muted-foreground">
                {__('Move to the main column, at', 'schemapress')}
              </p>
            ) : null}

            <Segmented
              value={width}
              onChange={(next) => {
                if (aside) {
                  onRegion('main', next)
                } else {
                  onWidth(next)
                }

                setSizing(false)
              }}
              options={WIDTHS.map((candidate) => ({
                value: candidate.value,
                label: candidate.label,
              }))}
            />

            {regions && !aside ? (
              <Button
                variant="ghost"
                size="sm"
                className="justify-start"
                onClick={() => {
                  onRegion('sidebar')
                  setSizing(false)
                }}
              >
                <PanelRight />
                {__('Move to the sidebar', 'schemapress')}
              </Button>
            ) : null}
          </div>
        </Popover>
      </div>

      {/* the control, roughly. every one is the same height on purpose: this
          screen arranges fields across the row, and a textarea drawn taller
          than its neighbor only makes the cards in a row line up badly while
          saying nothing about the layout being set */}
      <div
        className={cn(
          'relative flex h-9 w-full items-center rounded-md border border-input bg-input-fill px-2.5 text-[12px] text-muted-foreground transition-colors group-hover:border-primary/40',
          dragging && 'invisible'
        )}
      >
        <span className="min-w-0 flex-1 truncate">{field.config?.placeholder || ''}</span>

        <Pencil className="size-3.5 shrink-0 opacity-0 transition-opacity group-hover:opacity-60" />
      </div>
    </div>
  )
}

/**
 * One field's presentation, as a dialog. None of it changes what the field
 * stores, which is why none of it is on the Schema tab.
 *
 * Edits are held here until Done: writing them straight through made the card
 * behind the dialog jump the instant you touched a control, with no way back.
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
        'schemapress'
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
            help={__('Grayed-out text inside the empty control.', 'schemapress')}
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
 * When a field appears on the entry form. The rule reads as a sentence — "show
 * when Contactable is filled in" — so it is laid out as one, with the lead-in a
 * label over the row rather than a word wedged beside the first select.
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
