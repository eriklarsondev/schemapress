/**
 * Defining what an entry is made of.
 *
 * This writes the schema, which every entry of the collection is then validated
 * and rendered against. It is the one screen here whose changes reach content
 * that already exists, so it says so, and it saves explicitly rather than as
 * you type — a half-typed field name should not become the shape of the data.
 */

import { useEffect, useState } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { Save, Users } from 'lucide-react'
import { Card, CardBody, Button, Alert } from '../../ui'
import { FieldsEditor } from '../../shared/builder/FieldEditor'
import { fieldTypes } from '../../shared/settings'

/**
 * The field definition editor.
 *
 * @param {Object} props
 * @return {JSX.Element} The tab.
 */
export function FieldsTab({ fields, onChange }) {
  const [draft, setDraft] = useState(fields)
  const [saving, setSaving] = useState(false)

  // the server normalizes what it stores, so an accepted save comes back
  // possibly changed — adopt it rather than keeping the local guess
  useEffect(() => {
    setDraft(fields)
  }, [fields])

  const dirty = JSON.stringify(draft) !== JSON.stringify(fields)

  /**
   * Stores the draft.
   *
   * @return {void}
   */
  const save = () => {
    setSaving(true)
    Promise.resolve(onChange(draft)).finally(() => setSaving(false))
  }

  return (
    <div className="flex flex-col gap-3">
      <Alert variant="info">
        <span className="flex items-start gap-2">
          <Users className="mt-0.5 size-3.5 shrink-0" />
          <span>
            {__(
              'These fields shape every entry in this collection. Removing one stops its saved values being delivered.',
              'schemapress'
            )}
          </span>
        </span>
      </Alert>

      <Card>
        <CardBody>
          <FieldsEditor fields={draft} fieldTypes={fieldTypes} onChange={setDraft} />
        </CardBody>
      </Card>

      <div className="flex items-center gap-3">
        <Button disabled={!dirty || saving} onClick={save}>
          <Save />
          {saving ? __('Saving…', 'schemapress') : __('Save fields', 'schemapress')}
        </Button>

        {dirty && !saving ? (
          <span className="text-[12px] text-muted-foreground">
            {__('Unsaved changes', 'schemapress')}
          </span>
        ) : null}
      </div>
    </div>
  )
}
