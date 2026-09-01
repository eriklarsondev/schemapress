/**
 * Offered when nothing supplies a schema yet: start one, or reuse an existing
 * one.
 *
 * Binds through the template when there is one, and straight to the page when
 * there is not — either way the binding is made before building starts,
 * because an unbound schema delivers nothing.
 */

import { useState } from '@wordpress/element'
import { __, sprintf } from '@wordpress/i18n'
import { ArrowLeft, Plus, Layers } from 'lucide-react'
import { api } from '../../shared/api'
import { useAsync } from '../useAsync'
import { Button, Card, CardBody, Alert, Loading, Spinner, Badge } from '../../ui'

/**
 * Schema chooser.
 *
 * @param {Object} props
 * @return {JSX.Element} The chooser.
 */
export function SchemaChooser({ postId, template, onBound, onBack }) {
  const { data: schemas, loading } = useAsync(() => api.schemas(), [])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  /**
   * Binds a schema by whichever route this page is using, then loads it.
   *
   * @param {number} schemaId
   * @param {Array}  currentTemplates
   * @return {Promise<Object>} The bound schema payload.
   */
  const bind = async (schemaId, currentTemplates = []) => {
    if (template) {
      return api.saveSchema(schemaId, { templates: [...currentTemplates, template.slug] })
    }

    await api.assignSchema(postId, schemaId)

    return api.schema(schemaId)
  }

  /**
   * Reuses an existing schema.
   *
   * @param {Object} summary
   * @return {Promise<void>}
   */
  const reuse = async (summary) => {
    setBusy(true)
    setError(null)

    try {
      onBound(await bind(summary.id, summary.templates))
    } catch (exception) {
      setError(exception.message || __('Could not bind that schema.', 'schemapress'))
      setBusy(false)
    }
  }

  /**
   * Creates an empty schema and binds it. No starter section is seeded — the
   * builder's own "add component" flow is where components come from, and
   * seeding one here would just be something to delete.
   *
   * @return {Promise<void>}
   */
  const create = async () => {
    setBusy(true)
    setError(null)

    try {
      const created = await api.createSchema(
        template ? template.label : __('Page schema', 'schemapress')
      )

      onBound(await bind(created.id, []))
    } catch (exception) {
      setError(exception.message || __('Could not create the schema.', 'schemapress'))
      setBusy(false)
    }
  }

  if (loading) {
    return <Loading label={__('Loading schemas…', 'schemapress')} />
  }

  const reusable = (schemas || []).filter((entry) => entry.section_count > 0)

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h3 className="text-[15px] font-semibold">{__('Start building', 'schemapress')}</h3>
        <p className="mt-1 text-[13px] text-muted-foreground">
          {template
            ? sprintf(
                /* translators: %s: template label */
                __('The %s template has no structure yet.', 'schemapress'),
                template.label
              )
            : __('This page will get a structure of its own, not shared with others.', 'schemapress')}
        </p>
      </div>

      {error ? <Alert variant="error">{error}</Alert> : null}

      <button
        type="button"
        disabled={busy}
        onClick={create}
        className="flex items-start gap-3 rounded-lg border border-dashed border-border bg-background p-4 text-left transition-colors hover:border-ring/40 hover:bg-accent/40 disabled:opacity-60"
      >
        <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-md bg-primary text-primary-foreground">
          {busy ? <Spinner className="size-4" /> : <Plus className="size-4" />}
        </span>
        <span>
          <span className="block text-[13px] font-medium">
            {__('Build from scratch', 'schemapress')}
          </span>
          <span className="mt-0.5 block text-[12px] text-muted-foreground">
            {__('Add components and fill them in as you go.', 'schemapress')}
          </span>
        </span>
      </button>

      {reusable.length > 0 ? (
        <Card>
          <CardBody className="flex flex-col gap-2">
            <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
              {__('Or reuse an existing structure', 'schemapress')}
            </p>

            {reusable.map((entry) => (
              <button
                key={entry.id}
                type="button"
                disabled={busy}
                onClick={() => reuse(entry)}
                className="flex items-center gap-3 rounded-md border border-border p-3 text-left transition-colors hover:border-ring/40 hover:bg-accent/40 disabled:opacity-60"
              >
                <Layers className="size-4 shrink-0 text-muted-foreground" />
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-[13px] font-medium">{entry.title}</span>
                  <span className="text-[12px] text-muted-foreground">
                    {entry.section_count} {__('components', 'schemapress')}
                  </span>
                </span>
                {entry.templates.map((slug) => (
                  <Badge key={slug} variant="mono">
                    {slug}
                  </Badge>
                ))}
              </button>
            ))}
          </CardBody>
        </Card>
      ) : null}

      {onBack ? (
        <div className="border-t border-border pt-4">
          <Button variant="ghost" onClick={onBack}>
            <ArrowLeft />
            {__('Template', 'schemapress')}
          </Button>
        </div>
      ) : null}
    </div>
  )
}
