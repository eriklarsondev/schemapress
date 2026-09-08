/**
 * Hash-based routing.
 *
 * The admin page has one PHP entry point, so routes live in the fragment.
 * That keeps deep links and the browser's back button working without a
 * router dependency or any server-side rewrite.
 */

import { useState, useEffect, useCallback } from '@wordpress/element'

const DEFAULT_ROUTE = { view: 'pages', id: null }

/**
 * Parses the current fragment into a route.
 *
 * The id is left as written. A collection is identified by a post id and a
 * documentation page by its slug, so coercing to a number here turned every
 * `#/docs/reading-content` into `#/docs/NaN`. The screens that want a number
 * ask for one; this only reports what the fragment says.
 *
 * @return {{view: string, id: string|null}} The active route.
 */
function parse() {
  const [view, id] = window.location.hash.replace(/^#\/?/, '').split('/')

  if (!view) {
    return DEFAULT_ROUTE
  }

  return { view, id: id || null }
}

/**
 * Tracks the active route and exposes a navigator.
 *
 * @return {[{view: string, id: string|null}, Function]} Route and navigate.
 */
export function useRoute() {
  const [route, setRoute] = useState(parse)

  useEffect(() => {
    const onChange = () => setRoute(parse())

    window.addEventListener('hashchange', onChange)

    return () => window.removeEventListener('hashchange', onChange)
  }, [])

  /**
   * Goes to a route.
   *
   * `replace` swaps the current history entry rather than adding one, which is
   * what a redirect the reader did not ask for should do — otherwise Back
   * returns to a route that immediately redirects again.
   */
  const navigate = useCallback((view, id = null, replace = false) => {
    const hash = id ? `/${view}/${id}` : `/${view}`

    if (replace) {
      window.location.replace(`#${hash}`)

      return
    }

    window.location.hash = hash
  }, [])

  return [route, navigate]
}
