/**
 * The admin transport.
 *
 * One thin wrapper over the plugin's REST namespace. Every call returns a
 * promise of parsed JSON, or rejects with an Error carrying the server's
 * message — screens show that message rather than inventing their own, because
 * the server is the only thing that knows what actually went wrong.
 */

import apiFetch from '@wordpress/api-fetch'
import { settings } from './settings'

const root = settings.rest?.root || ''
const nonce = settings.rest?.nonce || ''

/**
 * Performs one request.
 *
 * @param {string} path
 * @param {Object} options method, data
 * @return {Promise<*>} The parsed response.
 */
function request(path, { method = 'GET', data } = {}) {
  return apiFetch({
    url: `${root}${path}`,
    method,
    data,
    headers: nonce ? { 'X-WP-Nonce': nonce } : {},
  }).catch((error) => {
    const failure = new Error(error?.message || 'Request failed')

    // the message is what a screen shows, and for most failures it is all a
    // screen needs. a few have to be TOLD APART rather than only read out — a
    // 409 offers to reload, a validation error can mark up the offending
    // controls — and throwing a bare Error discarded everything but the
    // sentence. carried on the Error rather than thrown separately, so every
    // existing `.catch((failure) => setError(failure.message))` is unaffected
    failure.code = error?.code || ''
    failure.data = error?.data || {}

    throw failure
  })
}

/**
 * Builds a query string from defined values only.
 *
 * @param {Object} args
 * @return {string} The query string, with leading ? or empty.
 */
function query(args = {}) {
  const pairs = Object.entries(args).filter(
    ([, value]) => value !== '' && value !== undefined && value !== null
  )

  return pairs.length ? `?${new URLSearchParams(pairs).toString()}` : ''
}

