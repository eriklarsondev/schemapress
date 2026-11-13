/**
 * Pointing at entries of another collection.
 *
 * What is stored is an identifier and nothing else, so this control has two
 * jobs: find the entry somebody means, and then say what the identifiers
 * already in the field are called. The second is the one that matters — a
 * column of uuids tells you nothing about whether the right things are linked.
 *
 * Choosing happens in a dialog rather than in a dropdown under the field. A
 * collection is searched, not scrolled: the list is however many entries the
 * site has, and the control sits in a grid cell that is sometimes a third of a
 * row wide. The same reasoning put the image picker in the media modal.
 *
 * A reverse relation is the other direction and is read-only here. It is
 * answered by the server when the entry is read — see class-relations.php — so
 * the control shows what came back rather than asking again.
 */

import { useState, useEffect, useMemo } from '@wordpress/element'
import { __, sprintf, _n } from '@wordpress/i18n'
import { Link2, Plus, Search, X, ArrowUp, ArrowDown, Check } from 'lucide-react'
import { Field, Button, Badge, Alert, Dialog, Input, Loading, cn } from '../../ui'
import { api } from '../api'

/**
 * How long to wait before searching, in milliseconds. Long enough that typing a
 * name is one request rather than eight, short enough that it still feels like
 * the list is following you.
 */
const PAUSE = 250

/**
 * The most entries a reverse relation answers with. Mirrors
 * Relations::INVERSE_LIMIT — a list that stops at the ceiling and says nothing
 * reads as the whole answer, which is the one thing it is not.
 */
const INVERSE_LIMIT = 50

/**
 * The references a field holds, always as a list.
 *
 * Which shape the VALUE is in follows the field rather than what happens to be
 * stored: a field changed from one to many still holds a bare string until the
 * next save, and a list of one is not the same thing as a single reference.
 *
 * @param {Object} field
 * @param {*}      value
 * @return {string[]} The identifiers.
 */
function referencesOf(field, value) {
  if (field.config?.multiple) {
    return Array.isArray(value) ? value : value ? [String(value)] : []
  }

  if (Array.isArray(value)) {
    return value.length ? [String(value[0])] : []
  }

  return value ? [String(value)] : []
}

/**
 * Looks up what a list of identifiers is called.
 *
 * Keyed by identifier and merged rather than replaced, so removing one entry
 * does not blank the names of the others while the request is in flight.
 *
 * @param {string}   collection
 * @param {string[]} references
 * @return {Object} Entries keyed by identifier.
 */
function useNames(collection, references) {
  const [names, setNames] = useState({})

  // the identifiers as a string, so the effect compares by value: a new array
  // holding the same ids is a different array and would fetch on every render
  const key = references.join(',')

  useEffect(() => {
    if (!collection || key === '') {
      return undefined
    }

    let live = true

    api
      .lookup(collection, { ids: key })
      .then((result) => {
        if (!live) {
          return
        }

        setNames((current) =>
          (result.entries || []).reduce((all, entry) => ({ ...all, [entry.id]: entry }), current)
        )
      })
      .catch(() => {})

    return () => {
      live = false
    }
  }, [collection, key])

  return names
}

/**
 * The relation control, either way round.
 *
 * One type faces both directions, so one control dispatches on which: pointing
 * outwards it is a picker, and pointing back it is a list of whatever points
 * here, which nothing on this screen can change.
 *
 * @param {Object} props
 * @return {JSX.Element} The control.
 */
export function RelationField(props) {
  return props.field?.config?.direction === 'inverse' ? (
    <InverseField {...props} />
  ) : (
    <OwnedField {...props} />
  )
}

/**
 * Choosing the entries this one points at.
 *
 * @param {Object} props
 * @return {JSX.Element} The control.
 */
