/**
 * A collection's entries, as a table.
 *
 * Tabular because that is what a collection is: many things of one shape, and
 * the useful questions about them — which exist, which is newest, which is
 * missing something — are comparisons across rows.
 *
 * Columns come from the collection's own fields, so the table describes this
 * collection rather than a generic list of posts. Only the first few are shown:
 * a table wide enough to scroll sideways stops being scannable, and everything
 * is in the entry anyway.
 *
 * There is no Title column. A WordPress post has a title; an entry here does
 * not — the form never asks for one, and what the database stores is derived
 * from the entry's own first text field. Showing it as a column would print
 * Full Name twice under two different headings. Instead the FIRST column is
 * the link into the entry, whichever field that happens to be.
 */

import { useCallback, useEffect, useState } from '@wordpress/element'
import { __, sprintf, _n } from '@wordpress/i18n'
import {
  Plus,
  Trash2,
  Copy,
  Undo2,
  ChevronLeft,
  ChevronRight,
  ChevronsUpDown,
  ArrowUp,
  ArrowDown,
  Table2,
  Search,
  Settings2,
} from 'lucide-react'
import {
  Button,
  Empty,
  Loading,
  Alert,
  ConfirmDialog,
  Checkbox,
  Input,
  Tooltip,
  Segmented,
  cn,
} from '../../ui'
import { Ago } from '../../shared/time'
import { api } from '../../shared/api'
import { can } from '../../shared/settings'
import { ConfigureTableDialog } from './ConfigureTableDialog'

/**
 * What a bulk action reads as once it has happened, for the confirmation.
 *
 * Past tense and separate from the button labels, because "Publish" on a button
 * and "3 entries published" in a message are different sentences and sharing one
 * string would make both of them slightly wrong.
 */
const BULK_PAST = {
  publish: __('published', 'schemapress'),
  unpublish: __('unpublished', 'schemapress'),
  discard: __('reverted to what is published', 'schemapress'),
  duplicate: __('duplicated', 'schemapress'),
  delete: __('moved to the trash', 'schemapress'),
  restore: __('restored', 'schemapress'),
}

/**
 * The actions offered over several entries at once.
 *
 * Three sets, because what can be done to an entry depends on where it is. A
 * collection that keeps no drafts has nothing to publish — saving already did —
 * and offering a Publish button that does nothing is worse than not offering it.
 *
 * Labels are functions rather than strings so they are translated when the view
 * renders, not when the module is first evaluated: __() called at module scope
 * runs before the locale's translations have been registered.
 */
const DRAFT_ACTIONS = [
  { value: 'publish', label: () => __('Publish', 'schemapress') },
  { value: 'unpublish', label: () => __('Unpublish', 'schemapress') },
  { value: 'duplicate', label: () => __('Duplicate', 'schemapress') },
  {
    value: 'delete',
    label: () => __('Delete', 'schemapress'),
    destructive: true,
    confirm: 'trash',
  },
]

const FLAT_ACTIONS = [
  { value: 'duplicate', label: () => __('Duplicate', 'schemapress') },
  {
    value: 'delete',
    label: () => __('Delete', 'schemapress'),
    destructive: true,
    confirm: 'trash',
  },
]

const TRASH_ACTIONS = [{ value: 'restore', label: () => __('Restore', 'schemapress') }]

/**
 * Field types the database can order by — mirrors Index::TYPES in
 * class-index.php, which is what decides whether a value is mirrored into a
 * column of its own.
 *
 * Narrower than that list on purpose: an image and a file ARE indexed, so the
 * API can ask whether one is set, but they are indexed as attachment ids and
 * ordering by an id is ordering by upload order wearing a photograph. The rest
 * cannot be indexed at all — rich text is a document, a link and a group hold
 * several values, and a repeater holds many rows.
 *
 * A header that cannot sort says so by not offering. A sort that silently did
 * nothing would be worse than none.
 */
const SORTABLE_TYPES = [
  'text',
  'textarea',
  'email',
  'url',
  'phone',
  'select',
  'number',
  'toggle',
  // a hex string compares as one, so "everything brand red together" works
  'color',
]

