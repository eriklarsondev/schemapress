/**
 * Small shared helpers. Kept dependency-free and pure so they can be reasoned
 * about (and tested) in isolation.
 */

/**
 * Generates a client-side node id. The server accepts these verbatim, so a
 * row keeps the same identity from the moment it is added through to save.
 *
 * @param {string} prefix
 * @return {string} A short unique id.
 */
export function nodeId(prefix = 'n') {
  return `${prefix}_${Math.random().toString(36).slice(2, 12)}`
}

/**
 * Returns a copy of a list with one entry moved to a new index.
 *
 * @param {Array}  list
 * @param {number} from
 * @param {number} to
 * @return {Array} The reordered list.
 */
export function move(list, from, to) {
  if (to < 0 || to >= list.length || from === to) {
    return list
  }

  const next = [...list]
  const [item] = next.splice(from, 1)
  next.splice(to, 0, item)

  return next
}

/**
 * Returns a copy of a list with the entry at an index replaced.
 *
 * @param {Array}  list
 * @param {number} index
 * @param {*}      value
 * @return {Array} The updated list.
 */
export function replaceAt(list, index, value) {
  return list.map((item, i) => (i === index ? value : item))
}

/**
 * Returns a copy of a list without the entry at an index.
 *
 * @param {Array}  list
 * @param {number} index
 * @return {Array} The shortened list.
 */
export function removeAt(list, index) {
  return list.filter((_, i) => i !== index)
}

/**
 * Converts a human label into the snake_case key the server expects. Mirrors
 * SchemaModel::uniqueKey so the client can preview the key it will receive.
 *
 * @param {string} label
 * @return {string} A slugified key.
 */
export function toKey(label) {
  return String(label)
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
}

/**
 * Ensures a key is unique among its siblings by appending a numeric suffix.
 *
 * @param {string}   candidate
 * @param {string[]} taken
 * @return {string} A key not present in taken.
 */
export function uniqueKey(candidate, taken) {
  const base = candidate || 'field'

  if (!taken.includes(base)) {
    return base
  }

  let suffix = 2
  while (taken.includes(`${base}_${suffix}`)) {
    suffix++
  }

  return `${base}_${suffix}`
}

/**
 * The empty value for a field type, matching FieldTypes::defaultValue on the
 * server so a freshly added row round-trips without shape drift.
 *
 * @param {string} type
 * @return {*} The type's empty value.
 */
export function emptyValue(type) {
  switch (type) {
    case 'toggle':
      return false
    case 'repeater':
    case 'group':
      return []
    case 'number':
    case 'image':
    case 'file':
      return null
    case 'link':
      return { url: '', label: '', target: '' }
    default:
      return ''
  }
}

/**
 * Builds an empty value bag for a field list.
 *
 * @param {Array} fields
 * @return {Object} A value bag keyed by field key.
 */
export function emptyValues(fields = []) {
  return fields.reduce((values, field) => {
    values[field.key] =
      field.type === 'group' ? emptyValues(field.fields || []) : emptyValue(field.type)

    return values
  }, {})
}
