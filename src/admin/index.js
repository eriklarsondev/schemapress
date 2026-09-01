/**
 * SchemaPress admin entry point. One React application handles schema
 * definition, template registration and content editing.
 */

import domReady from '@wordpress/dom-ready'
import { createRoot } from '@wordpress/element'
import { App } from './App'
import '../shared/style.css'

domReady(() => {
  const container = document.getElementById('schemapress-admin-root')

  if (!container) {
    return
  }

  // clears the server-rendered loading state
  container.innerHTML = ''

  createRoot(container).render(<App settings={window.SchemaPress || {}} />)
})
