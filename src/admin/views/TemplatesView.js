/**
 * The template registry.
 *
 * A template is a slug with a label, but it is the contract between three
 * things: the schema bound to it, the pages assigned to it, and the front-end
 * component that renders them. The slug is shown prominently and treated as
 * the identity of the row.
 */

import { useState, useEffect } from '@wordpress/element'
import { __, sprintf, _n } from '@wordpress/i18n'
import { Plus, Trash2, LayoutTemplate, Save } from 'lucide-react'
import { api } from '../../shared/api'
import { canManage } from '../../shared/settings'
import { useAsync } from '../useAsync'
import { toKey, removeAt, replaceAt } from '../../shared/utils'
import {
  Button,
  Card,
  CardBody,
  Input,
  Field,
  Alert,
  Loading,
  Empty,
  Badge,
  Heading,
  Spinner
} from '../../ui'

/**
 * Template registry editor.
 *
 * @param {Object} props
 * @return {JSX.Element} The view.
 */
export function TemplatesView({ navigate }) {
  const { data: loaded, error, loading, reload } = useAsync(() => api.templates(), [])
  const [templates, setTemplates] = useState([])
  const [dirty, setDirty] = useState(false)
  const [saving, setSaving] = useState(false)
  const [failure, setFailure] = useState(null)

  useEffect(() => {
    if (loaded) {
      // theme-provided templates are discovered, not stored, so they are shown
      // separately and never sent back for saving
      setTemplates(loaded.filter((template) => template.source !== 'theme'))
      setDirty(false)
    }
  }, [loaded])

  const themeTemplates = (loaded || []).filter((template) => template.source === 'theme')

  /**
   * Applies a change and marks the registry unsaved.
   *
   * @param {Array} next
   * @return {void}
   */
  const update = (next) => {
    setTemplates(next)
    setDirty(true)
  }

  /**
   * Persists the registry.
   *
   * @return {Promise<void>}
   */
  const save = async () => {
    setSaving(true)
    setFailure(null)

    try {
      await api.saveTemplates(templates)
      setDirty(false)
      reload()
    } catch (exception) {
      setFailure(exception.message || __('Could not save templates.', 'schemapress'))
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return <Loading label={__('Loading templates…', 'schemapress')} />
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-end justify-between gap-4">
        <div>
          <h2 className="text-lg font-semibold tracking-tight">
            {__('Templates', 'schemapress')}
          </h2>
          <p className="mt-1 text-[13px] text-muted-foreground">
            {__(
              'Every page delivers its template slug. Your front-end maps the slug to a page component.',
              'schemapress'
            )}
          </p>
        </div>

        {canManage ? (
          <div className="flex gap-2">
            <Button
              variant="outline"
              onClick={() => update([...templates, { slug: '', label: '', description: '' }])}
            >
              <Plus />
              {__('Add', 'schemapress')}
            </Button>
            <Button disabled={!dirty || saving} onClick={save}>
              {saving ? <Spinner /> : <Save />}
              {dirty ? __('Save', 'schemapress') : __('Saved', 'schemapress')}
            </Button>
          </div>
        ) : null}
      </div>

      {error ? <Alert variant="error">{error}</Alert> : null}
      {failure ? <Alert variant="error">{failure}</Alert> : null}

      {canManage ? null : (
        <Alert variant="info">
          {__(
            'The template registry is site-wide and can only be changed by an administrator. You can see which templates exist and what they are bound to.',
            'schemapress'
          )}
        </Alert>
      )}

      {templates.length === 0 ? (
        <Empty
          icon={LayoutTemplate}
          title={__('No templates yet', 'schemapress')}
          description={__(
            'Add one to bind a schema to, or create one from a page’s setup.',
            'schemapress'
          )}
        />
      ) : null}

      <div className="flex flex-col gap-2">
        {templates.map((template, index) => (
          <Card key={index}>
            <CardBody className="flex flex-col gap-3">
              <div className="flex items-end gap-3">
                <Field label={__('Name', 'schemapress')} className="flex-1">
                  {(id) => (
                    <Input
                      id={id}
                      readOnly={!canManage}
                      value={template.label}
                      onChange={(event) =>
                        update(
                          replaceAt(templates, index, {
                            ...template,
                            label: event.target.value,
                            // the slug follows the label only while no page
                            // uses it — renaming it later orphans live pages
                            slug: template.page_count
                              ? template.slug
                              : toKey(event.target.value)
                          })
                        )
                      }
                    />
                  )}
                </Field>

                <Field
                  label={__('Slug', 'schemapress')}
                  className="flex-1"
                  help={
                    template.page_count
                      ? __('Locked — pages already use it', 'schemapress')
                      : undefined
                  }
                >
                  {(id) => (
                    <Input
                      id={id}
                      className="font-mono text-[12px]"
                      readOnly={!canManage}
                      value={template.slug}
                      onChange={(event) =>
                        update(
                          replaceAt(templates, index, {
                            ...template,
                            slug: toKey(event.target.value)
                          })
                        )
                      }
                    />
                  )}
                </Field>

                {canManage ? (
                  <Button
                    size="icon"
                    variant="destructive-ghost"
                    aria-label={__('Remove template', 'schemapress')}
                    onClick={() => update(removeAt(templates, index))}
                  >
                    <Trash2 />
                  </Button>
                ) : null}
              </div>

              <div className="flex flex-wrap items-center gap-1.5">
                {template.schema ? (
                  <button type="button" onClick={() => navigate('schemas', template.schema.id)}>
                    <Badge variant="success">{template.schema.title}</Badge>
                  </button>
                ) : (
                  <Badge variant="outline">{__('No schema bound', 'schemapress')}</Badge>
                )}

                {typeof template.page_count === 'number' ? (
                  <Badge variant="outline">
                    {sprintf(
                      /* translators: %d: number of pages */
                      _n('%d page', '%d pages', template.page_count, 'schemapress'),
                      template.page_count
                    )}
                  </Badge>
                ) : null}
              </div>
            </CardBody>
          </Card>
        ))}
      </div>

      {themeTemplates.length > 0 ? (
        <Card>
          <CardBody className="flex flex-col gap-2">
            <Heading>{__('From the active theme', 'schemapress')}</Heading>
            <p className="text-[12px] text-muted-foreground">
              {__(
                'Page templates the theme provides. They can be bound to, but are not editable here.',
                'schemapress'
              )}
            </p>
            <div className="flex flex-wrap gap-1.5">
              {themeTemplates.map((template) => (
                <Badge key={template.slug} variant="mono">
                  {template.slug}
                </Badge>
              ))}
            </div>
          </CardBody>
        </Card>
      ) : null}
    </div>
  )
}
