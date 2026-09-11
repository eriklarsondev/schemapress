/**
 * Work in progress that has not been stored yet.
 *
 * Three ways to walk away from a half-filled form need different machinery, so
 * the fact that there IS unsaved work is kept in one place:
 *
 *   the Back link      an in-app confirm, because we own the click
 *   the sidebar        the same, from App, which owns navigation
 *   the tab or reload  beforeunload, whose dialog cannot be styled or skipped
 *
 * A module-level flag rather than context: the guard is read from event handlers
 * and from a window listener, neither inside the React tree, and there is only
 * ever one entry being edited at a time.
 */

import { useEffect } from '@wordpress/element'

let unsaved = false

/**
 * Whether anything would be lost by navigating now.
 *
 * @return {boolean} True when there is unsaved work.
 */
export function hasUnsaved() {
  return unsaved
}

/**
 * Called explicitly rather than left to the next render: a screen that saves and
 * then navigates does both in one go, and the flag has to be down before the
 * navigation asks about it.
 *
 * @return {void}
 */
export function clearUnsaved() {
  unsaved = false
}

/**
 * Registers a screen's unsaved state for as long as it is mounted.
 *
 * @param {boolean} dirty
 * @return {void}
 */
export function useUnsavedGuard(dirty) {
  useEffect(() => {
    unsaved = dirty

    /**
     * @param {Object} event
     * @return {void}
     */
    const onLeave = (event) => {
      if (!unsaved) {
        return
      }

      event.preventDefault()
      // some browsers still want this set, and none of them show what is in it
      event.returnValue = ''
    }

    window.addEventListener('beforeunload', onLeave)

    return () => {
      window.removeEventListener('beforeunload', onLeave)
      unsaved = false
    }
  }, [dirty])
}
