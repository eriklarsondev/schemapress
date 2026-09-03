/**
 * One collection type.
 *
 * Three tabs, because there are three jobs and they are genuinely different:
 *
 *   Entries  the content itself — what an editor does every day
 *   Schema   what an entry is made of — what a developer sets up once
 *   Form     how those fields are arranged on the entry screen
 *
 * What the collection *is* — its name, whether it has drafts, whether it exists
 * at all — is not one of those jobs, so it sits behind the header button.
 *
 * Entries leads, because filling content in is the common act and defining the
 * shape is the rare one. The definition is loaded here, once, and handed down —
 * all three tabs read the same fields, and a form arranged against a stale copy
 * would put an editor in front of a field that no longer exists.
 */

import { useCallback, useEffect, useState } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { Table2, Wrench, LayoutList, SlidersHorizontal } from 'lucide-react'
import { Tabs, TabPanel, Loading, Alert, Button } from '../../ui'
import { api } from '../../shared/api'
import { EntriesView } from './EntriesView'
import { EntryView } from './EntryView'
import { FieldsTab } from './FieldsTab'
import { FormTab } from './FormTab'
import { SettingsDialog } from './SettingsDialog'

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
  const [configuring, setConfiguring] = useState(false)

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

  // the tabs are the three things you do to a collection. what the collection
  // *is* — its name, its description, whether it has drafts, whether it exists
  // at all — is not one of them, so it lives behind the header button instead
  // of taking a quarter of the tab strip
  const tabs = [
    { value: 'entries', label: __('Entries', 'schemapress'), icon: Table2 },
    { value: 'fields', label: __('Schema', 'schemapress'), icon: Wrench },
    { value: 'layout', label: __('Form', 'schemapress'), icon: LayoutList },
  ]

  return (
    <div className="flex flex-col gap-4">
      {/* the button sits on the title's own line rather than beside the whole
          block, so it lines up with the name instead of floating against a
          description of unpredictable height */}
      <header className="flex flex-col gap-0.5">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h1 className="min-w-0 truncate text-[20px] font-semibold leading-tight tracking-tight">
            {type.pluralLabel || type.label}
          </h1>

          <Button variant="outline" size="sm" onClick={() => setConfiguring(true)}>
            <SlidersHorizontal />
            {__('Settings', 'schemapress')}
          </Button>
        </div>

        {/* the machine keys are not here: they are reference material a
            template author looks up once, not something worth a line under
            the title on every visit. they live in Settings */}
        {type.description ? (
          <p className="max-w-prose text-[13px] leading-snug text-muted-foreground">
            {type.description}
          </p>
        ) : null}
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

        <TabPanel value="layout">
          <FormTab fields={fields} onChange={saveFields} />
        </TabPanel>
      </Tabs>

      {configuring ? (
        <SettingsDialog
          type={type}
          settings={definition.settings || {}}
          onClose={() => setConfiguring(false)}
          onSave={update}
          onDelete={() =>
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
