/**
 * What a component is, rather than what is in it.
 *
 * Its name, the note shown when picking it from the field list, and whether it
 * goes on existing. Behind a button rather than a permanent card, for the same
 * reason as a collection's settings: none of it is work, it is decided once and
 * revisited twice a year.
 */

import { useState } from '@wordpress/element'
import { __, sprintf } from '@wordpress/i18n'
import { Trash2, Blocks } from 'lucide-react'
import {
  Dialog,
  Card,
  CardBody,
  Field,
  Input,
  Textarea,
  Button,
  Alert,
  ConfirmDialog,
} from '../../ui'

/**
 * The component settings dialog.
 *
 * @param {Object} props
 * @return {JSX.Element} The dialog.
 */
export function ComponentSettingsDialog({ component, onClose, onSave, onDelete }) {
  const [name, setName] = useState(component.label || '')
  const [description, setDescription] = useState(component.description || '')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [confirming, setConfirming] = useState(false)

  const dirty =
    name.trim() !== (component.label || '') || description.trim() !== (component.description || '')

  /**
   * Stores the settings.
   *
   * @return {void}
   */
  const save = () => {
    setSaving(true)
    setError('')

    Promise.resolve(
      onSave({ label: name.trim() || component.label, description: description.trim() }),
    )
      .then(onClose)
      .catch((failure) => {
        setSaving(false)
        setError(failure.message)
      })
  }

  return (
    <Dialog
      open
      size="md"
      onOpenChange={(next) => !next && onClose()}
      title={sprintf(
        /* translators: %s: the component's name */
        __('%s settings', 'schemapress'),
        component.label,
      )}
      description={__('What this component is, and where it shows up.', 'schemapress')}
      footer={
        <>
          <Button
            variant="destructive-outline"
            className="mr-auto"
            onClick={() => setConfirming(true)}
          >
            <Trash2 />
            {__('Delete component', 'schemapress')}
          </Button>

          <Button variant="outline" onClick={onClose}>
            {__('Cancel', 'schemapress')}
          </Button>

          <Button disabled={!dirty || saving} onClick={save}>
            {saving ? __('Saving…', 'schemapress') : __('Save settings', 'schemapress')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {error ? <Alert variant="warning">{error}</Alert> : null}

        <Card>
          <CardBody className="flex flex-col gap-4">
            <Field label={__('Name', 'schemapress')}>
              {(id) => (
                <Input id={id} value={name} onChange={(event) => setName(event.target.value)} />
              )}
            </Field>

            <Field
              label={__('Description', 'schemapress')}
              hint={__('Optional', 'schemapress')}
              help={__('Shown when picking this from the field list.', 'schemapress')}
            >
              {(id) => (
                <Textarea
                  id={id}
                  rows={2}
                  value={description}
                  placeholder={__('A street, a city and a postcode.', 'schemapress')}
                  onChange={(event) => setDescription(event.target.value)}
                />
              )}
            </Field>
          </CardBody>
        </Card>

        {/* stated here rather than on the editing screen, where it would be the
            same sentence every visit forever */}
        <Alert variant="info">
          <span className="flex items-start gap-2">
            <Blocks className="mt-0.5 size-3.5 shrink-0" />
            <span>
              {__(
                'Importing this into a collection copies its fields in. Editing it afterwards does not reach collections that already imported it.',
                'schemapress',
              )}
            </span>
          </span>
        </Alert>
      </div>

      {confirming ? (
        <ConfirmDialog
          open
          onOpenChange={(next) => !next && setConfirming(false)}
          title={__('Delete this component?', 'schemapress')}
          description={sprintf(
            /* translators: %s: the component's name */
            __(
              'Collections that already imported “%s” keep their copy of the fields — importing copies rather than links, so nothing they hold is lost.',
              'schemapress',
            ),
            component.label,
          )}
          confirmLabel={__('Delete', 'schemapress')}
          onConfirm={() => {
            setConfirming(false)
            onDelete()
          }}
        />
      ) : null}
    </Dialog>
  )
}
