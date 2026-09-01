/**
 * The element palette: a horizontal, scrollable strip of the things that can
 * go inside a component.
 *
 * An element is a field with its type and role already decided - "Button" is a
 * link with the action role. Dragging one in is the same operation as picking
 * a field type and then a role, minus the two decisions that only make sense
 * once you already understand the schema.
 */

import { __ } from '@wordpress/i18n'
import {
  Heading as HeadingIcon,
  Type,
  AlignLeft,
  FileText,
  Image as ImageIcon,
  Layers2,
  MousePointerClick,
  Link2,
  Rows3,
  Group,
  ToggleRight,
  ListChecks,
  Hash,
  FileSymlink
} from 'lucide-react'
import { cn } from '../../ui'
import { elements } from '../settings'
import { useDrag, draggableProps } from './dnd'

const ICONS = {
  heading: HeadingIcon,
  eyebrow: Type,
  text: AlignLeft,
  richtext: FileText,
  image: ImageIcon,
  background: Layers2,
  button: MousePointerClick,
  link: Link2,
  repeater: Rows3,
  group: Group,
  toggle: ToggleRight,
  select: ListChecks,
  number: Hash,
  post: FileSymlink
}

/**
 * Horizontal element strip.
 *
 * @param {Object} props
 * @return {JSX.Element|null} The palette.
 */
export function ElementPalette({ onAdd, label }) {
  const { setDragging } = useDrag()

  if (elements.length === 0) {
    return null
  }

  return (
    <div className="rounded-md bg-muted/50 p-2">
      <p className="mb-1.5 px-0.5 text-[11px] text-muted-foreground">
        {label || __('Drag in an element, or click to add', 'schemapress')}
      </p>

      {/* a single scrolling row rather than a wrapping grid: the palette is a
          reference strip, and keeping it one line deep leaves the component
          being edited as the tallest thing on screen */}
      <div className="flex gap-1 overflow-x-auto pb-1">
        {elements.map((element) => {
          const Icon = ICONS[element.icon] || Type

          return (
            <button
              key={element.id}
              type="button"
              title={element.label}
              onClick={() => onAdd(element)}
              {...draggableProps({ kind: 'element', element }, setDragging)}
              className={cn(
                'flex shrink-0 cursor-grab select-none items-center gap-1.5 rounded border border-transparent bg-background px-2 py-1 text-muted-foreground transition-colors',
                'hover:border-border hover:text-foreground active:cursor-grabbing'
              )}
            >
              <Icon className="size-3.5" />
              <span className="whitespace-nowrap text-[11px] font-medium">{element.label}</span>
            </button>
          )
        })}
      </div>
    </div>
  )
}

/**
 * The icon for an element, looked up by the field it produced.
 *
 * @param {Object} field
 * @return {Function} A lucide icon component.
 */
export function iconForField(field) {
  const match = elements.find(
    (element) =>
      element.field.type === field.type &&
      (element.field.role || '') === (field.role || '')
  )

  return ICONS[match?.icon] || Type
}