function OwnedField({ field, value, onChange }) {
  const collection = field.config?.collection || ''
  const many = Boolean(field.config?.multiple)
  const max = Number(field.config?.max) || 0

  const references = useMemo(() => referencesOf(field, value), [field, value])
  const names = useNames(collection, references)

  const [picking, setPicking] = useState(false)

  const full = many && max > 0 && references.length >= max

  /**
   * Stores a list of references in the shape the field holds.
   *
   * @param {string[]} next
   * @return {void}
   */
  const store = (next) => onChange(many ? next : next[0] || '')

  /**
   * Adds or removes one reference.
   *
   * @param {string} id
   * @return {void}
   */
  const toggle = (id) => {
    if (!many) {
      store([id])
      setPicking(false)

      return
    }

    if (references.includes(id)) {
      store(references.filter((one) => one !== id))

      return
    }

    if (max > 0 && references.length >= max) {
      return
    }

    store([...references, id])
  }

  /**
   * Moves one reference one place in either direction. The order is content: it
   * is the order a template loops them in, the same promise a gallery makes.
   *
   * @param {number} from
   * @param {number} direction
   * @return {void}
   */
  const move = (from, direction) => {
    const to = from + direction

    if (to < 0 || to >= references.length) {
      return
    }

    const next = [...references]
    const [moved] = next.splice(from, 1)
    next.splice(to, 0, moved)

    store(next)
  }

  // a field nobody finished setting up. said plainly rather than drawn as an
  // empty picker, which would look like a collection with nothing in it
  if (!collection) {
    return (
      <Field label={field.label} help={field.help}>
        <Alert variant="warning">
          {__(
            'This field has no collection to point at yet. Choose one on the Schema tab.',
            'schemapress'
          )}
        </Alert>
      </Field>
    )
  }

  return (
    <Field
      label={field.label}
      help={field.help}
      required={field.required}
      // the ceiling beside the control rather than in a message after the
      // fact — you can see you have room for one more
      hint={
        many && max > 0
          ? sprintf(
              /* translators: 1: entries chosen, 2: the most allowed */
              __('%1$d of %2$d', 'schemapress'),
              references.length,
              max
            )
          : undefined
      }
    >
      {references.length > 0 ? (
        <ul className="mb-2 flex flex-col gap-1.5">
          {references.map((id, index) => (
            <RelatedRow
              key={id}
              entry={names[id]}
              reference={id}
              position={index}
              total={references.length}
              reorderable={many}
              onMove={(direction) => move(index, direction)}
              onRemove={() => store(references.filter((one) => one !== id))}
            />
          ))}
        </ul>
      ) : null}

      <div>
        <Button size="sm" variant="outline" disabled={full} onClick={() => setPicking(true)}>
          {many ? <Plus /> : <Link2 />}
          {full
            ? __('That is as many as this field takes', 'schemapress')
            : references.length === 0
            ? __('Choose an entry', 'schemapress')
            : many
            ? __('Add another', 'schemapress')
            : __('Choose a different one', 'schemapress')}
        </Button>
      </div>

      {picking ? (
        <RelationPicker
          collection={collection}
          chosen={references}
          many={many}
          full={full}
          label={field.label}
          onToggle={toggle}
          onClose={() => setPicking(false)}
        />
      ) : null}
    </Field>
  )
}

/**
 * One chosen entry.
 *
 * An identifier that no longer names anything is shown as itself rather than
 * dropped. The reference is still stored, and an entry that was deleted — or
 * that an import has not written yet — starts resolving again the moment it is
 * there. A blank row would be the one thing that cannot be acted on.
 *
 * @param {Object} props
 * @return {JSX.Element} The row.
 */
function RelatedRow({ entry, reference, position, total, reorderable, onMove, onRemove }) {
  return (
    <li className="flex items-center gap-2 rounded-md border border-border bg-background px-2.5 py-1.5">
      <span className="flex min-w-0 flex-1 flex-col">
        <span className="flex min-w-0 items-center gap-1.5">
          <span
            className={cn(
              'min-w-0 truncate text-[13px] font-medium',
              !entry && 'text-muted-foreground'
            )}
          >
            {entry ? entry.title : __('No entry with that id', 'schemapress')}
          </span>

          {entry && entry.state !== 'published' ? (
            <Badge variant={entry.state === 'draft' ? 'outline' : 'warning'}>
              {entry.state === 'draft'
                ? __('draft', 'schemapress')
                : __('unpublished edits', 'schemapress')}
            </Badge>
          ) : null}
        </span>

        <span className="truncate font-mono text-[11px] text-muted-foreground">
          {entry ? entry.slug : reference}
        </span>
      </span>

      {reorderable && total > 1 ? (
        <span className="flex shrink-0 gap-0.5">
          <Button
            size="icon-sm"
            variant="ghost"
            disabled={position === 0}
            aria-label={__('Move up', 'schemapress')}
            onClick={() => onMove(-1)}
          >
            <ArrowUp />
          </Button>
          <Button
            size="icon-sm"
            variant="ghost"
            disabled={position === total - 1}
            aria-label={__('Move down', 'schemapress')}
            onClick={() => onMove(1)}
          >
            <ArrowDown />
          </Button>
        </span>
      ) : null}

      <Button
        size="icon-sm"
        variant="destructive-ghost"
        aria-label={__('Remove', 'schemapress')}
        onClick={onRemove}
      >
        <X />
      </Button>
    </li>
  )
}

