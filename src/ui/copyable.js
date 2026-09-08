/**
 * A value you are meant to take somewhere else.
 *
 * An id is only ever read to be pasted into something — a template, a URL, a
 * support ticket. Selecting 36 characters of uuid by hand out of a sidebar is
 * the kind of small failure that happens on the third attempt, so the button
 * does it.
 */

import { useEffect, useRef, useState } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { Copy, Check } from 'lucide-react'
import { cn } from './utils'

/**
 * Copies text to the clipboard.
 *
 * The async Clipboard API needs a secure context, and a WordPress install on a
 * plain http host — which is most local development — is not one. There the
 * API is simply absent, so this falls back to the old selection trick rather
 * than failing silently on exactly the machines this gets built on.
 *
 * Exported because the documentation screen builds its code blocks' copy
 * buttons as DOM rather than as components, and had its own copy of this that
 * was missing the fallback: `navigator.clipboard?.writeText(…).then(…)` reads
 * `.then` off undefined the moment the API is absent, so it threw before
 * reaching the `.catch` meant to report the failure. One implementation, and it
 * is this one.
 *
 * @param {string} text
 * @return {Promise<boolean>} Whether it was copied.
 */
export function copyText(text) {
  if (navigator.clipboard?.writeText) {
    return navigator.clipboard.writeText(text).then(
      () => true,
      () => false
    )
  }

  const field = document.createElement('textarea')

  field.value = text
  field.setAttribute('readonly', '')
  field.style.position = 'fixed'
  field.style.opacity = '0'

  document.body.appendChild(field)
  field.select()

  let copied = false

  try {
    copied = document.execCommand('copy')
  } catch (error) {
    copied = false
  }

  document.body.removeChild(field)

  return Promise.resolve(copied)
}

/**
 * A monospaced value with a copy button.
 *
 * What is SHOWN and what is COPIED can differ, because for a URL they want
 * different things. Pasting one needs the whole absolute address; reading one
 * in a card needs the part that identifies it, and an origin on the front —
 * `http://wp-starter.local:10010/` — is a prefix every row shares that pushes
 * the part you were looking for out past the truncation.
 *
 * @param {Object} props
 * @param {string} props.value   The text copied.
 * @param {string} props.display The text shown, when it differs from the value.
 * @param {string} props.label   What the button announces.
 * @return {JSX.Element} The row.
 */
export function Copyable({ value, display, label = __('Copy', 'schemapress'), className }) {
  const [copied, setCopied] = useState(false)
  const timer = useRef(null)

  // the tick is a temporary state on a component that can unmount while it is
  // showing — closing the entry mid-flash would otherwise set state on nothing
  useEffect(() => () => clearTimeout(timer.current), [])

  /**
   * Copies, and shows that it did.
   *
   * @return {void}
   */
  const run = () =>
    copyText(String(value)).then((ok) => {
      if (!ok) {
        return
      }

      setCopied(true)
      clearTimeout(timer.current)
      timer.current = setTimeout(() => setCopied(false), 1600)
    })

  return (
    <span
      className={cn(
        'flex items-center gap-1 rounded-md bg-muted py-1 pl-2 pr-1 text-[11px] font-medium',
        className
      )}
    >
      {/* one line, truncated. a uuid broken across two lines is harder to
          compare at a glance, and it is here to be copied rather than read —
          the full value is in the title for the rare time you do read it */}
      <span
        title={String(value)}
        className="min-w-0 flex-1 truncate font-mono text-muted-foreground"
      >
        {display ?? value}
      </span>

      <button
        type="button"
        onClick={run}
        aria-label={copied ? __('Copied', 'schemapress') : label}
        className="shrink-0 rounded p-1 text-muted-foreground transition-colors hover:bg-background hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      >
        {copied ? <Check className="size-3.5 text-emerald-600" /> : <Copy className="size-3.5" />}
      </button>
    </span>
  )
}
