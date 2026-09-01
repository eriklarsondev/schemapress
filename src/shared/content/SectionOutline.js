/**
 * The page outline: a compact list of what is on the page, in order.
 *
 * The canvas is rendered inside a shadow root, which makes it a poor place to
 * put drop targets - the markup belongs to the templates, not to the editor.
 * So arranging happens here and editing happens there: this is the layers
 * panel, and it stays in step with the canvas through selection.
 */

import { __ } from '@wordpress/i18n'
import { ChevronRight, GripVertical, Trash2, Plus } from 'lucide-react'
import { removeAt, listAt } from '../utils'
import { Button, cn } from '../../ui'
import { DropZone, useDrag, draggableProps } from './dnd'
import { SectionPicker } from './SectionPicker'

/**
 * Page outline.
 *
 * @param {Object} props
 * @return {JSX.Element} The outline.
 */
export function SectionOutline({
  sections,
  types,
  selectedId,
  counts,
  editable,
  onSelect,
  onDrop,
  onListChange
}) {
  return (
    <div className="rounded-lg border border-border bg-background p-2">
      <p className="mb-1.5 px-1 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
        {__('Page outline', 'schemapress')}
      </p>

      <Level
        sections={sections}
        types={types}
        path={[]}
        depth={0}
        selectedId={selectedId}
        counts={counts}
        editable={editable}
        onSelect={onSelect}
        onDrop={onDrop}
        onListChange={onListChange}
      />
    </div>
  )
}

/**
 * One level of the outline.
 *
 * @param {Object} props
 * @return {JSX.Element} The level.
 */
function Level({
  sections,
  types,
  path,
  depth,
  selectedId,
  counts,
  editable,
  onSelect,
  onDrop,
  onListChange
}) {
  const { dragging, setDragging } = useDrag()

  return (
    <div className={cn('flex flex-col', depth > 0 && 'ml-3 border-l border-border pl-2')}>
      <DropZone
        index={0}
        onDrop={(index, payload) => onDrop(path, index, payload)}
        label={__('Drop here', 'schemapress')}
      />

      {sections.map((section, index) => {
        const definition = types.find((candidate) => candidate.key === section.type)
        const selected = section.id === selectedId

        return (
          <div key={section.id}>
            <div
              {...draggableProps({ kind: 'move', index, path }, setDragging)}
              className={cn(
                'group flex items-center gap-1.5 rounded px-1.5 py-1 transition-colors',
                selected ? 'bg-primary/10' : 'hover:bg-accent',
                dragging?.kind === 'move' &&
                  dragging.index === index &&
                  String(dragging.path) === String(path) &&
                  'opacity-40'
              )}
            >
              <GripVertical className="size-3 shrink-0 cursor-grab text-muted-foreground/40" />

              <button
                type="button"
                onClick={() => onSelect(section.id)}
                className="flex min-w-0 flex-1 items-center gap-1.5 text-left"
              >
                {definition?.container ? (
                  <ChevronRight className="size-3 shrink-0 text-muted-foreground" />
                ) : null}
                <span
                  className={cn(
                    'truncate text-[12px]',
                    selected ? 'font-semibold text-foreground' : 'text-muted-foreground'
                  )}
                >
                  {definition?.label || section.type}
                </span>
              </button>

              <Button
                size="icon-sm"
                variant="destructive-ghost"
                className="opacity-0 transition-opacity group-hover:opacity-100"
                aria-label={__('Remove', 'schemapress')}
                draggable={false}
                onDragStart={(event) => event.preventDefault()}
                onClick={() => onListChange(path, removeAt(sections, index))}
              >
                <Trash2 />
              </Button>
            </div>

            {definition?.container ? (
              <Level
                sections={section.children || []}
                types={types}
                path={[...path, index]}
                depth={depth + 1}
                selectedId={selectedId}
                counts={counts}
                editable={editable}
                onSelect={onSelect}
                onDrop={onDrop}
                onListChange={onListChange}
              />
            ) : null}

            <DropZone
              index={index + 1}
              onDrop={(dropIndex, payload) => onDrop(path, dropIndex, payload)}
              label={__('Drop here', 'schemapress')}
            />
          </div>
        )
      })}

      <SectionPicker
        sections={types}
        counts={counts}
        onSelect={(key) => onDrop(path, sections.length, { kind: 'type', key })}
        onCreate={
          editable
            ? (preset) => onDrop(path, sections.length, { kind: 'preset', preset })
            : undefined
        }
        trigger={
          <button
            type="button"
            className="mt-1 flex w-full items-center justify-center gap-1.5 rounded border border-dashed border-border py-1.5 text-[11px] font-medium text-muted-foreground transition-colors hover:border-ring/40 hover:bg-accent/40 hover:text-foreground"
          >
            <Plus className="size-3" />
            {depth === 0 ? __('Add component', 'schemapress') : __('Add inside', 'schemapress')}
          </button>
        }
      />
    </div>
  )
}
