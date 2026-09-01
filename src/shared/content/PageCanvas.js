/**
 * The page, rendered, as the thing you edit.
 *
 * Not a list of cards standing in for a page - the actual output, with every
 * section in one document so spacing, backgrounds and full-bleed widths behave
 * as they will on the site. Selection and editing happen on top of it.
 *
 * Clicking a text field types into it. Clicking anything else selects its
 * section, which the panel beside the canvas then edits.
 */

import { useEffect, useRef } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { Loading } from '../../ui'
import { usePagePreview } from './preview'

/**
 * Chrome injected into the canvas document: hover and selection outlines, and
 * the small label that names the section under the pointer.
 *
 * Kept here rather than in render.css because none of it belongs on a
 * delivered page - it exists only inside the editor's shadow root.
 */
const CHROME = `
  [data-sp-section] { position: relative; }
  [data-sp-section]::after {
    content: ''; position: absolute; inset: 0; pointer-events: none;
    outline: 2px solid transparent; outline-offset: -2px;
    transition: outline-color .12s ease;
  }
  [data-sp-section]:hover::after { outline-color: rgb(59 130 246 / .45); }
  [data-sp-section].is-selected::after { outline-color: rgb(59 130 246); }
  [data-sp-section]::before {
    content: attr(data-sp-label);
    position: absolute; top: 0; left: 0; z-index: 2;
    padding: 2px 8px; border-radius: 0 0 4px 0;
    background: rgb(59 130 246); color: #fff;
    font: 600 11px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    opacity: 0; transition: opacity .12s ease; pointer-events: none;
  }
  [data-sp-section]:hover::before,
  [data-sp-section].is-selected::before { opacity: 1; }
  [data-sp-inline]:focus { outline: 2px solid rgb(59 130 246); outline-offset: 3px; }
`

/**
 * Live page canvas.
 *
 * @param {Object} props
 * @return {JSX.Element} The canvas.
 */
export function PageCanvas({ selectedId, onSelect, onFieldEdit, onFieldOpen }) {
  const page = usePagePreview()
  const host = useRef(null)
  const root = useRef(null)
  const callbacks = useRef({})

  callbacks.current = { onSelect, onFieldEdit, onFieldOpen }

  useEffect(() => {
    if (!host.current || !page) {
      return
    }

    if (!root.current) {
      root.current = host.current.attachShadow({ mode: 'open' })
    }

    root.current.innerHTML =
      `<style>:host{display:block}${page.css}${CHROME}</style>${page.html}`
  }, [page])

  // --- selection -----------------------------------------------------------

  useEffect(() => {
    const shadow = root.current

    if (!shadow) {
      return undefined
    }

    /**
     * Routes a click to the right thing: a typable field takes the caret, a
     * field that needs a control opens it, anything else selects the section.
     *
     * @param {MouseEvent} event
     * @return {void}
     */
    const onClick = (event) => {
      const path = event.composedPath()

      // a rendered page is full of links; following one would leave the editor
      event.preventDefault()

      const field = path.find((node) => node.dataset && node.dataset.spField)

      if (field && field.dataset.spInline) {
        return
      }

      const section = path.find((node) => node.dataset && node.dataset.spSection)

      if (section) {
        callbacks.current.onSelect(section.dataset.spSection)
      }

      if (field) {
        callbacks.current.onFieldOpen?.(field.dataset.spField)
      }
    }

    shadow.addEventListener('click', onClick)

    return () => shadow.removeEventListener('click', onClick)
  }, [page])

  // marking selection in the DOM rather than re-rendering keeps the caret and
  // scroll position intact while you work
  useEffect(() => {
    const shadow = root.current

    if (!shadow) {
      return
    }

    shadow.querySelectorAll('[data-sp-section]').forEach((node) => {
      node.classList.toggle('is-selected', node.dataset.spSection === selectedId)
    })
  }, [selectedId, page])

  // --- typing into the page ------------------------------------------------

  useEffect(() => {
    const shadow = root.current

    if (!shadow) {
      return undefined
    }

    const nodes = Array.from(shadow.querySelectorAll('[data-sp-inline]'))

    const onFocus = (event) => {
      page?.pause(true)

      // sample text is a prompt, not a value; it should not become one
      if (event.target.classList.contains('sp-sample')) {
        event.target.textContent = ''
        event.target.classList.remove('sp-sample')
      }

      const section = event.target.closest('[data-sp-section]')

      if (section) {
        callbacks.current.onSelect(section.dataset.spSection)
      }
    }

    const onInput = (event) => {
      const section = event.target.closest('[data-sp-section]')

      callbacks.current.onFieldEdit(
        section?.dataset.spSection,
        event.target.dataset.spField,
        event.target.textContent
      )
    }

    const onBlur = () => page?.pause(false)

    const onKeyDown = (event) => {
      if (event.key === 'Enter') {
        event.preventDefault()
        event.target.blur()
      }
    }

    nodes.forEach((node) => {
      node.setAttribute('contenteditable', 'plaintext-only')
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
  }, [page])

  if (!page) {
    return <Loading label={__('Rendering the page…', 'schemapress')} />
  }

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-white">
      <div ref={host} />
    </div>
  )
}
