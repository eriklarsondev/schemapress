/**
 * The permanent sidebar.
 *
 * Every collection is on screen at all times. That is the whole point of it:
 * what a site is made of should be readable without opening anything, and
 * moving between two collections should be one click rather than a trip back
 * through a listing.
 *
 * The group heading stays even when empty, because "no collections yet" is
 * information — a missing heading only looks like the sidebar failed to load.
 */

import { __ } from '@wordpress/i18n'
import { Boxes, Database, BookOpen, Plus } from 'lucide-react'
import { cn } from '../ui'

/**
 * Sidebar navigation.
 *
 * @param {Object} props
 * @return {JSX.Element} The sidebar.
 */
export function Sidebar({ types = [], activeId, loading, docsUrl, version, onSelect, onCreate }) {
  return (
    <aside className="flex w-64 shrink-0 flex-col bg-appbar text-appbar-foreground">
      <header className="flex items-center gap-2.5 px-4 py-4">
        <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-white/10 ring-1 ring-inset ring-white/15">
          <Boxes className="size-4" />
        </span>

        <span className="min-w-0">
          <span className="block truncate text-[14px] font-semibold leading-tight tracking-tight">
            {__('SchemaPress', 'schemapress')}
          </span>
          <span className="block truncate text-[11px] text-appbar-muted">
            {__('Content manager', 'schemapress')}
          </span>
        </span>
      </header>

      <nav
        className="flex-1 overflow-y-auto px-2 pb-4"
        aria-label={__('Collections', 'schemapress')}
      >
        <div className="flex items-center gap-1 pr-1">
          <p className="flex flex-1 items-center gap-1.5 px-2 pb-1 pt-1.5 text-[10px] font-semibold uppercase tracking-wider text-appbar-muted">
            <Database className="size-3 shrink-0" />
            {__('Collection types', 'schemapress')}
            {!loading ? (
              <span className="rounded bg-white/10 px-1 text-[10px] tabular-nums">
                {types.length}
              </span>
            ) : null}
          </p>

          <button
            type="button"
            title={__('Create a collection type', 'schemapress')}
            aria-label={__('Create a collection type', 'schemapress')}
            onClick={onCreate}
            className="flex size-5 shrink-0 items-center justify-center rounded text-appbar-muted transition-colors hover:bg-white/10 hover:text-appbar-foreground"
          >
            <Plus className="size-3.5" />
          </button>
        </div>

        {loading ? (
          <p className="px-2 py-1.5 text-[12px] italic text-appbar-muted/70">
            {__('Loading…', 'schemapress')}
          </p>
        ) : types.length === 0 ? (
          <p className="px-2 py-1.5 text-[12px] italic text-appbar-muted/70">
            {__('No collections yet', 'schemapress')}
          </p>
        ) : (
          types.map((type) => (
            <Item
              key={type.id}
              label={type.pluralLabel || type.label}
              count={type.entries}
              active={type.id === activeId}
              onClick={() => onSelect(type.id)}
            />
          ))
        )}

        {docsUrl ? (
          <>
            <hr className="mx-2 my-3 border-0 border-t border-white/10" />
            <Item label={__('Documentation', 'schemapress')} icon={BookOpen} href={docsUrl} />
          </>
        ) : null}
      </nav>

      {version ? (
        <footer className="border-t border-white/10 px-4 py-2.5 text-[10px] text-appbar-muted">
          {__('SchemaPress', 'schemapress')} {version}
        </footer>
      ) : null}
    </aside>
  )
}

/**
 * One navigable row.
 *
 * The active row carries a left bar rather than only a background: at this size
 * a tinted row and a hovered row are hard to tell apart, and only one of them
 * is where you are.
 *
 * @param {Object} props
 * @return {JSX.Element} The row.
 */
function Item({ label, icon: Icon, count, active, onClick, href }) {
  const className = cn(
    'relative flex w-full items-center gap-2 rounded-md py-1.5 pl-3 pr-2 text-left text-[13px] transition-colors',
    active
      ? 'bg-appbar-active font-medium text-appbar-foreground'
      : 'text-appbar-muted hover:bg-white/5 hover:text-appbar-foreground',
  )

  const body = (
    <>
      {active ? (
        <span
          aria-hidden="true"
          className="absolute inset-y-1 left-0 w-0.5 rounded-full bg-appbar-foreground"
        />
      ) : null}

      {Icon ? <Icon className="size-3.5 shrink-0" /> : null}
      <span className="min-w-0 flex-1 truncate">{label}</span>

      {typeof count === 'number' ? (
        <span className="shrink-0 rounded-full bg-white/10 px-1.5 text-[10px] tabular-nums">
          {count}
        </span>
      ) : null}
    </>
  )

  if (href) {
    return (
      <a href={href} className={className}>
        {body}
      </a>
    )
  }

  return (
    <button type="button" onClick={onClick} className={className}>
      {body}
    </button>
  )
}
