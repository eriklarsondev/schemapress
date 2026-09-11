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
 * The element palette: field types expressed as things an author recognizes.
 *
 * @type {Array<{id: string, label: string, icon: string, field: Object}>}
 */
export const elements = settings.elements || []

/**
 * Ready-made option lists a select can draw from. Sent with the page because they
 * are static, so a control renders without a request.
 *
 * @type {Array<{slug: string, label: string, options: Array}>}
 */
export const datasets = settings.datasets || []

/**
 * What this user may do beyond editing entries.
 *
 * Screens read it to stop offering what the transport would refuse. It is not the
 * check itself — every one of those routes checks for itself, and a capability
 * sent to the browser is a hint, not a gate.
 *
 * @type {{manageSchema: boolean}}
 */
export const can = settings.can || {}

/**
 * The site's own settings, as opposed to a collection's.
 *
 * Held here rather than threaded through props: two unrelated screens read it,
 * and prop-drilling between them would pass through three components with no
 * interest in it. Read when a screen opens rather than subscribed to.
 */
let siteSettings = normalize(settings.site)

/**
 * Fills in whatever PHP did not send. Mirrors Settings::normalize.
 *
 * @param {Object} value
 * @return {{restApi: boolean, apiCacheMaxAge: number, deleteDataOnUninstall: boolean}} The settings.
 */
function normalize(value) {
  return {
    restApi: value?.restApi !== false,
    apiCacheMaxAge: Number(value?.apiCacheMaxAge) || 0,
    // the one default that is not a judgement call: the destructive direction
    // has to be asked for
    deleteDataOnUninstall: value?.deleteDataOnUninstall === true,
  }
}

/**
 * The site's settings as they currently stand.
 *
 * @return {Object} The settings.
 */
export function site() {
  return siteSettings
}

/**
 * The site's own role list rather than a fixed one, so a role added by another
 * plugin is offered without this one knowing it exists.
 *
 * @type {Array<{value: string, label: string}>}
 */
export const roles = settings.roles || []

/**
 * Long-running work already in flight when the page loaded, so a reindex started
 * before a reload is still visible after it.
 *
 * @type {Array<{id: string, job: string, typeId: number, done: number, total: number}>}
 */
export const queuedJobs = settings.jobs || []

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
 * Mirrors Datasets::forField on the server — one answer on each side is what
 * stops a control offering a value the sanitizer will then discard.
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
