/**
 * Layout controls for one placed section.
 *
 * The values are tokens - '3', 'narrow', 'dark' - delivered as-is. Mapping
 * them to markup is the front-end's job, which is what keeps CSS classes out
 * of the database and inside the codebase the build actually scans.
 *
 * The vocabulary is deliberately small. Options an author can set are the ones
 * that genuinely vary between pages; everything else about how a component
 * looks belongs to the component.
 */

import { __ } from '@wordpress/i18n'
import { Segmented, Badge } from '../../ui'
import { layoutOption, layoutOptions } from '../settings'

/**
 * Whether a layout option applies to a section built from these fields.
 *
 * Mirrors Layout::applies on the server, so the admin never offers an option
 * the server would then strip.
 *
 * @param {Object} option
 * @param {Array}  fields
 * @return {boolean} True when the option applies.
 */
export function optionApplies(option, fields = []) {
  if (!option.requires) {
    return true
  }

  return containsType(fields, option.requires)
}

/**
 * Whether a field list contains a field of a type, at any depth.
 *
 * @param {Array}  fields
 * @param {string} type
 * @return {boolean} True when present.
 */
function containsType(fields, type) {
  return fields.some(
    (field) => field.type === type || containsType(field.fields || [], type)
  )
}

/**
 * The layout options offered for a section built from these fields.
 *
 * @param {Array} fields
 * @return {Array} Applicable option definitions.
 */
export function availableLayoutOptions(fields = []) {
  return layoutOptions.filter((option) => optionApplies(option, fields))
}

/**
 * Renders a control per layout option the section type enables.
 *
 * @param {Object} props
 * @return {JSX.Element|null} The controls, or null when none are enabled.
 */
export function LayoutControls({ enabled = [], values = {}, onChange }) {
  const options = enabled.map(layoutOption).filter(Boolean)

  if (options.length === 0) {
    return null
  }

  // laid out as label-beside-control rows rather than a grid of columns: with
  // one or two options a grid leaves most of the row empty, which reads as
  // something failing to load
  return (
    <div className="flex flex-col gap-2.5">
      {options.map((option) => (
        <div key={option.key} className="flex flex-wrap items-center gap-x-4 gap-y-1.5">
          <span className="w-24 shrink-0 text-[12px] font-medium text-muted-foreground">
            {option.label}
          </span>
          <Segmented
            value={values[option.key] ?? option.default}
            options={option.choices}
            onChange={(next) => onChange({ ...values, [option.key]: next })}
          />
        </div>
      ))}
    </div>
  )
}

/**
 * A compact read-only rendering of a section's layout, for its collapsed row.
 *
 * @param {Object} props
 * @return {JSX.Element|null} The badges, or null when nothing is set.
 */
export function LayoutSummary({ enabled = [], values = {} }) {
  const parts = enabled
    .map((key) => {
      const option = layoutOption(key)

      if (!option) {
        return null
      }

      const value = values[key] ?? option.default

      // an option sitting at its default says nothing worth the space
      if (value === option.default) {
        return null
      }

      const choice = option.choices.find((entry) => entry.value === value)

      return {
        key,
        label: key === 'columns' ? `${value}${__(' cols', 'schemapress')}` : choice?.label || value
      }
    })
    .filter(Boolean)

  if (parts.length === 0) {
    return null
  }

  return (
    <>
      {parts.map((part) => (
        <Badge key={part.key} variant="outline">
          {part.label}
        </Badge>
      ))}
    </>
  )
}

/**
 * Whether a section type exposes any layout options.
 *
 * @param {string[]} enabled
 * @return {boolean} True when at least one is registered.
 */
export function hasLayout(enabled = []) {
  return enabled.some((key) => Boolean(layoutOption(key)))
}
