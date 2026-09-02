/**
 * The SchemaPress application shell.
 *
 * A permanent sidebar beside one content pane. The sidebar is the map — every
 * collection, always on screen — and the pane is whatever you picked. There is
 * no "back to the list of lists", because the list never went away.
 *
 * One kind of thing exists here: a collection type. You define its fields, you
 * arrange its entry form, and you fill in entries. Everything else this plugin
 * used to do has been taken out until that works properly.
 */

import { useCallback, useEffect, useState } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { Database, Plus } from 'lucide-react'
import { useRoute } from './useRoute'
import { Loading, Alert, Button } from '../ui'
import { api } from '../shared/api'
import { Sidebar } from './Sidebar'
import { ErrorBoundary } from './ErrorBoundary'
import { CreateTypeDialog } from './CreateTypeDialog'
import { TypeView } from './views/TypeView'

/**
 * Root component.
 *
 * @param {Object} props
 * @return {JSX.Element} The application.
 */
export function App({ settings }) {
  const [route, navigate] = useRoute()
  const [types, setTypes] = useState(null)
  const [error, setError] = useState('')
  const [creating, setCreating] = useState(false)

  /**
   * Reloads the sidebar, which carries live entry counts.
   *
   * @return {Promise<void>} Resolves once loaded.
   */
  const reload = useCallback(
    () =>
      api
        .types()
        .then((result) => {
          setTypes(result.types || [])
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

  const selected = types?.find((type) => type.id === Number(route.id)) || null

  return (
    // `.schemapress` is the scope every Tailwind utility is prefixed with, and
    // that prefix is a descendant selector — so this element carries the class
    // alone, and all layout utilities go on the child inside it
    <div className="schemapress">
      <div className="flex min-h-[calc(100vh-32px)] bg-muted/30">
        <Sidebar
          types={types || []}
          activeId={selected?.id}
          loading={types === null}
          docsUrl={settings.docsUrl}
          version={settings.version}
          onSelect={(id) => navigate('type', id)}
          onCreate={() => setCreating(true)}
        />

        <main className="min-w-0 flex-1 px-6 py-6 xl:px-8">
          {types === null ? (
            <Loading label={__('Loading…', 'schemapress')} />
          ) : error ? (
            <Alert variant="warning">{error}</Alert>
          ) : (
            // remounted per type, so a crash in one does not persist when you
            // navigate to another
            <ErrorBoundary key={selected?.id || 'none'}>
              {selected ? (
                <TypeView type={selected} onChanged={reload} onDeleted={() => navigate('')} />
              ) : (
                <Welcome
                  types={types}
                  onCreate={() => setCreating(true)}
                  onSelect={(id) => navigate('type', id)}
                />
              )}
            </ErrorBoundary>
          )}
        </main>
      </div>

      {creating ? (
        <CreateTypeDialog
          onClose={() => setCreating(false)}
          onCreated={(result) => {
            setCreating(false)
            setTypes(result.types || [])
            navigate('type', result.type.id)
          }}
        />
      ) : null}
    </div>
  )
}

/**
 * What the screen says before a collection is picked.
 *
 * @param {Object} props
 * @return {JSX.Element} The welcome pane.
 */
function Welcome({ types, onCreate, onSelect }) {
  return (
    <div className="mx-auto max-w-lg py-16 text-center">
      <span className="mx-auto flex size-12 items-center justify-center rounded-xl bg-muted text-muted-foreground">
        <Database className="size-5" />
      </span>

      <h1 className="mt-4 text-[20px] font-semibold tracking-tight">
        {types.length === 0
          ? __('No collections yet', 'schemapress')
          : __('Pick a collection', 'schemapress')}
      </h1>

      <p className="mx-auto mt-1.5 max-w-sm text-[13px] text-muted-foreground">
        {types.length === 0
          ? __(
              'A collection is a shape of content you have many of — Team Members, News Articles, Events. Define its fields once and add entries.',
              'schemapress'
            )
          : __('Choose one from the sidebar, or create another.', 'schemapress')}
      </p>

      {types.length > 0 ? (
        <div className="mt-5 flex flex-wrap justify-center gap-1.5">
          {types.map((type) => (
            <button
              key={type.id}
              type="button"
              onClick={() => onSelect(type.id)}
              className="rounded-md border border-border bg-background px-3 py-1.5 text-[13px] font-medium transition-colors hover:bg-accent"
            >
              {type.label}
            </button>
          ))}
        </div>
      ) : null}

      <div className="mt-6">
        <Button onClick={onCreate}>
          <Plus />
          {__('Create a collection type', 'schemapress')}
        </Button>
      </div>
    </div>
  )
}
