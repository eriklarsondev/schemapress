/**
 * The SchemaPress application shell.
 *
 * A full-bleed dark app bar over a light canvas, so the screen reads as one
 * tool rather than a plugin page bolted into wp-admin. Pages lead, because
 * that is where the work starts: pick a page and the guided editor walks it
 * through template → schema → content. Schemas and Templates remain as
 * libraries for managing those objects directly once they exist.
 */

import { __ } from '@wordpress/i18n'
import { FileText, Layers, LayoutTemplate, Boxes, Code2, Settings } from 'lucide-react'
import { useRoute } from './useRoute'
import { cn } from '../ui'
import { PagesView } from './views/PagesView'
import { WorkflowView } from './views/WorkflowView'
import { SchemasView } from './views/SchemasView'
import { SchemaView } from './views/SchemaView'
import { TemplatesView } from './views/TemplatesView'
import { SettingsView } from './views/SettingsView'

const TABS = [
  { view: 'pages', label: __('Pages', 'schemapress'), icon: FileText },
  { view: 'schemas', label: __('Schemas', 'schemapress'), icon: Layers },
  { view: 'templates', label: __('Templates', 'schemapress'), icon: LayoutTemplate },
  { view: 'settings', label: __('Settings', 'schemapress'), icon: Settings }
]

/**
 * Root component.
 *
 * @param {Object} props
 * @return {JSX.Element} The application.
 */
export function App({ settings }) {
  const [route, navigate] = useRoute()

  return (
    // `.schemapress` is the scope every Tailwind utility is prefixed with, and
    // that prefix is a descendant selector — so this element carries the class
    // alone, and all layout utilities go on the child inside it
    <div className="schemapress">
      <div className="flex min-h-[calc(100vh-32px)] flex-col bg-muted/40">
        <AppBar route={route} navigate={navigate} settings={settings} />

        <main className="flex-1 px-6 py-6 xl:px-8">
          <Route route={route} navigate={navigate} settings={settings} />
        </main>
      </div>
    </div>
  )
}

/**
 * The full-width dark bar carrying the brand and primary navigation.
 *
 * @param {Object} props
 * @return {JSX.Element} The app bar.
 */
function AppBar({ route, navigate, settings }) {
  return (
    <header className="flex items-center gap-5 bg-appbar px-4 py-2.5 text-appbar-foreground">
      <span className="flex items-center gap-2.5 pr-1">
        <span className="flex size-8 items-center justify-center rounded-md bg-white/10 ring-1 ring-inset ring-white/15">
          <Boxes className="size-4" />
        </span>
        <span className="text-[14px] font-semibold tracking-tight">
          {__('SchemaPress', 'schemapress')}
        </span>
      </span>

      <nav className="flex items-center gap-1" aria-label={__('Sections', 'schemapress')}>
        {TABS.map((tab) => {
          const active = route.view === tab.view

          return (
            <button
              key={tab.view}
              type="button"
              aria-current={active ? 'page' : undefined}
              onClick={() => navigate(tab.view)}
              className={cn(
                'flex items-center gap-2 rounded-md px-3 py-1.5 text-[13px] font-medium transition-colors',
                active
                  ? 'bg-appbar-active text-appbar-foreground'
                  : 'text-appbar-muted hover:bg-white/5 hover:text-appbar-foreground'
              )}
            >
              <tab.icon className="size-4" />
              {tab.label}
            </button>
          )
        })}
      </nav>

      {settings.contractUrl ? (
        <a
          href={settings.contractUrl}
          target="_blank"
          rel="noreferrer"
          title={__('The structural contract your front-end consumes', 'schemapress')}
          className="ml-auto flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-[12px] font-medium text-appbar-muted transition-colors hover:bg-white/5 hover:text-appbar-foreground"
        >
          <Code2 className="size-3.5" />
          {__('API contract', 'schemapress')}
        </a>
      ) : null}
    </header>
  )
}

/**
 * Renders the view for the active route.
 *
 * @param {Object} props
 * @return {JSX.Element} The active view.
 */
function Route({ route, navigate, settings }) {
  switch (route.view) {
    case 'schemas':
      return route.id ? (
        <SchemaView schemaId={route.id} navigate={navigate} settings={settings} />
      ) : (
        <SchemasView navigate={navigate} />
      )

    case 'templates':
      return <TemplatesView navigate={navigate} />

    case 'settings':
      return <SettingsView />

    case 'pages':
      return route.id ? (
        <WorkflowView postId={route.id} navigate={navigate} settings={settings} />
      ) : (
        <PagesView navigate={navigate} />
      )

    default:
      return <PagesView navigate={navigate} />
  }
}
