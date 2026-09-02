/**
 * One collection type.
 *
 * Four tabs, because there are four jobs and they are genuinely different:
 *
 *   Entries   the content itself — what an editor does every day
 *   Fields    what an entry is made of — what a developer sets up once
 *   Form      how those fields are arranged on the entry screen
 *   Settings  what the collection is, and how publishing works in it
 *
 * Entries leads, because filling content in is the common act and defining the
 * shape is the rare one. The definition is loaded here, once, and handed down —
 * all three tabs read the same fields, and a form arranged against a stale copy
 * would put an editor in front of a field that no longer exists.
 */

import { useCallback, useEffect, useState } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { Table2, Wrench, LayoutList, SlidersHorizontal, Trash2 } from 'lucide-react'
import { Tabs, TabPanel, Loading, Alert, Button, ConfirmDialog } from '../../ui'
import { api } from '../../shared/api'
import { EntriesView } from './EntriesView'
import { EntryView } from './EntryView'
import { FieldsTab } from './FieldsTab'
import { FormTab } from './FormTab'
import { SettingsTab } from './SettingsTab'

/**
 * The container for one collection type.
 *
 * @param {Object} props
 * @return {JSX.Element} The view.
 */
export function TypeView({ type, onChanged, onDeleted }) {
  const [definition, setDefinition] = useState(null)
  const [error, setError] = useState('')
  const [tab, setTab] = useState('entries')

  // undefined = the listing; null = a new entry; a number = that entry
  const [entryId, setEntryId] = useState(undefined)
  const [removing, setRemoving] = useState(false)

  const load = useCallback(() => {
    setDefinition(null)
    setError('')

    return api
      .type(type.id)
      .then((result) => setDefinition(result.definition))
      .catch((failure) => setError(failure.message))
  }, [type.id])

  useEffect(() => {
    load()
  }, [load])

  // moving to another collection leaves whatever was open behind
  useEffect(() => {
    setEntryId(undefined)
    setTab('entries')
  }, [type.id])

  /**
   * Persists a new definition and adopts what the server kept — keys are
   * slugified and deduplicated server-side, so the client must not assume its
   * own copy won.
   *
   * The whole definition goes up each time, not just the changed half: the
   * server normalizes what it is sent and defaults what it is not, so posting
   * fields alone would quietly reset the collection's settings.
   *
   * @param {Object} changes fields and/or settings, plus type-level keys
   * @return {Promise<void>} Resolves once stored.
   */
  const update = ({ fields, settings, ...rest }) =>
    api
      .updateType(type.id, {
        ...rest,
        definition: {
          ...definition,
          fields: fields || definition.fields,
          settings: { ...definition.settings, ...settings },
        },
      })
      .then((result) => {
        setDefinition(result.definition)
        onChanged()
      })

  /**
   * Replaces the field list.
   *
   * @param {Array} fields
   * @return {Promise<void>} Resolves once stored.
   */
  const saveFields = (fields) =>
    update({ fields }).catch((failure) => setError(failure.message))

  if (error) {
    return (
      <div className="flex flex-col gap-3">
        <Alert variant="warning">{error}</Alert>
        <div>
          <Button variant="outline" size="sm" onClick={load}>
            {__('Try again', 'schemapress')}
          </Button>
        </div>
      </div>
    )
  }

  if (!definition) {
    return <Loading label={__('Loading…', 'schemapress')} />
  }

  const fields = definition.fields || []

  // an open entry takes the whole pane: it is a form, and a tab strip above it
  // would offer to navigate away mid-edit
  if (entryId !== undefined) {
    return (
      <EntryView
        type={type}
        fields={fields}
        entryId={entryId}
        onBack={() => setEntryId(undefined)}
        onSaved={onChanged}
      />
    )
  }

  const tabs = [
    { value: 'entries', label: __('Entries', 'schemapress'), icon: Table2 },
    { value: 'fields', label: __('Fields', 'schemapress'), icon: Wrench },
    { value: 'form', label: __('Form', 'schemapress'), icon: LayoutList },
    { value: 'settings', label: __('Settings', 'schemapress'), icon: SlidersHorizontal },
  ]

  return (
    <div className="flex flex-col gap-4">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-[20px] font-semibold tracking-tight">
              {type.pluralLabel || type.label}
            </h1>
          </div>

          {/* the machine keys are not here: they are reference material a
              template author looks up once, not something worth a line under
              the title on every visit. they live in Settings */}
          {type.description ? (
            <p className="mt-1 max-w-prose text-[13px] leading-relaxed text-muted-foreground">
              {type.description}
            </p>
          ) : null}
        </div>

        <Button variant="destructive-ghost" size="sm" onClick={() => setRemoving(true)}>
          <Trash2 />
          {__('Delete type', 'schemapress')}
        </Button>
      </header>

      <Tabs tabs={tabs} value={tab} onValueChange={setTab}>
        <TabPanel value="entries">
          <EntriesView
            type={type}
            fields={fields}
            settings={definition.settings || {}}
            onOpenEntry={setEntryId}
            onConfigure={(listColumns) => update({ settings: { listColumns } })}
          />
        </TabPanel>

        <TabPanel value="fields">
          <FieldsTab fields={fields} onChange={saveFields} />
        </TabPanel>

        <TabPanel value="form">
          <FormTab fields={fields} onChange={saveFields} />
        </TabPanel>

        <TabPanel value="settings">
          <SettingsTab type={type} settings={definition.settings || {}} onSave={update} />
        </TabPanel>
      </Tabs>

      {removing ? (
        <ConfirmDialog
          open
          onOpenChange={(next) => !next && setRemoving(false)}
          title={__('Delete this collection type?', 'schemapress')}
          description={__(
            'Every entry in it is deleted too, permanently. This cannot be undone.',
            'schemapress',
          )}
          confirmLabel={__('Delete', 'schemapress')}
          onConfirm={() =>
            api.deleteType(type.id).then(() => {
              onChanged()
              onDeleted()
            })
          }
        />
      ) : null}
    </div>
  )
}
