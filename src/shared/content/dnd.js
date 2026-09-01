/**
 * Drag and drop for the page canvas.
 *
 * Built on native HTML5 drag events rather than a library: the interaction is
 * a list with insertion points, which the platform already models.
 *
 * The one thing the platform does not give us is knowing *what* is being
 * dragged while it is in flight - dataTransfer is deliberately unreadable
 * during dragover, so a drop zone cannot inspect the payload to decide whether
 * to light up. A context carries that alongside, and dataTransfer still holds
 * the real payload so a drop outside the app degrades to inert text.
 */

import { createContext, useContext, useState } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { cn } from '../../ui'

const DragContext = createContext({ dragging: null, setDragging: () => {} })

/**
 * Provides drag state to a canvas and its component library.
 *
 * @param {Object} props
 * @return {JSX.Element} The provider.
 */
export function DragProvider({ children }) {
  const [dragging, setDragging] = useState(null)

  return <DragContext.Provider value={{ dragging, setDragging }}>{children}</DragContext.Provider>
}

/**
 * The current drag state.
 *
 * @return {{dragging: Object|null, setDragging: Function}} The state.
 */
export function useDrag() {
  return useContext(DragContext)
}

/**
 * Props that make an element draggable with a payload.
 *
 * @param {Object}   payload
 * @param {Function} setDragging
 * @return {Object} Props to spread onto the element.
 */
export function draggableProps(payload, setDragging) {
  return {
    draggable: true,
    onDragStart: (event) => {
      event.stopPropagation()
      event.dataTransfer.effectAllowed = 'copyMove'
      event.dataTransfer.setData('text/plain', JSON.stringify(payload))
      setDragging(payload)
    },
    onDragEnd: () => setDragging(null)
  }
}

/**
 * An insertion point between two placed sections.
 *
 * Collapsed to a hairline until a drag is in progress, so the canvas is not
 * littered with gaps the rest of the time.
 *
 * @param {Object} props
 * @return {JSX.Element|null} The drop zone.
 */
export function DropZone({ index, onDrop, label }) {
  const { dragging, setDragging } = useDrag()
  const [over, setOver] = useState(false)

  if (!dragging) {
    return null
  }

  return (
    <div
      onDragOver={(event) => {
        event.preventDefault()
        event.dataTransfer.dropEffect = dragging.kind === 'move' ? 'move' : 'copy'
        setOver(true)
      }}
      onDragLeave={() => setOver(false)}
      onDrop={(event) => {
        event.preventDefault()
        setOver(false)
        setDragging(null)
        onDrop(index, dragging)
      }}
      className={cn(
        'relative -my-1 flex items-center justify-center rounded transition-all',
        over ? 'h-12 bg-primary/5 ring-2 ring-primary' : 'h-3'
      )}
    >
      {over ? (
        <span className="text-[11px] font-medium text-primary">{label}</span>
      ) : (
        <span className="h-0.5 w-full rounded-full bg-primary/25" />
      )}
    </div>
  )
}

/**
 * A grid cell that accepts a drop.
 *
 * In a column layout there is nowhere to put a hairline between items - the
 * gap between two columns is not an insertion point anyone can aim at - so the
 * cell itself becomes the target, and a drop lands before the item it covers.
 *
 * @param {Object} props
 * @return {JSX.Element|null} The cell.
 */
export function CellDropZone({ index, onDrop, children, placeholder = false }) {
  const { dragging, setDragging } = useDrag()
  const [over, setOver] = useState(false)

  if (placeholder && !dragging) {
    return null
  }

  return (
    <div
      onDragOver={(event) => {
        if (!dragging) {
          return
        }

        event.preventDefault()
        event.dataTransfer.dropEffect = dragging.kind === 'move' ? 'move' : 'copy'
        setOver(true)
      }}
      onDragLeave={() => setOver(false)}
      onDrop={(event) => {
        if (!dragging) {
          return
        }

        event.preventDefault()
        setOver(false)
        setDragging(null)
        onDrop(index, dragging)
      }}
      className={cn(
        'rounded-lg transition-all',
        over && 'ring-2 ring-primary',
        placeholder &&
          'flex min-h-24 items-center justify-center border border-dashed border-primary/30 text-[11px] font-medium text-muted-foreground'
      )}
    >
      {children || (placeholder ? __('Drop here', 'schemapress') : null)}
    </div>
  )
}

/**
 * The whole-canvas drop target shown when a page has no sections yet.
 *
 * @param {Object} props
 * @return {JSX.Element} The target.
 */
export function EmptyDropZone({ onDrop, children }) {
  const { dragging, setDragging } = useDrag()
  const [over, setOver] = useState(false)

  return (
    <div
      onDragOver={(event) => {
        if (!dragging) {
          return
        }

        event.preventDefault()
        event.dataTransfer.dropEffect = 'copy'
        setOver(true)
      }}
      onDragLeave={() => setOver(false)}
      onDrop={(event) => {
        if (!dragging) {
          return
        }

        event.preventDefault()
        setOver(false)
        setDragging(null)
        onDrop(0, dragging)
      }}
      className={cn(
        'rounded-lg transition-colors',
        dragging && 'ring-2',
        over ? 'bg-primary/5 ring-primary' : dragging ? 'ring-primary/30' : ''
      )}
    >
      {children}
    </div>
  )
}