/**
 * How many field columns the table shows before it stops being scannable.
 */
const MAX_COLUMNS = 4

/**
 * One cell, drawn.
 *
 * Most values are text and go through `cell` below. An image is the exception:
 * a column of the word "Set" tells you an avatar exists and nothing about which
 * one, when the listing already carries the thumbnail.
 *
 * Round, because at this size the subject of a photograph is in the middle and
 * the corners are whatever was behind them.
 *
 * A plain function rather than a component, so an empty cell is falsy and the
 * caller's fallback — the entry's name, or a dash — still works. A JSX element
 * is always truthy, and returning one for "nothing here" would have quietly
 * swallowed both.
 *
 * @param {Object} field
 * @param {Object} entry
 * @return {JSX.Element|string} The cell's contents, or '' when there are none.
 */
function cellValue(field, entry) {
  const value = entry.values?.[field.key]

  // a color is the one value that can be printed as itself. the hex code
  // beside it because that is what somebody comparing two rows reads
  if (field.type === 'color') {
    if (!value) {
      return ''
    }

    return (
      <span className="flex items-center gap-1.5">
        <span
          aria-hidden="true"
          style={{ backgroundColor: value }}
          className="size-3.5 shrink-0 rounded border border-border"
        />
        <span className="font-mono text-[12px]">{value}</span>
      </span>
    )
  }

  if (field.type === 'image') {
    const image = entry.data?.[field.key]
    const src = image?.sizes?.thumbnail?.url || image?.url

    if (!src) {
      // an empty frame, not the entry's name. this column was asked for because
      // it shows a picture, and printing a name in it makes a column of names
      // that happens to be headed Avatar
      return (
        <span
          aria-hidden="true"
          className="block size-7 shrink-0 rounded-full border border-dashed border-border bg-muted/50"
        />
      )
    }

    return (
      <img
        src={src}
        alt={image?.alt || ''}
        loading="lazy"
        className="size-7 shrink-0 rounded-full border border-border object-cover"
      />
    )
  }

  return cell(field, value)
}

/**
 * Renders one cell's value as short, comparable text.
 *
 * @param {Object} field
 * @param {*}      value
 * @return {string} The cell text.
 */
