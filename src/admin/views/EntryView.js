/**
 * One entry, as a form.
 *
 * The page is the fields and nothing else. Everything that is *about* the entry
 * rather than *in* it — whether it is published, how far the draft has run
 * ahead, deleting it — lives in the sidebar, so the main column is only ever
 * the thing being written.
 *
 * There is no Title input. The listing title is derived from the entry's own
 * first text field, so asking for it separately would be asking twice and
 * inviting the two to disagree.
 *
 * Saving writes the draft. The published copy does not move until you publish,
 * so editing a live entry never takes it off the site half-finished — unless
 * the collection has draft and publish turned off, in which case there is only
 * one copy, saving is publishing, and the status card has nothing to say.
 */

import { useEffect, useState } from '@wordpress/element'
import { __, sprintf, _n } from '@wordpress/i18n'
import { ChevronLeft, Save, Trash2, CircleDot, GitBranch, Undo2, CloudUpload, EyeOff } from 'lucide-react'
import {
  Button,
  Card,
  CardBody,
  Loading,
  Alert,
  ConfirmDialog,
  Tooltip,
  Copyable,
  cn
} from '../../ui'
import { FieldControl } from '../../shared/fields'
import { emptyValues } from '../../shared/utils'
import { Ago } from '../../shared/time'
import { visibleFields } from '../../shared/conditions'
import { api } from '../../shared/api'

/**
 * The column span for each width the Form tab can set. Written out because
 * Tailwind cannot see a computed class name; unknown or unset falls back to
 * full, so a field never disappears because its width was mis-set.
 */
const SPANS = {
  third: 'sm:col-span-4',
  half: 'sm:col-span-6',
  'two-thirds': 'sm:col-span-8',
  full: 'sm:col-span-12'
}

/**
 * The entry editor.
 *
 * @param {Object} props
 * @return {JSX.Element} The view.
 */
