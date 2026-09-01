/**
 * Debounced autosave.
 *
 * The debounce is the easy half. The hard half is that edits keep arriving
 * while a save is in flight, and firing a second request on top of the first
 * lets them land out of order - the older payload wins, and the author watches
 * their last few keystrokes disappear. So a save that arrives during one marks
 * the state dirty instead, and runs once the first has returned.
 */

import { useState, useEffect, useRef, useCallback } from '@wordpress/element'

/**
 * Saves whenever the payload changes and settles.
 *
 * @param {Object}   options
 * @param {string}   options.payload  serialized state; a change triggers a save
 * @param {Function} options.save     receives the payload, returns a promise
 * @param {boolean}  options.enabled
 * @param {number}   options.delay
 * @return {{status: string, error: string|null, saveNow: Function}} The state.
 */
export function useAutosave({ payload, save, enabled = true, delay = 1200 }) {
  const [status, setStatus] = useState('saved')
  const [error, setError] = useState(null)

  const timer = useRef(null)
  const inFlight = useRef(false)
  const pending = useRef(null)
  const saved = useRef(payload)
  const saveRef = useRef(save)

  saveRef.current = save

  const run = useCallback(async (next) => {
    if (inFlight.current) {
      // a save is already going; remember this one and let it finish
      pending.current = next

      return
    }

    inFlight.current = true
    setStatus('saving')
    setError(null)

    try {
      await saveRef.current(next)

      saved.current = next
      setStatus('saved')
    } catch (exception) {
      setStatus('error')
      setError(exception.message || 'Could not save.')
    } finally {
      inFlight.current = false

      const queued = pending.current
      pending.current = null

      // anything that arrived mid-flight goes now, in order
      if (queued && queued !== saved.current) {
        run(queued)
      }
    }
  }, [])

  useEffect(() => {
    if (!enabled || payload === saved.current) {
      return undefined
    }

    setStatus('dirty')

    clearTimeout(timer.current)
    timer.current = setTimeout(() => run(payload), delay)

    return () => clearTimeout(timer.current)
  }, [payload, enabled, delay, run])

  // an unsaved change must not leave with the tab
  useEffect(() => {
    if (status === 'saved') {
      return undefined
    }

    const warn = (event) => {
      event.preventDefault()
      event.returnValue = ''
    }

    window.addEventListener('beforeunload', warn)

    return () => window.removeEventListener('beforeunload', warn)
  }, [status])

  /**
   * Saves immediately, skipping the debounce.
   *
   * @return {void}
   */
  const saveNow = useCallback(() => {
    clearTimeout(timer.current)
    run(payload)
  }, [payload, run])

  return { status, error, saveNow }
}
