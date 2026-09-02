/**
 * Enough English to preview a name as you type it.
 *
 * The server is authoritative — SchemaPress\Inflector derives the keys that are
 * actually stored, and this never writes anything. This exists only so the
 * create dialog can show you the singular it is about to use, and say so when
 * what you typed looks plural, before you commit to a key that cannot change.
 *
 * Kept to the same rules as the PHP, in the same order. If they drift, the
 * preview is wrong and the stored key is still right.
 */

const IRREGULAR = {
  people: 'person',
  children: 'child',
  men: 'man',
  women: 'woman',
  teeth: 'tooth',
  feet: 'foot',
  mice: 'mouse',
  geese: 'goose',
  oxen: 'ox',
  data: 'datum',
  media: 'medium',
  indices: 'index',
  matrices: 'matrix',
  vertices: 'vertex',
  analyses: 'analysis',
  criteria: 'criterion',
}

const UNCOUNTABLE = [
  'news',
  'series',
  'species',
  'equipment',
  'information',
  'staff',
  'content',
  'fish',
  'sheep',
  'deer',
  'aircraft',
  'software',
  'research',
  'feedback',
  'evidence',
  'furniture',
]

/** Words ending in "s" that are already singular. */
const SINGULAR_ENDING_IN_S = [
  'status',
  'campus',
  'bonus',
  'focus',
  'virus',
  'census',
  'bias',
  'canvas',
  'atlas',
  'lens',
  'address',
  'process',
  'class',
  'business',
]

const SINGULAR_RULES = [
  [/(quiz)zes$/i, '$1'],
  [/([^aeiouy]|qu)ies$/i, '$1y'],
  [/([^f])ves$/i, '$1fe'],
  [/([lr])ves$/i, '$1f'],
  [/(x|ch|ss|sh|z)es$/i, '$1'],
  [/([^aeiou])oes$/i, '$1o'],
  [/([^s])s$/i, '$1'],
]

/**
 * Whether a word has no separate plural.
 *
 * @param {string} word
 * @return {boolean} True when uncountable.
 */
function isUncountable(word) {
  return UNCOUNTABLE.includes(String(word).toLowerCase())
}

/**
 * The singular of one word.
 *
 * @param {string} word
 * @return {string} The singular form.
 */
export function singularize(word) {
  const value = String(word)
  const lower = value.toLowerCase()

  if (value === '' || isUncountable(value)) {
    return value
  }

  if (IRREGULAR[lower]) {
    return IRREGULAR[lower]
  }

  if (SINGULAR_ENDING_IN_S.includes(lower)) {
    return value
  }

  for (const [rule, replacement] of SINGULAR_RULES) {
    if (rule.test(value)) {
      return value.replace(rule, replacement)
    }
  }

  return value
}

/**
 * Applies a transform to the last word of a phrase, leaving the rest alone.
 *
 * "Team Members" singularizes on "Members"; the head noun is what changes.
 *
 * @param {string}   phrase
 * @param {Function} transform
 * @return {string} The transformed phrase.
 */
export function lastWord(phrase, transform) {
  const value = String(phrase).trim()

  if (value === '') {
    return value
  }

  const parts = value.split(/(\s+|_)/)
  const last = parts.length - 1

  parts[last] = transform(parts[last])

  return parts.join('')
}

/**
 * Whether what was typed reads as a plural.
 *
 * @param {string} phrase
 * @return {boolean} True when it looks plural.
 */
export function looksPlural(phrase) {
  const value = String(phrase).trim()

  if (value === '') {
    return false
  }

  const head = value.split(/[\s_]+/).pop()

  if (isUncountable(head) || SINGULAR_ENDING_IN_S.includes(head.toLowerCase())) {
    return false
  }

  return singularize(head).toLowerCase() !== head.toLowerCase()
}