function cell(field, value) {
  if (value === null || value === undefined || value === '') {
    return ''
  }

  switch (field.type) {
    case 'repeater':
      return Array.isArray(value)
        ? sprintf(
            /* translators: %d: number of items */
            __('%d items', 'schemapress'),
            value.length,
          )
        : ''

    case 'gallery':
      return Array.isArray(value) && value.length
        ? sprintf(
            /* translators: %d: number of images */
            _n('%d image', '%d images', value.length, 'schemapress'),
            value.length,
          )
        : ''

    case 'image':
    case 'file':
      return __('Set', 'schemapress')

    case 'link':
      return value.label || value.url || ''

    case 'toggle':
      return value ? __('Yes', 'schemapress') : __('No', 'schemapress')

    case 'json':
      // the shape rather than the payload. a column of {"a":1,"b":[2… is a
      // column of noise, and how many keys it has is the comparable thing
      return typeof value === 'object'
        ? sprintf(
            /* translators: %d: number of keys in a JSON value */
            _n('%d key', '%d keys', Object.keys(value).length, 'schemapress'),
            Object.keys(value).length,
          )
        : ''

    case 'group':
      return ''

    default: {
      const text = String(value)
        .replace(/<[^>]*>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()

      return text.length > 60 ? `${text.slice(0, 60)}…` : text
    }
  }
}

/**
 * The entries table.
 *
 * @param {Object} props
 * @return {JSX.Element} The view.
 */
export function EntriesView({ type, fields, settings = {}, onOpenEntry, onConfigure }) {
  const [state, setState] = useState({
    loading: true,
    entries: [],
    total: 0,
    pages: 0,
    perPage: 10,
  })
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [term, setTerm] = useState('')
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [removing, setRemoving] = useState(null)
  const [configuring, setConfiguring] = useState(false)

  // which list is on screen. the trash is a view of the same collection rather
  // than a screen of its own — it is the same rows, in a different state, and
  // switching should not lose where you were
  const [view, setView] = useState('entries')
  const trashed = view === 'trash'

  // the rows a bulk action would act on. cleared whenever the list underneath
  // changes, because a tick against row four of page one means nothing on page
  // two and acting on it would be acting on something nobody pointed at
  const [selected, setSelected] = useState([])
  const [confirming, setConfirming] = useState(null)
  const [working, setWorking] = useState(false)

  // newest first, which is the order you want when you have just saved
  // something and are looking for it
  const [sort, setSort] = useState({ orderby: 'modified', order: 'desc' })

  // a request per keystroke would be one per letter; the field stays responsive
  // and the query follows a beat later
  useEffect(() => {
    const timer = setTimeout(() => {
      setPage(1)
      setTerm(search)
    }, 250)

    return () => clearTimeout(timer)
  }, [search])

  // bumped to make the effect below run again after something has changed the
  // list — a bulk action, a restore — without duplicating the fetch
  const [reloads, setReloads] = useState(0)
  const reload = useCallback(() => setReloads((count) => count + 1), [])

  useEffect(() => {
    let live = true

    setState((current) => ({ ...current, loading: true }))
    setError('')
    setSelected([])

    const request = trashed
      ? api.trash(type.id, { page })
      : api.entries(type.id, { page, search: term, orderby: sort.orderby, order: sort.order })

    request
      .then((result) => {
        if (!live) {
          return
        }

        setState({
          loading: false,
          entries: result.entries || [],
          total: result.total || 0,
          pages: result.pages || 0,
          perPage: result.perPage || 10,
        })
      })
      .catch((failure) => {
        if (!live) {
          return
        }

        setError(failure.message)
        setState({ loading: false, entries: [], total: 0, pages: 0, perPage: 10 })
      })

    return () => {
      live = false
    }
  }, [type.id, page, term, sort.orderby, sort.order, trashed, reloads])

  // going back to page four of a list that now has two pages shows nothing and
  // looks broken rather than empty
  useEffect(() => {
    setPage(1)
  }, [view])

  /**
   * Applies one action to the ticked rows.
   *
   * The server answers per entry rather than per request, so a publish where one
   * of forty entries has a required field empty publishes the thirty-nine and
   * names the one it could not. Reporting the whole thing as a failure would be
   * both untrue and unhelpful.
   *
   * @param {string} action
   * @return {Promise<void>}
   */
  const runBulk = (action) => {
    setWorking(true)
    setError('')
    setNotice('')

    return api
      .bulk(type.id, action, selected)
      .then((result) => {
        const done = result.succeeded.length

        setNotice(
          sprintf(
            /* translators: 1: number of entries, 2: the action taken */
            _n('%1$d entry %2$s.', '%1$d entries %2$s.', done, 'schemapress'),
            done,
            BULK_PAST[action] || action,
          ),
        )

        if (result.failed.length) {
          setError(
            result.failed
              .map((failure) => failure.message)
              // the same rule broken by ten entries is one sentence, not ten
              .filter((message, index, all) => all.indexOf(message) === index)
              .join(' '),
          )
        }

        reload()
      })
      .catch((failure) => setError(failure.message))
      .finally(() => setWorking(false))
  }

  /**
   * Sorts by a column, or flips the direction if it is already the one sorted.
   *
   * Re-sorting sends you back to page one: staying on page four of a different
   * order shows a slice of rows nobody asked for.
   *
   * @param {string} orderby
   * @return {void}
   */
  const sortBy = (orderby) => {
    setPage(1)
    setSort((current) =>
      current.orderby === orderby
        ? { orderby, order: current.order === 'asc' ? 'desc' : 'asc' }
        : // a first click means "show me this column", and what that means
          // differs: a time reads newest first, everything else reads A-Z
          {
            orderby,
            order: ['modified', 'date'].includes(orderby) ? 'desc' : 'asc',
          },
    )
  }

  /**
   * Removes an entry, then reloads so paging and totals stay truthful.
   *
   * In the trash this erases rather than trashes, which is the one irreversible
   * thing in this screen — see the confirm dialog, which says so in as many
   * words rather than asking the same "are you sure" it asks for a trashing.
   *
   * @param {number} id
   * @return {void}
   */
  const remove = (id) =>
    (trashed ? api.purgeEntry(type.id, id) : api.deleteEntry(type.id, id))
      .then(() => {
        // dropping the last row of the last page would otherwise leave you on
        // an empty page with no way to tell it is empty rather than broken
        const remaining = state.entries.length - 1

        if (remaining === 0 && page > 1) {
          setPage(page - 1)
        } else {
          setState((current) => ({
            ...current,
            entries: current.entries.filter((entry) => entry.id !== id),
            total: Math.max(0, current.total - 1),
          }))
        }
      })
      .catch((failure) => setError(failure.message))

  /**
   * Brings one entry back to the state it was in.
   *
   * @param {string} id
   * @return {void}
   */
  const restore = (id) =>
    api
      .restoreEntry(type.id, id)
      .then(() => {
        setNotice(__('Entry restored.', 'schemapress'))
        reload()
      })
      .catch((failure) => setError(failure.message))

  /**
   * Copies an entry and opens the copy, which is where you were going anyway.
   *
   * @param {string} id
   * @return {void}
   */
  const duplicate = (id) =>
    api
      .duplicateEntry(type.id, id)
      .then((result) => onOpenEntry(result.entry.id))
      .catch((failure) => setError(failure.message))

  /**
   * Ticks or unticks one row.
   *
   * @param {string} id
   * @return {void}
   */
  const toggle = (id) =>
    setSelected((current) =>
      current.includes(id) ? current.filter((one) => one !== id) : [...current, id],
    )

  // a chosen column list wins; nobody having chosen means the first few fields,
  // which is what makes a field added later show up without being configured
  const columns = Array.isArray(settings.listColumns)
    ? settings.listColumns.map((key) => fields.find((field) => field.key === key)).filter(Boolean)
    : fields.slice(0, MAX_COLUMNS)

  // with drafts off every entry is published, so a column of identical badges
  // would cost width and say nothing
  const drafts = type.draftAndPublish !== false

  // the field the stored post_title is derived from, mirroring
  // Entries::deriveTitle. only that column can be sorted, because 'title' is
  // the only thing the database has to order by — every other field's value
  // lives inside one JSON blob
  // the collection's own choice first — that field IS the post title, so
  // ordering by the post row is the same answer without a meta join
  const titleKey =
    settings.titleField ||
    fields.find((field) => field.type === 'text' || field.type === 'textarea')?.key

  /**
   * What to sort by when a column's header is clicked, or nothing.
   *
   * @param {Object} field
   * @return {string|undefined} The sort key.
   */
  const sortKey = (field) => {
    if (field.key === titleKey) {
      return 'title'
    }

    return SORTABLE_TYPES.includes(field.type) ? field.key : undefined
  }

  const from = (page - 1) * state.perPage + 1
  const to = from + state.entries.length - 1

  // every row on this page, for the header tick. "all" means all of what is on
  // screen rather than all of what exists — a tick that silently selected four
  // hundred rows you cannot see is how bulk deletes go wrong
  const allOnPage = state.entries.map((entry) => entry.id)
  const allTicked = allOnPage.length > 0 && allOnPage.every((id) => selected.includes(id))

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-3">
          <Segmented
            value={view}
            onChange={setView}
            options={[
              { value: 'entries', label: __('Entries', 'schemapress') },
              { value: 'trash', label: __('Trash', 'schemapress') },
            ]}
          />

          {/* the width lives on the wrapper: the scoped reset owns the input's
              own width, and a utility on the input itself would lose to it */}
          {trashed ? null : (
            <div className="relative w-full sm:w-64">
              <Search className="pointer-events-none absolute left-3 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
              <Input
                type="search"
                className="sp-inset-icon"
                value={search}
                placeholder={__('Search entries…', 'schemapress')}
                onChange={(event) => setSearch(event.target.value)}
              />
            </div>
          )}
        </div>

        <div className="flex items-center gap-2">
          {trashed ? (
            <Button
              size="sm"
              variant="outline"
              disabled={state.total === 0 || !can.manageSchema}
              onClick={() => setConfirming({ action: 'empty' })}
            >
              <Trash2 />
              {__('Empty trash', 'schemapress')}
            </Button>
          ) : (
            <>
              <Tooltip label={__('Configure the table', 'schemapress')}>
                <Button
                  size="icon"
                  variant="outline"
                  aria-label={__('Configure the table', 'schemapress')}
                  disabled={fields.length === 0}
                  onClick={() => setConfiguring(true)}
                >
                  <Settings2 />
                </Button>
              </Tooltip>

              <Button size="sm" disabled={fields.length === 0} onClick={() => onOpenEntry(null)}>
                <Plus />
                {sprintf(
                  /* translators: %s: the singular name of the collection */
                  __('Create %s', 'schemapress'),
                  type.singularLabel || type.label,
                )}
              </Button>
            </>
          )}
        </div>
      </div>

      {error ? <Alert variant="warning">{error}</Alert> : null}
      {notice ? <Alert variant="success">{notice}</Alert> : null}

      {/* the bar appears only once something is ticked, and says how many —
          a bulk action whose scope you have to count for yourself is one you
          will eventually run on the wrong number of things */}
      {selected.length > 0 ? (
        <div className="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2">
          <span className="text-[13px] font-medium">
            {sprintf(
              /* translators: %d: number of selected entries */
              _n('%d selected', '%d selected', selected.length, 'schemapress'),
              selected.length,
            )}
          </span>

          <span className="ml-auto flex flex-wrap items-center gap-2">
            {(trashed ? TRASH_ACTIONS : drafts ? DRAFT_ACTIONS : FLAT_ACTIONS).map((action) => (
              <Button
                key={action.value}
                size="sm"
                variant={action.destructive ? 'destructive-ghost' : 'outline'}
                disabled={working}
                onClick={() =>
                  action.confirm ? setConfirming(action) : runBulk(action.value)
                }
              >
                {action.label()}
              </Button>
            ))}

            <Button size="sm" variant="ghost" onClick={() => setSelected([])}>
              {__('Clear', 'schemapress')}
            </Button>
          </span>
        </div>
      ) : null}

      {fields.length === 0 ? (
        <Empty
          icon={Table2}
          title={__('This collection has no fields yet', 'schemapress')}
          description={__(
            'Add some in the Schema tab, then you can create entries.',
            'schemapress',
          )}
          className="py-16"
        />
      ) : state.loading ? (
        <Loading label={__('Loading entries…', 'schemapress')} />
      ) : state.entries.length === 0 ? (
        <Empty
          icon={trashed ? Trash2 : Table2}
          title={
            trashed
              ? __('The trash is empty', 'schemapress')
              : term
                ? __('Nothing matches that search', 'schemapress')
                : __('No entries yet', 'schemapress')
          }
          description={
            trashed
              ? __('Deleted entries wait here, and can be restored.', 'schemapress')
              : term
                ? __('Try a different term.', 'schemapress')
                : __('Create the first one to get started.', 'schemapress')
          }
          className="py-16"
        />
      ) : (
        <div className="overflow-x-auto rounded-lg border border-border bg-background">
          <table className="w-full border-collapse text-[13px]">
            <thead>
              <tr className="border-b border-border bg-muted/40 text-left">
                <th className="w-px px-3 py-2">
                  <Checkbox
                    checked={allTicked}
                    aria-label={__('Select every entry on this page', 'schemapress')}
                    onChange={() => setSelected(allTicked ? [] : allOnPage)}
                  />
                </th>

                {/* nothing ticked still needs something to click */}
                {columns.length === 0 ? (
                  <Th sortBy={trashed ? undefined : 'title'} sort={sort} onSort={sortBy}>
                    {__('Name', 'schemapress')}
                  </Th>
                ) : null}

                {columns.map((field) => (
                  <Th
                    key={field.key}
                    // the trash is ordered by when things went into it, which is
                    // the only question anybody asks of a trash
                    sortBy={trashed ? undefined : sortKey(field)}
                    sort={sort}
                    onSort={sortBy}
                  >
                    {field.label}
                  </Th>
                ))}

                {trashed ? (
                  <Th>{__('Deleted', 'schemapress')}</Th>
                ) : (
                  <Th sortBy="modified" sort={sort} onSort={sortBy}>
                    {__('Updated', 'schemapress')}
                  </Th>
                )}

                {drafts && !trashed ? <Th>{__('Status', 'schemapress')}</Th> : null}
                <th className="w-20 px-3 py-2" />
              </tr>
            </thead>

            <tbody>
              {state.entries.map((entry) => (
                // the whole row opens the entry. the first cell is still a
                // button, so the row is reachable by keyboard and reads as a
                // link rather than as a surface that happens to respond
                <tr
                  key={entry.id}
                  // a trashed entry cannot be opened: there is no form for it,
                  // and the way to work on it again is to restore it first
                  onClick={trashed ? undefined : () => onOpenEntry(entry.id)}
                  className={cn(
                    'group border-b border-border/60 transition-colors last:border-0 hover:bg-accent/40',
                    trashed ? '' : 'cursor-pointer',
                    selected.includes(entry.id) && 'bg-accent/30',
                  )}
                >
                  <td className="w-px px-3 py-2.5" onClick={(event) => event.stopPropagation()}>
                    <Checkbox
                      checked={selected.includes(entry.id)}
                      aria-label={sprintf(
                        /* translators: %s: the entry's title */
                        __('Select %s', 'schemapress'),
                        entry.title,
                      )}
                      onChange={() => toggle(entry.id)}
                    />
                  </td>

                  {columns.length === 0 ? (
                    <td className="whitespace-nowrap px-3 py-2.5">
                      <Open entry={entry} onOpen={onOpenEntry}>
                        {entry.title}
                      </Open>
                    </td>
                  ) : null}

                  {columns.map((field, index) => (
                    <td
                      key={field.key}
                      className={cn(
                        'whitespace-nowrap px-3 py-2.5 text-muted-foreground',
                        // a thumbnail is a fixed size and must not be truncated
                        field.type === 'image' ? 'w-px' : 'max-w-[16rem] truncate',
                      )}
                    >
                      {index === 0 ? (
                        // the first column is the way in. an entry whose first
                        // field is blank still has to be openable, so it falls
                        // back to the derived name
                        <Open entry={entry} onOpen={onOpenEntry} label={entry.title}>
                          {cellValue(field, entry) ||
                            (field.type === 'image'
                              ? null
                              : entry.title || __('Untitled', 'schemapress'))}
                        </Open>
                      ) : (
                        cellValue(field, entry) || (
                          <span className="text-muted-foreground/40">—</span>
                        )
                      )}
                    </td>
                  ))}

                  <td className="whitespace-nowrap px-3 py-2.5 text-muted-foreground">
                    <Ago stamp={trashed ? entry.trashedAt : entry.modified} fallback="—" />
                  </td>

                  {drafts && !trashed ? (
                    <td className="px-3 py-2.5">
                      <State entry={entry} />
                    </td>
                  ) : null}

                  {/* the row already opens the entry, so what is left here is
                      what the row does not do — and each stops the click
                      reaching the row, or confirming a deletion would also open
                      what you deleted */}
                  <td className="whitespace-nowrap px-3 py-2.5">
                    <span className="flex items-center justify-end gap-0.5 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100">
                      {trashed ? (
                        <Tooltip label={__('Restore', 'schemapress')}>
                          <Button
                            size="icon-sm"
                            variant="ghost"
                            aria-label={__('Restore', 'schemapress')}
                            onClick={(event) => {
                              event.stopPropagation()
                              restore(entry.id)
                            }}
                          >
                            <Undo2 />
                          </Button>
                        </Tooltip>
                      ) : (
                        <Tooltip label={__('Duplicate', 'schemapress')}>
                          <Button
                            size="icon-sm"
                            variant="ghost"
                            aria-label={__('Duplicate', 'schemapress')}
                            onClick={(event) => {
                              event.stopPropagation()
                              duplicate(entry.id)
                            }}
                          >
                            <Copy />
                          </Button>
                        </Tooltip>
                      )}

                      {/* erasing is only offered where the entry has already
                          been somewhere recoverable, and only to somebody who
                          could delete the whole collection anyway */}
                      {trashed && !can.manageSchema ? null : (
                        <Button
                          size="icon-sm"
                          variant="destructive-ghost"
                          aria-label={
                            trashed
                              ? __('Delete permanently', 'schemapress')
                              : __('Delete', 'schemapress')
                          }
                          onClick={(event) => {
                            event.stopPropagation()
                            setRemoving(entry)
                          }}
                        >
                          <Trash2 />
                        </Button>
                      )}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* always, not only when there is more than one page: "1–4 of 4" is the
          answer to "did it save?" and "is that all of them?", and a bar that
          disappears below ten rows answers neither */}
      {!state.loading && state.entries.length > 0 ? (
        <div className="flex flex-wrap items-center justify-between gap-3 text-[12px] text-muted-foreground">
          <span>
            {sprintf(
              /* translators: 1: first row shown, 2: last row shown, 3: total rows */
              __('Showing %1$d–%2$d of %3$d entries', 'schemapress'),
              from,
              to,
              state.total,
            )}
          </span>

          <span className="flex items-center gap-2">
            <Button
              size="icon-sm"
              variant="outline"
              aria-label={__('Previous page', 'schemapress')}
              disabled={page <= 1}
              onClick={() => setPage(page - 1)}
            >
              <ChevronLeft />
            </Button>

            <span>
              {sprintf(
                /* translators: 1: current page, 2: total pages */
                __('Page %1$d of %2$d', 'schemapress'),
                page,
                Math.max(1, state.pages),
              )}
            </span>

            <Button
              size="icon-sm"
              variant="outline"
              aria-label={__('Next page', 'schemapress')}
              disabled={page >= state.pages}
              onClick={() => setPage(page + 1)}
            >
              <ChevronRight />
            </Button>
          </span>
        </div>
      ) : null}

      {configuring ? (
        <ConfigureTableDialog
          fields={fields}
          columns={settings.listColumns}
          fallback={MAX_COLUMNS}
          onClose={() => setConfiguring(false)}
          onSave={onConfigure}
        />
      ) : null}

      {/* two different questions wearing the same dialog. trashing is
          reversible and says where the entry goes; erasing is not, and says so
          rather than asking the same "are you sure" for both */}
      {removing ? (
        <ConfirmDialog
          open
          onOpenChange={(next) => !next && setRemoving(null)}
          title={
            trashed
              ? __('Erase this entry?', 'schemapress')
              : __('Delete this entry?', 'schemapress')
          }
          description={sprintf(
            trashed
              ? /* translators: %s: the entry's title */
                __('“%s” will be gone for good. This cannot be undone.', 'schemapress')
              : /* translators: %s: the entry's title */
                __('“%s” will be moved to the trash, where you can restore it.', 'schemapress'),
            removing.title,
          )}
          confirmLabel={
            trashed
              ? __('Erase permanently', 'schemapress')
              : __('Delete', 'schemapress')
          }
          onConfirm={() => remove(removing.id)}
        />
      ) : null}

      {confirming ? (
        <ConfirmDialog
          open
          onOpenChange={(next) => !next && setConfirming(null)}
          title={
            confirming.action === 'empty'
              ? __('Empty the trash?', 'schemapress')
              : __('Delete these entries?', 'schemapress')
          }
          description={
            confirming.action === 'empty'
              ? sprintf(
                  /* translators: %d: number of entries in the trash */
                  _n(
                    '%d entry will be gone for good. This cannot be undone.',
                    '%d entries will be gone for good. This cannot be undone.',
                    state.total,
                    'schemapress',
                  ),
                  state.total,
                )
              : sprintf(
                  /* translators: %d: number of selected entries */
                  _n(
                    '%d entry will be moved to the trash, where you can restore it.',
                    '%d entries will be moved to the trash, where you can restore them.',
                    selected.length,
                    'schemapress',
                  ),
                  selected.length,
                )
          }
          confirmLabel={
            confirming.action === 'empty'
              ? __('Empty trash', 'schemapress')
              : __('Delete', 'schemapress')
          }
          onConfirm={() => {
            if (confirming.action === 'empty') {
              setWorking(true)

              return api
                .emptyTrash(type.id)
                .then((result) => {
                  setNotice(
                    sprintf(
                      /* translators: %d: number of entries erased */
                      _n('%d entry erased.', '%d entries erased.', result.erased, 'schemapress'),
                      result.erased,
                    ),
                  )
                  reload()
                })
                .catch((failure) => setError(failure.message))
                .finally(() => setWorking(false))
            }

            return runBulk(confirming.value)
          }}
        />
      ) : null}
    </div>
  )
}

/**
 * The link into an entry, wherever the first column happens to be.
 *
 * @param {Object} props
 * @return {JSX.Element} The button.
 */
function Open({ entry, onOpen, children, label }) {
  return (
    <button
      type="button"
      // the contents may be a picture, or an empty frame with nothing to read,
      // so the entry's name is given to the button rather than drawn in it
      aria-label={label || __('Untitled', 'schemapress')}
      // the row handles the click too; without this the same entry is opened
      // twice, and it exists mainly so the row is reachable by keyboard
      onClick={(event) => {
        event.stopPropagation()
        onOpen(entry.id)
      }}
      className="block max-w-[18rem] truncate text-left font-medium text-foreground hover:underline"
    >
      {children}
    </button>
  )
}

/**
 * Where one entry stands, as a dot and a word.
 *
 * Three states, not two: an entry can be live on the site *and* have edits that
 * are not. Collapsing that into "published" would hide unpublished work behind
 * a green tick, which is exactly the case someone scanning this table is
 * looking for. The count of how far ahead the draft is stays in the entry —
 * here it only needs to say that there is one.
 *
 * @param {Object} props
 * @return {JSX.Element} The badge.
 */
function State({ entry }) {
  const states = {
    published: { label: __('Published', 'schemapress'), tone: 'bg-emerald-500' },
    modified: { label: __('Edited', 'schemapress'), tone: 'bg-amber-500' },
    draft: { label: __('Draft', 'schemapress'), tone: 'bg-muted-foreground/40' },
  }

  const state = states[entry.state] || states.draft

  return (
    <span
      className="flex items-center gap-1.5 whitespace-nowrap text-[12px] text-muted-foreground"
      title={
        entry.ahead > 0
          ? sprintf(
              /* translators: %d: number of unpublished changes */
              __('%d changes ahead of published', 'schemapress'),
              entry.ahead,
            )
          : undefined
      }
    >
      <span className={cn('size-1.5 shrink-0 rounded-full', state.tone)} />
      {state.label}
    </span>
  )
}

/**
 * A header cell, sortable when it names something the database can order by.
 *
 * The arrow is always present on a sortable column, faint until it is the one
 * in use — an affordance that only appears once you have already found it is
 * not an affordance.
 *
 * @param {Object} props
 * @return {JSX.Element} The cell.
 */
function Th({ children, className, sortBy: column, sort, onSort }) {
  const base =
    'whitespace-nowrap px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground'

  if (!column) {
    return <th className={cn(base, className)}>{children}</th>
  }

  const active = sort?.orderby === column
  const Icon = !active ? ChevronsUpDown : sort.order === 'asc' ? ArrowUp : ArrowDown

  return (
    <th
      scope="col"
      aria-sort={active ? (sort.order === 'asc' ? 'ascending' : 'descending') : 'none'}
      className={cn(base, 'p-0', className)}
    >
      <button
        type="button"
        onClick={() => onSort(column)}
        className={cn(
          'flex w-full items-center gap-1 px-3 py-2 text-[11px] font-semibold uppercase tracking-wide transition-colors hover:text-foreground',
          active ? 'text-foreground' : 'text-muted-foreground',
        )}
      >
        {children}
        <Icon className={cn('size-3 shrink-0', active ? 'opacity-100' : 'opacity-40')} />
      </button>
    </th>
  )
}
