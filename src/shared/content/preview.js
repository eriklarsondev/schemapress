/**
 * Live rendered markup for the build canvas.
 *
 * Each section card shows the real output of the reference renderer rather
 * than a drawing of it, so a hero with a background image looks like that hero
 * while it is being built.
 *
 * The markup is dropped into a shadow root. That is not fussiness: the admin
 * runs a scoped CSS reset that neutralises headings and paragraph margins, and
 * the rendered page depends on both. A shadow root ends the argument - the
 * page's stylesheet is the only one inside it - without the height syncing an
 * iframe per section would need.
 */

import { createContext, useContext, useEffect, useRef, useState } from '@wordpress/element'
import { api } from '../api'

const PreviewContext = createContext({ parts: {}, css: '', ready: false, pause: () => {} })

/**
 * Renders section markup for a page and provides it to the canvas.
 *
 * @param {Object} props
 * @return {JSX.Element} The provider.
 */
export function PreviewProvider({
  postId,
  definition,
  sections,
  enabled = true,
  editing = true,
  children
}) {
  const [state, setState] = useState({ parts: {}, html: '', css: '', ready: false })
  const timer = useRef(null)

  // typing into the rendered page is typing into nodes this component
  // replaces wholesale on every refresh. re-rendering mid-keystroke would
  // destroy the node under the caret, so refreshes are held until the edit
  // ends and the state has caught up
  const [paused, setPaused] = useState(false)

  // the caller builds `definition` inline, so its identity changes every
  // render; keying on the serialized payload requests once per real change
  const payload = JSON.stringify({
    definition,
    content: { version: 1, sections },
    // in the builder, empty fields render as sample content and every field is
    // tagged so it can be clicked into
    editing
  })

  useEffect(() => {
    if (!enabled || !postId || paused) {
      return undefined
    }

    let cancelled = false

    clearTimeout(timer.current)
    timer.current = setTimeout(() => {
      api
        .preview(postId, JSON.parse(payload))
        .then((result) => {
          if (!cancelled) {
            setState({
              parts: result.sections || {},
              html: result.html || '',
              css: result.css || '',
              ready: true
            })
          }
        })
        // a failed render leaves the canvas on its schematic fallback, which
        // is degraded but still editable
        .catch(() => {
          if (!cancelled) {
            setState((current) => ({ ...current, ready: false }))
          }
        })
    }, 400)

    return () => {
      cancelled = true
      clearTimeout(timer.current)
    }
  }, [postId, payload, enabled, paused])

  const value = { ...state, pause: setPaused }

  return <PreviewContext.Provider value={value}>{children}</PreviewContext.Provider>
}

/**
 * The rendered markup for one section, or null when it is not available yet.
 *
 * @param {string} id
 * @return {{html: string, css: string}|null} The markup.
 */
/**
 * The whole page as one rendered document.
 *
 * @return {{html: string, css: string, pause: Function}|null} The page.
 */
export function usePagePreview() {
  const { html, css, ready, pause } = useContext(PreviewContext)

  if (!ready) {
    return null
  }

  return { html, css, pause }
}

export function useSectionPreview(id) {
  const { parts, css, ready, pause } = useContext(PreviewContext)

  if (!ready || !parts[id]) {
    return null
  }

  return { html: parts[id], css, pause }
}

/**
 * Renders untrusted-of-the-admin markup in an isolated shadow root.
 *
 * @param {Object} props
 * @return {JSX.Element} The host element.
 */
export function ShadowRender({ html, css, className, onFieldClick, onFieldEdit, onEditing }) {
  const host = useRef(null)
  const root = useRef(null)
  const handler = useRef(onFieldClick)
  const editHandler = useRef(onFieldEdit)
  const editingHandler = useRef(onEditing)

  // the listeners are attached once; reading the callbacks through refs keeps
  // them current without re-attaching on every render
  handler.current = onFieldClick
  editHandler.current = onFieldEdit
  editingHandler.current = onEditing

  useEffect(() => {
    if (!host.current) {
      return
    }

    if (!root.current) {
      root.current = host.current.attachShadow({ mode: 'open' })
    }

    root.current.innerHTML = `<style>:host{display:block}${css}</style>${html}`
  }, [html, css])

  useEffect(() => {
    const shadow = root.current

    if (!shadow) {
      return undefined
    }

    /**
     * Opens the field that was clicked.
     *
     * Uses composedPath rather than event.target: the target is whatever leaf
     * node was hit, which for a button is the anchor inside the paragraph, not
     * the element carrying the marker.
     *
     * @param {Event} event
     * @return {void}
     */
    const onClick = (event) => {
      if (!handler.current) {
        return
      }

      const marked = event
        .composedPath()
        .find((node) => node.dataset && node.dataset.spField)

      if (!marked) {
        return
      }

      // a rendered page is full of links, and following one would navigate
      // away from the editor mid-edit
      event.preventDefault()

      // a field that can be typed into is being typed into, not opened
      if (marked.dataset.spInline && editHandler.current) {
        return
      }

      handler.current(marked.dataset.spField)
    }

    shadow.addEventListener('click', onClick)

    return () => shadow.removeEventListener('click', onClick)
  }, [html])

  // --- typing directly into the rendered page ------------------------------

  useEffect(() => {
    const shadow = root.current

    if (!shadow || !editHandler.current) {
      return undefined
    }

    const nodes = Array.from(shadow.querySelectorAll('[data-sp-inline]'))

    /**
     * Starts an edit: clears sample content so it is not mistaken for
     * something someone wrote, and holds preview refreshes.
     *
     * @param {Event} event
     * @return {void}
     */
    const onFocus = (event) => {
      editingHandler.current?.(true)

      if (event.target.classList.contains('sp-sample')) {
        event.target.textContent = ''
        event.target.classList.remove('sp-sample')
      }
    }

    /**
     * Reports the new value as it is typed.
     *
     * @param {Event} event
     * @return {void}
     */
    const onInput = (event) => {
      editHandler.current(event.target.dataset.spField, event.target.textContent)
    }

    /**
     * Ends the edit and lets the preview catch up.
     *
     * @return {void}
     */
    const onBlur = () => editingHandler.current?.(false)

    /**
     * Enter commits rather than inserting a line break — these are headings
     * and single paragraphs, not documents.
     *
     * @param {KeyboardEvent} event
     * @return {void}
     */
    const onKeyDown = (event) => {
      if (event.key === 'Enter') {
        event.preventDefault()
        event.target.blur()
      }
    }

    nodes.forEach((node) => {
      // plaintext-only keeps pasted markup out of a value that is escaped on
      // the way back in anyway
      node.setAttribute('contenteditable', 'plaintext-only')
      node.setAttribute('spellcheck', 'true')
      node.addEventListener('focus', onFocus)
      node.addEventListener('input', onInput)
      node.addEventListener('blur', onBlur)
      node.addEventListener('keydown', onKeyDown)
    })

    return () => {
      nodes.forEach((node) => {
        node.removeEventListener('focus', onFocus)
        node.removeEventListener('input', onInput)
        node.removeEventListener('blur', onBlur)
        node.removeEventListener('keydown', onKeyDown)
      })
    }
  }, [html])

  return <div ref={host} className={className} />
}
