/**
 * Where a control sits on the twelve-column form grid — one source for the Form
 * tab that sets these, the entry form that renders them, and the nested lists
 * inside a group or a repeater row.
 *
 * Every class is written out because Tailwind cannot see a computed name, and an
 * unknown width falls back to full: a field should never vanish because its
 * layout was mis-set.
 */

import { cn } from '../ui'
import { fieldTypes } from './settings'

/** The widths a control may take, in twelfths. */
export const WIDTHS = [
  { value: 'third', span: 4 },
  { value: 'half', span: 6 },
  { value: 'two-thirds', span: 8 },
  { value: 'full', span: 12 },
]

const SPANS = {
  third: 'sm:col-span-4',
  half: 'sm:col-span-6',
  'two-thirds': 'sm:col-span-8',
  full: 'sm:col-span-12',
}

/** Written out for the same reason as SPANS: Tailwind cannot see a computed name. */
const SPACERS = {
  1: 'sm:col-span-1',
  2: 'sm:col-span-2',
  3: 'sm:col-span-3',
  4: 'sm:col-span-4',
  5: 'sm:col-span-5',
  6: 'sm:col-span-6',
  7: 'sm:col-span-7',
  8: 'sm:col-span-8',
}

/**
 * Its own width when it has one. A field that has not been given one starts at
 * its type's, matching what the server fills in on save (FieldTypes::WIDTHS) — so
 * the form shows now what will be stored, rather than a full-width field that
 * jumps to a third the first time it is saved.
 *
 * @param {Object} field
 * @return {string} third, half, two-thirds or full.
 */
export function widthOf(field) {
  const own = field?.config?.width

  if (WIDTHS.some((option) => option.value === own)) {
    return own
  }

  const type = fieldTypes.find((one) => one.type === field?.type)

  return WIDTHS.some((option) => option.value === type?.width) ? type.width : 'full'
}

/**
 * How many twelfths a field takes.
 *
 * @param {Object} field
 * @return {number} The span.
 */
export function spanOf(field) {
  return WIDTHS.find((option) => option.value === widthOf(field)).span
}

/**
 * How much blank space sits before a field on its row, clamped so the control
 * still fits.
 *
 * @param {Object} field
 * @return {number} The offset in twelfths.
 */
export function offsetOf(field) {
  const offset = Number(field?.config?.offset) || 0

  return Math.max(0, Math.min(offset, 12 - spanOf(field)))
}

/**
 * A grid packs its items together, so a half-width field after a third-width one
 * shares that row whether or not you meant it to. Where a row ends cannot be
 * inferred from widths, so a field says it — and keeps saying it however the
 * fields before it are later resized.
 *
 * @param {Object} field
 * @return {boolean} True when it starts a row.
 */
export function startsRow(field) {
  return Boolean(field?.config?.new_row)
}

/**
 * Which column of the entry screen a field sits in: the form itself, or the
 * sidebar beside it that holds the entry's status.
 *
 * Anything unreadable is the form — the same bargain an unknown width makes: a
 * field should never vanish because its layout was mis-set.
 *
 * @param {Object} field
 * @return {string} main or sidebar.
 */
export function regionOf(field) {
  return field?.config?.region === 'sidebar' ? 'sidebar' : 'main'
}

/**
 * Never before the first field: there is no row above it to end, and a break
 * there is an empty row at the top of the form.
 *
 * @param {Object} field
 * @param {number} index Position among the fields actually being rendered.
 * @return {boolean} True when a break element should precede it.
 */
export function breakBefore(field, index) {
  return index > 0 && startsRow(field)
}

/**
 * An item of no height spanning every column: being full width it cannot share
 * the row above, so it takes what is left and the next field begins a row. Below
 * the breakpoint the grid is one column, so it is not rendered.
 *
 * @return {string} A class string.
 */
export function rowBreakClass() {
  return 'hidden h-0 sm:block sm:col-span-12'
}

/**
 * Width only. A field's leading blank space is drawn as a spacer beside it (see
 * spacerClass) rather than set here as a column to start at.
 *
 * `col-start` is the obvious way and it is wrong: an offset is space before a
 * field on its row, and col-start counts from the left edge of the grid — so a
 * field third along a row would be sent to column 3 instead of column 9, and
 * dropped onto the next row besides.
 *
 * @param {Object} field
 * @return {string} A class string.
 */
export function cellClass(field) {
  return cn('min-w-0', SPANS[widthOf(field)])
}

/**
 * How much blank space to draw before a field, in columns.
 *
 * @param {Object} field
 * @return {number} Zero when none.
 */
export function leadingSpace(field) {
  return offsetOf(field)
}

/**
 * An item of the right width and nothing in it. Below the breakpoint the grid is
 * one column and there is no space to leave, so it is not rendered.
 *
 * @param {number} columns
 * @return {string} A class string.
 */
export function spacerClass(columns) {
  return cn('hidden sm:block', SPACERS[columns] || '')
}

/**
 * The classes for a container of laid-out fields.
 *
 * @return {string} A class string.
 */
export function gridClass() {
  return 'grid grid-cols-1 gap-4 sm:grid-cols-12'
}

/**
 * The entry screen's two columns — the form, and the sidebar beside it. The Form
 * tab draws its canvas on the same two, so what it shows is where things land.
 *
 * `grid-cols-1` below the breakpoint, where they stack, rather than no columns
 * at all: an implicit column is sized to its widest content, and an image drawn
 * from its large size is 1024px of content — the form came out wider than the
 * pane and scrolled sideways.
 *
 * @return {string} A class string.
 */
export function columnsClass() {
  return 'grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]'
}

/**
 * The sidebar column. An ordinary column of cards: the page scrolls and this
 * goes with it.
 *
 * It was sticky, and capped to the height of the pane with a scrollbar of its
 * own so that a sidebar taller than the window could still be read. Both are
 * gone. A scroll container inside a scrolling page means the wheel does one
 * thing over the form and another over the sidebar, and sticking the column
 * puts its foot below the fold the moment it is taller than the window — which
 * is the problem the inner scrollbar was there to paper over.
 *
 * @return {string} A class string.
 */
export function sidebarClass() {
  return 'flex flex-col gap-3'
}
