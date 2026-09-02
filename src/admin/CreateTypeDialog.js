/**
 * Making a collection type.
 *
 * One decision: what it is called. Type a name, press return.
 *
 * A collection is named for ONE of the things in it — Team Member, not Team
 * Members — because everything else is derived from that: the machine key, the
 * post type, the "New Team Member" button. Typing the plural is the obvious
 * mistake to make, so the dialog notices and offers the singular rather than
 * silently naming the post type after it.
 *
 * The keys are shown, not asked for. They are fixed at creation — the singular
 * names the post type entries are stored against, so it cannot follow a later
 * rename without orphaning them. Showing them now is the only chance to notice
 * before that matters.
 */

import { useEffect, useRef, useState } from '@wordpress/element'
import { __, sprintf } from '@wordpress/i18n'
import { Dialog, Button, Field, Input, Alert } from '../ui'
import { toKey } from '../shared/utils'
import { lastWord, looksPlural, singularize } from '../shared/inflect'
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

  // previewed here, derived for real on the server
  const singular = lastWord(name, singularize)
  const key = toKey(singular).slice(0, 16)
  const plural = looksPlural(name) ? toKey(name).slice(0, 16) : ''

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
          help={
            key
              ? sprintf(
                  /* translators: %s: the generated machine key */
                  __('Stored as %s — fixed once created.', 'schemapress'),
                  key,
                )
              : __('Name it after one item: Team Member, News Article.', 'schemapress')
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

        {plural ? (
          <Alert variant="info">
            <span className="flex flex-wrap items-center gap-1.5">
              <span>
                {sprintf(
                  /* translators: %s: the singular form of what was typed */
                  __('That reads as a plural. It will be stored as “%s”.', 'schemapress'),
                  singular,
                )}
              </span>

              <button
                type="button"
                onClick={() => setName(singular)}
                className="rounded border border-border bg-background px-1.5 py-0.5 text-[12px] font-medium transition-colors hover:bg-accent"
              >
                {__('Use the singular', 'schemapress')}
              </button>
            </span>
          </Alert>
        ) : null}

        {error ? <Alert variant="warning">{error}</Alert> : null}
      </div>
    </Dialog>
  )
}
