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

import { useEffect, useState } from '@wordpress/element'
import { __, sprintf } from '@wordpress/i18n'
import {
  Plus,
  Pencil,
  Trash2,
  ChevronLeft,
  ChevronRight,
  ChevronsUpDown,
  ArrowUp,
  ArrowDown,
  Table2,
  Search,
  Settings2,
} from 'lucide-react'
import { Button, Empty, Loading, Alert, ConfirmDialog, Input, Tooltip, cn } from '../../ui'
import { Ago } from '../../shared/time'
import { api } from '../../shared/api'
import { ConfigureTableDialog } from './ConfigureTableDialog'

/**
 * How many field columns the table shows before it stops being scannable.
 */
const MAX_COLUMNS = 4

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

    case 'image':
    case 'file':
      return __('Set', 'schemapress')

    case 'link':
      return value.label || value.url || ''

    case 'toggle':
      return value ? __('Yes', 'schemapress') : __('No', 'schemapress')

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
  const [removing, setRemoving] = useState(null)
  const [configuring, setConfiguring] = useState(false)

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

  useEffect(() => {
    let live = true

    setState((current) => ({ ...current, loading: true }))
    setError('')

    api
      .entries(type.id, { page, search: term, orderby: sort.orderby, order: sort.order })
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
  }, [type.id, page, term, sort.orderby, sort.order])

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
          // differs: names read A-Z, times read newest first
          { orderby, order: orderby === 'title' ? 'asc' : 'desc' },
    )
  }

  /**
   * Removes an entry, then reloads so paging and totals stay truthful.
   *
   * @param {number} id
   * @return {void}
   */
  const remove = (id) =>
    api
      .deleteEntry(type.id, id)
      .then(() => {
        // dropping the last row of the last page would otherwise leave you on
        // an empty page with no way to tell it is empty rather than broken
        const remaining = state.entries.length - 1

        if (remaining === 0 && page > 1) {
          setPage(page - 1)
        } else {
          setTerm((current) => current)
          setState((current) => ({
            ...current,
            entries: current.entries.filter((entry) => entry.id !== id),
            total: Math.max(0, current.total - 1),
          }))
        }
      })
      .catch((failure) => setError(failure.message))

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
  const titleKey = fields.find((field) => field.type === 'text' || field.type === 'textarea')?.key

  const from = (page - 1) * state.perPage + 1
  const to = from + state.entries.length - 1

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        {/* the width lives on the wrapper: the scoped reset owns the input's
            own width, and a utility on the input itself would lose to it */}
        <div className="relative w-full sm:w-72">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
          <Input
            type="search"
            className="sp-inset-icon"
            value={search}
            placeholder={__('Search entries…', 'schemapress')}
            onChange={(event) => setSearch(event.target.value)}
          />
        </div>

        <div className="flex items-center gap-2">
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
        </div>
      </div>

      {error ? <Alert variant="warning">{error}</Alert> : null}

      {fields.length === 0 ? (
        <Empty
          icon={Table2}
          title={__('This collection has no fields yet', 'schemapress')}
          description={__(
            'Add some in the Fields tab, then you can create entries.',
            'schemapress',
          )}
          className="py-16"
        />
      ) : state.loading ? (
        <Loading label={__('Loading entries…', 'schemapress')} />
      ) : state.entries.length === 0 ? (
        <Empty
          icon={Table2}
          title={
            term
              ? __('Nothing matches that search', 'schemapress')
              : __('No entries yet', 'schemapress')
          }
          description={
            term
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
                {/* nothing ticked still needs something to click */}
                {columns.length === 0 ? (
                  <Th sortBy="title" sort={sort} onSort={sortBy}>
                    {__('Name', 'schemapress')}
                  </Th>
                ) : null}

                {columns.map((field) => (
                  <Th
                    key={field.key}
                    sortBy={field.key === titleKey ? 'title' : undefined}
                    sort={sort}
                    onSort={sortBy}
                  >
                    {field.label}
                  </Th>
                ))}

                <Th sortBy="modified" sort={sort} onSort={sortBy}>
                  {__('Updated', 'schemapress')}
                </Th>
                {drafts ? <Th>{__('Status', 'schemapress')}</Th> : null}
                <th className="w-20 px-3 py-2" />
              </tr>
            </thead>

            <tbody>
              {state.entries.map((entry) => (
                <tr
                  key={entry.id}
                  className="group border-b border-border/60 transition-colors last:border-0 hover:bg-accent/40"
                >
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
                      className="max-w-[16rem] truncate whitespace-nowrap px-3 py-2.5 text-muted-foreground"
                    >
                      {index === 0 ? (
                        // the first column is the way in. an entry whose first
                        // field is blank still has to be openable, so it falls
                        // back to the derived name
                        <Open entry={entry} onOpen={onOpenEntry}>
                          {cell(field, entry.values?.[field.key]) ||
                            entry.title ||
                            __('Untitled', 'schemapress')}
                        </Open>
                      ) : (
                        cell(field, entry.values?.[field.key]) || (
                          <span className="text-muted-foreground/40">—</span>
                        )
                      )}
                    </td>
                  ))}

                  <td className="whitespace-nowrap px-3 py-2.5 text-muted-foreground">
                    <Ago stamp={entry.modified} fallback="—" />
                  </td>

                  {drafts ? (
                    <td className="px-3 py-2.5">
                      <State entry={entry} />
                    </td>
                  ) : null}

                  <td className="whitespace-nowrap px-3 py-2.5">
                    <span className="flex items-center justify-end gap-0.5 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100">
                      <Button
                        size="icon-sm"
                        variant="ghost"
                        aria-label={__('Edit', 'schemapress')}
                        onClick={() => onOpenEntry(entry.id)}
                      >
                        <Pencil />
                      </Button>
                      <Button
                        size="icon-sm"
                        variant="destructive-ghost"
                        aria-label={__('Delete', 'schemapress')}
                        onClick={() => setRemoving(entry)}
                      >
                        <Trash2 />
                      </Button>
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

      {removing ? (
        <ConfirmDialog
          open
          onOpenChange={(next) => !next && setRemoving(null)}
          title={__('Delete this entry?', 'schemapress')}
          description={sprintf(
            /* translators: %s: the entry's title */
            __('“%s” will be moved to the trash.', 'schemapress'),
            removing.title,
          )}
          confirmLabel={__('Delete', 'schemapress')}
          onConfirm={() => remove(removing.id)}
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
function Open({ entry, onOpen, children }) {
  return (
    <button
      type="button"
      onClick={() => onOpen(entry.id)}
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
