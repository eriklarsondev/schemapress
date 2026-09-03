/**
 * Making a component.
 *
 * Named for the shape it holds — Address, Call to action, Social links — not
 * for a thing you have many of, because a component is never counted. Nothing
 * is stored *as* one; it only ever appears inside something else.
 *
 * No key is shown, and none is fixed. A collection's key names the post type
 * its entries live in and so can never change; a component has no storage of
 * its own, so its name is only ever a label and renaming it costs nothing.
 */

import { useEffect, useRef, useState } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { Dialog, Button, Field, Input, Textarea, Alert } from '../ui'
import { api } from '../shared/api'

/**
 * The create dialog.
 *
 * @param {Object} props
 * @return {JSX.Element} The dialog.
 */
export function CreateComponentDialog({ onClose, onCreated }) {
  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  const input = useRef(null)

  useEffect(() => {
    input.current?.focus()
  }, [])

  /**
   * Creates the component and hands it back.
   *
   * @return {void}
   */
  const create = () => {
    if (name.trim() === '' || saving) {
      return
    }

    setSaving(true)
    setError('')

    api
      .createComponent(name.trim(), description.trim())
      .then(onCreated)
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
      title={__('Create a component', 'schemapress')}
      description={__(
        'A group of fields you can import into any collection. You can add its fields next.',
        'schemapress',
      )}
      footer={
        <>
          <Button variant="outline" onClick={onClose}>
            {__('Cancel', 'schemapress')}
          </Button>
          <Button disabled={name.trim() === '' || saving} onClick={create}>
            {saving ? __('Creating…', 'schemapress') : __('Create', 'schemapress')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <Field
          label={__('Name', 'schemapress')}
          help={__('Name it after the shape it holds: Address, Call to action.', 'schemapress')}
        >
          {(id) => (
            <Input
              id={id}
              ref={input}
              value={name}
              placeholder={__('Address', 'schemapress')}
              onChange={(event) => setName(event.target.value)}
              onKeyDown={(event) => event.key === 'Enter' && create()}
            />
          )}
        </Field>

        <Field
          label={__('Description', 'schemapress')}
          hint={__('Optional', 'schemapress')}
          help={__('Shown when picking it from the field list.', 'schemapress')}
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

        {error ? <Alert variant="warning">{error}</Alert> : null}
      </div>
    </Dialog>
  )
}
