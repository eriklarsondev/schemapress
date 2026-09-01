/**
 * Shared UI helpers.
 */

import { clsx } from 'clsx'
import { twMerge } from 'tailwind-merge'

/**
 * Merges class names, letting later Tailwind utilities override earlier ones
 * of the same kind rather than both landing in the class list.
 *
 * @param {...*} inputs
 * @return {string} The merged class string.
 */
export function cn(...inputs) {
  return twMerge(clsx(inputs))
}

/**
 * Stacking order for everything that portals out of the page.
 *
 * Kept in one place because the failure is invisible until it happens: a
 * select rendered at the same level as a dialog appears *behind* it, which
 * looks like two overlapping modals rather than a z-index mistake.
 *
 * Transient layers always sit above persistent ones - a dropdown belongs on
 * top of the dialog that opened it, and a confirmation on top of everything,
 * since it is asking about something the layer beneath it is doing.
 */
export const LAYERS = {
  dialogOverlay: 'z-[100000]',
  dialogContent: 'z-[100010]',
  confirmOverlay: 'z-[100020]',
  confirmContent: 'z-[100030]',
  // dropdowns, popovers and selects: above any dialog they were opened from
  transient: 'z-[100100]'
}

let container = null

/**
 * The element Radix portals should render into.
 *
 * Portals escape the React tree and land on document.body, which is outside
 * the `.schemapress` scope every Tailwind utility is prefixed with — so
 * portalled content would render unstyled. Giving Radix a scoped container of
 * our own fixes that once, for every overlay in the app.
 *
 * @return {HTMLElement} A `.schemapress` element attached to the document.
 */
export function portalContainer() {
  if (container && document.body.contains(container)) {
    return container
  }

  container = document.createElement('div')
  container.className = 'schemapress'
  document.body.appendChild(container)

  return container
}
