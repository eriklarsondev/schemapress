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

import { Fragment, useCallback, useEffect, useState } from '@wordpress/element'
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
import { missingRequired } from '../../shared/required'
import { UnsaveableProvider } from '../../shared/unsaveable'
import { clearUnsaved, useUnsavedGuard } from '../../shared/unsaved'
import {
  breakBefore,
  cellClass,
  gridClass,
  leadingSpace,
  rowBreakClass,
  spacerClass,
} from '../../shared/layout'
import { api } from '../../shared/api'

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
  // a conflict is not an ordinary error: it has an action attached, and the
  // action is what makes it recoverable rather than only regrettable
  const [conflict, setConflict] = useState(false)
  const [busy, setBusy] = useState(false)
  const [leaving, setLeaving] = useState(false)
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
    setConflict(false)

    work
      .then((result) => {
        adopt(result.entry)
        setBusy(false)
        // down before onSaved navigates, not on the next render
        clearUnsaved()
        onSaved()
      })
      .catch((failure) => {
        setBusy(false)
        setError(failure.message)
        setConflict(failure.code === 'schemapress_conflict')
      })
  }

  /**
   * Takes the current version of the entry, keeping what is typed here.
   *
   * The point of a conflict is that BOTH versions matter. Reloading the whole
   * form would throw away the work that could not be saved, which is the loss
   * the conflict check exists to prevent — so this adopts the other person's
   * version as the baseline and leaves the values on screen alone. Saving again
   * then goes through, and what it saves is this form.
   *
   * @return {void}
   */
  const takeLatest = () => {
    setBusy(true)

    api
      .entry(type.id, entry.id)
      .then((result) => {
        setEntry((current) => ({ ...result.entry, values: current.values }))
        setError('')
        setConflict(false)
      })
      .catch((failure) => setError(failure.message))
      .finally(() => setBusy(false))
  }

  /**
   * Saves the draft, optionally publishing it in the same act.
   *
   * No title is sent: the server derives one from the content, so the listing
   * follows what was written rather than a stale label.
   *
   * `expectedModified` IS sent, and is the version this form was built on. If
   * somebody else has saved the entry since it was loaded, the server refuses
   * with a 409 rather than replacing their work with a form that never saw it —
   * and the refusal says so, with what is typed here still on screen to copy
   * across. Losing an afternoon's writing to a colleague pressing save is the
   * kind of bug nobody reports, because neither person can tell it happened.
   *
   * @param {boolean} publish
   * @return {void}
   */
  const save = (publish = false) =>
    run(
      api.saveEntry(type.id, entry.id, {
        values: entry.values,
        publish,
        expectedModified: entry.modified,
      }),
    )

  // a save that would store what is already stored is not a save.
  //
  // this and the guard below sit ABOVE the early return, and have to. an entry
  // being opened is null until it arrives, so the render that returns Loading
  // ran one fewer hook than the render after it — which React refuses outright
  // with "rendered more hooks than during the previous render", and which meant
  // opening any existing entry threw
  // fields holding text they could not parse, and so could not commit. these
  // are NOT in entry.values by design — the last good value is still there —
  // which is exactly why the form has to be told about them separately, or a
  // save reports success while quietly storing the value somebody was in the
  // middle of replacing. see shared/unsaveable
  //
  // ABOVE THE EARLY RETURN, for the reason the note on `dirty` below gives:
  // these ran after it, so the render that shows Loading ran two fewer hooks
  // than the one after it and React refused the lot with error #310. An entry
  // being fetched is exactly the case, so opening any existing entry threw.
  const [unsaveable, setUnsaveable] = useState({})

  const reportUnsaveable = useCallback((id, label) => {
    setUnsaveable((current) => {
      if ((current[id] || null) === label) {
        return current
      }

      const next = { ...current }

      if (label) {
        next[id] = label
      } else {
        delete next[id]
      }

      return next
    })
  }, [])

  const dirty = entry ? JSON.stringify(entry.values || {}) !== saved : false

  useUnsavedGuard(dirty)

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

  // every required field still empty, anywhere in the form — inside groups and
  // inside each repeater row, and only counting fields actually on screen
  const missing = missingRequired(fields, entry.values)

  const unparsed = [...new Set(Object.values(unsaveable))]

  const incomplete = missing.length > 0 || unparsed.length > 0

  /**
   * What to say on a control that cannot be used yet.
   *
   * The names, not a count: "2 required fields" sends you hunting, and on a
   * long form the empty one is usually below the fold.
   *
   * @return {string} The message, or '' when nothing is missing.
   */
  const blocked = () => {
    // the unparseable ones first: an empty required field is a thing you have
    // not done yet, and a field full of broken JSON is a thing you have done
    // wrong — the second is the one you want naming when both are true
    if (unparsed.length > 0) {
      return sprintf(
        /* translators: %s: a comma-separated list of field names */
        __('%s cannot be saved as written', 'schemapress'),
        unparsed.join(', ')
      )
    }

    return missing.length > 0
      ? sprintf(
          /* translators: %s: a comma-separated list of field names */
          __('Fill in %s first', 'schemapress'),
          missing.map((field) => field.label).join(', ')
        )
      : ''
  }

  return (
    <div className="flex w-full flex-col gap-4">
      <nav className="flex items-center gap-1 text-[12px]">
        <button
          type="button"
          onClick={() => (dirty ? setLeaving(true) : onBack())}
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
          label={blocked() || (dirty || busy ? '' : __('No changes to save', 'schemapress'))}
          disabled={incomplete || (!dirty && !busy)}
        >
          <Button
            size="sm"
            disabled={busy || !dirty || incomplete}
            onClick={() => save(false)}
          >
            <Save />
            {busy ? __('Saving…', 'schemapress') : __('Save', 'schemapress')}
          </Button>
        </Tooltip>
      </header>

      {error ? (
        <Alert variant="warning">
          <span className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
            <span>{error}</span>

            {/* the offer, rather than only the news. taking their version keeps
                what is typed here, so the next save goes through and saves this
                form — nothing on screen is lost by pressing it */}
            {conflict ? (
              <Button size="sm" variant="outline" disabled={busy} onClick={takeLatest}>
                {__('Keep mine and continue', 'schemapress')}
              </Button>
            ) : null}
          </span>
        </Alert>
      ) : null}

      <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]">
        <Card>
          <CardBody>
            {fields.length === 0 ? (
              <Alert variant="warning">
                {__('This collection has no fields yet. Add some in the Schema tab.', 'schemapress')}
              </Alert>
            ) : (
              <UnsaveableProvider onChange={reportUnsaveable}>
              <div className={gridClass()}>
                {visible.map((field, index) => (
                  <Fragment key={field.key}>
                    {breakBefore(field, index) ? (
                      <div aria-hidden="true" className={rowBreakClass()} />
                    ) : null}

                    {leadingSpace(field) > 0 ? (
                      <div aria-hidden="true" className={spacerClass(leadingSpace(field))} />
                    ) : null}

                    <div className={cellClass(field)}>
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
                  </Fragment>
                ))}
              </div>
              </UnsaveableProvider>
            )}
          </CardBody>
        </Card>

        <aside className="flex flex-col gap-3 lg:sticky lg:top-6 lg:self-start">
          {drafts ? (
            <StatusCard
              entry={entry}
              busy={busy}
              blocked={blocked()}
              onPublish={() => save(true)}
              onUnpublish={() => run(api.unpublishEntry(type.id, entry.id))}
              onDiscard={() => run(api.discardDraft(type.id, entry.id))}
            />
          ) : null}

          {entry.id ? <IdCard entry={entry} type={type} /> : null}

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

      {leaving ? (
        <ConfirmDialog
          open
          onOpenChange={(next) => !next && setLeaving(false)}
          title={__('Leave without saving?', 'schemapress')}
          description={
            incomplete
              ? __(
                  'This entry has changes that have not been saved, and required fields still to fill in. Leaving loses the changes.',
                  'schemapress'
                )
              : __(
                  'This entry has changes that have not been saved. Leaving loses them.',
                  'schemapress'
                )
          }
          confirmLabel={__('Leave', 'schemapress')}
          onConfirm={() => {
            clearUnsaved()
            onBack()
          }}
        />
      ) : null}

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
                clearUnsaved()
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
function StatusCard({ entry, busy, blocked, onPublish, onUnpublish, onDiscard }) {
  const [confirming, setConfirming] = useState('')

  const states = {
    published: { label: __('Published', 'schemapress'), tone: 'text-emerald-600' },
    modified: { label: __('Published, edited', 'schemapress'), tone: 'text-amber-600' },
    draft: { label: __('Draft', 'schemapress'), tone: 'text-muted-foreground' }
  }

  const state = states[entry.state] || states.draft

  // the three acts that move an entry between states, always all three and
  // always in the same places — a control that comes and goes has to be found
  // again every time, where a grayed-out one can be learned once
  //
  // each one asks first. they are icon buttons sitting side by side, all three
  // change what the public sees, and two of them cannot be undone — the click
  // is too cheap for the consequence
  const actions = [
    {
      key: 'publish',
      icon: CloudUpload,
      variant: 'default',
      wide: true,
      // an entry that has never been saved has no row to promote: publishing
      // it would have to create it and make it live in one act, which is a
      // decision the click does not look like it is making. save first, and
      // from then on publishing saves whatever is on screen as part of the same
      // step — see EntryView.save
      enabled: Boolean(entry.id) && entry.state !== 'published' && !blocked,
      onClick: onPublish,
      label: entry.isPublished
        ? __('Publish changes', 'schemapress')
        : __('Publish', 'schemapress'),
      unavailable:
        blocked ||
        (entry.id
          ? __('Nothing new to publish', 'schemapress')
          : __('Save this entry first', 'schemapress')),
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
      label: __('Discard', 'schemapress'),
      unavailable: __('No unpublished changes', 'schemapress'),
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
      unavailable: __('Not published', 'schemapress'),
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

        {/* publishing is the act you came here for, so it gets the full width
            and reads as a sentence; the two that undo it share the row below */}
        <div className="grid grid-cols-2 gap-1.5">
          {actions.map((action) => (
            // the span lives on a wrapper, not on the button: a disabled
            // button is wrapped again by the tooltip, and the grid only sees
            // its own direct children
            <div key={action.key} className={cn('min-w-0', action.wide && 'col-span-2')}>
              <Tooltip
                stretch
                label={action.enabled ? '' : action.unavailable}
                disabled={busy || !action.enabled}
              >
                {/* the tooltip wraps a disabled button in a span of its own,
                    so that span has to stretch too or the button inside it
                    only fills the text it contains */}
                <Button
                  variant={action.variant}
                  size="sm"
                  className="w-full"
                  disabled={busy || !action.enabled}
                  onClick={() => setConfirming(action.key)}
                >
                  <action.icon />
                  {action.label}
                </Button>
              </Tooltip>
            </div>
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
 * The address this entry answers on.
 *
 * ONE address, the one a front end would actually use. This showed the id and
 * the slug as two separate values, and for a collection with no slug field they
 * were the same uuid printed twice — because an entry with nothing to build a
 * slug from is given its uuid as one. Two copies of the same string under two
 * headings reads as two different things, and neither was the URL anybody was
 * here to copy.
 *
 * So: a collection that builds slugs from a field is addressed by the slug,
 * which is what a route like /team/ada-lovelace has in hand. One that does not
 * is addressed by the id. Both resolve — Entries::resolve tries the uuid and
 * then the slug — so the choice is only which one is worth handing over.
 *
 * The address is built the way the collection settings dialog builds it, so
 * the two copy buttons never disagree about where a collection lives.
 *
 * @param {Object} props
 * @return {JSX.Element} The card.
 */
function IdCard({ entry, type }) {
  // a slug that IS the uuid is the fallback, not a slug anybody chose
  const bySlug = Boolean(entry.slug) && entry.slug !== entry.id
  const ref = bySlug ? entry.slug : entry.id
  const url = `${window.location.origin}/wp-json/schemapress/api/${type.apiSlug}/${ref}`

  // the address exists whether or not anything answers on it. saying when it
  // will not is cheaper than somebody pasting it into a browser to find out
  const closed = !type.publicApi?.single
  const unpublished = !entry.isPublished

  return (
    <Card>
      <CardBody className="flex flex-col gap-2">
        <Label>
          {bySlug ? __('Endpoint · by slug', 'schemapress') : __('Endpoint · by ID', 'schemapress')}
        </Label>

        <Copyable value={url} label={__('Copy endpoint', 'schemapress')} />

        {closed || unpublished ? (
          <p className="text-[12px] leading-relaxed text-muted-foreground">
            {closed
              ? __(
                  'Not answering yet: turn on Read one in this collection’s settings.',
                  'schemapress',
                )
              : __('Not answering yet: publish this entry first.', 'schemapress')}
          </p>
        ) : null}
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
function Label({ children, className }) {
  return (
    <p
      className={cn(
        'text-[11px] font-semibold uppercase tracking-wide text-muted-foreground',
        className,
      )}
    >
      {children}
    </p>
  )
}

/**
 * One labeled fact.
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
