/**
 * Minimal data-loading hook.
 *
 * The app's requests are few and independent, so a full data layer would cost
 * more than it saves. This covers the three states every view needs and gives
 * back a reload for after a mutation.
 */

import { useState, useEffect, useCallback } from '@wordpress/element'

/**
 * Runs a loader and tracks its lifecycle.
 *
 * @param {Function} loader   returns a promise
 * @param {Array}    deps     re-runs the loader when these change
 * @return {{data: *, error: string|null, loading: boolean, reload: Function, setData: Function}}
 *   The request state.
 */
export function useAsync(loader, deps = []) {
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)
  const [loading, setLoading] = useState(true)
  const [nonce, setNonce] = useState(0)

  const reload = useCallback(() => setNonce((value) => value + 1), [])

  useEffect(() => {
    let cancelled = false

    setLoading(true)
    setError(null)

    loader()
      .then((result) => {
        if (!cancelled) {
          setData(result)
        }
      })
      .catch((exception) => {
        if (!cancelled) {
          setError(exception.message || 'Request failed.')
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLoading(false)
        }
      })

    return () => {
      cancelled = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, nonce])

  return { data, error, loading, reload, setData }
}
