/**
 * The values PHP bootstrapped onto the page.
 *
 * Read once at module load — the inline script that defines them is printed
 * before the bundle — so components can reach registry data like the layout
 * options without threading it through every level of props.
 */

export const settings = window.SchemaPress || {}

/**
 * The layout option registry, as declared by PHP.
 *
 * @type {Array<{key: string, label: string, default: string, choices: Array}>}
 */
export const layoutOptions = settings.layoutOptions || []

/**
 * The component presets offered when adding a section.
 *
 * Empty on screens that cannot edit structure, such as the metabox on a page's
 * own edit screen — which is what hides the "new component" affordance there.
 *
 * @type {Array<{id: string, label: string, icon: string, fields: Array}>}
 */
export const presets = settings.presets || []

/**
 * The element palette: fields expressed as things an author recognises.
 *
 * @type {Array<{id: string, label: string, icon: string, field: Object}>}
 */
export const elements = settings.elements || []

/**
 * Field roles: what a field is structurally for, as opposed to what it holds.
 *
 * A role decides where the renderer composes a field — a `background` is
 * lifted out of the flow into a layer behind everything, an `action` is
 * collected into the button row.
 *
 * @type {Array<{key: string, label: string, description: string, types: string[]}>}
 */
export const roles = settings.roles || []

/**
 * The roles that may be applied to a field of a given type.
 *
 * @param {string} type
 * @return {Array} Applicable role definitions.
 */
export function rolesFor(type) {
  return roles.filter((role) => role.types.length === 0 || role.types.includes(type))
}

/**
 * Whether the current user may change site-wide configuration - the template
 * registry and the design tokens.
 *
 * Page content and schemas are governed by the page capabilities instead, so
 * everyone who can write pages has the same surface there.
 *
 * @type {boolean}
 */
export const canManage = Boolean(settings.canManage)

/**
 * Looks up one layout option.
 *
 * @param {string} key
 * @return {Object|undefined} The option definition.
 */
export function layoutOption(key) {
  return layoutOptions.find((option) => option.key === key)
}

/**
 * The default values for a set of enabled layout option keys.
 *
 * @param {string[]} enabled
 * @return {Object} Layout values keyed by option.
 */
export function defaultLayout(enabled = [], overrides = {}) {
  return enabled.reduce((values, key) => {
    const option = layoutOption(key)

    if (option) {
      values[key] = overrides[key] ?? option.default
    }

    return values
  }, {})
}