export function EntryView({ type, fields, entryId, onBack, onSaved }) {
  const [entry, setEntry] = useState(() =>
    entryId
      ? null
      : { id: null, title: '', state: 'draft', isPublished: false, ahead: 0, values: emptyValues(fields) }
  )
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [removing, setRemoving] = useState(false)

  // what the server last confirmed, so "has anything changed" is a question
  // about this form rather than about the counter the server keeps. the shape
  // is only ever built by spreading the previous one, so key order is stable
  // and serializing is a sound comparison
  const [saved, setSaved] = useState(() => (entryId ? '' : JSON.stringify(emptyValues(fields))))

  /**
   * Adopts an entry the server returned, and treats it as the new baseline.
   *
   * @param {Object} next
   * @return {void}
   */
  const adopt = (next) => {
    setEntry(next)
    setSaved(JSON.stringify(next.values || {}))
  }

  useEffect(() => {
    if (!entryId) {
      return undefined
    }

    let live = true

    api
      .entry(type.id, entryId)
      .then((result) => live && adopt(result.entry))
      .catch((failure) => live && setError(failure.message))

    return () => {
      live = false
    }
  }, [type.id, entryId])

  /**
   * Runs a promise that returns an entry, holding the screen while it does.
   *
   * @param {Promise} work
   * @return {void}
   */
  const run = (work) => {
    setBusy(true)
    setError('')

    work
      .then((result) => {
        adopt(result.entry)
        setBusy(false)
        onSaved()
      })
      .catch((failure) => {
        setBusy(false)
        setError(failure.message)
      })
  }

  /**
   * Saves the draft, optionally publishing it in the same act.
   *
   * No title is sent: the server derives one from the content, so the listing
   * follows what was written rather than a stale label.
   *
   * @param {boolean} publish
   * @return {void}
   */
  const save = (publish = false) =>
    run(api.saveEntry(type.id, entry.id, { values: entry.values, publish }))

  if (!entry) {
    return error ? (
      <div className="flex flex-col gap-3">
        <Alert variant="warning">{error}</Alert>
        <div>
          <Button variant="outline" size="sm" onClick={onBack}>
            {__('Back', 'schemapress')}
          </Button>
        </div>
      </div>
    ) : (
      <Loading label={__('Loading…', 'schemapress')} />
    )
  }

  const visible = visibleFields(fields, entry.values)

  // with drafts off there is one copy of an entry, so there is nothing to
  // publish, discard or take down — and no state worth a card
  const drafts = type.draftAndPublish !== false

  // a save that would store what is already stored is not a save
  const dirty = JSON.stringify(entry.values || {}) !== saved

  return (
    <div className="flex w-full flex-col gap-4">
      <nav className="flex items-center gap-1 text-[12px]">
        <button
          type="button"
          onClick={onBack}
          className="flex items-center gap-1 rounded px-1.5 py-1 font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
        >
          <ChevronLeft className="size-3.5" />
          {type.pluralLabel || type.label}
        </button>
      </nav>

      <header className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="min-w-0 truncate text-[20px] font-semibold tracking-tight">
          {entry.id
            ? entry.title || __('Untitled', 'schemapress')
            : sprintf(
                /* translators: %s: the collection's singular name */
                __('New %s', 'schemapress'),
                type.singularLabel || type.label
              )}
        </h1>

        <Tooltip
          label={dirty || busy ? '' : __('No changes to save', 'schemapress')}
          disabled={!dirty || busy}
        >
          <Button size="sm" disabled={busy || !dirty} onClick={() => save(false)}>
            <Save />
            {busy ? __('Saving…', 'schemapress') : __('Save', 'schemapress')}
          </Button>
        </Tooltip>
      </header>

      {error ? <Alert variant="warning">{error}</Alert> : null}

      <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]">
        <Card>
          <CardBody>
            {fields.length === 0 ? (
              <Alert variant="warning">
                {__('This collection has no fields yet. Add some in the Fields tab.', 'schemapress')}
              </Alert>
            ) : (
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-12">
                {visible.map((field) => (
                  <div
                    key={field.key}
                    className={cn('min-w-0', SPANS[field.config?.width] || SPANS.full)}
                  >
                    <FieldControl
                      field={field}
                      value={entry.values?.[field.key]}
                      onChange={(value) =>
                        setEntry({
                          ...entry,
                          values: { ...entry.values, [field.key]: value }
                        })
                      }
                    />
                  </div>
                ))}
              </div>
            )}
          </CardBody>
        </Card>

        <aside className="flex flex-col gap-3 lg:sticky lg:top-6 lg:self-start">
          {drafts ? (
            <StatusCard
              entry={entry}
              busy={busy}
              onPublish={() => save(true)}
              onUnpublish={() => run(api.unpublishEntry(type.id, entry.id))}
              onDiscard={() => run(api.discardDraft(type.id, entry.id))}
            />
          ) : null}

          {entry.id ? <IdCard entry={entry} /> : null}

          {entry.id ? <DetailsCard entry={entry} drafts={drafts} /> : null}

          {entry.id ? (
            <Card>
              <CardBody className="flex flex-col gap-2">
                <Label>{__('Danger zone', 'schemapress')}</Label>

                {/* red before you touch it, not on hover: what the button does
                    is not the kind of thing you should have to discover */}
                <Button
                  variant="destructive-outline"
                  size="sm"
                  className="w-full"
                  onClick={() => setRemoving(true)}
                >
                  <Trash2 />
                  {__('Delete entry', 'schemapress')}
                </Button>
              </CardBody>
            </Card>
          ) : null}
        </aside>
      </div>

      {removing ? (
        <ConfirmDialog
          open
          onOpenChange={(next) => !next && setRemoving(false)}
          title={__('Delete this entry?', 'schemapress')}
          description={__('It will be moved to the trash.', 'schemapress')}
          confirmLabel={__('Delete', 'schemapress')}
          onConfirm={() =>
            api
              .deleteEntry(type.id, entry.id)
              .then(() => {
                onSaved()
                onBack()
              })
              .catch((failure) => setError(failure.message))
          }
        />
      ) : null}
    </div>
  )
}

/**
 * Where this entry stands, and what can be done about it.
 *
 * The draft branches off the published copy, so the interesting number is how
 * far it has run ahead — which is also the only thing that tells you there is
 * unpublished work here at all.
 *
 * @param {Object} props
 * @return {JSX.Element} The card.
 */
