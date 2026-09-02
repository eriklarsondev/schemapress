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
