/**
 * Work in progress that has not been stored yet.
 *
 * Four ways to walk away from a half-filled screen need different machinery, so
 * the fact that there IS unsaved work is kept in one place:
 *
 *   the Back link      an in-app confirm, because we own the click
 *   the sidebar        the same, from App, which owns navigation
 *   the tab strip      the same, from the view that owns the tabs — a Radix
 *                      panel unmounts when you leave it, so a layout half
 *                      rearranged on the Form tab is gone by the time anything
 *                      downstream could ask about it
 *   the tab or reload  beforeunload, whose dialog cannot be styled or skipped
 *
 * Module level rather than context: it is read from event handlers and from a
 * window listener, neither of which is inside the React tree.
 */

import { useEffect, useId } from '@wordpress/element'
import { __ } from '@wordpress/i18n'

/**
 * Held inside a tab panel, which unmounts when you leave it — so changing tabs
 * loses it, and the tab strip has to ask.
 */
export const PANEL = 'panel'

/**
 * Held by the view around the tabs. Changing tabs does not touch it; navigating
 * away or reloading does.
 */
export const SCREEN = 'screen'

/**
 * Every screen holding work that is not stored, by the id React gave it: what
 * it would lose, and how far that work reaches.
 *
 * A map and not one flag: a view and a tab inside it can both be mounted and
 * both be holding something — a component's name and its layout — and effects
 * run child-first, so a single flag would end up with the parent's answer
 * whatever the child had to say. One of them saying "nothing to lose" when
 * there is is exactly the bug a guard exists to prevent.
 */
const holding = new Map()

/**
 * The first registration matching a scope, innermost first — effects run
 * child-first and a Map keeps what it was given in order, so the tab you are on
 * answers before the view around it.
 *
 * @param {string} scope PANEL, SCREEN, or empty for either.
 * @return {Object|null} The registration.
 */
function first(scope) {
  for (const one of holding.values()) {
    if (!scope || one.scope === scope) {
      return one
    }
  }

  return null
}

/**
 * Whether anything would be lost by navigating away now.
 *
 * @return {boolean} True when there is unsaved work.
 */
export function hasUnsaved() {
  return holding.size > 0
}

/**
 * Whether anything would be lost by changing tabs now — which is less than the
 * above, because a view's own draft outlives its tab strip.
 *
 * @return {boolean} True when a tab holds unsaved work.
 */
export function hasUnsavedPanel() {
  return Boolean(first(PANEL))
}

/**
 * What would be lost, for the confirm that asks about it.
 *
 * @param {string} scope PANEL when it is a tab change asking; empty otherwise.
 * @return {string} A sentence.
 */
export function unsavedMessage(scope = '') {
  return (
    first(scope)?.message ||
    __('This screen has changes that have not been saved. Leaving loses them.', 'schemapress')
  )
}

/**
 * Called explicitly rather than left to the next render: a screen that saves and
 * then navigates does both in one go, and this has to be down before the
 * navigation asks about it.
 *
 * @return {void}
 */
export function clearUnsaved() {
  holding.clear()
}

/**
 * Registers a screen's unsaved state for as long as it is mounted.
 *
 * @param {boolean} dirty
 * @param {string}  message What would be lost, for the confirm dialog.
 * @param {string}  scope   PANEL or SCREEN — see both.
 * @return {void}
 */
export function useUnsavedGuard(dirty, message = '', scope = SCREEN) {
  const id = useId()

  useEffect(() => {
    if (dirty) {
      holding.set(id, { message, scope })
    } else {
      holding.delete(id)
    }

    /**
     * @param {Object} event
     * @return {void}
     */
    const onLeave = (event) => {
      if (!hasUnsaved()) {
        return
      }

      event.preventDefault()
      // some browsers still want this set, and none of them show what is in it
      event.returnValue = ''
    }

    window.addEventListener('beforeunload', onLeave)

    return () => {
      window.removeEventListener('beforeunload', onLeave)
      holding.delete(id)
    }
  }, [dirty, message, scope, id])
}
