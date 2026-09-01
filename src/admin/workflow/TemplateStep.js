/**
 * Step one: give the page a template.
 *
 * The template slug is what the front-end switches page components on, so it
 * is shown on every option rather than hidden behind a label.
 */

import { useState } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { Check, Plus, LayoutTemplate, ArrowRight } from 'lucide-react'
import { api } from '../../shared/api'
import { useAsync } from '../useAsync'
import { toKey } from '../../shared/utils'
import { Button, Card, CardBody, Field, Input, Alert, Loading, Empty, Badge, cn } from '../../ui'

/**
 * Template chooser and inline creator.
 *
 * @param {Object} props
 * @return {JSX.Element} The step.
 */
export function TemplateStep({ postId, current, onDone, onSkip }) {
  const { data: templates, loading, reload } = useAsync(() => api.templates(), [])
  const [creating, setCreating] = useState(false)
  const [label, setLabel] = useState('')
  const [slug, setSlug] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  /**
   * Assigns a template to the page and advances.
   *
   * @param {string} value
   * @return {Promise<void>}
   */
  const choose = async (value) => {
    setBusy(true)
    setError(null)

    try {
      await api.assignTemplate(postId, value)
      onDone()
    } catch (exception) {
      setError(exception.message || __('Could not assign the template.', 'schemapress'))
    } finally {
      setBusy(false)
    }
  }

  /**
   * Creates a template, then assigns it.
   *
   * The registry is saved as a whole list, so the existing plugin-defined
   * templates are resent alongside the new one — theme-provided entries are
   * discovered rather than stored and must be left out.
   *
   * @return {Promise<void>}
   */
  const create = async () => {
    const nextSlug = toKey(slug || label)

    if (nextSlug === '') {
      return
    }

    setBusy(true)
    setError(null)

    try {
      const existing = (templates || [])
        .filter((template) => template.source !== 'theme')
        .map(({ slug: s, label: l, description }) => ({ slug: s, label: l, description }))

      await api.saveTemplates([
        ...existing,
        { slug: nextSlug, label: label || nextSlug, description: '' }
      ])

      await reload()
      await choose(nextSlug)
    } catch (exception) {
      setError(exception.message || __('Could not create the template.', 'schemapress'))
      setBusy(false)
    }
  }

  if (loading) {
    return <Loading label={__('Loading templates…', 'schemapress')} />
  }

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h3 className="text-[15px] font-semibold">{__('Choose a template', 'schemapress')}</h3>
        <p className="mt-1 text-[13px] text-muted-foreground">
          {__(
            'The template groups pages that share a structure. Its slug is delivered with the page so your front-end knows which component to render.',
            'schemapress'
          )}
        </p>
      </div>

      {error ? <Alert variant="error">{error}</Alert> : null}

      <div className="grid gap-2 sm:grid-cols-2">
        {(templates || []).map((template) => {
          const active = current?.slug === template.slug

          return (
            <button
              key={template.slug}
              type="button"
              disabled={busy}
              onClick={() => choose(template.slug)}
              className={cn(
                'flex items-start gap-3 rounded-lg border bg-background p-3.5 text-left transition-colors disabled:opacity-60',
                active
                  ? 'border-primary ring-1 ring-primary'
                  : 'border-border hover:border-ring/40 hover:bg-accent/40'
              )}
            >
              <span
                className={cn(
                  'mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-md',
                  active ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'
                )}
              >
                {active ? <Check className="size-4" /> : <LayoutTemplate className="size-4" />}
              </span>

              <span className="min-w-0 flex-1">
                <span className="block truncate text-[13px] font-medium">{template.label}</span>
                <span className="mt-1 flex flex-wrap items-center gap-1.5">
                  <Badge variant="mono">{template.slug}</Badge>
                  {template.schema ? (
                    <Badge variant="success">{template.schema.title}</Badge>
                  ) : (
                    <Badge variant="outline">{__('No schema yet', 'schemapress')}</Badge>
                  )}
                  {template.source === 'theme' ? (
                    <Badge variant="outline">{__('theme', 'schemapress')}</Badge>
                  ) : null}
                </span>
              </span>
            </button>
          )
        })}
      </div>

      {(templates || []).length === 0 && !creating ? (
        <Empty
          icon={LayoutTemplate}
          title={__('No templates yet', 'schemapress')}
          description={__(
            'Create the first one to describe what kind of page this is.',
            'schemapress'
          )}
        />
      ) : null}

      {creating ? (
        <Card>
          <CardBody className="flex flex-col gap-3">
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label={__('Name', 'schemapress')}>
                {(id) => (
                  <Input
                    id={id}
                    autoFocus
                    placeholder={__('e.g. Services Page', 'schemapress')}
                    value={label}
                    onChange={(event) => {
                      setLabel(event.target.value)
                      setSlug(toKey(event.target.value))
                    }}
                  />
                )}
              </Field>
              <Field
                label={__('Slug', 'schemapress')}
                help={__('Delivered in the page JSON', 'schemapress')}
              >
                {(id) => (
                  <Input
                    id={id}
                    value={slug}
                    onChange={(event) => setSlug(toKey(event.target.value))}
                  />
                )}
              </Field>
            </div>

            <div className="flex gap-2">
              <Button disabled={busy || slug === ''} onClick={create}>
                {__('Create and use', 'schemapress')}
              </Button>
              <Button variant="ghost" onClick={() => setCreating(false)}>
                {__('Cancel', 'schemapress')}
              </Button>
            </div>
          </CardBody>
        </Card>
      ) : (
        <div>
          <Button variant="outline" onClick={() => setCreating(true)}>
            <Plus />
            {__('New template', 'schemapress')}
          </Button>
        </div>
      )}

      <div className="border-t border-border pt-4">
        <p className="text-[12px] text-muted-foreground">
          {__(
            'A template lets several pages share one structure. For a one-off page you can skip it and give this page a schema of its own.',
            'schemapress'
          )}
        </p>
        <Button variant="link" size="sm" className="mt-1 px-0" onClick={onSkip}>
          {__('Skip — give this page its own schema', 'schemapress')}
          <ArrowRight />
        </Button>
      </div>
    </div>
  )
}
