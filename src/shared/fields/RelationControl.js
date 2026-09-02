/**
 * A link to entries in another collection.
 *
 * Stores ids only, so a linked entry's title stays live rather than being
 * copied in at save time — rename Ada Lovelace once and every story that
 * credits her follows.
 *
 * The picker searches rather than listing everything: a collection can hold
 * thousands of entries, and a select element with thousands of options is not a
 * picker.
 */

import { useEffect, useState } from '@wordpress/element'
import { __, sprintf } from '@wordpress/i18n'
import { X, Search, CircleAlert } from 'lucide-react'
import { Field, Button, Badge, Alert, cn } from '../../ui'
import { api } from '../api'

/**
 * Loads the entries currently selected, so they can be shown by title rather
 * than by id. Ids that no longer resolve are kept and marked, not dropped —
 * silently discarding a broken link hides that it broke.
 *
 * @param {number}   typeId
 * @param {number[]} ids
 * @return {Object} Titles keyed by id.
 */
function useTitles(typeId, ids) {
  const [titles, setTitles] = useState({})
  const key = ids.join(',')

  useEffect(() => {
    if (!typeId || ids.length === 0) {
      return undefined
    }

    let live = true

    Promise.all(
      ids.map((id) =>
        api
          .entry(typeId, id)
          .then((result) => [id, result.entry.title])
          .catch(() => [id, null]),
      ),
    ).then((pairs) => {
      if (live) {
        setTitles(Object.fromEntries(pairs))
      }
    })

    return () => {
      live = false
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [typeId, key])

  return titles
}

/**
 * The relation control.
 *
 * @param {Object} props
 * @return {JSX.Element} The control.
 */
export function RelationField({ field, value, onChange }) {
  const typeId = Number(field.config?.collection) || 0
  const multiple = Boolean(field.config?.multiple)

  const selected = multiple
    ? (Array.isArray(value) ? value : []).map(Number).filter(Boolean)
    : [Number(value) || 0].filter(Boolean)

  const titles = useTitles(typeId, selected)

  const [search, setSearch] = useState('')
  const [results, setResults] = useState([])
  const [open, setOpen] = useState(false)

  useEffect(() => {
    if (!typeId || !open) {
      return undefined
    }

    let live = true

    const timer = setTimeout(() => {
      api
        .entries(typeId, { search, perPage: 10 })
        .then((result) => live && setResults(result.entries || []))
        .catch(() => live && setResults([]))
    }, 200)

    return () => {
      live = false
      clearTimeout(timer)
    }
  }, [typeId, search, open])

  /**
   * Adds an id to the selection.
   *
   * @param {number} id
   * @return {void}
   */
  const add = (id) => {
    if (multiple) {
      if (!selected.includes(id)) {
        onChange([...selected, id])
      }
    } else {
      onChange(id)
    }

    setSearch('')
    setOpen(false)
  }

  /**
   * Removes an id from the selection.
   *
   * @param {number} id
   * @return {void}
   */
  const remove = (id) => onChange(multiple ? selected.filter((entry) => entry !== id) : null)

  if (!typeId) {
    return (
      <Field label={field.label} help={field.help} required={field.required}>
        <Alert variant="warning">
          {__(
            'This relation does not say which collection it points at. Set one in the Fields tab.',
            'schemapress',
          )}
        </Alert>
      </Field>
    )
  }

  const full = !multiple && selected.length > 0

  return (
    <Field label={field.label} help={field.help} required={field.required}>
      <div className="flex flex-col gap-2">
        {selected.length > 0 ? (
          <div className="flex flex-wrap gap-1.5">
            {selected.map((id) => {
              const title = titles[id]
              const missing = title === null

              return (
                <Badge
                  key={id}
                  variant={missing ? 'warning' : 'outline'}
                  className="gap-1 py-1 pl-2 pr-1"
                >
                  {missing ? <CircleAlert className="size-3" /> : null}
                  {missing
                    ? sprintf(
                        /* translators: %d: the id of an entry that no longer exists */
                        __('Missing entry #%d', 'schemapress'),
                        id,
                      )
                    : title || `#${id}`}
                  <Button
                    size="icon-sm"
                    variant="ghost"
                    className="size-4"
                    aria-label={__('Remove', 'schemapress')}
                    onClick={() => remove(id)}
                  >
                    <X className="size-3" />
                  </Button>
                </Badge>
              )
            })}
          </div>
        ) : null}

        {full ? null : (
          <div className="relative">
            <Search className="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
            <input
              type="search"
              value={search}
              placeholder={__('Search entries to link…', 'schemapress')}
              onFocus={() => setOpen(true)}
              onChange={(event) => setSearch(event.target.value)}
              className="h-9 w-full rounded-md border border-input bg-background pl-8 pr-2 text-[13px] outline-none transition-colors focus:border-ring"
            />

            {open ? (
              <div className="absolute inset-x-0 top-full z-10 mt-1 max-h-56 overflow-y-auto rounded-md border border-border bg-background p-1 shadow-lg">
                {results.length === 0 ? (
                  <p className="px-2 py-3 text-center text-[12px] text-muted-foreground">
                    {__('Nothing found.', 'schemapress')}
                  </p>
                ) : (
                  results
                    .filter((entry) => !selected.includes(entry.id))
                    .map((entry) => (
                      <button
                        key={entry.id}
                        type="button"
                        onClick={() => add(entry.id)}
                        className={cn(
                          'flex w-full items-center justify-between gap-2 rounded px-2 py-1.5 text-left text-[13px] transition-colors',
                          'hover:bg-accent',
                        )}
                      >
                        <span className="min-w-0 truncate">{entry.title}</span>
                        {entry.status !== 'publish' ? (
                          <Badge variant="mono">{__('draft', 'schemapress')}</Badge>
                        ) : null}
                      </button>
                    ))
                )}
              </div>
            ) : null}
          </div>
        )}

        {open ? (
          // clicking anywhere else closes the results without stealing the click
          <button
            type="button"
            aria-hidden="true"
            tabIndex={-1}
            onClick={() => setOpen(false)}
            className="fixed inset-0 z-0 cursor-default"
          />
        ) : null}
      </div>
    </Field>
  )
}