export const api = {
  /**
   * Every content type, for the sidebar.
   *
   * @return {Promise<{types: Array}>} The types.
   */
  types: () => request('/types'),

  /**
   * One content type with its definition.
   *
   * @param {number} id
   * @return {Promise<{type: Object, definition: Object, types: Array}>} The type.
   */
  type: (id) => request(`/types/${id}`),

  /**
   * Creates a content type.
   *
   * @param {string} title
   * @param {string} description
   * @return {Promise<{type: Object, definition: Object, types: Array}>} The new type.
   */
  createType: (title, description = '') =>
    request('/types', { method: 'POST', data: { title, description } }),

  /**
   * Renames a type, rewrites its description, replaces its definition, or any
   * combination. A description sent as an empty string clears it.
   *
   * @param {number} id
   * @param {Object} data title, description and/or definition
   * @return {Promise<{type: Object, definition: Object, types: Array}>} The stored type.
   */
  updateType: (id, data) => request(`/types/${id}`, { method: 'POST', data }),

  /**
   * Stores the site's master switch and every collection's own pair.
   *
   * One request, because the Settings screen is one form: { restApi, collections }
   * where collections is keyed by type id.
   *
   * @param {Object} settings
   * @return {Promise<{settings: Object, types: Array}>} What was stored.
   */
  saveSettings: (settings) => request('/settings', { method: 'POST', data: settings }),

  /**
   * Every component, for the sidebar and the field picker.
   *
   * @return {Promise<{components: Array}>} The components.
   */
  components: () => request('/components'),

  /**
   * One component, with the fields it holds.
   *
   * @param {number} id
   * @return {Promise<{component: Object}>} The component.
   */
  component: (id) => request(`/components/${id}`),

  /**
   * Creates a component.
   *
   * @param {string} title
   * @param {string} description
   * @return {Promise<{component: Object, components: Array}>} The new one.
   */
  createComponent: (title, description = '') =>
    request('/components', { method: 'POST', data: { title, description } }),

  /**
   * Renames a component, rewrites its description, or replaces its fields.
   *
   * @param {number} id
   * @param {Object} data title, description and/or fields
   * @return {Promise<{component: Object, components: Array}>} The stored one.
   */
  updateComponent: (id, data) => request(`/components/${id}`, { method: 'POST', data }),

  /**
   * Deletes a component. Collections that imported it keep their copy.
   *
   * @param {number} id
   * @return {Promise<{deleted: boolean, components: Array}>} What remains.
   */
  deleteComponent: (id) => request(`/components/${id}`, { method: 'DELETE' }),

  /**
   * Deletes a type and every entry in it.
   *
   * @param {number} id
   * @return {Promise<{deleted: boolean, types: Array}>} What remains.
   */
  deleteType: (id) => request(`/types/${id}`, { method: 'DELETE' }),

  /**
   * A page of a collection's entries, with the definition its columns and form
   * are built from.
   *
   * @param {number} id
   * @param {Object} args page, perPage, search, orderby, order
   * @return {Promise<{entries: Array, total: number, pages: number, definition: Object}>} The page.
   */
  entries: (id, args = {}) => request(`/types/${id}/entries${query(args)}`),

  /**
   * One entry, with the definition it was saved against.
   *
   * @param {number} id
   * @param {number} entryId
   * @return {Promise<{entry: Object, definition: Object}>} The entry.
   */
  entry: (id, entryId) => request(`/types/${id}/entries/${entryId}`),

  /**
   * Creates or updates an entry. A null entryId creates.
   *
   * @param {number}      id
   * @param {number|null} entryId
   * @param {Object}      data    title, status, values
   * @return {Promise<{entry: Object}>} The stored entry.
   */
  saveEntry: (id, entryId, data) =>
    request(entryId ? `/types/${id}/entries/${entryId}` : `/types/${id}/entries`, {
      method: 'POST',
      data,
    }),

  /**
   * Moves the published copy up to the draft.
   *
   * @param {number} id
   * @param {number} entryId
   * @return {Promise<{entry: Object}>} The entry.
   */
  publishEntry: (id, entryId) =>
    request(`/types/${id}/entries/${entryId}/publish`, { method: 'POST' }),

  /**
   * Takes an entry off the front end, keeping its work.
   *
   * @param {number} id
   * @param {number} entryId
   * @return {Promise<{entry: Object}>} The entry.
   */
  unpublishEntry: (id, entryId) =>
    request(`/types/${id}/entries/${entryId}/unpublish`, { method: 'POST' }),

  /**
   * Throws the draft away, returning to what is published.
   *
   * @param {number} id
   * @param {number} entryId
   * @return {Promise<{entry: Object}>} The entry.
   */
  discardDraft: (id, entryId) =>
    request(`/types/${id}/entries/${entryId}/discard`, { method: 'POST' }),

  /**
   * Copies an entry. The copy is always a draft, whatever the original was.
   *
   * @param {number} id
   * @param {number} entryId
   * @return {Promise<{entry: Object}>} The copy.
   */
  duplicateEntry: (id, entryId) =>
    request(`/types/${id}/entries/${entryId}/duplicate`, { method: 'POST' }),

  /**
   * Trashes an entry.
   *
   * @param {number} id
   * @param {number} entryId
   * @return {Promise<{deleted: boolean}>} Whether it went.
   */
  deleteEntry: (id, entryId) => request(`/types/${id}/entries/${entryId}`, { method: 'DELETE' }),

  /**
   * One action over several entries.
   *
   * The response is per entry — `succeeded` and `failed` — because a bulk
   * publish where one entry has a required field empty should publish the rest
   * and say which one it could not.
   *
   * @param {number}   id
   * @param {string}   action  publish, unpublish, discard, duplicate, delete, restore
   * @param {string[]} entries
   * @return {Promise<{succeeded: Array, failed: Array}>} What happened to each.
   */
  bulk: (id, action, entries) =>
    request(`/types/${id}/bulk`, { method: 'POST', data: { action, entries } }),

  /**
   * A page of a collection's trashed entries.
   *
   * @param {number} id
   * @param {Object} args page, perPage
   * @return {Promise<{entries: Array, total: number, pages: number}>} The page.
   */
  trash: (id, args = {}) => request(`/types/${id}/trash${query(args)}`),

  /**
   * Brings an entry back from the trash, to the state it was in.
   *
   * @param {number} id
   * @param {string} entryId
   * @return {Promise<{entry: Object}>} The entry.
   */
  restoreEntry: (id, entryId) => request(`/types/${id}/trash/${entryId}`, { method: 'POST' }),

  /**
   * Erases one trashed entry, permanently.
   *
   * @param {number} id
   * @param {string} entryId
   * @return {Promise<{deleted: boolean}>} Whether it went.
   */
  purgeEntry: (id, entryId) => request(`/types/${id}/trash/${entryId}`, { method: 'DELETE' }),

  /**
   * Erases everything in a collection's trash.
   *
   * @param {number} id
   * @return {Promise<{erased: number}>} How many went.
   */
  emptyTrash: (id) => request(`/types/${id}/trash`, { method: 'DELETE' }),

  /**
   * The site's collections as a portable document.
   *
   * @param {Object} args types (comma-separated ids), entries (1 to include)
   * @return {Promise<{filename: string, export: Object}>} The document.
   */
  export: (args = {}) => request(`/export${query(args)}`),

  /**
   * Reads a portable document back in.
   *
   * @param {Object}  payload the parsed document
   * @param {Object}  options mode, entries
   * @return {Promise<{report: Object, types: Array}>} What happened.
   */
  import: (payload, options = {}) =>
    request('/import', { method: 'POST', data: { export: payload, ...options } }),

  /**
   * Long-running work still in flight.
   *
   * @return {Promise<{jobs: Array}>} The queue.
   */
  jobs: () => request('/jobs'),
}
