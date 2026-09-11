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
 * Transient layers always sit above persistent ones: a dropdown belongs on top of
 * the dialog that opened it, and a confirmation on top of everything. The failure
 * is invisible until it happens — a select at the same level as a dialog appears
 * behind it, which reads as two overlapping modals rather than a z-index mistake.
 */
export const LAYERS = {
  dialogOverlay: 'z-[100000]',
  dialogContent: 'z-[100010]',
  confirmOverlay: 'z-[100020]',
  confirmContent: 'z-[100030]',
  // dropdowns, popovers and selects: above any dialog they were opened from
  transient: 'z-[100100]',
}

let container = null

/**
 * Portals escape the React tree and land on document.body, outside the
 * `.schemapress` scope every Tailwind utility is prefixed with — so portalled
 * content would render unstyled without a scoped container of our own.
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
