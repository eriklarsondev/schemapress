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
import { Boxes, Database, Blocks, BookOpen, Plus } from 'lucide-react'
import { cn } from '../ui'

/**
 * Sidebar navigation.
 *
 * @param {Object} props
 * @return {JSX.Element} The sidebar.
 */
export function Sidebar({
  types = [],
  components = [],
  active,
  loading,
  version,
  onSelect,
  onCreate,
  onSelectComponent,
  onCreateComponent,
  onOpenDocs
}) {
  return (
    <aside className="flex w-64 shrink-0 flex-col border-r border-border bg-background">
      <header className="flex items-center gap-3 px-5 py-5">
        <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground">
          <Boxes className="size-4" />
        </span>

        <span className="min-w-0">
          <span className="block truncate text-[14px] font-semibold leading-tight tracking-tight">
            {__('SchemaPress', 'schemapress')}
          </span>
          <span className="block truncate text-[11px] text-muted-foreground">
            {__('Content manager', 'schemapress')}
          </span>
        </span>
      </header>

      <nav
        className="flex-1 overflow-y-auto px-3 pb-6"
        aria-label={__('Collections', 'schemapress')}
      >
        <Group
          icon={Database}
          label={__('Collection types', 'schemapress')}
          count={loading ? null : types.length}
          addLabel={__('Create a collection type', 'schemapress')}
          onAdd={onCreate}
        />

        {loading ? (
          <p className="px-2 py-2 text-[12px] italic text-muted-foreground/70">
            {__('Loading…', 'schemapress')}
          </p>
        ) : types.length === 0 ? (
          <p className="px-2 py-2 text-[12px] italic text-muted-foreground/70">
            {__('No collections yet', 'schemapress')}
          </p>
        ) : (
          types.map((type) => (
            <Item
              key={type.id}
              label={type.pluralLabel || type.label}
              count={type.entries}
              active={active?.view === 'type' && active.id === type.id}
              onClick={() => onSelect(type.id)}
            />
          ))
        )}

        {/* components are the other kind of thing a site is made of: a shape
            with no content of its own, described once and imported wherever it
            is needed. its own group, because it is not a collection */}
        {/* no count. a number beside a collection is how much content is in it,
            which is worth knowing at a glance; a component holds no content, so
            the same badge in the same place would mean something else entirely */}
        <Group
          icon={Blocks}
          label={__('Components', 'schemapress')}
          addLabel={__('Create a component', 'schemapress')}
          onAdd={onCreateComponent}
          className="mt-4"
        />

        {!loading && components.length === 0 ? (
          <p className="px-2 py-2 text-[12px] italic text-muted-foreground/70">
            {__('No components yet', 'schemapress')}
          </p>
        ) : (
          components.map((component) => (
            <Item
              key={component.id}
              label={component.label}
              active={active?.view === 'component' && active.id === component.id}
              onClick={() => onSelectComponent(component.id)}
            />
          ))
        )}

        {/* a screen of the app, not a page elsewhere in wp-admin: looking
            something up should not cost you the collection you were in */}
        <hr className="mx-2 my-4 border-0 border-t border-border" />
        <Item
          label={__('Documentation', 'schemapress')}
          icon={BookOpen}
          active={active?.view === 'docs'}
          onClick={onOpenDocs}
        />
      </nav>

      {version ? (
        <footer className="border-t border-border px-5 py-3 text-[10px] text-muted-foreground">
          {__('SchemaPress', 'schemapress')} {version}
        </footer>
      ) : null}
    </aside>
  )
}

/**
 * A section heading with its own add button.
 *
 * It stays even when the section is empty, because "no components yet" is
 * information — a missing heading only looks like the sidebar failed to load.
 *
 * @param {Object} props
 * @return {JSX.Element} The heading.
 */
function Group({ icon: Icon, label, count, addLabel, onAdd, className }) {
  return (
    <div className={cn('flex items-center gap-1 pr-1', className)}>
      <p className="flex flex-1 items-center gap-1.5 px-2 pb-2 pt-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
        <Icon className="size-3 shrink-0" />
        {label}
        {typeof count === 'number' ? (
          <span className="rounded bg-muted px-1 text-[10px] tabular-nums">{count}</span>
        ) : null}
      </p>

      <button
        type="button"
        title={addLabel}
        aria-label={addLabel}
        onClick={onAdd}
        className="flex size-5 shrink-0 items-center justify-center rounded text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
      >
        <Plus className="size-3.5" />
      </button>
    </div>
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
function Item({ label, icon: Icon, count, active, onClick }) {
  const className = cn(
    'relative flex w-full items-center gap-2 rounded-md py-2 pl-3 pr-2 text-left text-[13px] transition-colors',
    active
      ? 'bg-accent font-medium text-foreground'
      : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground',
  )

  const body = (
    <>
      {active ? (
        <span
          aria-hidden="true"
          className="absolute inset-y-1 left-0 w-0.5 rounded-full bg-primary"
        />
      ) : null}

      {Icon ? <Icon className="size-3.5 shrink-0" /> : null}
      <span className="min-w-0 flex-1 truncate">{label}</span>

      {typeof count === 'number' ? (
        <span className="shrink-0 rounded-full bg-muted px-1.5 text-[10px] tabular-nums">
          {count}
        </span>
      ) : null}
    </>
  )

  return (
    <button type="button" onClick={onClick} className={className}>
      {body}
    </button>
  )
}
