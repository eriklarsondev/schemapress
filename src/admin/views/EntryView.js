/**
 * One entry, as a form.
 *
 * The fields are handed in already loaded, in the order and at the widths the
 * Form tab arranged — so the entry screen is what was configured, not a second
 * opinion about it.
 */

import { useEffect, useState } from '@wordpress/element'
import { __, sprintf } from '@wordpress/i18n'
import { ChevronLeft, Save, Trash2 } from 'lucide-react'
import {
  Button,
  Card,
  CardBody,
  Field,
  Input,
  Loading,
  Alert,
  Segmented,
  ConfirmDialog,
  cn
} from '../../ui'
import { FieldControl } from '../../shared/fields'
import { emptyValues } from '../../shared/utils'
import { api } from '../../shared/api'

const STATUSES = [
  { value: 'publish', label: __('Published', 'schemapress') },
  { value: 'draft', label: __('Draft', 'schemapress') }
]

/**
 * The entry editor.
 *
 * @param {Object} props
 * @return {JSX.Element} The view.
 */
export function EntryView({ type, fields, entryId, onBack, onSaved }) {
  const [entry, setEntry] = useState(() =>
    entryId
      ? null
      : { id: null, title: '', status: 'publish', values: emptyValues(fields) }
  )
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const [removing, setRemoving] = useState(false)

  useEffect(() => {
    if (!entryId) {
      return undefined
    }

    let live = true

    api
      .entry(type.id, entryId)
      .then((result) => live && setEntry(result.entry))
      .catch((failure) => live && setError(failure.message))

    return () => {
      live = false
    }
  }, [type.id, entryId])

  /**
   * Persists the entry.
   *
   * @return {void}
   */
  const save = () => {
    setSaving(true)
    setError('')

    api
      .saveEntry(type.id, entry.id, {
        title: entry.title,
        status: entry.status,
        values: entry.values
      })
      .then((result) => {
        setEntry(result.entry)
        setSaving(false)
        onSaved()
      })
      .catch((failure) => {
        setSaving(false)
        setError(failure.message)
      })
  }

  if (!entry) {
    return error ? (
      <div className="flex flex-col gap-3">
        <Alert variant="warning">{error}</Alert>
        <div>
          <Button variant="outline" size="sm" onClick={onBack}>
            {__('Back', 'schemapress')}
          </Button>
        </div>
      </div>
    ) : (
      <Loading label={__('Loading…', 'schemapress')} />
    )
  }

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-4">
      <nav className="flex items-center gap-1 text-[12px]">
        <button
          type="button"
          onClick={onBack}
          className="flex items-center gap-1 rounded px-1.5 py-1 font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
        >
          <ChevronLeft className="size-3.5" />
          {type.label}
        </button>
      </nav>

      <header className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="min-w-0 truncate text-[20px] font-semibold tracking-tight">
          {entry.id
            ? entry.title || __('Untitled', 'schemapress')
            : sprintf(
                /* translators: %s: the collection's name */
                __('New %s', 'schemapress'),
                type.label
              )}
        </h1>

        <div className="flex items-center gap-2">
          {entry.id ? (
            <Button variant="destructive-ghost" size="sm" onClick={() => setRemoving(true)}>
              <Trash2 />
              {__('Delete', 'schemapress')}
            </Button>
          ) : null}

          <Button size="sm" disabled={saving} onClick={save}>
            <Save />
            {saving ? __('Saving…', 'schemapress') : __('Save', 'schemapress')}
          </Button>
        </div>
      </header>

      {error ? <Alert variant="warning">{error}</Alert> : null}

      <Card>
        <CardBody className="flex flex-col gap-4">
          <div className="flex flex-wrap items-end gap-4">
            <div className="min-w-0 flex-1">
              <Field
                label={__('Title', 'schemapress')}
                help={__('How this entry is listed. Left blank, one is derived.', 'schemapress')}
              >
                {(id) => (
                  <Input
                    id={id}
                    value={entry.title}
                    onChange={(event) => setEntry({ ...entry, title: event.target.value })}
                  />
                )}
              </Field>
            </div>

            <div className="pb-5">
              <Segmented
                value={entry.status}
                options={STATUSES}
                onChange={(status) => setEntry({ ...entry, status })}
              />
            </div>
          </div>

          {fields.length === 0 ? (
            <Alert variant="warning">
              {__('This collection has no fields yet. Add some in the Fields tab.', 'schemapress')}
            </Alert>
          ) : (
            // the widths the Form tab set, honoured here
            <div className="flex flex-wrap gap-x-4 gap-y-4">
              {fields.map((field) => (
                <div
                  key={field.key}
                  className={cn(
                    'min-w-0',
                    (field.config?.width || 'full') === 'half'
                      ? 'w-[calc(50%-0.5rem)]'
                      : 'w-full'
                  )}
                >
                  <FieldControl
                    field={field}
                    value={entry.values?.[field.key]}
                    onChange={(value) =>
                      setEntry({
                        ...entry,
                        values: { ...entry.values, [field.key]: value }
                      })
                    }
                  />
                </div>
              ))}
            </div>
          )}
        </CardBody>
      </Card>

      {removing ? (
        <ConfirmDialog
          open
          onOpenChange={(next) => !next && setRemoving(false)}
          title={__('Delete this entry?', 'schemapress')}
          confirmLabel={__('Delete', 'schemapress')}
          onConfirm={() =>
            api
              .deleteEntry(type.id, entry.id)
              .then(() => {
                onSaved()
                onBack()
              })
              .catch((failure) => setError(failure.message))
          }
        />
      ) : null}
    </div>
  )
}
