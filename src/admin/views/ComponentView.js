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
import { __ } from '@wordpress/i18n'
import { Save, Wrench, LayoutList, SlidersHorizontal } from 'lucide-react'
import { Card, CardBody, Loading, Alert, Button, Tabs, TabPanel, ConfirmDialog } from '../../ui'
import { FieldsEditor } from '../../shared/builder/FieldEditor'
import { FormTab } from './FormTab'
import { ComponentSettingsDialog } from './ComponentSettingsDialog'
import { fieldTypes } from '../../shared/settings'
import { api } from '../../shared/api'
import { hasUnsavedPanel, unsavedMessage, useUnsavedGuard, PANEL } from '../../shared/unsaved'

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
  // the tab somebody is trying to reach with work unsaved on the one they are
  // on, held until they say whether to lose it
  const [leaving, setLeaving] = useState('')

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

  // ABOVE the early returns, and it has to be: a component being fetched is
  // null until it arrives, so a hook after them would run on one render and not
  // the next, which React refuses outright. Nothing is loaded yet at that
  // point, so nothing is unsaved either
  useUnsavedGuard(
    Boolean(component) && Boolean(draft) && JSON.stringify(draft) !== JSON.stringify(component),
    __('This component has changes that have not been saved. Leaving loses them.', 'schemapress')
  )

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

  // this draft outlives a tab change — it is held here rather than in a panel —
  // but not a move to another component in the sidebar, or a reload. registered
  // above, where the hook rules put it
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
   * `expectedModified` is the version this editor was built on. A component
   * save replaces the WHOLE field list, so without it two people with the same
   * component open in two tabs meant the second save deleted whatever the first
   * had added — and a component is imported by copy, so that loss travels into
   * every collection that imports it afterwards.
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
        expectedModified: component.modified,
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
        // a panel unmounts when you leave it, so the Form tab's arrangement is
        // gone before anything else could ask about it
        onValueChange={(next) => (hasUnsavedPanel() ? setLeaving(next) : setTab(next))}
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

      {leaving ? (
        <ConfirmDialog
          open
          onOpenChange={(next) => !next && setLeaving('')}
          title={__('Leave without saving?', 'schemapress')}
          description={unsavedMessage(PANEL)}
          confirmLabel={__('Leave', 'schemapress')}
          onConfirm={() => {
            // nothing to clear by hand: the panel unmounts on the way out and
            // takes its registration with it. this view's own draft is NOT
            // lost by changing tabs, and must keep its guard
            setTab(leaving)
            setLeaving('')
          }}
        />
      ) : null}

      {configuring ? (
        <ComponentSettingsDialog
          component={component}
          onClose={() => setConfiguring(false)}
          onSave={(changes) =>
            api
              .updateComponent(id, {
                title: changes.label,
                description: changes.description,
                expectedModified: component.modified,
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
