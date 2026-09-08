/**
 * Which required fields have not been filled in.
 *
 * "Anywhere in the form" is the whole difficulty: a required field can be at the
 * top level, inside a group, or inside every row of a repeater — and a repeater
 * with three rows has three chances to be incomplete.
 *
 * Only fields that are ON SCREEN count. A field hidden by its condition is not
 * being asked for, so it cannot be missing, and blocking a save on something the
 * author cannot see would be unanswerable.
 */

import { visibleFields } from './conditions'

/**
 * Whether a value counts as unanswered.
 *
 * A boolean never does. A toggle is answered by being off as much as by being
 * on, and treating `false` as missing would make a required toggle a checkbox
 * you cannot save without ticking — which is a different feature, and not one
 * anybody asked this switch for.
 *
 * Zero is a number, not an absence.
 *
 * @param {*} value
 * @return {boolean} True when nothing was entered.
 */
export function isBlank(value) {
  if (value === null || value === undefined) {
    return true
  }

  if (typeof value === 'boolean' || typeof value === 'number') {
    return false
  }

  if (typeof value === 'string') {
    return value.trim() === ''
  }

  if (Array.isArray(value)) {
    return value.length === 0
  }

  if (typeof value === 'object') {
    // a link is { url, label, target }: it is filled in when it points somewhere
    return Object.values(value).every((part) => isBlank(part))
  }

  return false
}

/**
 * The rows of a repeater value, whichever shape they arrive in.
 *
 * @param {*} value
 * @return {Array} The rows' value bags.
 */
function rowsOf(value) {
  if (!Array.isArray(value)) {
    return []
  }

  return value.map((row) => (row && typeof row === 'object' ? row.values || row.data || {} : {}))
}

/**
 * Every required field still waiting for an answer.
 *
 * @param {Array}  fields
 * @param {Object} values
 * @param {string} path   Labels of the groups and rows above, for the message.
 * @return {Array<{key: string, label: string}>} In the order they appear.
 */
export function missingRequired(fields = [], values = {}, path = '') {
  const missing = []

  visibleFields(fields, values || {}).forEach((field) => {
    const value = (values || {})[field.key]
    const label = path ? `${path} → ${field.label || field.key}` : field.label || field.key

    if (field.type === 'repeater') {
      if (field.required && rowsOf(value).length === 0) {
        missing.push({ key: field.key, label })
      }

      rowsOf(value).forEach((row, index) => {
        missing.push(...missingRequired(field.fields, row, `${label} ${index + 1}`))
      })

      return
    }

    if (field.type === 'group') {
      missing.push(...missingRequired(field.fields, value || {}, label))

      return
    }

    if (field.required && isBlank(value)) {
      missing.push({ key: field.key, label })
    }
  })

  return missing
}
