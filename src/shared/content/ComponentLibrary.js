/**
 * The component library rail.
 *
 * Kept permanently beside the page rather than behind a button: the whole
 * vocabulary a page can be built from is visible at once, and adding something
 * is a single click.
 *
 * Two tabs. "Add new" leads, because early on a schema has nothing in it and
 * the presets are the only way forward. "In schema" holds what this page's
 * schema already declares, which is what gets reused once a page is underway.
 */

import { useState } from '@wordpress/element'
import { __, sprintf } from '@wordpress/i18n'
import {
  Heading as HeadingIcon,
  AlignLeft,
  LayoutGrid,
  Image as ImageIcon,
  MousePointerClick,
  Quote,
  List,
  Sparkles,
  Square,
  Layers,
  Columns3,
  Box,
  Plus
} from 'lucide-react'
import { cn, Tabs, TabPanel } from '../../ui'
import { presets } from '../settings'
import { useDrag, draggableProps } from './dnd'
import { useDesign } from './mode'

const ICONS = {
  heading: HeadingIcon,
  text: AlignLeft,
  cards: LayoutGrid,
  hero: Sparkles,
  image: ImageIcon,
  cta: MousePointerClick,
  quote: Quote,
  list: List,
  columns: Columns3,
  container: Box
}

/**
 * Component palette.
 *
 * @param {Object} props
 * @return {JSX.Element} The library.
 */
export function ComponentLibrary({ sections, counts, target, onSelect, onCreate }) {
  const design = useDesign()
  const { setDragging } = useDrag()

  // defining a new component type is a design act; placing one that already
  // exists is not. in content mode the palette goes and the rail becomes a
  // plain list of what this schema offers
  const creating = design && typeof onCreate === 'function'

  const [tab, setTab] = useState(creating ? 'new' : 'schema')

  const tabs = [
    ...(creating ? [{ value: 'new', label: __('Add New', 'schemapress'), icon: Plus }] : []),
    { value: 'schema', label: __('Schema', 'schemapress'), icon: Layers }
  ]

  return (
    <aside className="lg:sticky lg:top-6 lg:max-h-[calc(100vh-9rem)] lg:self-start lg:overflow-y-auto">
      <div className="rounded-lg border border-border bg-background p-2">
        {target ? (
          <p className="mb-2 truncate rounded bg-muted px-2 py-1.5 text-[11px] text-muted-foreground">
            {__('Adding into', 'schemapress')}{' '}
            <span className="font-medium text-foreground">{target}</span>
          </p>
        ) : null}

        <Tabs tabs={tabs} value={tab} onValueChange={setTab} className="gap-2">
          {creating ? (
            <TabPanel value="new">
              <div className="grid grid-cols-2 gap-1.5">
                {presets.map((preset) => {
                  const Icon = ICONS[preset.icon] || Layers

                  return (
                    <button
                      key={preset.id}
                      type="button"
                      title={preset.description}
                      onClick={() => onCreate(preset)}
                      {...draggableProps({ kind: 'preset', preset }, setDragging)}
                      className="flex cursor-grab flex-col items-center gap-1.5 rounded-md border border-border px-2 py-3 text-center transition-colors hover:border-ring/40 hover:bg-accent/50 active:cursor-grabbing"
                    >
                      <Icon className="size-4 text-muted-foreground" />
                      <span className="w-full truncate text-[11px] font-medium leading-tight">
                        {preset.label}
                      </span>
                    </button>
                  )
                })}

                <button
                  type="button"
                  title={__('Start with no fields and define your own.', 'schemapress')}
                  onClick={() => onCreate(null)}
                  {...draggableProps({ kind: 'preset', preset: null }, setDragging)}
                  className="flex cursor-grab flex-col items-center gap-1.5 rounded-md border border-dashed border-border px-2 py-3 text-center transition-colors hover:border-ring/40 hover:bg-accent/50 active:cursor-grabbing"
                >
                  <Square className="size-4 text-muted-foreground" />
                  <span className="w-full truncate text-[11px] font-medium leading-tight">
                    {__('Blank', 'schemapress')}
                  </span>
                </button>
              </div>
            </TabPanel>
          ) : null}

          <TabPanel value="schema">
            {sections.length === 0 ? (
              <p className="px-2 py-6 text-center text-[12px] text-muted-foreground">
                {onCreate
                  ? __(
                      'Components you add appear here, ready to place again.',
                      'schemapress'
                    )
                  : __('This schema defines no components yet.', 'schemapress')}
              </p>
            ) : (
              <div className="flex flex-col">
                {sections.map((section) => {
                  const used = counts[section.key] || 0
                  const atLimit = section.max > 0 && used >= section.max
                  const Icon = ICONS[section.icon] || Layers

                  return (
                    <button
                      key={section.key}
                      type="button"
                      disabled={atLimit}
                      onClick={() => onSelect(section.key)}
                      title={
                        atLimit
                          ? sprintf(
                              /* translators: %d: maximum allowed instances */
                              __('Limit of %d reached', 'schemapress'),
                              section.max
                            )
                          : section.description || undefined
                      }
                      {...(atLimit
                        ? {}
                        : draggableProps({ kind: 'type', key: section.key }, setDragging))}
                      className={cn(
                        'flex items-center gap-2 rounded-md px-2 py-1.5 text-left transition-colors',
                        atLimit
                          ? 'cursor-not-allowed opacity-45'
                          : 'cursor-grab hover:bg-accent active:cursor-grabbing'
                      )}
                    >
                      <Icon className="size-3.5 shrink-0 text-muted-foreground" />
                      <span className="min-w-0 flex-1 truncate text-[12px] font-medium">
                        {section.label}
                      </span>
                      {used > 0 ? <Count value={used} /> : null}
                    </button>
                  )
                })}
              </div>
            )}
          </TabPanel>
        </Tabs>
      </div>
    </aside>
  )
}

/**
 * A small count pip.
 *
 * @param {Object} props
 * @return {JSX.Element} The pip.
 */
function Count({ value }) {
  return (
    <span className="inline-flex min-w-4 shrink-0 items-center justify-center rounded-full bg-muted px-1 text-[10px] text-muted-foreground">
      {value}
    </span>
  )
}
