/**
 * The list of collections, shared.
 *
 * Several controls need to know what collections exist — a relation field's
 * settings, and the relation control itself. Each fetching for itself would
 * mean one request per field on a form, so the promise is cached at module
 * level and every caller awaits the same one.
 *
 * It is deliberately not invalidated. Collections change on a screen you are
 * not on while filling in an entry, and a stale-by-seconds list is a far
 * smaller problem than a form that refetches under the cursor.
 */

import { useEffect, useState } from '@wordpress/element'
import { api } from './api'

let pending = null

/**
 * Fetches the collections once per page load.
 *
 * @return {Promise<Array>} The collections.
 */
export function loadCollections() {
  if (!pending) {
    pending = api
      .types()
      .then((result) => result.types || [])
      .catch(() => [])
  }

  return pending
}

/**
 * Forgets the cached list, so the next read refetches. Called after a type is
 * created or deleted.
 *
 * @return {void}
 */
export function forgetCollections() {
  pending = null
}

/**
 * The collections, as state.
 *
 * @return {Array} The collections, empty until loaded.
 */
export function useCollections() {
  const [collections, setCollections] = useState([])

  useEffect(() => {
    let live = true

    loadCollections().then((result) => live && setCollections(result))

    return () => {
      live = false
    }
  }, [])

  return collections
}
