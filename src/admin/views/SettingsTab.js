/**
 * What a collection is, rather than what is in it.
 *
 * Its name, the note explaining what belongs here, and whether entries get a
 * working copy separate from what the site serves. Three settings, and the last
 * one changes the shape of every entry screen underneath it — which is why it
 * asks before turning itself off rather than after.
 *
 * The machine keys are shown and not editable. They were fixed when the type
 * was created, because the singular one names the post type entries are stored
 * against: changing it would leave every existing entry addressed to a post
 * type nothing declares any more.
 */

import { useEffect, useState } from '@wordpress/element'
import { __, sprintf } from '@wordpress/i18n'
import { Save, GitBranch, Zap } from 'lucide-react'
import {
  Card,
  CardBody,
  Field,
  Input,
  Textarea,
  Button,
  Badge,
  Alert,
  Switch,
  ConfirmDialog,
} from '../../ui'

/**
 * The settings tab.
 *
 * @param {Object} props
 * @return {JSX.Element} The tab.
 */
export function SettingsTab({ type, settings, onSave }) {
  const [name, setName] = useState(type.label || '')
  const [description, setDescription] = useState(type.description || '')
  const [drafts, setDrafts] = useState(settings.draftAndPublish !== false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [confirming, setConfirming] = useState(false)

  // switching collections reuses this component, so the fields have to follow
  useEffect(() => {
    setName(type.label || '')
    setDescription(type.description || '')
    setDrafts(settings.draftAndPublish !== false)
  }, [type.id, type.label, type.description, settings.draftAndPublish])

  const dirty =
    name.trim() !== (type.label || '') ||
    description.trim() !== (type.description || '') ||
    drafts !== (settings.draftAndPublish !== false)

  /**
   * Stores the settings.
   *
   * @return {void}
   */
  const save = () => {
    setSaving(true)
    setError('')

    Promise.resolve(
      onSave({
        title: name.trim() || type.label,
        description: description.trim(),
        settings: { draftAndPublish: drafts },
      }),
    )
      .then(() => setSaving(false))
      .catch((failure) => {
        setSaving(false)
        setError(failure.message)
      })
  }

  return (
    <div className="flex flex-col gap-4">
      {error ? <Alert variant="warning">{error}</Alert> : null}

      {/* what the collection is called on the left, how publishing works on the
          right: one column is what you edit, the other is what you decide once */}
      <div className="grid items-start gap-4 lg:grid-cols-2">
        <Card>
          <CardBody className="flex flex-col gap-4">
            <Field
              label={__('Name', 'schemapress')}
              help={__('What people see. The machine keys below do not follow it.', 'schemapress')}
            >
              {(id) => (
                <Input id={id} value={name} onChange={(event) => setName(event.target.value)} />
              )}
            </Field>

            <Field
              label={__('Description', 'schemapress')}
              hint={__('Optional', 'schemapress')}
              help={__('Shown under the name on this screen.', 'schemapress')}
            >
              {(id) => (
                <Textarea
                  id={id}
                  rows={2}
                  value={description}
                  placeholder={__('The people we list on the about page.', 'schemapress')}
                  onChange={(event) => setDescription(event.target.value)}
                />
              )}
            </Field>

            <div className="flex flex-col gap-1.5">
              <p className="text-[13px] font-medium leading-none">
                {__('Machine keys', 'schemapress')}
              </p>

              <p className="flex flex-wrap items-center gap-1.5">
                <Badge variant="mono">{type.key}</Badge>
                {type.plural && type.plural !== type.key ? (
                  <Badge variant="mono">{type.plural}</Badge>
                ) : null}
              </p>

              <p className="text-[12px] text-muted-foreground">
                {__('What templates ask for. Fixed when the collection was made.', 'schemapress')}
              </p>
            </div>
          </CardBody>
        </Card>

        <Card>
          <CardBody className="flex flex-col gap-3">
            <div className="flex items-start justify-between gap-4">
              <div className="min-w-0">
                <p className="flex items-center gap-1.5 text-[13px] font-medium">
                  {drafts ? (
                    <GitBranch className="size-3.5 text-muted-foreground" />
                  ) : (
                    <Zap className="size-3.5 text-muted-foreground" />
                  )}
                  {__('Draft and publish', 'schemapress')}
                </p>

                <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
                  {drafts
                    ? __(
                        'Saving writes a draft. Nothing reaches the site until you publish it, so a half-finished edit to a live entry stays private.',
                        'schemapress',
                      )
                    : __(
                        'Saving publishes. There is one copy of each entry and it is always the one the site is serving.',
                        'schemapress',
                      )}
                </p>
              </div>

              {/* the kit's Switch takes onChange, not Radix's onCheckedChange */}
              <Switch
                checked={drafts}
                aria-label={__('Draft and publish', 'schemapress')}
                onChange={(next) => (next ? setDrafts(true) : setConfirming(true))}
              />
            </div>
          </CardBody>
        </Card>
      </div>

      <div className="flex items-center gap-3">
        <Button disabled={!dirty || saving} onClick={save}>
          <Save />
          {saving ? __('Saving…', 'schemapress') : __('Save settings', 'schemapress')}
        </Button>

        {dirty && !saving ? (
          <span className="text-[12px] text-muted-foreground">
            {__('Unsaved changes', 'schemapress')}
          </span>
        ) : null}
      </div>

      {/* turning drafts off is the direction that loses something: every draft
          in the collection becomes live the next time it is saved, and there is
          no longer a copy to hold work back in */}
      {confirming ? (
        <ConfirmDialog
          open
          onOpenChange={(next) => !next && setConfirming(false)}
          title={__('Turn off draft and publish?', 'schemapress')}
          description={sprintf(
            /* translators: %s: the plural name of the collection */
            __(
              'Saving a %s will publish it immediately, and unpublished drafts go live the next time they are saved. You can turn this back on later.',
              'schemapress',
            ),
            (type.singularLabel || type.label || '').toLowerCase(),
          )}
          confirmLabel={__('Turn it off', 'schemapress')}
          onConfirm={() => {
            setDrafts(false)
            setConfirming(false)
          }}
        />
      ) : null}
    </div>
  )
}
