/**
 * Rich text field backed by the editor WordPress already loads.
 *
 * wp.editor.initialize gives the same TinyMCE the classic editor uses, so
 * formatting behaves exactly as authors expect. If that API is unavailable the
 * control degrades to a plain textarea rather than failing.
 *
 * Two things the classic editor has are deliberately off here. The **media
 * button** inserts an <img> into the markup, which puts an attachment id inside
 * a text field where nothing can find it again — this plugin has an Image field
 * for that, and one whose value the API resolves. The **Visual/Text tabs** are
 * off because the raw HTML is not the field's value: what is stored has been
 * through wp_kses_post, so the markup the Text tab invites somebody to write is
 * not necessarily the markup that comes back.
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
      mediaButtons: false,
      // off, which is also what takes the Visual/Text tabs away: the switcher
      // only appears when there are two modes to switch between
      quicktags: false,
      tinymce: {
        wpautop: true,
        toolbar1: 'formatselect,bold,italic,bullist,numlist,blockquote,link,unlink,undo,redo',
        setup(instance) {
          const push = () => onChangeRef.current(instance.getContent())

          instance.on('change keyup undo redo SetContent', push)
        },
      },
    })

    return () => editor.remove(id)
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
