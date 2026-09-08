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
 * What this user may do beyond editing entries.
 *
 * `manageSchema` is the Content-Type Builder half of Strapi's split: defining
 * collections, changing their fields, deleting them, and deciding what the site
 * publishes. Filling entries in is the other half, and everyone who can open
 * this screen can do that.
 *
 * The screens read it to stop OFFERING what the transport would refuse. It is
 * not the check itself — every one of those routes checks for itself, and a
 * capability sent to the browser is a hint, not a gate.
 *
 * @type {{manageSchema: boolean}}
 */
export const can = settings.can || {}

/**
 * The site's own settings, as opposed to a collection's.
 *
 * Held here rather than threaded through props because two unrelated screens
 * read it: the Settings view edits it, and a collection's settings dialog reads
 * it to say when the API it is offering to publish to is switched off.
 * Prop-drilling it between those two would pass through three components with
 * no interest in it.
 *
 * Read when a screen opens rather than subscribed to — this is a fact about the
 * installation that changes on one screen a couple of times a year, and the
 * dialog that reads it is opened fresh each time.
 */
let siteSettings = normalize(settings.site)

/**
 * Fills in whatever PHP did not send. Mirrors Settings::normalize.
 *
 * @param {Object} value
 * @return {{restApi: boolean}} The settings.
 */
function normalize(value) {
  return { restApi: value?.restApi !== false }
}

/**
 * The site's settings as they currently stand.
 *
 * @return {{restApi: boolean}} The settings.
 */
export function site() {
  return siteSettings
}

/**
 * Adopts what the server stored, so a screen opened after the Settings view was
 * saved reads the new value rather than the one the page booted with.
 *
 * @param {Object} next
 * @return {void}
 */
export function setSite(next) {
  siteSettings = normalize(next)
}

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
