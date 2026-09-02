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
    headers: nonce ? { 'X-WP-Nonce': nonce } : {}
  }).catch((error) => {
    throw new Error(error?.message || 'Request failed')
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
   * @return {Promise<{type: Object, definition: Object, types: Array}>} The new type.
   */
  createType: (title) => request('/types', { method: 'POST', data: { title } }),

  /**
   * Renames a type, replaces its definition, or both.
   *
   * @param {number} id
   * @param {Object} data title and/or definition
   * @return {Promise<{type: Object, definition: Object, types: Array}>} The stored type.
   */
  updateType: (id, data) => request(`/types/${id}`, { method: 'POST', data }),

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
      data
    }),

  /**
   * Trashes an entry.
   *
   * @param {number} id
   * @param {number} entryId
   * @return {Promise<{deleted: boolean}>} Whether it went.
   */
  deleteEntry: (id, entryId) =>
    request(`/types/${id}/entries/${entryId}`, { method: 'DELETE' }),

  /**
   * Published posts, for the post relationship field's picker.
   *
   * @param {Object} args types, search
   * @return {Promise<Array>} Matching posts.
   */
  posts: (args = {}) => request(`/posts${query(args)}`)
}
