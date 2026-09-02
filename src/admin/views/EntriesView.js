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
 */

import { useEffect, useState } from '@wordpress/element'
import { __, sprintf } from '@wordpress/i18n'
import { Plus, Pencil, Trash2, ChevronLeft, ChevronRight, Table2, Search } from 'lucide-react'
import { Button, Badge, Empty, Loading, Alert, ConfirmDialog, cn } from '../../ui'
import { api } from '../../shared/api'

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

    case 'relation':
      return Array.isArray(value)
        ? sprintf(
            /* translators: %d: number of linked entries */
            __('%d linked', 'schemapress'),
            value.length,
          )
        : __('Linked', 'schemapress')

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
export function EntriesView({ type, fields, onOpenEntry }) {
  const [state, setState] = useState({ loading: true, entries: [], total: 0, pages: 0 })
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [term, setTerm] = useState('')
  const [error, setError] = useState('')
  const [removing, setRemoving] = useState(null)

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
      .entries(type.id, { page, search: term })
      .then((result) => {
        if (!live) {
          return
        }

        setState({
          loading: false,
          entries: result.entries || [],
          total: result.total || 0,
          pages: result.pages || 0,
        })
      })
      .catch((failure) => {
        if (!live) {
          return
        }

        setError(failure.message)
        setState({ loading: false, entries: [], total: 0, pages: 0 })
      })

    return () => {
      live = false
    }
  }, [type.id, page, term])

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

  const columns = fields.slice(0, MAX_COLUMNS)

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="relative">
          <Search className="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
          <input
            type="search"
            value={search}
            placeholder={__('Search entries…', 'schemapress')}
            onChange={(event) => setSearch(event.target.value)}
            className="h-8 w-72 rounded-md border border-border bg-background pl-8 pr-2 text-[13px] outline-none transition-colors focus:border-ring"
          />
        </div>

        <div className="flex items-center gap-3">
          <span className="text-[12px] text-muted-foreground">
            {state.loading
              ? __('Loading…', 'schemapress')
              : sprintf(
                  /* translators: %d: number of entries */
                  __('%d entries', 'schemapress'),
                  state.total,
                )}
          </span>

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
                <Th>{__('Title', 'schemapress')}</Th>
                {columns.map((field) => (
                  <Th key={field.key}>{field.label}</Th>
                ))}
                <Th>{__('Status', 'schemapress')}</Th>
                <th className="w-20 px-3 py-2" />
              </tr>
            </thead>

            <tbody>
              {state.entries.map((entry) => (
                <tr
                  key={entry.id}
                  className="group border-b border-border/60 transition-colors last:border-0 hover:bg-accent/40"
                >
                  <td className="px-3 py-2.5">
                    <button
                      type="button"
                      onClick={() => onOpenEntry(entry.id)}
                      className="font-medium text-foreground hover:underline"
                    >
                      {entry.title}
                    </button>
                  </td>

                  {columns.map((field) => (
                    <td key={field.key} className="px-3 py-2.5 text-muted-foreground">
                      {cell(field, entry.values?.[field.key]) || (
                        <span className="text-muted-foreground/40">—</span>
                      )}
                    </td>
                  ))}

                  <td className="px-3 py-2.5">
                    <Badge variant={entry.status === 'publish' ? 'outline' : 'mono'}>
                      {entry.status === 'publish'
                        ? __('Published', 'schemapress')
                        : __('Draft', 'schemapress')}
                    </Badge>
                  </td>

                  <td className="px-3 py-2.5">
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

      {state.pages > 1 ? (
        <div className="flex items-center justify-end gap-2 text-[12px]">
          <Button
            size="icon-sm"
            variant="outline"
            aria-label={__('Previous page', 'schemapress')}
            disabled={page <= 1}
            onClick={() => setPage(page - 1)}
          >
            <ChevronLeft />
          </Button>

          <span className="text-muted-foreground">
            {sprintf(
              /* translators: 1: current page, 2: total pages */
              __('%1$d of %2$d', 'schemapress'),
              page,
              state.pages,
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
        </div>
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
 * A header cell.
 *
 * @param {Object} props
 * @return {JSX.Element} The cell.
 */
function Th({ children, className }) {
  return (
    <th
      className={cn(
        'px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground',
        className,
      )}
    >
      {children}
    </th>
  )
}
