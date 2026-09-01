/**
 * Rich text field backed by the editor WordPress already loads.
 *
 * wp.editor.initialize gives the same TinyMCE + Quicktags pair the classic
 * editor uses, so formatting, the media button and shortcodes behave exactly
 * as authors expect. If that API is unavailable the control degrades to a
 * plain textarea rather than failing.
 */

import { useEffect, useRef, useState } from '@wordpress/element'
import { Field, Textarea } from '../../ui'
import { nodeId } from '../utils'

/**
 * TinyMCE-backed rich text control.
 *
 * @param {Object} props
 * @return {JSX.Element} The control.
 */
export function RichTextField({ field, value, onChange }) {
  const [id] = useState(() => `schemapress-rte-${nodeId('e')}`)
  const [supported] = useState(() => Boolean(window.wp?.editor?.initialize))
  const onChangeRef = useRef(onChange)

  // the editor is initialized once; reading the callback through a ref keeps
  // it current without tearing TinyMCE down on every parent render
  onChangeRef.current = onChange

  useEffect(() => {
    if (!supported) {
      return undefined
    }

    const { editor } = window.wp

    editor.initialize(id, {
      mediaButtons: true,
      quicktags: true,
      tinymce: {
        wpautop: true,
        toolbar1: 'formatselect,bold,italic,bullist,numlist,blockquote,link,unlink,undo,redo',
        setup(instance) {
          const push = () => onChangeRef.current(instance.getContent())

          instance.on('change keyup undo redo SetContent', push)
        }
      }
    })

    // the text tab writes straight to the textarea, bypassing TinyMCE events
    const textarea = document.getElementById(id)
    const onInput = (event) => onChangeRef.current(event.target.value)
    textarea?.addEventListener('input', onInput)

    return () => {
      textarea?.removeEventListener('input', onInput)
      editor.remove(id)
    }
  }, [id, supported])

  if (!supported) {
    return (
      <Field label={field.label} help={field.help} required={field.required}>
        {(fieldId) => (
          <Textarea
            id={fieldId}
            rows={6}
            value={value ?? ''}
            onChange={(event) => onChange(event.target.value)}
          />
        )}
      </Field>
    )
  }

  return (
    <Field label={field.label} help={field.help} required={field.required}>
      <div className="schemapress-rte">
        <textarea id={id} defaultValue={value ?? ''} rows={8} />
      </div>
    </Field>
  )
}
