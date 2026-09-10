/**
 * The SchemaPress application shell.
 *
 * A permanent sidebar beside one content pane. The sidebar is the map — every
 * collection, always on screen — and the pane is whatever you picked. There is
 * no "back to the list of lists", because the list never went away.
 *
 * Two kinds of thing live here. A COLLECTION TYPE is a shape you have many of —
 * you define its fields, arrange its entry form, and fill in entries. A
 * COMPONENT is a shape with no content of its own, described once and imported
 * into collections that need it.
 */

import { useCallback, useEffect, useState } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { Database, Plus } from 'lucide-react'
import { useRoute } from './useRoute'
import { Loading, Alert, Button, ConfirmDialog, cn } from '../ui'
import { api } from '../shared/api'
import { can } from '../shared/settings'
import { clearUnsaved, hasUnsaved } from '../shared/unsaved'
import { Sidebar } from './Sidebar'
import { ErrorBoundary } from './ErrorBoundary'
import { CreateTypeDialog } from './CreateTypeDialog'
import { CreateComponentDialog } from './CreateComponentDialog'
import { TypeView } from './views/TypeView'
import { ComponentView } from './views/ComponentView'
import { DocsView } from './views/DocsView'
import { SettingsView } from './views/SettingsView'
import { JobsBanner } from './JobsBanner'

/**
 * Routes that are a screen of their own rather than a collection.
 *
 * They share one property that matters here: none of them is a collection, so
 * the redirect that opens the first collection must leave them alone.
 */
const STANDALONE = ['component', 'docs', 'settings']

/**
 * Root component.
 *
 * @param {Object} props
 * @return {JSX.Element} The application.
 */
