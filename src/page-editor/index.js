/**
 * Page editor entry point. Mounts the section editor into the metabox that
 * ContentEditor rendered.
 */

import domReady from '@wordpress/dom-ready'
import { createRoot } from '@wordpress/element'
import { App } from './App'
import '../shared/style.css'

domReady(() => {
  const container = document.getElementById('schemapress-content-root')

  if (!container) {
    return
  }

  createRoot(container).render(<App settings={window.SchemaPress || {}} />)
})
