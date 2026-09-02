/**
 * Field visibility conditions.
 *
 * A field can say it should only appear once a sibling has been filled in, or
 * has a particular value. That keeps a long form short: ask for the phone
 * number after someone ticks "Contactable", not before.
 *
 * Two rules this deliberately follows:
 *
 * A hidden field KEEPS its value. Untick the box and the phone number is still
 * there when you tick it again, and it is still delivered — hiding a control is
 * a statement about the form, not about the data. Clearing on hide would be a
 * silent delete triggered by a checkbox.
 *
 * A condition that cannot be evaluated shows the field. A malformed condition
 * or a reference to a field that has since been removed should leave you with a
 * visible control you can reason about, not an invisible one you cannot.
 */

/**
 * Whether a value counts as filled in.
 *
 * @param {*} value
 * @return {boolean} True when there is something there.
 */
export function isFilled(value) {
  if (value === undefined || value === null || value === '') {
    return false
  }

  // an unticked toggle is the case conditions are usually written against
  if (value === false) {
    return false
  }

  if (Array.isArray(value)) {
    return value.length > 0
  }

  if (typeof value === 'object') {
    // a link is an empty shape until it has a url; an attachment until it has
    // an id. both arrive as objects that are technically present
    if ('url' in value) {
      return Boolean(value.url)
    }

    return Object.keys(value).length > 0
  }

  return true
}

/**
 * Whether a field's condition is currently met.
 *
 * @param {Object} condition the field's config.condition
 * @param {Object} values    the sibling values it is evaluated against
 * @return {boolean} True when the field should show.
 */
export function matches(condition, values = {}) {
  const key = condition?.field

  if (!key) {
    return true
  }

  const value = values?.[key]
  const filled = isFilled(value)

  switch (condition.operator) {
    case 'empty':
      return !filled

    case 'equals':
      return Array.isArray(value)
        ? value.map(String).includes(String(condition.value))
        : String(value ?? '') === String(condition.value)

    case 'not_equals':
      return Array.isArray(value)
        ? !value.map(String).includes(String(condition.value))
        : String(value ?? '') !== String(condition.value)

    case 'filled':
    default:
      return filled
  }
}

/**
 * The fields that should currently show, given their siblings' values.
 *
 * @param {Array}  fields
 * @param {Object} values
 * @return {Array} The visible fields, in order.
 */
export function visibleFields(fields = [], values = {}) {
  return fields.filter((field) => matches(field.config?.condition, values))
}

/**
 * The fields a condition may point at: siblings, minus the field itself, minus
 * the types that hold no comparable value of their own.
 *
 * @param {Array}  fields
 * @param {string} exclude the key of the field being configured
 * @return {Array} Choosable fields.
 */
export function conditionTargets(fields = [], exclude = '') {
  return fields.filter(
    (field) => field.key !== exclude && !['group', 'repeater', 'wysiwyg'].includes(field.type),
  )
}