function StatusCard({ entry, busy, onPublish, onUnpublish, onDiscard }) {
  const [confirming, setConfirming] = useState('')

  const states = {
    published: { label: __('Published', 'schemapress'), tone: 'text-emerald-600' },
    modified: { label: __('Published, edited', 'schemapress'), tone: 'text-amber-600' },
    draft: { label: __('Draft', 'schemapress'), tone: 'text-muted-foreground' }
  }

  const state = states[entry.state] || states.draft

  // the three acts that move an entry between states, always all three and
  // always in the same places — a control that comes and goes has to be found
  // again every time, where a greyed-out one can be learned once
  //
  // each one asks first. they are icon buttons sitting side by side, all three
  // change what the public sees, and two of them cannot be undone — the click
  // is too cheap for the consequence
  const actions = [
    {
      key: 'publish',
      icon: CloudUpload,
      variant: 'default',
      enabled: entry.state !== 'published',
      onClick: onPublish,
      label: __('Publish changes', 'schemapress'),
      confirm: {
        destructive: false,
        title: __('Publish changes?', 'schemapress'),
        description: __(
          'This entry becomes what the front end serves, replacing whatever is there now.',
          'schemapress'
        ),
        confirmLabel: __('Publish', 'schemapress')
      }
    },
    {
      key: 'discard',
      icon: Undo2,
      variant: 'outline',
      enabled: entry.ahead > 0,
      onClick: onDiscard,
      label: __('Discard changes', 'schemapress'),
      confirm: {
        destructive: true,
        title: __('Discard changes?', 'schemapress'),
        description: sprintf(
          /* translators: %d: number of unpublished changes */
          _n(
            'The %d unpublished change on this entry is thrown away and the published copy comes back. This cannot be undone.',
            'The %d unpublished changes on this entry are thrown away and the published copy comes back. This cannot be undone.',
            entry.ahead,
            'schemapress'
          ),
          entry.ahead
        ),
        confirmLabel: __('Discard', 'schemapress')
      }
    },
    {
      key: 'unpublish',
      icon: EyeOff,
      variant: 'outline',
      enabled: entry.isPublished,
      onClick: onUnpublish,
      label: __('Unpublish', 'schemapress'),
      confirm: {
        destructive: true,
        title: __('Unpublish this entry?', 'schemapress'),
        description: __(
          'It comes off the front end immediately. The draft is kept, so you can publish it again later.',
          'schemapress'
        ),
        confirmLabel: __('Unpublish', 'schemapress')
      }
    }
  ]

  const pending = actions.find((action) => action.key === confirming)

  return (
    <Card>
      <CardBody className="flex flex-col gap-3">
        <Label>{__('Status', 'schemapress')}</Label>

        <p className="flex items-center gap-1.5 text-[13px] font-semibold">
          <CircleDot className={cn('size-3.5', state.tone)} />
          {state.label}
        </p>

        {entry.ahead > 0 ? (
          <p className="flex items-start gap-1.5 rounded-md bg-amber-50 px-2 py-1.5 text-[12px] text-amber-900">
            <GitBranch className="mt-0.5 size-3.5 shrink-0" />
            <span>
              {sprintf(
                /* translators: %d: number of unpublished changes */
                _n(
                  '%d change ahead of published',
                  '%d changes ahead of published',
                  entry.ahead,
                  'schemapress'
                ),
                entry.ahead
              )}
            </span>
          </p>
        ) : null}

        <div className="grid grid-cols-3 gap-1.5">
          {actions.map((action) => (
            <Tooltip key={action.key} label={action.label} disabled={busy || !action.enabled}>
              <Button
                variant={action.variant}
                className="w-full"
                disabled={busy || !action.enabled}
                onClick={() => setConfirming(action.key)}
                aria-label={action.label}
              >
                <action.icon />
              </Button>
            </Tooltip>
          ))}
        </div>
      </CardBody>

      {pending ? (
        <ConfirmDialog
          open
          onOpenChange={(next) => !next && setConfirming('')}
          title={pending.confirm.title}
          description={pending.confirm.description}
          confirmLabel={pending.confirm.confirmLabel}
          destructive={pending.confirm.destructive}
          onConfirm={() => {
            setConfirming('')
            pending.onClick()
          }}
        />
      ) : null}
    </Card>
  )
}

/**
 * What this entry is called from outside.
 *
 * Its own card, because the id is the one thing here that gets used somewhere
 * else — pasted into a template, a URL, a bug report — rather than read. That
 * makes it an action, not a detail, and it earns the room to show all 36
 * characters instead of trailing off inside a row.
 *
 * @param {Object} props
 * @return {JSX.Element} The card.
 */
function IdCard({ entry }) {
  return (
    <Card>
      <CardBody className="flex flex-col gap-2">
        <Label>{__('ID', 'schemapress')}</Label>

        <Copyable value={entry.id} label={__('Copy entry ID', 'schemapress')} />
      </CardBody>
    </Card>
  )
}

/**
 * When things happened.
 *
 * @param {Object} props
 * @return {JSX.Element} The card.
 */
function DetailsCard({ entry, drafts }) {
  return (
    <Card>
      <CardBody className="flex flex-col gap-2.5">
        <Label>{__('Details', 'schemapress')}</Label>

        <dl className="flex flex-col gap-1.5 text-[12px]">
          <Detail label={__('Last edited', 'schemapress')}>
            <Ago stamp={entry.modified} className="text-muted-foreground" />
          </Detail>

          {/* when saving is publishing, the two timestamps are the same fact */}
          {drafts ? (
            <Detail label={__('Published', 'schemapress')}>
              <Ago stamp={entry.publishedAt} className="text-muted-foreground" />
            </Detail>
          ) : null}
        </dl>
      </CardBody>
    </Card>
  )
}

/**
 * A sidebar card heading.
 *
 * @param {Object} props
 * @return {JSX.Element} The heading.
 */
function Label({ children }) {
  return (
    <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
      {children}
    </p>
  )
}

/**
 * One labelled fact.
 *
 * @param {Object} props
 * @return {JSX.Element} The row.
 */
function Detail({ label, children }) {
  return (
    <div className="flex items-center justify-between gap-2">
      <dt className="shrink-0 text-muted-foreground">{label}</dt>
      <dd className="min-w-0 truncate text-right">{children}</dd>
    </div>
  )
}
