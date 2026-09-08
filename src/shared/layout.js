/**
 * Where a control sits on the twelve-column form grid.
 *
 * One source for the whole app: the Form tab that sets these, the entry form
 * that renders them, and the nested lists inside a group or a repeater row. A
 * component carries its layout with it when it is imported, so a group has to
 * lay its children out the same way the top level does — otherwise arranging a
 * component would be arranging something nobody ever sees.
 *
 * Every class is written out because Tailwind cannot see a computed name, and
 * an unknown width falls back to full: a field should never vanish because its
 * layout was mis-set.
 */

import { cn } from '../ui'

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
 * How many twelfths a field takes.
 *
 * @param {Object} field
 * @return {number} The span.
 */
export function spanOf(field) {
  const found = WIDTHS.find((option) => option.value === field?.config?.width)

  return found ? found.span : 12
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
 * Whether a field insists on starting a row of its own.
 *
 * A grid packs its items together, so a half-width field after a third-width
 * one shares that row whether or not you meant it to. Arranging a form is
 * partly deciding where a row ENDS, and that is a decision the layout cannot
 * infer from widths — hence a field can say it, and keep saying it however the
 * fields before it are later resized.
 *
 * @param {Object} field
 * @return {boolean} True when it starts a row.
 */
export function startsRow(field) {
  return Boolean(field?.config?.new_row)
}

/**
 * Whether a row break belongs before a field as it is rendered.
 *
 * Never before the first one: there is no row above it to end, and a break
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
 * The classes for that break: an item of no height spanning every column.
 *
 * Being full width it cannot share the row above, so it takes what is left of
 * it and the next field begins a row. Below the breakpoint the grid is a single
 * column — every field already has a row to itself — so it is not rendered.
 *
 * @return {string} A class string.
 */
export function rowBreakClass() {
  return 'hidden h-0 sm:block sm:col-span-12'
}

/**
 * The grid classes placing one field.
 *
 * Width only. A field's leading blank space is drawn as a spacer beside it —
 * see spacerClass — rather than set here as a column to start at.
 *
 * `col-start` was the obvious way to do it and it is wrong: an offset is space
 * before a field ON ITS ROW, and col-start counts from the left edge of the
 * grid. A field third along a row with two columns of space in front of it
 * would be sent to column 3 instead of column 9 — and, being a definite
 * position earlier than the row had already reached, dropped onto the next row
 * as well.
 *
 * @param {Object} field
 * @return {string} A class string.
 */
export function cellClass(field) {
  return cn('min-w-0', SPANS[field?.config?.width] || SPANS.full)
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
 * The classes for that blank space: an item of the right width and nothing in
 * it. Below the breakpoint the grid is one column and there is no space to
 * leave, so it is not rendered.
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
