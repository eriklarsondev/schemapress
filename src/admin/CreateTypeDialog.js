/**
 * Making a collection type.
 *
 * One decision: what it is called. Type a name, press return.
 *
 * The machine key is shown, not asked for. It is derived from the name and
 * fixed at creation — it names the post type entries are stored against, so it
 * cannot follow a later rename without orphaning them. Showing it now is the
 * only chance to notice it before that matters.
 */

import { useEffect, useRef, useState } from '@wordpress/element'
import { __, sprintf } from '@wordpress/i18n'
import { Dialog, Button, Field, Input, Alert } from '../ui'
import { toKey } from '../shared/utils'
import { api } from '../shared/api'

/**
 * The create dialog.
 *
 * @param {Object} props
 * @return {JSX.Element} The dialog.
 */
export function CreateTypeDialog({ onClose, onCreated }) {
  const [name, setName] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  const input = useRef(null)

  useEffect(() => {
    input.current?.focus()
  }, [])

  const key = toKey(name).slice(0, 16)

  /**
   * Creates the type and hands it back.
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
      .createType(name.trim())
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
      title={__('Create a collection type', 'schemapress')}
      description={__(
        'A shape of content you have many of. You can add its fields next.',
        'schemapress'
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
          help={
            key
              ? sprintf(
                  /* translators: %s: the generated machine key */
                  __('Stored as %s — fixed once created.', 'schemapress'),
                  key
                )
              : __('Singular reads best: Team Member, News Article.', 'schemapress')
          }
        >
          {(id) => (
            <Input
              id={id}
              ref={input}
              value={name}
              placeholder={__('Team Member', 'schemapress')}
              onChange={(event) => setName(event.target.value)}
              onKeyDown={(event) => event.key === 'Enter' && create()}
            />
          )}
        </Field>

        {error ? <Alert variant="warning">{error}</Alert> : null}
      </div>
    </Dialog>
  )
}
