/**
 * The schema library: every defined schema, what it is bound to, and how to
 * create or remove one.
 *
 * Secondary to the page workflow — this is for managing schemas directly once
 * they exist, rather than the path a new page takes.
 */

import { useState } from '@wordpress/element'
import { __, sprintf, _n } from '@wordpress/i18n'
import { Plus, Trash2, Layers, ArrowRight } from 'lucide-react'
import { api } from '../../shared/api'
import { useAsync } from '../useAsync'
import {
  Button,
  Card,
  Input,
  Alert,
  Loading,
  Empty,
  Badge,
  ConfirmDialog
} from '../../ui'

/**
 * Schema list.
 *
 * @param {Object} props
 * @return {JSX.Element} The view.
 */
export function SchemasView({ navigate }) {
  const { data: schemas, error, loading, reload } = useAsync(() => api.schemas(), [])
  const [title, setTitle] = useState('')
  const [busy, setBusy] = useState(false)
  const [failure, setFailure] = useState(null)
  const [pendingDelete, setPendingDelete] = useState(null)

  /**
   * Creates a schema and opens it — the list has nothing more to offer once
   * one exists.
   *
   * @return {Promise<void>}
   */
  const create = async () => {
    if (title.trim() === '') {
      return
    }

    setBusy(true)
    setFailure(null)

    try {
      const schema = await api.createSchema(title.trim())

      setTitle('')
      navigate('schemas', schema.id)
    } catch (exception) {
      setFailure(exception.message || __('Could not create the schema.', 'schemapress'))
    } finally {
      setBusy(false)
    }
  }

  /**
   * Trashes the schema awaiting confirmation.
   *
   * @return {Promise<void>}
   */
  const remove = async () => {
    await api.deleteSchema(pendingDelete.id)
    reload()
  }

  if (loading) {
    return <Loading label={__('Loading schemas…', 'schemapress')} />
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-end justify-between gap-4">
        <div>
          <h2 className="text-lg font-semibold tracking-tight">{__('Schemas', 'schemapress')}</h2>
          <p className="mt-1 text-[13px] text-muted-foreground">
            {__('The section types a template delivers.', 'schemapress')}
          </p>
        </div>

        <div className="flex gap-2">
          <Input
            className="w-56"
            placeholder={__('New schema name…', 'schemapress')}
            value={title}
            onChange={(event) => setTitle(event.target.value)}
            onKeyDown={(event) => event.key === 'Enter' && create()}
          />
          <Button disabled={busy || title.trim() === ''} onClick={create}>
            <Plus />
            {__('Create', 'schemapress')}
          </Button>
        </div>
      </div>

      {error ? <Alert variant="error">{error}</Alert> : null}
      {failure ? <Alert variant="error">{failure}</Alert> : null}

      {(schemas || []).length === 0 ? (
        <Empty
          icon={Layers}
          title={__('No schemas yet', 'schemapress')}
          description={__(
            'Schemas are usually created from a page’s setup, but you can start one here.',
            'schemapress'
          )}
        />
      ) : null}

      <div className="flex flex-col gap-1.5">
        {(schemas || []).map((schema) => (
          <Card key={schema.id} className="flex items-center gap-3 px-4 py-3">
            <button
              type="button"
              onClick={() => navigate('schemas', schema.id)}
              className="min-w-0 flex-1 text-left"
            >
              <span className="block truncate text-[13px] font-medium">
                {schema.title || __('(untitled)', 'schemapress')}
              </span>
              <span className="mt-1 flex flex-wrap items-center gap-1.5">
                <Badge variant="outline">
                  {sprintf(
                    /* translators: %d: number of section types */
                    _n('%d section type', '%d section types', schema.section_count, 'schemapress'),
                    schema.section_count
                  )}
                </Badge>
                {schema.templates.length > 0 ? (
                  schema.templates.map((slug) => (
                    <Badge key={slug} variant="mono">
                      {slug}
                    </Badge>
                  ))
                ) : (
                  <Badge variant="warning">{__('Not bound', 'schemapress')}</Badge>
                )}
              </span>
            </button>

            <Button size="sm" variant="outline" onClick={() => navigate('schemas', schema.id)}>
              {__('Edit', 'schemapress')}
              <ArrowRight />
            </Button>

            <Button
              size="icon-sm"
              variant="destructive-ghost"
              aria-label={__('Delete schema', 'schemapress')}
              onClick={() => setPendingDelete(schema)}
            >
              <Trash2 />
            </Button>
          </Card>
        ))}
      </div>

      <ConfirmDialog
        open={Boolean(pendingDelete)}
        onOpenChange={(open) => !open && setPendingDelete(null)}
        title={sprintf(
          /* translators: %s: schema title */
          __('Delete “%s”?', 'schemapress'),
          pendingDelete?.title || ''
        )}
        description={__(
          'Pages keep their stored content, but stop delivering sections until another schema is bound to their template.',
          'schemapress'
        )}
        confirmLabel={__('Delete schema', 'schemapress')}
        onConfirm={remove}
      />
    </div>
  )
}
