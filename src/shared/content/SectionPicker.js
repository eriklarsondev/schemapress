/**
 * The "add a component" tray.
 *
 * Two ways in, both one click. Components the schema already declares are
 * placed directly; components it does not are picked from a preset library
 * that arrives complete with its fields and layout options. Defining a
 * component from an empty form is still possible, but it is the exception at
 * the bottom of the list rather than the price of adding anything at all.
 */

import { useState } from '@wordpress/element'
import { __, sprintf } from '@wordpress/i18n'
import {
  Plus,
  Heading as HeadingIcon,
  AlignLeft,
  LayoutGrid,
  Image as ImageIcon,
  MousePointerClick,
  Quote,
  List,
  Sparkles,
  Square,
  Layers
} from 'lucide-react'
import { Button, Popover, cn } from '../../ui'
import { presets } from '../settings'

const ICONS = {
  heading: HeadingIcon,
  text: AlignLeft,
  cards: LayoutGrid,
  hero: Sparkles,
  image: ImageIcon,
  cta: MousePointerClick,
  quote: Quote,
  list: List
}

/**
 * Section type chooser.
 *
 * @param {Object} props
 * @return {JSX.Element} The picker.
 */
export function SectionPicker({ sections, counts, onSelect, onCreate, trigger }) {
  const [open, setOpen] = useState(false)

  /**
   * Places a component and closes the tray. Adding one component at a time is
   * the common case, and leaving the tray open hides what was just added.
   *
   * @param {Function} action
   * @return {void}
   */
  const place = (action) => {
    action()
    setOpen(false)
  }

  return (
    <Popover
      open={open}
      onOpenChange={setOpen}
      className="w-[26rem] p-2"
      trigger={
        trigger || (
          <Button>
            <Plus />
            {__('Add component', 'schemapress')}
          </Button>
        )
      }
    >
      <div className="flex max-h-[26rem] flex-col gap-3 overflow-y-auto">
        {sections.length > 0 ? (
          <section className="flex flex-col gap-1">
            <p className="px-1 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
              {__('In this page’s schema', 'schemapress')}
            </p>

            {sections.map((section) => {
              const used = counts[section.key] || 0
              const atLimit = section.max > 0 && used >= section.max
              const Icon = ICONS[section.icon] || Layers

              return (
                <button
                  key={section.key}
                  type="button"
                  disabled={atLimit}
                  onClick={() => place(() => onSelect(section.key))}
                  className={cn(
                    'flex items-center gap-2.5 rounded-md p-2 text-left transition-colors',
                    atLimit ? 'cursor-not-allowed opacity-50' : 'hover:bg-accent'
                  )}
                >
                  <Icon className="size-4 shrink-0 text-muted-foreground" />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-[13px] font-medium">{section.label}</span>
                    {atLimit ? (
                      <span className="block text-[11px] text-muted-foreground">
                        {sprintf(
                          /* translators: %d: maximum allowed instances */
                          __('Limit of %d reached', 'schemapress'),
                          section.max
                        )}
                      </span>
                    ) : null}
                  </span>
                  {used > 0 && !atLimit ? (
                    <span className="shrink-0 text-[11px] text-muted-foreground">×{used}</span>
                  ) : null}
                </button>
              )
            })}
          </section>
        ) : null}

        {onCreate ? (
          <section className={cn('flex flex-col gap-1.5', sections.length > 0 && 'border-t border-border pt-3')}>
            <p className="px-1 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
              {sections.length > 0
                ? __('Add a new component', 'schemapress')
                : __('Start with a component', 'schemapress')}
            </p>

            <div className="grid grid-cols-2 gap-1.5">
              {presets.map((preset) => {
                const Icon = ICONS[preset.icon] || Layers

                return (
                  <button
                    key={preset.id}
                    type="button"
                    title={preset.description}
                    onClick={() => place(() => onCreate(preset))}
                    className="flex items-start gap-2 rounded-md border border-border p-2.5 text-left transition-colors hover:border-ring/40 hover:bg-accent/50"
                  >
                    <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                    <span className="min-w-0">
                      <span className="block truncate text-[13px] font-medium">{preset.label}</span>
                      <span className="mt-0.5 line-clamp-2 text-[11px] leading-snug text-muted-foreground">
                        {preset.description}
                      </span>
                    </span>
                  </button>
                )
              })}
            </div>

            <button
              type="button"
              onClick={() => place(() => onCreate(null))}
              className="flex items-center gap-2.5 rounded-md p-2 text-left transition-colors hover:bg-accent"
            >
              <Square className="size-4 shrink-0 text-muted-foreground" />
              <span>
                <span className="block text-[13px] font-medium">
                  {__('Blank component', 'schemapress')}
                </span>
                <span className="block text-[11px] text-muted-foreground">
                  {__('Start with no fields and define your own.', 'schemapress')}
                </span>
              </span>
            </button>
          </section>
        ) : null}

        {sections.length === 0 && !onCreate ? (
          <p className="p-3 text-[12px] text-muted-foreground">
            {__('This schema defines no components yet.', 'schemapress')}
          </p>
        ) : null}
      </div>
    </Popover>
  )
}
