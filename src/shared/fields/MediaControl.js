/**
 * Image and file selection through the core media modal.
 *
 * Only the attachment id is stored; everything else (sizes, alt text, mime)
 * stays in the media library, where it can change without invalidating page
 * content.
 */

import { useState, useEffect } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { ImagePlus, Paperclip, X } from 'lucide-react'
import { Field, Button } from '../../ui'

/**
 * Opens the media modal and resolves with the chosen attachment.
 *
 * @param {Object}   options
 * @param {Function} onSelect
 * @return {void}
 */
function openMediaModal({ title, type }, onSelect) {
  const frame = window.wp.media({
    title,
    library: type ? { type } : {},
    multiple: false,
    button: { text: __('Use this file', 'schemapress') }
  })

  frame.on('select', () => onSelect(frame.state().get('selection').first().toJSON()))
  frame.open()
}

/**
 * Loads an attachment's display data from the media library cache.
 *
 * @param {number|null} id
 * @return {Object|null} The attachment, or null while loading or unset.
 */
function useAttachment(id) {
  const [attachment, setAttachment] = useState(null)

  useEffect(() => {
    if (!id) {
      setAttachment(null)
      return
    }

    const model = window.wp.media.attachment(id)

    // fetch() resolves from cache when the attachment is already known
    model.fetch().then(() => setAttachment(model.toJSON()))
  }, [id])

  return attachment
}

/**
 * Image picker with a thumbnail preview.
 *
 * @param {Object} props
 * @return {JSX.Element} The control.
 */
export function ImageField({ field, value, onChange }) {
  const attachment = useAttachment(value)
  const thumbnail = attachment?.sizes?.medium?.url || attachment?.url

  const select = () =>
    openMediaModal({ title: field.label, type: 'image' }, (next) => onChange(next.id))

  return (
    <Field label={field.label} help={field.help} required={field.required}>
      {value ? (
        <div className="group relative w-full overflow-hidden rounded-lg border border-border">
          {/* the same width the empty picker takes, which is the width the cell
              was given on the Layout tab. w-fit here meant a chosen image shrank
              the control back to the picture's own size, so a field set to full
              width stopped being full width the moment it had a value in it.
              object-cover fills that width without stretching the picture — it
              crops, which a preview can afford and a distorted face cannot */}
          <img
            src={thumbnail}
            alt={attachment?.alt || ''}
            className="block h-32 w-full object-cover"
          />
          <div className="absolute inset-x-0 bottom-0 flex gap-1 bg-gradient-to-t from-black/70 to-transparent p-2 opacity-0 transition-opacity group-hover:opacity-100">
            <Button size="sm" variant="secondary" onClick={select}>
              {__('Replace', 'schemapress')}
            </Button>
            <Button
              size="icon-sm"
              variant="secondary"
              aria-label={__('Remove image', 'schemapress')}
              onClick={() => onChange(null)}
            >
              <X />
            </Button>
          </div>
        </div>
      ) : (
        <button
          type="button"
          onClick={select}
          // no max width: the cell this sits in is already exactly as wide as
          // the field was set to be on the Layout tab, so capping it here
          // silently overrode that — a field set to full width drew a third of
          // one, and nothing on the layout screen explained why
          className="flex h-32 w-full flex-col items-center justify-center gap-1.5 rounded-lg border border-dashed border-border bg-muted/40 text-muted-foreground transition-colors hover:border-ring/40 hover:bg-muted"
        >
          <ImagePlus className="size-5" />
          <span className="text-[13px] font-medium">
            {__('Select image', 'schemapress')}
          </span>
        </button>
      )}
    </Field>
  )
}

/**
 * Generic attachment picker showing the file name.
 *
 * @param {Object} props
 * @return {JSX.Element} The control.
 */
export function FileField({ field, value, onChange }) {
  const attachment = useAttachment(value)

  return (
    <Field label={field.label} help={field.help} required={field.required}>
      <div className="flex items-center gap-2 rounded-md border border-border px-3 py-2">
        <Paperclip className="size-3.5 shrink-0 text-muted-foreground" />
        <span className="min-w-0 flex-1 truncate text-[13px] text-muted-foreground">
          {attachment?.filename || __('No file selected', 'schemapress')}
        </span>
        <Button
          size="sm"
          variant="outline"
          onClick={() =>
            openMediaModal({ title: field.label }, (next) => onChange(next.id))
          }
        >
          {value ? __('Replace', 'schemapress') : __('Select', 'schemapress')}
        </Button>
        {value ? (
          <Button
            size="icon-sm"
            variant="destructive-ghost"
            aria-label={__('Remove file', 'schemapress')}
            onClick={() => onChange(null)}
          >
            <X />
          </Button>
        ) : null}
      </div>
    </Field>
  )
}
