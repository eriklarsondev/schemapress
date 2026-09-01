/**
 * REST transport for the admin application.
 *
 * Targets the admin namespace, not the public delivery namespace — these
 * routes exist to serve this UI and require an authenticated editor. apiFetch
 * is preconfigured by WordPress with the logged-in nonce, so the bootstrapped
 * root path is all this needs.
 */

import apiFetch from '@wordpress/api-fetch'

const settings = window.SchemaPress || {}
const root = (settings.rest && settings.rest.root) || ''

/**
 * Issues a request against the plugin's admin REST namespace.
 *
 * @param {string} path
 * @param {Object} options
 * @return {Promise<*>} The decoded response body.
 */
function request(path, options = {}) {
  return apiFetch({ url: `${root}${path}`, ...options })
}

export const api = {
  /**
   * Lists every schema with its bindings.
   *
   * @return {Promise<Array>} Schema summaries.
   */
  schemas: () => request('/schemas'),

  /**
   * Loads one schema with its definition and bindings.
   *
   * @param {number} id
   * @return {Promise<Object>} The schema payload.
   */
  schema: (id) => request(`/schemas/${id}`),

  /**
   * Creates an empty schema.
   *
   * @param {string} title
   * @return {Promise<Object>} The created schema.
   */
  createSchema: (title) => request('/schemas', { method: 'POST', data: { title } }),

  /**
   * Persists a schema. Returns the normalized server state, which may differ
   * from what was sent — keys are slugified and deduplicated server-side.
   *
   * @param {number} id
   * @param {Object} data
   * @return {Promise<Object>} The stored schema payload.
   */
  saveSchema: (id, data) => request(`/schemas/${id}`, { method: 'POST', data }),

  /**
   * Trashes a schema.
   *
   * @param {number} id
   * @return {Promise<Object>} The deletion result.
   */
  deleteSchema: (id) => request(`/schemas/${id}`, { method: 'DELETE' }),

  /**
   * Every registered template with its binding and usage count.
   *
   * @return {Promise<Array>} Template descriptors.
   */
  templates: () => request('/templates'),

  /**
   * Replaces the plugin-defined template list.
   *
   * @param {Array} templates
   * @return {Promise<Array>} The stored templates.
   */
  saveTemplates: (templates) =>
    request('/templates', { method: 'POST', data: { templates } }),

  /**
   * Lists pages, bound or not.
   *
   * @param {string} search
   * @return {Promise<Array>} Page summaries.
   */
  pages: (search = '') => request(`/pages?search=${encodeURIComponent(search)}`),

  /**
   * Assigns a template to a page, which is what binds it to a schema.
   *
   * @param {number} postId
   * @param {string} template
   * @return {Promise<Object>} The new binding.
   */
  assignTemplate: (postId, template) =>
    request(`/pages/${postId}/template`, { method: 'POST', data: { template } }),

  /**
   * Assigns a schema straight to a page, bypassing the template. Passing 0
   * clears it so the template's schema applies again.
   *
   * @param {number} postId
   * @param {number} schemaId
   * @return {Promise<Object>} The new binding.
   */
  assignSchema: (postId, schemaId) =>
    request(`/pages/${postId}/schema`, { method: 'POST', data: { schema: schemaId } }),

  /**
   * The site's design tokens.
   *
   * @return {Promise<{tokens: Array}>} The tokens.
   */
  settings: () => request('/settings'),

  /**
   * Stores design tokens. Returns what was kept — a malformed value falls back
   * to its default rather than being stored.
   *
   * @param {Object} tokens
   * @return {Promise<{tokens: Array}>} The stored tokens.
   */
  saveSettings: (tokens) => request('/settings', { method: 'POST', data: { tokens } }),

  /**
   * Renders unsaved content through the reference renderer. Persists nothing.
   *
   * @param {number} postId
   * @param {Object} payload definition and content
   * @return {Promise<{html: string, stylesheet: string}>} The rendered page.
   */
  preview: (postId, payload) =>
    request(`/preview/${postId}`, { method: 'POST', data: payload }),

  /**
   * Everything the guided editor needs for one page: its template, schema,
   * content, and which step to open on.
   *
   * @param {number} postId
   * @return {Promise<Object>} The workflow payload.
   */
  workflow: (postId) => request(`/pages/${postId}/workflow`),

  /**
   * Loads a page's schema definition and its current section content.
   *
   * @param {number} postId
   * @return {Promise<Object>} The content payload.
   */
  content: (postId) => request(`/content/${postId}`),

  /**
   * Persists a page's section content.
   *
   * @param {number} postId
   * @param {Object} content
   * @return {Promise<Object>} The sanitized stored content.
   */
  saveContent: (postId, content) =>
    request(`/content/${postId}`, { method: 'POST', data: { content } }),

  /**
   * Searches posts for the relationship field picker.
   *
   * @param {string} search
   * @param {string} postType
   * @return {Promise<Array>} Matching posts.
   */
  posts: (search, postType = 'page') =>
    request(
      `/posts?search=${encodeURIComponent(search)}&post_type=${encodeURIComponent(postType)}`
    )
}
