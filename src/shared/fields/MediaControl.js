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
    button: { text: __('Use this file', 'schemapress') },
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
 * Image picker, showing the whole picture.
 *
 * A chosen image is drawn at the full width of the cell it sits in and at its
 * own height — all of it, never cropped. A picture is chosen for what is in it,
 * and a strip cut across the middle of a portrait said nothing about whether it
 * was the right one. That is also what the sidebar is for: an image there is a
 * column wide, where the full width of the form would make it a poster.
 *
 * @param {Object} props
 * @return {JSX.Element} The control.
 */
export function ImageField({ field, value, onChange }) {
  const attachment = useAttachment(value)
  // big enough for the widest cell the form draws. medium is 300px at most, and
  // a full-width cell stretched it into a blur
  const size = attachment?.sizes?.large || attachment?.sizes?.full || null
  const source = size?.url || attachment?.url

  const select = () =>
    openMediaModal({ title: field.label, type: 'image' }, (next) => onChange(next.id))

  return (
    <Field label={field.label} help={field.help} required={field.required}>
      {value ? (
        <div className="group relative w-full overflow-hidden rounded-lg border border-border bg-muted/40">
          {/* the same width the empty picker takes, which is the width the cell
              was given on the Form tab. w-fit here meant a chosen image shrank
              the control back to the picture's own size, so a field set to full
              width stopped being full width the moment it had a value in it */}
          {source ? (
            <img
              src={source}
              alt={attachment?.alt || ''}
              // its own proportions, so the space is the right shape while the
              // file loads instead of jumping open when it arrives
              width={size?.width || attachment?.width}
              height={size?.height || attachment?.height}
              className="block h-auto w-full"
            />
          ) : (
            // the attachment is still being looked up
            <div className="h-32 w-full" />
          )}

          {/* on focus as well as hover: tabbing onto Replace should not land on
              a button nobody can see */}
          <div className="absolute inset-x-0 bottom-0 flex gap-1 bg-gradient-to-t from-black/70 to-transparent p-2 opacity-0 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100">
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
          <span className="text-[13px] font-medium">{__('Select image', 'schemapress')}</span>
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
          onClick={() => openMediaModal({ title: field.label }, (next) => onChange(next.id))}
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
