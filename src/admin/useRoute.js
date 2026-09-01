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
 * @return {{view: string, id: number|null}} The active route.
 */
function parse() {
  const [view, id] = window.location.hash.replace(/^#\/?/, '').split('/')

  if (!view) {
    return DEFAULT_ROUTE
  }

  return { view, id: id ? Number(id) : null }
}

/**
 * Tracks the active route and exposes a navigator.
 *
 * @return {[{view: string, id: number|null}, Function]} Route and navigate.
 */
export function useRoute() {
  const [route, setRoute] = useState(parse)

  useEffect(() => {
    const onChange = () => setRoute(parse())

    window.addEventListener('hashchange', onChange)

    return () => window.removeEventListener('hashchange', onChange)
  }, [])

  const navigate = useCallback((view, id = null) => {
    window.location.hash = id ? `/${view}/${id}` : `/${view}`
  }, [])

  return [route, navigate]
}
