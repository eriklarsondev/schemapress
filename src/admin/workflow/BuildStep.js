/**
 * Step two: build the page.
 *
 * Structure and content are edited together. Defining a component and filling
 * it in are the same activity here, which is the point — walking a schema
 * first and then walking it again to enter content feels like building the
 * page twice.
 *
 * They remain two stores underneath: the schema is shared by every page on the
 * template, the content belongs to this page. Saving writes the schema first,
 * because content is sanitized against it — sending content that references a
 * component the server has not been told about yet would see it discarded.
 */

import { useState, useEffect } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { ArrowLeft, ExternalLink, Check, CloudOff, RefreshCw } from 'lucide-react'
import { api } from '../../shared/api'
import { Button, Alert, Spinner, Badge, Segmented, cn } from '../../ui'
import { useAutosave } from '../useAutosave'
import { PageBuilder } from '../../shared/content/PageBuilder'
import { PreviewProvider } from '../../shared/content/preview'
import { DesignProvider } from '../../shared/content/mode'
import { SchemaChooser } from './SchemaChooser'
import { PagePreview } from './PagePreview'

/**
 * The autosave indicator.
 *
 * Quiet when it has nothing to say. A save that failed is the one state worth
 * interrupting for, because the work only exists in the tab until it succeeds.
 *
 * @param {Object} props
 * @return {JSX.Element} The indicator.
 */
function SaveStatus({ status, onRetry }) {
  if (status === 'error') {
    return (
      <Button size="sm" variant="destructive" onClick={onRetry}>
        <CloudOff />
        {__('Not saved — retry', 'schemapress')}
      </Button>
    )
  }

  const label = {
    saving: __('Saving…', 'schemapress'),
    dirty: __('Unsaved', 'schemapress'),
    saved: __('Saved', 'schemapress')
  }[status]

  return (
    <span className="flex items-center gap-1.5 px-2 text-[12px] text-muted-foreground">
      {status === 'saving' ? <Spinner className="size-3" /> : null}
      {status === 'saved' ? <Check className="size-3.5 text-emerald-600" /> : null}
      {status === 'dirty' ? <RefreshCw className="size-3" /> : null}
      {label}
    </span>
  )
}

/**
 * Combined structure and content editor.
 *
 * @param {Object} props
 * @return {JSX.Element} The step.
 */
export function BuildStep({
  postId,
  template,
  schema,
  source,
  content,
  fieldTypes,
  onBack
}) {
  const [bound, setBound] = useState(schema)
  const [types, setTypes] = useState(() => schema?.definition?.sections || [])
  const [sections, setSections] = useState(() => content?.sections || [])
  const [view, setView] = useState('build')

  // opens in design mode: while templates and schemas are still being shaped,
  // the person here is the one shaping them
  const [mode, setMode] = useState('design')

  useEffect(() => {
    setBound(schema)
    setTypes(schema?.definition?.sections || [])
    setSections(content?.sections || [])
  }, [schema, content])

  /**
   * Writes the schema, then the content.
   *
   * In that order, because content is sanitized against the stored schema —
   * sending content that references a component the server has not been told
   * about yet would see it discarded.
   *
   * @param {string} serialized
   * @return {Promise<void>}
   */
  const persist = async (serialized) => {
    const { types: nextTypes, sections: nextSections } = JSON.parse(serialized)

    await api.saveSchema(bound.id, { definition: { sections: nextTypes } })
    await api.saveContent(postId, { version: 1, sections: nextSections })
  }

  const { status, error, saveNow } = useAutosave({
    payload: JSON.stringify({ types, sections }),
    enabled: Boolean(bound),
    save: persist
  })

  if (!bound) {
    return (
      <SchemaChooser
        postId={postId}
        template={template}
        onBack={onBack}
        onBound={(next) => {
          setBound(next)
          setTypes(next.definition?.sections || [])
        }}
      />
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-start justify-between gap-3">
        <div>
          <div className="flex items-center gap-2">
            <h3 className="text-[15px] font-semibold">{__('Page components', 'schemapress')}</h3>
            <Badge variant={source === 'page' ? 'outline' : 'default'}>
              {source === 'page'
                ? __('This page only', 'schemapress')
                : __('Shared by template', 'schemapress')}
            </Badge>
          </div>

          {/* the modes differ by scope, not by difficulty, and that is not
              something a two-word toggle can convey on its own */}
          <p className="mt-1 text-[13px] text-muted-foreground">
            {mode === 'design'
              ? __(
                  'Design: shaping the components themselves — what elements they have and what authors can change. Affects every page using this schema.',
                  'schemapress'
                )
              : __(
                  'Content: filling in this page. Structural controls are hidden — switch to Design to change the components.',
                  'schemapress'
                )}
          </p>
        </div>

        <div className="flex shrink-0 items-center gap-2">
          <Segmented
            value={mode}
            onChange={setMode}
            options={[
              { value: 'content', label: __('Content', 'schemapress') },
              { value: 'design', label: __('Design', 'schemapress') }
            ]}
          />

          <span className="h-5 w-px bg-border" aria-hidden="true" />

          <Segmented
            className="w-auto"
            value={view}
            onChange={setView}
            options={[
              { value: 'build', label: __('Build', 'schemapress') },
              { value: 'split', label: __('Split', 'schemapress') },
              { value: 'preview', label: __('Preview', 'schemapress') }
            ]}
          />

          <SaveStatus status={status} onRetry={saveNow} />
        </div>
      </div>

      {error ? (
        <Alert
          variant="error"
          action={
            <Button size="sm" variant="outline" onClick={saveNow}>
              {__('Try again', 'schemapress')}
            </Button>
          }
        >
          {error}
        </Alert>
      ) : null}

      {!template ? (
        <Alert variant="info">
          {__(
            'With no template, the delivered JSON has no template key — your front-end will need to route this page some other way, such as by its slug.',
            'schemapress'
          )}
        </Alert>
      ) : null}

      {view === 'preview' ? (
        <PagePreview postId={postId} definition={{ sections: types }} sections={sections} />
      ) : (
        <div
          className={cn(
            view === 'split' && 'grid items-start gap-4 xl:grid-cols-2'
          )}
        >
          {/* the canvas renders each section's real markup; in split view the
              full preview beside it already does that, so the cards fall back
              to their schematic and one render pass is saved */}
          <PreviewProvider
            postId={postId}
            definition={{ sections: types }}
            sections={sections}
            enabled={view === 'build'}
          >
            <DesignProvider design={mode === 'design'}>
              <PageBuilder
                definition={{ sections: types }}
                sections={sections}
                fieldTypes={fieldTypes}
                onChange={setSections}
                onDefinitionChange={setTypes}
              />
            </DesignProvider>
          </PreviewProvider>

          {view === 'split' ? (
            <div className="xl:sticky xl:top-6">
              <PagePreview
                postId={postId}
                definition={{ sections: types }}
                sections={sections}
                compact
              />
            </div>
          ) : null}
        </div>
      )}

      <div className="flex items-center justify-between gap-2 border-t border-border pt-4">
        <Button variant="ghost" onClick={onBack}>
          <ArrowLeft />
          {__('Template', 'schemapress')}
        </Button>

        <Button variant="outline" size="sm" asChild>
          <a href={`${window.SchemaPress?.contractUrl || '#'}`} target="_blank" rel="noreferrer">
            {__('Preview the JSON contract', 'schemapress')}
            <ExternalLink />
          </a>
        </Button>
      </div>
    </div>
  )
}
