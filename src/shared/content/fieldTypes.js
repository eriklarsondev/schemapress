/**
 * The field type registry, read from what PHP bootstrapped.
 *
 * Nested element editors need it but sit too deep to be worth threading it
 * through as a prop from the page that happens to own it.
 */

import { settings } from '../settings'

/**
 * Every registered field type.
 *
 * @return {Array} Type descriptors.
 */
export function fieldTypesForClient() {
  return settings.fieldTypes || []
}
