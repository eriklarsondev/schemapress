/**
 * Select, built on Radix so the listbox is keyboard- and screen-reader-correct
 * and never clipped by an overflow container.
 */

import * as SelectPrimitive from '@radix-ui/react-select'
import { Check, ChevronDown } from 'lucide-react'
import { cn, portalContainer, LAYERS } from './utils'

/**
 * A single-choice select.
 *
 * @param {Object} props
 * @return {JSX.Element} The select.
 */
export function Select({
  value,
  onChange,
  options = [],
  placeholder,
  id,
  disabled,
  className
}) {
  return (
    <SelectPrimitive.Root
      // Radix reserves the empty string for "no value", so an empty option is
      // represented by a sentinel and mapped back on the way out
      value={value === '' || value === null || value === undefined ? '__none__' : String(value)}
      onValueChange={(next) => onChange(next === '__none__' ? '' : next)}
      disabled={disabled}
    >
      <SelectPrimitive.Trigger
        id={id}
        className={cn(
          'flex h-9 w-full items-center justify-between gap-2 rounded-md border border-input bg-background px-3 py-2 text-[13px] transition-shadow focus:outline-none focus:ring-2 focus:ring-ring/20 disabled:cursor-not-allowed disabled:opacity-50 [&>span]:truncate',
          className
        )}
      >
        <SelectPrimitive.Value placeholder={placeholder} />
        <SelectPrimitive.Icon asChild>
          <ChevronDown className="size-3.5 shrink-0 opacity-50" />
        </SelectPrimitive.Icon>
      </SelectPrimitive.Trigger>

      <SelectPrimitive.Portal container={portalContainer()}>
        <SelectPrimitive.Content
          position="popper"
          sideOffset={4}
          className={cn(
            LAYERS.transient,
            'max-h-72 min-w-[var(--radix-select-trigger-width)] overflow-hidden rounded-md border border-border bg-popover text-popover-foreground shadow-lg animate-sp-in'
          )}
        >
          <SelectPrimitive.Viewport className="p-1">
            {options.map((option) => (
              <SelectPrimitive.Item
                key={option.value === '' ? '__none__' : option.value}
                value={option.value === '' ? '__none__' : String(option.value)}
                className="relative flex cursor-pointer select-none items-center rounded-sm py-1.5 pl-7 pr-2 text-[13px] outline-none data-[highlighted]:bg-accent data-[highlighted]:text-accent-foreground"
              >
                <span className="absolute left-2 flex size-3.5 items-center justify-center">
                  <SelectPrimitive.ItemIndicator>
                    <Check className="size-3.5" />
                  </SelectPrimitive.ItemIndicator>
                </span>
                <SelectPrimitive.ItemText>{option.label}</SelectPrimitive.ItemText>
              </SelectPrimitive.Item>
            ))}
          </SelectPrimitive.Viewport>
        </SelectPrimitive.Content>
      </SelectPrimitive.Portal>
    </SelectPrimitive.Root>
  )
}