export function App({ settings }) {
  const [route, navigate] = useRoute()
  const [types, setTypes] = useState(null)
  const [components, setComponents] = useState([])
  const [error, setError] = useState('')
  // which create dialog is open: '', 'type' or 'component'
  const [creating, setCreating] = useState('')

  // bumped every time the sidebar is clicked, and part of the pane's key. the
  // hash does not change when you click the collection you are already in, so
  // without this, picking it from the sidebar while three tabs deep leaves you
  // exactly where you were — which is not what clicking a collection means
  const [visit, setVisit] = useState(0)
  const [leaving, setLeaving] = useState(null)

  /**
   * Opens a screen from the sidebar, starting it fresh.
   *
   * @param {string} view
   * @param {number} id
   * @return {void}
   */
  const open = (view, id) => {
    // the sidebar is the other way out of a half-filled entry form. the screen
    // being left is the one that knows whether anything would be lost, so it
    // says so through a flag rather than this having to know what is on it
    if (hasUnsaved()) {
      setLeaving({ view, id })

      return
    }

    setVisit((count) => count + 1)
    navigate(view, id)
  }

  /**
   * Reloads the sidebar, which carries live entry and field counts.
   *
   * @return {Promise<void>} Resolves once loaded.
   */
  const reload = useCallback(
    () =>
      Promise.all([api.types(), api.components()])
        .then(([typeResult, componentResult]) => {
          setTypes(typeResult.types || [])
          setComponents(componentResult.components || [])
          setError('')
        })
        .catch((failure) => {
          setTypes([])
          setError(failure.message)
        }),
    []
  )

  useEffect(() => {
    reload()
  }, [reload])

  const selected = STANDALONE.includes(route.view)
    ? null
    : types?.find((type) => type.id === Number(route.id)) || null

  // with collections to show, the screen opens in one. a list of links to the
  // same collections already in the sidebar is a page that asks you to choose
  // twice — and after deleting a collection it is where you land, so it is not
  // only the first visit that would see it
  useEffect(() => {
    if (!types || types.length === 0 || selected) {
      return
    }

    if (STANDALONE.includes(route.view)) {
      return
    }

    navigate('type', types[0].id, true)
  }, [types, selected, route.view, navigate])

  return (
    // `.schemapress` is the scope every Tailwind utility is prefixed with, and
    // that prefix is a descendant selector — so this element carries the class
    // alone, and all layout utilities go on the child inside it
    <div className="schemapress">
      {/* the shell is exactly the viewport below wp-admin's bar and does not
          scroll: the sidebar is a map, and a map that scrolls away with the
          thing you are reading has stopped being one. what scrolls is the pane
          — and the sidebar's own list, when it outgrows the column */}
      <div className="flex h-[calc(100vh-32px)] overflow-hidden bg-muted/30">
        <Sidebar
          types={types || []}
          components={components}
          docs={settings.docs?.sections || []}
          active={{ view: route.view, id: route.id }}
          loading={types === null}
          version={settings.version}
          onSelect={(id) => open('type', id)}
          onCreate={() => setCreating('type')}
          onSelectComponent={(id) => open('component', id)}
          onCreateComponent={() => setCreating('component')}
          onOpenDocs={(id) => open('docs', id)}
          onOpenSettings={() => open('settings')}
        />

        {/* the builder screens are cards laid on the muted ground, so the ground
            is what separates one card from the next. the docs are not cards —
            they are a column of text, and text wants paper. so the pane itself
            goes white there rather than the page floating a white block on gray */}
        <main
          className={cn(
            'min-w-0 flex-1 overflow-y-auto px-6 py-6 xl:px-8',
            route.view === 'docs' && 'bg-background'
          )}
        >
          {/* above whatever screen is open, because the work it describes is
              about the site rather than about this screen — a reindex started
              on one collection is still running while you are looking at
              another, and its filters are still wrong until it finishes */}
          {route.view === 'docs' ? null : (
            <div className="mb-4 empty:mb-0">
              <JobsBanner onFinished={reload} />
            </div>
          )}

          {/* the docs do not wait on the sidebar's data, and are still readable
              when loading it is what failed — the page explaining the plugin is
              the last thing that should go down with it */}
          {route.view === 'docs' ? (
            <ErrorBoundary key="docs">
              <DocsView docs={settings.docs} page={route.id} />
            </ErrorBoundary>
          ) : types === null ? (
            <Loading label={__('Loading…', 'schemapress')} />
          ) : error ? (
            <Alert variant="warning">{error}</Alert>
          ) : (
            // remounted per screen, so a crash in one does not persist when you
            // navigate to another
            <ErrorBoundary key={`${route.view}-${route.id || 'none'}-${visit}`}>
              {route.view === 'settings' ? (
                // reload, because the sidebar's collections carry which of them
                // publish to the API and this screen names them
                // reload on both: saving changes which collections publish, and
                // an import can create collections the sidebar has never heard
                // of
                <SettingsView types={types} onSaved={reload} onImported={reload} />
              ) : route.view === 'component' && route.id ? (
                <ComponentView
                  id={Number(route.id)}
                  onChanged={reload}
                  onDeleted={() => navigate('')}
                />
              ) : selected ? (
                <TypeView type={selected} onChanged={reload} onDeleted={() => navigate('')} />
              ) : types.length === 0 ? (
                <Welcome onCreate={() => setCreating('type')} />
              ) : (
                // a collection exists, so the redirect above is already on its
                // way into it; anything drawn here would only flash
                <Loading label={__('Loading…', 'schemapress')} />
              )}
            </ErrorBoundary>
          )}
        </main>
      </div>

      {leaving ? (
        <ConfirmDialog
          open
          onOpenChange={(next) => !next && setLeaving(null)}
          title={__('Leave without saving?', 'schemapress')}
          description={__(
            'The entry you are editing has changes that have not been saved. Leaving loses them.',
            'schemapress'
          )}
          confirmLabel={__('Leave', 'schemapress')}
          onConfirm={() => {
            clearUnsaved()
            setVisit((count) => count + 1)
            navigate(leaving.view, leaving.id)
            setLeaving(null)
          }}
        />
      ) : null}

      {creating === 'type' ? (
        <CreateTypeDialog
          onClose={() => setCreating('')}
          onCreated={(result) => {
            setCreating('')
            setTypes(result.types || [])
            open('type', result.type.id)
          }}
        />
      ) : null}

      {creating === 'component' ? (
        <CreateComponentDialog
          onClose={() => setCreating('')}
          onCreated={(result) => {
            setCreating('')
            setComponents(result.components || [])
            open('component', result.component.id)
          }}
        />
      ) : null}
    </div>
  )
}

/**
 * What the screen says when there is nothing to open yet.
 *
 * Only reached with no collections at all: once one exists, the app opens it
 * rather than asking which.
 *
 * @param {Object} props
 * @return {JSX.Element} The welcome pane.
 */
function Welcome({ onCreate }) {
  return (
    <div className="mx-auto max-w-3xl px-6 py-20 text-center">
      <span className="mx-auto flex size-14 items-center justify-center rounded-2xl bg-muted text-muted-foreground">
        <Database className="size-6" />
      </span>

      <h1 className="mt-5 text-[24px] font-semibold tracking-tight">
        {__('No collections yet', 'schemapress')}
      </h1>

      <p className="mx-auto mt-2.5 max-w-xl text-[15px] leading-relaxed text-muted-foreground">
        {__(
          'A collection is a shape of content you have many of — Team Members, News Articles, Events. Define its fields once and add entries.',
          'schemapress'
        )}
      </p>

      {/* without the capability there is nothing to offer, and a button that
          would be refused is worse than the sentence saying who to ask */}
      <div className="mt-7">
        {can.manageSchema ? (
          <Button onClick={onCreate}>
            <Plus />
            {__('Create a collection type', 'schemapress')}
          </Button>
        ) : (
          <p className="text-[13px] text-muted-foreground">
            {__('An administrator can create one.', 'schemapress')}
          </p>
        )}
      </div>
    </div>
  )
}
