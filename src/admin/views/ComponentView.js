/**
 * One component: a named group of fields, defined once and imported anywhere.
 *
 * The same editor a collection's Schema tab uses, because a component IS a
 * schema — it just has no entries of its own. Nothing is ever saved *as* a
 * component; it only ever appears inside something else.
 *
 * The same two tabs a collection has, for the same reason: the layout is part
 * of the shape. A component is arranged once here and arrives in every
 * collection that imports it looking exactly as it was laid out — widths,
 * offsets, placeholders and all — because importing copies the fields, and the
 * layout lives on the fields.
 */

import { useCallback, useEffect, useState } from '@wordpress/element'
import { __, sprintf } from '@wordpress/i18n'
import { Save, Wrench, LayoutList, SlidersHorizontal } from 'lucide-react'
import { Card, CardBody, Loading, Alert, Button, Tabs, TabPanel } from '../../ui'
import { FieldsEditor } from '../../shared/builder/FieldEditor'
import { FormTab } from './FormTab'
import { ComponentSettingsDialog } from './ComponentSettingsDialog'
import { fieldTypes } from '../../shared/settings'
import { api } from '../../shared/api'

/**
 * The component editor.
 *
 * @param {Object} props
 * @return {JSX.Element} The view.
 */
export function ComponentView({ id, onChanged, onDeleted }) {
  const [component, setComponent] = useState(null)
  const [draft, setDraft] = useState(null)
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const [configuring, setConfiguring] = useState(false)
  const [tab, setTab] = useState('schema')

  const load = useCallback(() => {
    setComponent(null)
    setError('')

    return api
      .component(id)
      .then((result) => {
        setComponent(result.component)
        setDraft(result.component)
      })
      .catch((failure) => setError(failure.message))
  }, [id])

  useEffect(() => {
    load()
  }, [load])

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

  if (!component || !draft) {
    return <Loading label={__('Loading…', 'schemapress')} />
  }

  const dirty = JSON.stringify(draft) !== JSON.stringify(component)

  /**
   * Merges a change into the working copy.
   *
   * @param {Object} changes
   * @return {void}
   */
  const update = (changes) => setDraft((current) => ({ ...current, ...changes }))

  /**
   * Stores the component and adopts what the server kept.
   *
   * Everything on screen goes up together, whichever tab asked. The name, the
   * fields and the layout are one definition, and saving half of it would
   * silently drop whatever the other tab was holding.
   *
   * @param {Array} fields the field list to store, defaulting to the draft
   * @return {Promise<void>} Resolves once stored.
   */
  const persist = (fields = draft.fields) => {
    setSaving(true)
    setError('')

    return api
      .updateComponent(id, {
        title: draft.label.trim() || component.label,
        description: draft.description.trim(),
        fields,
      })
      .then((result) => {
        setComponent(result.component)
        setDraft(result.component)
        setSaving(false)
        onChanged()
      })
      .catch((failure) => {
        setSaving(false)
        setError(failure.message)
      })
  }

  return (
    <div className="flex flex-col gap-4">
      <header className="flex flex-col gap-0.5">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h1 className="min-w-0 truncate text-[20px] font-semibold leading-tight tracking-tight">
            {component.label}
          </h1>

          <Button variant="outline" size="sm" onClick={() => setConfiguring(true)}>
            <SlidersHorizontal />
            {__('Settings', 'schemapress')}
          </Button>
        </div>

        {component.description ? (
          <p className="max-w-prose text-[13px] leading-snug text-muted-foreground">
            {component.description}
          </p>
        ) : null}
      </header>

      {error ? <Alert variant="warning">{error}</Alert> : null}

      <Tabs
        tabs={[
          { value: 'schema', label: __('Schema', 'schemapress'), icon: Wrench },
          { value: 'form', label: __('Form', 'schemapress'), icon: LayoutList },
        ]}
        value={tab}
        onValueChange={setTab}
      >
        <TabPanel value="schema">
          <div className="flex flex-col gap-3">
            <Card>
              <CardBody>
                <FieldsEditor
                  fields={draft.fields}
                  fieldTypes={fieldTypes}
                  editing={id}
                  onChange={(next) => update({ fields: next })}
                />
              </CardBody>
            </Card>

            <div className="flex items-center gap-3">
              <Button disabled={!dirty || saving} onClick={() => persist()}>
                <Save />
                {saving ? __('Saving…', 'schemapress') : __('Save component', 'schemapress')}
              </Button>

              {dirty && !saving ? (
                <span className="text-[12px] text-muted-foreground">
                  {__('Unsaved changes', 'schemapress')}
                </span>
              ) : null}
            </div>
          </div>
        </TabPanel>

        <TabPanel value="form">
          {/* the Form tab saves through its own button, so it is handed a
              committing callback rather than the local draft setter */}
          <FormTab fields={draft.fields} onChange={persist} />
        </TabPanel>
      </Tabs>

      {configuring ? (
        <ComponentSettingsDialog
          component={component}
          onClose={() => setConfiguring(false)}
          onSave={(changes) =>
            api
              .updateComponent(id, {
                title: changes.label,
                description: changes.description,
                // the fields go up too: the name and the shape are one
                // definition, and posting half would drop the other half
                fields: draft.fields,
              })
              .then((result) => {
                setComponent(result.component)
                setDraft(result.component)
                onChanged()
              })
          }
          onDelete={() =>
            api
              .deleteComponent(id)
              .then(() => {
                onChanged()
                onDeleted()
              })
              .catch((failure) => setError(failure.message))
          }
        />
      ) : null}
    </div>
  )
}
