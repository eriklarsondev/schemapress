/**
 * Small helpers shared by both admin apps. Kept dependency-free and pure so
 * they can be reasoned about (and tested) in isolation.
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
 * Assigns a key to every field in a preset's field list, recursing into
 * nesting types. Keys are derived from labels and deduplicated among siblings,
 * matching what SchemaModel::normalize would produce server-side — so a
 * preset's stored shape is the same whether it round-trips or not.
 *
 * @param {Array} fields
 * @return {Array} Fields with keys.
 */
function assignFieldKeys(fields = []) {
  const taken = []

  return fields.map((field) => {
    const key = uniqueKey(toKey(field.label), taken)
    taken.push(key)

    const assigned = {
      key,
      label: field.label,
      type: field.type,
      help: field.help || '',
      required: Boolean(field.required),
      config: field.config || {}
    }

    if (Array.isArray(field.fields)) {
      assigned.fields = assignFieldKeys(field.fields)
    }

    return assigned
  })
}

/**
 * Turns a component preset into a section type definition ready to place.
 *
 * @param {Object}   preset
 * @param {string[]} takenKeys keys already used by sibling section types
 * @return {Object} The section type definition.
 */
export function presetToSection(preset, takenKeys = []) {
  return {
    key: uniqueKey(toKey(preset.label), takenKeys),
    label: preset.label,
    description: preset.description || '',
    icon: preset.icon || 'layout',
    max: 0,
    container: Boolean(preset.container),
    layout: preset.layout || [],
    // a preset can start an option somewhere other than its registry default:
    // a hero is full width to begin with, a columns row is two across
    layoutDefaults: preset.layout_defaults || {},
    fields: assignFieldKeys(preset.fields)
  }
}

/**
 * Turns a palette element into a field definition ready to add.
 *
 * @param {Object}   element
 * @param {string[]} takenKeys keys already used by sibling fields
 * @return {Object} The field definition.
 */
export function elementToField(element, takenKeys = []) {
  const source = element.field

  const field = {
    key: uniqueKey(toKey(source.label), takenKeys),
    label: source.label,
    type: source.type,
    help: '',
    required: false,
    role: source.role || '',
    classes: '',
    config: source.config || {}
  }

  if (Array.isArray(source.fields)) {
    field.fields = assignFieldKeys(source.fields)
  }

  return field
}

/**
 * Reads the section list at a path of child indices. An empty path is the
 * page's own top-level list.
 *
 * @param {Array} sections
 * @param {Array} path
 * @return {Array} The list at that path.
 */
export function listAt(sections, path = []) {
  return path.reduce((list, index) => list[index]?.children || [], sections)
}

/**
 * Returns a copy of the tree with the list at a path replaced.
 *
 * @param {Array} sections
 * @param {Array} path
 * @param {Array} next
 * @return {Array} The updated tree.
 */
export function setListAt(sections, path = [], next) {
  if (path.length === 0) {
    return next
  }

  const [index, ...rest] = path

  return sections.map((section, i) =>
    i === index
      ? { ...section, children: setListAt(section.children || [], rest, next) }
      : section
  )
}

/**
 * Reads the section at an address - the full list of child indices that
 * reaches it, so [1, 0] is the first child of the second section.
 *
 * @param {Array} sections
 * @param {Array} address
 * @return {Object|null} The section, or null if the address is stale.
 */
export function nodeAt(sections, address = []) {
  return address.reduce(
    (node, index, depth) => (depth === 0 ? sections[index] : node?.children?.[index]) || null,
    null
  )
}

/**
 * Returns a copy of the tree with the section at an address replaced.
 *
 * @param {Array}  sections
 * @param {Array}  address
 * @param {Object} next
 * @return {Array} The updated tree.
 */
export function setNodeAt(sections, address, next) {
  const [index, ...rest] = address

  return sections.map((section, i) => {
    if (i !== index) {
      return section
    }

    return rest.length === 0
      ? next
      : { ...section, children: setNodeAt(section.children || [], rest, next) }
  })
}

/**
 * Whether one path is the same as, or inside, another.
 *
 * Used to stop a container being dropped into itself, which would detach the
 * whole branch from the tree.
 *
 * @param {Array} path
 * @param {Array} ancestor
 * @return {boolean} True when path is at or below ancestor.
 */
export function isWithin(path, ancestor) {
  return ancestor.every((step, index) => path[index] === step)
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
    case 'post':
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
