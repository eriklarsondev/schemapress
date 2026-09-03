/**
 * The values PHP bootstrapped onto the page.
 *
 * Read once at module load — the inline script that defines them is printed
 * before the bundle — so components can reach registry data without threading
 * it through every level of props.
 */

export const settings = window.SchemaPress || {}

/**
 * The field type registry, as declared by PHP.
 *
 * @type {Array<{type: string, label: string, children: boolean, repeatable: boolean}>}
 */
export const fieldTypes = settings.fieldTypes || []

/**
 * The element palette: field types expressed as things an author recognises.
 *
 * @type {Array<{id: string, label: string, icon: string, field: Object}>}
 */
export const elements = settings.elements || []

/**
 * Ready-made option lists a select can draw from — countries, US states and so
 * on. Sent with the page because they are static, so a control can render
 * without a request and the picker can name them.
 *
 * @type {Array<{slug: string, label: string, options: Array}>}
 */
export const datasets = settings.datasets || []

/**
 * The choices a select field offers, from whichever source it names.
 *
 * Mirrors Datasets::forField on the server. Having one answer on each side is
 * what stops a control offering a value the sanitizer will then discard.
 *
 * @param {Object} field
 * @return {Array} Options of {value, label}.
 */
export function optionsFor(field) {
  const source = field?.config?.source

  if (source) {
    const dataset = datasets.find((set) => set.slug === source)

    if (dataset) {
      return dataset.options
    }
  }

  return field?.config?.options || []
}
