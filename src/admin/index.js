/**
 * SchemaPress admin entry point. One React application handles schema
 * definition, template registration and content editing.
 */

import domReady from '@wordpress/dom-ready'
import { createRoot } from '@wordpress/element'
import { App } from './App'
import { TooltipProvider } from '../ui'
import '../shared/style.css'

domReady(() => {
  const container = document.getElementById('schemapress-admin-root')

  if (!container) {
    return
  }

  // clears the server-rendered loading state
  container.innerHTML = ''

  // the provider renders no markup of its own — it only shares one open-delay
  // timer between every tooltip — so it can sit outside the scoped root
  createRoot(container).render(
    <TooltipProvider>
      <App settings={window.SchemaPress || {}} />
    </TooltipProvider>
  )
})