/**
 * Searching a collection for the entry somebody means.
 *
 * Drafts are listed and said so. You link to a draft all the time — the two are
 * being written together — and the thing that must not happen is finding out at
 * publish time that half the references resolve to nothing on the front end.
 *
 * @param {Object} props
 * @return {JSX.Element} The dialog.
 */
function RelationPicker({ collection, chosen, many, full, label, onToggle, onClose }) {
  const [search, setSearch] = useState('')
  const [entries, setEntries] = useState(null)
  const [failed, setFailed] = useState('')
  const [name, setName] = useState('')

  useEffect(() => {
    let live = true

    // the first list arrives without waiting, so the dialog opens onto the
    // most recently edited entries rather than onto an empty box
    const pause = search === '' ? 0 : PAUSE

    const timer = setTimeout(() => {
      api
        .lookup(collection, { search })
        .then((result) => {
          if (!live) {
            return
          }

          setEntries(result.entries || [])
          setName(result.label || '')
          setFailed('')
        })
        .catch((error) => live && setFailed(error.message))
    }, pause)

    return () => {
      live = false
      clearTimeout(timer)
    }
  }, [collection, search])

  return (
    <Dialog
      open
      size="md"
      onOpenChange={(next) => !next && onClose()}
      title={label}
      description={
        name
          ? sprintf(
              /* translators: %s: a collection's name */
              __('Search %s for the entry this points at.', 'schemapress'),
              name.toLowerCase()
            )
          : __('Search for the entry this points at.', 'schemapress')
      }
      footer={
        <>
          <span className="mr-auto text-[12px] text-muted-foreground">
            {many
              ? sprintf(
                  /* translators: %d: number of entries chosen */
                  _n('%d chosen', '%d chosen', chosen.length, 'schemapress'),
                  chosen.length
                )
              : ''}
          </span>
          <Button variant="outline" onClick={onClose}>
            {many ? __('Done', 'schemapress') : __('Cancel', 'schemapress')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <div className="relative">
          <Search
            aria-hidden="true"
            className="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground"
          />
          <Input
            autoFocus
            type="search"
            className="pl-8"
            value={search}
            placeholder={__('Search by name…', 'schemapress')}
            onChange={(event) => setSearch(event.target.value)}
          />
        </div>

        {failed ? <Alert variant="warning">{failed}</Alert> : null}

        {entries === null ? (
          <Loading label={__('Loading…', 'schemapress')} />
        ) : entries.length === 0 ? (
          <div className="rounded-lg border border-dashed border-border px-4 py-8 text-center">
            <p className="text-[13px] font-medium">
              {search === ''
                ? __('Nothing in this collection yet', 'schemapress')
                : __('Nothing matched', 'schemapress')}
            </p>
            <p className="mt-0.5 text-[12px] text-muted-foreground">
              {search === ''
                ? __('Add an entry to it and it will be here to point at.', 'schemapress')
                : __('Entries are searched by their name and their text.', 'schemapress')}
            </p>
          </div>
        ) : (
          <ul className="flex max-h-80 flex-col gap-1 overflow-y-auto">
            {entries.map((entry) => {
              const picked = chosen.includes(entry.id)

              return (
                <li key={entry.id}>
                  <button
                    type="button"
                    // a full field can still have something taken OUT of it
                    // from here, which is the only way back from one
                    disabled={full && !picked}
                    onClick={() => onToggle(entry.id)}
                    className={cn(
                      'flex w-full items-center gap-2.5 rounded-md border p-2.5 text-left transition-colors',
                      picked
                        ? 'border-ring/40 bg-accent/40'
                        : 'border-border hover:border-ring/30 hover:bg-accent/30',
                      full && !picked && 'cursor-not-allowed opacity-50'
                    )}
                  >
                    <span
                      className={cn(
                        'flex size-7 shrink-0 items-center justify-center rounded-md',
                        picked
                          ? 'bg-primary text-primary-foreground'
                          : 'bg-muted text-muted-foreground'
                      )}
                    >
                      {picked ? <Check className="size-3.5" /> : <Link2 className="size-3.5" />}
                    </span>

                    <span className="min-w-0 flex-1">
                      <span className="flex min-w-0 items-center gap-1.5">
                        <span className="min-w-0 truncate text-[13px] font-medium">
                          {entry.title}
                        </span>
                        {entry.state !== 'published' ? (
                          <Badge variant={entry.state === 'draft' ? 'outline' : 'warning'}>
                            {entry.state === 'draft'
                              ? __('draft', 'schemapress')
                              : __('unpublished edits', 'schemapress')}
                          </Badge>
                        ) : null}
                      </span>
                      <span className="block truncate font-mono text-[11px] text-muted-foreground">
                        {entry.slug}
                      </span>
                    </span>
                  </button>
                </li>
              )
            })}
          </ul>
        )}
      </div>
    </Dialog>
  )
}

/**
 * What a reverse relation's two machine keys are called.
 *
 * The field stores a collection's key and a field's key, and neither is
 * something to put in front of somebody filling in an entry — `team_member` is
 * the builder's vocabulary. Looked up rather than sent with the page, for the
 * reason the component list is: both labels follow a title that is edited on
 * another screen. The keys stand in until the names arrive, and if the request
 * fails they stay, which is worse to read and still true.
 *
 * @param {string} collection
 * @param {string} key
 * @return {{collection: string, field: string}} The labels.
 */
function useRelationLabels(collection, key) {
  const [labels, setLabels] = useState({ collection, field: key })

  useEffect(() => {
    if (!collection) {
      return undefined
    }

    let live = true

    api
      .relations()
      .then((result) => {
        if (!live) {
          return
        }

        const one = (result.collections || []).find((candidate) => candidate.key === collection)
        const relation = (one?.relations || []).find((candidate) => candidate.key === key)

        setLabels({ collection: one?.label || collection, field: relation?.label || key })
      })
      .catch(() => {})

    return () => {
      live = false
    }
  }, [collection, key])

  return labels
}

/**
 * The other direction: what points at this entry.
 *
 * Read-only, and not because it was easier. The reference lives on the other
 * entry, so editing this list would mean editing entries that are not the one
 * on screen — saving a draft of one entry to change another is not something
 * this form can promise to do safely.
 *
 * So the control's second job is to say where the list IS changed. A read-only
 * list with no explanation reads as a broken control; one that names the
 * collection and the field is a direction to follow.
 *
 * It shows what the server sent back with the entry. Nothing is asked for
 * again: the list is a query over the index, already run.
 *
 * @param {Object} props
 * @return {JSX.Element} The control.
 */
function InverseField({ field, context }) {
  const related = context?.data?.[field.key]
  const entries = Array.isArray(related) ? related : []

  const configured = Boolean(field.config?.collection && field.config?.field)
  const labels = useRelationLabels(field.config?.collection || '', field.config?.field || '')

  /* translators: 1: a collection's name, 2: a relation field's name */
  const where = sprintf(
    __('Set on %1$s — open one there and choose this entry in its %2$s field.', 'schemapress'),
    labels.collection,
    labels.field
  )

  return (
    <Field
      label={field.label}
      help={field.help}
      hint={
        entries.length > 0
          ? sprintf(
              /* translators: %d: number of related entries */
              _n('%d entry', '%d entries', entries.length, 'schemapress'),
              entries.length
            )
          : undefined
      }
    >
      {!configured ? (
        <Alert variant="warning">
          {__(
            'This field does not say which relation it is the other end of yet. Choose one on the Schema tab.',
            'schemapress'
          )}
        </Alert>
      ) : entries.length === 0 ? (
        <p className="rounded-md border border-dashed border-border px-3 py-4 text-center text-[12px] text-muted-foreground">
          {/* a new entry has nothing pointing at it YET rather than nothing
              pointing at it — it has not been saved for anything to point at.
              Either way the next sentence is the same one: where to go to
              change that */}
          {context?.entry
            ? __('Nothing points at this entry yet.', 'schemapress')
            : __(
                'Save this entry, and anything pointing at it will be listed here.',
                'schemapress'
              )}{' '}
          {where}
        </p>
      ) : (
        <>
          <ul className="flex flex-col gap-1.5">
            {entries.map((entry) => (
              <li
                key={entry.id}
                className="flex items-center gap-2 rounded-md border border-border bg-muted/30 px-2.5 py-1.5"
              >
                <Link2 aria-hidden="true" className="size-3.5 shrink-0 text-muted-foreground" />
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-[13px] font-medium">
                    {entry.title || entry.slug}
                  </span>
                  <span className="block truncate font-mono text-[11px] text-muted-foreground">
                    {entry.slug}
                  </span>
                </span>
              </li>
            ))}
          </ul>

          {/* under the list rather than in the help text above it: the list is
              the thing that looks like it should be editable, and the sentence
              answering that has to be where the hand goes */}
          <p className="mt-1.5 text-[12px] text-muted-foreground">
            {where}
            {entries.length >= INVERSE_LIMIT
              ? ' ' +
                sprintf(
                  /* translators: %d: the most entries this field lists */
                  __(
                    'Showing the first %d — filter the collection itself to page through the rest.',
                    'schemapress'
                  ),
                  INVERSE_LIMIT
                )
              : ''}
          </p>
        </>
      )}
    </Field>
  )
}
