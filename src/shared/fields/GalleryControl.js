/**
 * Many images, in a stated order.
 *
 * Its own control rather than a `multiple` flag on the image one, mirroring the
 * split on the PHP side: what a field IS changes what can be done with it. One
 * image is a thing you replace; a gallery is a list you add to, take from and
 * reorder, and the order is content — it is the sequence a slideshow plays in.
 *
 * Only attachment ids are stored. Everything else stays in the media library
 * where it can change without touching the entry.
 */

import { useState, useEffect } from '@wordpress/element'
import { __, sprintf, _n } from '@wordpress/i18n'
import { ImagePlus, X, ArrowLeft, ArrowRight } from 'lucide-react'
import { Field, Button, cn } from '../../ui'

/**
 * Opens the media modal with the current selection already ticked.
 *
 * Pre-selecting matters more here than for a single image: the modal is how you
 * REMOVE one as well as add one, and opening it with nothing ticked would make
 * every visit start the gallery again from empty.
 *
 * @param {Object}   options
 * @param {number[]} chosen
 * @param {Function} onSelect
 * @return {void}
 */
function openGalleryModal({ title }, chosen, onSelect) {
  const frame = window.wp.media({
    title,
    library: { type: 'image' },
    multiple: 'add',
    button: { text: __('Use these images', 'schemapress') },
  })

  frame.on('open', () => {
    const selection = frame.state().get('selection')

    chosen.forEach((id) => {
      const attachment = window.wp.media.attachment(id)
      attachment.fetch()
      selection.add(attachment)
    })
  })

  frame.on('select', () =>
    onSelect(
      frame
        .state()
        .get('selection')
        .map((attachment) => attachment.toJSON().id),
    ),
  )

  frame.open()
}

/**
 * Loads display data for a list of attachments.
 *
 * One state object keyed by id rather than a hook per image, because the number
 * of images is not fixed and a hook cannot be called in a loop.
 *
 * @param {number[]} ids
 * @return {Object} Attachments keyed by id.
 */
function useAttachments(ids) {
  const [attachments, setAttachments] = useState({})

  // the ids as a string, so the effect compares by value: a new array holding
  // the same ids is a different array, and would re-fetch on every render
  const key = ids.join(',')

  useEffect(() => {
    let live = true

    Promise.all(
      ids.map((id) => {
        const model = window.wp.media.attachment(id)

        // fetch() resolves from cache when the attachment is already known
        return model.fetch().then(() => model.toJSON())
      }),
    ).then((loaded) => {
      if (!live) {
        return
      }

      setAttachments(
        loaded.reduce((all, one) => {
          all[one.id] = one

          return all
        }, {}),
      )
    })

    return () => {
      live = false
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key])

  return attachments
}

/**
 * The gallery control.
 *
 * @param {Object} props
 * @return {JSX.Element} The control.
 */
export function GalleryField({ field, value, onChange }) {
  const ids = Array.isArray(value) ? value : []
  const attachments = useAttachments(ids)
  const max = Number(field.config?.max) || 0
  const full = max > 0 && ids.length >= max

  /**
   * Moves one image one place in either direction.
   *
   * @param {number} from
   * @param {number} direction
   * @return {void}
   */
  const move = (from, direction) => {
    const to = from + direction

    if (to < 0 || to >= ids.length) {
      return
    }

    const next = [...ids]
    const [moved] = next.splice(from, 1)
    next.splice(to, 0, moved)

    onChange(next)
  }

  return (
    <Field
      label={field.label}
      help={field.help}
      required={field.required}
      // the ceiling belongs beside the control rather than in a validation
      // message after the fact — you can see you have room for two more
      hint={
        max > 0
          ? sprintf(
              /* translators: 1: images chosen, 2: the most allowed */
              __('%1$d of %2$d', 'schemapress'),
              ids.length,
              max,
            )
          : ids.length > 0
            ? sprintf(
                /* translators: %d: number of images */
                _n('%d image', '%d images', ids.length, 'schemapress'),
                ids.length,
              )
            : undefined
      }
    >
      {ids.length > 0 ? (
        <ul className="mb-2 grid grid-cols-3 gap-2 sm:grid-cols-4">
          {ids.map((id, index) => {
            const attachment = attachments[id]
            const thumbnail = attachment?.sizes?.thumbnail?.url || attachment?.url

            return (
              <li
                key={id}
                className="group relative aspect-square overflow-hidden rounded-md border border-border bg-muted/40"
              >
                {thumbnail ? (
                  <img
                    src={thumbnail}
                    alt={attachment?.alt || ''}
                    className="block size-full object-cover"
                  />
                ) : null}

                {/* the controls sit on the image rather than under it: at this
                    size a row of buttons below each thumbnail is taller than the
                    thumbnail, and the grid stops reading as a gallery */}
                <div className="absolute inset-0 flex items-end justify-between gap-0.5 bg-gradient-to-t from-black/70 to-transparent p-1 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100">
                  <span className="flex gap-0.5">
                    <Button
                      size="icon-sm"
                      variant="secondary"
                      disabled={index === 0}
                      aria-label={__('Move earlier', 'schemapress')}
                      onClick={() => move(index, -1)}
                    >
                      <ArrowLeft />
                    </Button>
                    <Button
                      size="icon-sm"
                      variant="secondary"
                      disabled={index === ids.length - 1}
                      aria-label={__('Move later', 'schemapress')}
                      onClick={() => move(index, 1)}
                    >
                      <ArrowRight />
                    </Button>
                  </span>

                  <Button
                    size="icon-sm"
                    variant="secondary"
                    aria-label={sprintf(
                      /* translators: %d: the image's position in the gallery */
                      __('Remove image %d', 'schemapress'),
                      index + 1,
                    )}
                    onClick={() => onChange(ids.filter((one) => one !== id))}
                  >
                    <X />
                  </Button>
                </div>
              </li>
            )
          })}
        </ul>
      ) : null}

      <button
        type="button"
        disabled={full}
        onClick={() =>
          openGalleryModal({ title: field.label }, ids, (next) =>
            onChange(max > 0 ? next.slice(0, max) : next),
          )
        }
        className={cn(
          'flex w-full flex-col items-center justify-center gap-1.5 rounded-lg border border-dashed border-border bg-muted/40 text-muted-foreground transition-colors',
          ids.length > 0 ? 'h-16' : 'h-32',
          full
            ? 'cursor-not-allowed opacity-50'
            : 'hover:border-ring/40 hover:bg-muted',
        )}
      >
        <ImagePlus className="size-5" />
        <span className="text-[13px] font-medium">
          {full
            ? __('Gallery is full', 'schemapress')
            : ids.length > 0
              ? __('Add or remove images', 'schemapress')
              : __('Select images', 'schemapress')}
        </span>
      </button>
    </Field>
  )
}
