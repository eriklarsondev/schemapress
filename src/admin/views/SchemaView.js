/**
 * Direct schema editing: name, template bindings and the section-type tree.
 *
 * Saving adopts the normalized server response wholesale. Keys are slugified
 * and deduplicated server-side, so treating the client copy as authoritative
 * would let the two drift apart.
 */

import { useState, useEffect } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { ArrowLeft, Save } from 'lucide-react'
import { api } from '../../shared/api'
import { useAsync } from '../useAsync'
import {
  Button,
  Card,
  CardBody,
  Input,
  Field,
  Alert,
  Loading,
  Spinner,
  Heading,
  Checkbox,
  Badge
} from '../../ui'
import { SchemaBuilder } from '../builder/SchemaBuilder'

/**
 * Schema detail and builder.
 *
 * @param {Object} props
 * @return {JSX.Element} The view.
 */
export function SchemaView({ schemaId, navigate, settings }) {
  const { data, error, loading } = useAsync(() => api.schema(schemaId), [schemaId])
  const { data: templates } = useAsync(() => api.templates(), [])

  const [title, setTitle] = useState('')
  const [sections, setSections] = useState([])
  const [bound, setBound] = useState([])
  const [dirty, setDirty] = useState(false)
  const [saving, setSaving] = useState(false)
  const [failure, setFailure] = useState(null)

  useEffect(() => {
    if (data) {
      setTitle(data.title)
      setSections(data.definition?.sections || [])
      setBound(data.templates || [])
      setDirty(false)
    }
  }, [data])

  useEffect(() => {
    if (!dirty) {
      return undefined
    }

    const warn = (event) => {
      event.preventDefault()
      event.returnValue = ''
    }

    window.addEventListener('beforeunload', warn)

    return () => window.removeEventListener('beforeunload', warn)
  }, [dirty])

  /**
   * Persists title, bindings and definition together.
   *
   * @return {Promise<void>}
   */
  const save = async () => {
    setSaving(true)
    setFailure(null)

    try {
      const saved = await api.saveSchema(schemaId, {
        title,
        templates: bound,
        definition: { sections }
      })

      setTitle(saved.title)
      setSections(saved.definition.sections)
      setBound(saved.templates)
      setDirty(false)
    } catch (exception) {
      setFailure(exception.message || __('Could not save the schema.', 'schemapress'))
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return <Loading label={__('Loading schema…', 'schemapress')} />
  }

  if (error) {
    return (
      <div className="flex flex-col gap-4">
        <Alert variant="error">{error}</Alert>
        <div>
          <Button variant="outline" onClick={() => navigate('schemas')}>
            <ArrowLeft />
            {__('Back to schemas', 'schemapress')}
          </Button>
        </div>
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-5">
      <div className="flex items-center gap-3">
        <Button
          size="icon"
          variant="ghost"
          aria-label={__('Back to schemas', 'schemapress')}
          onClick={() => navigate('schemas')}
        >
          <ArrowLeft />
        </Button>

        <div className="min-w-0 flex-1">
          <Input
            aria-label={__('Schema name', 'schemapress')}
            className="border-transparent bg-transparent px-0 text-base font-semibold hover:border-input focus:border-input"
            value={title}
            onChange={(event) => {
              setTitle(event.target.value)
              setDirty(true)
            }}
          />
        </div>

        <Button disabled={!dirty || saving} onClick={save}>
          {saving ? <Spinner /> : <Save />}
          {dirty ? __('Save schema', 'schemapress') : __('Saved', 'schemapress')}
        </Button>
      </div>

      {failure ? <Alert variant="error">{failure}</Alert> : null}

      <Card>
        <CardBody className="flex flex-col gap-3">
          <div>
            <Heading>{__('Bound templates', 'schemapress')}</Heading>
            <p className="mt-1 text-[12px] text-muted-foreground">
              {__(
                'Pages assigned to these templates deliver this schema’s sections.',
                'schemapress'
              )}
            </p>
          </div>

          <div className="grid gap-2.5 sm:grid-cols-2">
            {(templates || []).map((template) => {
              const takenBy =
                template.schema && template.schema.id !== schemaId ? template.schema : null

              return (
                <Checkbox
                  key={template.slug}
                  label={
                    <>
                      {template.label} <Badge variant="mono">{template.slug}</Badge>
                    </>
                  }
                  help={
                    takenBy
                      ? sprintfBound(takenBy.title)
                      : undefined
                  }
                  checked={bound.includes(template.slug)}
                  onChange={(checked) => {
                    setBound((current) =>
                      checked
                        ? [...current, template.slug]
                        : current.filter((slug) => slug !== template.slug)
                    )
                    setDirty(true)
                  }}
                />
              )
            })}
          </div>

          {(templates || []).length === 0 ? (
            <p className="text-[13px] text-muted-foreground">
              {__('No templates registered yet. Add one on the Templates tab.', 'schemapress')}
            </p>
          ) : null}
        </CardBody>
      </Card>

      <div>
        <Heading className="mb-2">{__('Section types', 'schemapress')}</Heading>
        <SchemaBuilder
          sections={sections}
          fieldTypes={settings.fieldTypes || []}
          onChange={(next) => {
            setSections(next)
            setDirty(true)
          }}
        />
      </div>
    </div>
  )
}

/**
 * Warning shown when a template is already claimed by another schema.
 *
 * @param {string} schemaTitle
 * @return {string} The warning.
 */
function sprintfBound(schemaTitle) {
  return __('Currently bound to: ', 'schemapress') + schemaTitle
}
