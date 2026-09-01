/**
 * Live preview of the page as the reference renderer produces it.
 *
 * Rendered server-side from the unsaved payload and shown in an iframe. The
 * iframe is not decoration: the rendered markup is the front end's, not the
 * admin's, and letting it inherit wp-admin's stylesheet would show a page that
 * looks nothing like the one that ships.
 *
 * What this previews is the plugin's own renderer. A headless front end that
 * takes the JSON and renders it with its own components will look different by
 * design - this shows the structure and the layout tokens taking effect, which
 * is the part the two have in common.
 */

import { useState, useEffect, useRef } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { api } from '../../shared/api'
import { Alert, Spinner, Segmented } from '../../ui'

const VIEWPORTS = {
  desktop: '100%',
  tablet: '820px',
  mobile: '390px'
}

/**
 * Wraps rendered markup in a standalone document.
 *
 * @param {string} html
 * @param {string} stylesheet
 * @return {string} The document.
 */
function document_(html, stylesheet) {
  return `<!doctype html>
<html><head><meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link rel="stylesheet" href="${stylesheet}" />
<style>
  body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; color:#16181d; }
  .sp-empty { padding:4rem 1.5rem; text-align:center; color:#8b8f98; font-size:.875rem; }
</style>
</head><body>${html || '<p class="sp-empty">Nothing to preview yet.</p>'}</body></html>`
}

/**
 * Server-rendered preview pane.
 *
 * @param {Object} props
 * @return {JSX.Element} The preview.
 */
export function PagePreview({ postId, definition, sections, compact = false }) {
  const [doc, setDoc] = useState(null)
  const [error, setError] = useState(null)
  const [loading, setLoading] = useState(true)
  const [viewport, setViewport] = useState('desktop')
  const timer = useRef(null)

  // the caller builds `definition` inline, so its identity changes on every
  // render — depending on it directly would make this effect re-fire on its
  // own result. serializing keys the request on value instead.
  const payload = JSON.stringify({ definition, content: { version: 1, sections } })

  useEffect(() => {
    let cancelled = false

    // editing is keystroke-frequent and rendering is a round trip; settling
    // first keeps the preview from queueing a request per character
    clearTimeout(timer.current)
    timer.current = setTimeout(() => {
      setLoading(true)

      api
        .preview(postId, JSON.parse(payload))
        .then((result) => {
          if (!cancelled) {
            setDoc(document_(result.html, result.stylesheet))
            setError(null)
          }
        })
        .catch((exception) => {
          if (!cancelled) {
            setError(exception.message || __('Could not render the preview.', 'schemapress'))
          }
        })
        .finally(() => {
          if (!cancelled) {
            setLoading(false)
          }
        })
    }, 400)

    return () => {
      cancelled = true
      clearTimeout(timer.current)
    }
  }, [postId, payload])

  return (
    <div className="flex flex-col gap-3">
      <div className="flex items-center justify-between gap-3">
        {compact ? null : (
          <p className="text-[12px] text-muted-foreground">
            {__('Rendered by SchemaPress from the page JSON.', 'schemapress')}
          </p>
        )}

        <div className="flex items-center gap-2">
          {loading ? <Spinner className="text-muted-foreground" /> : null}
          <Segmented
            className="w-auto"
            value={viewport}
            onChange={setViewport}
            options={[
              { value: 'desktop', label: __('Desktop', 'schemapress') },
              { value: 'tablet', label: __('Tablet', 'schemapress') },
              { value: 'mobile', label: __('Mobile', 'schemapress') }
            ]}
          />
        </div>
      </div>

      {error ? <Alert variant="error">{error}</Alert> : null}

      <div className="flex justify-center overflow-hidden rounded-lg border border-border bg-muted/30 p-3">
        <iframe
          title={__('Page preview', 'schemapress')}
          srcDoc={doc || ''}
          sandbox="allow-same-origin"
          style={{ width: VIEWPORTS[viewport] }}
          className="h-[70vh] rounded-md border border-border bg-white transition-[width] duration-200"
        />
      </div>
    </div>
  )
}
