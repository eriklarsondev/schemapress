/**
 * Switch and checkbox.
 */

import * as SwitchPrimitive from '@radix-ui/react-switch'
import * as CheckboxPrimitive from '@radix-ui/react-checkbox'
import { Check } from 'lucide-react'
import { cn } from './utils'

/**
 * A boolean switch with an inline label.
 *
 * @param {Object} props
 * @return {JSX.Element} The switch.
 */
export function Switch({ checked, onChange, label, help, disabled, className, ...props }) {
  return (
    <label className={cn('flex cursor-pointer items-start gap-2.5', className)}>
      <SwitchPrimitive.Root
        checked={Boolean(checked)}
        onCheckedChange={onChange}
        disabled={disabled}
        className="peer mt-0.5 inline-flex h-[18px] w-8 shrink-0 items-center rounded-full border-2 border-transparent bg-muted transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30 disabled:cursor-not-allowed disabled:opacity-50 data-[state=checked]:bg-primary"
        {...props}
      >
        <SwitchPrimitive.Thumb className="pointer-events-none block size-[14px] rounded-full bg-white shadow transition-transform data-[state=checked]:translate-x-3.5 data-[state=unchecked]:translate-x-0" />
      </SwitchPrimitive.Root>

      {/* a caller that lays out its own label passes none, and an empty span
          would still take the gap beside the control */}
      {label || help ? (
        <span className="min-w-0">
          {label ? (
            <span className="block text-[13px] font-medium leading-tight">{label}</span>
          ) : null}
          {help ? (
            <span className="mt-0.5 block text-[12px] text-muted-foreground">{help}</span>
          ) : null}
        </span>
      ) : null}
    </label>
  )
}

/**
 * A checkbox with an inline label.
 *
 * @param {Object} props
 * @return {JSX.Element} The checkbox.
 */
export function Checkbox({ checked, onChange, label, help, disabled, className, ...props }) {
  return (
    <label className={cn('flex cursor-pointer items-start gap-2.5', className)}>
      <CheckboxPrimitive.Root
        checked={Boolean(checked)}
        onCheckedChange={onChange}
        disabled={disabled}
        className="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded border border-input bg-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30 disabled:cursor-not-allowed disabled:opacity-50 data-[state=checked]:border-primary data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground"
        {...props}
      >
        <CheckboxPrimitive.Indicator>
          <Check className="size-3" strokeWidth={3} />
        </CheckboxPrimitive.Indicator>
      </CheckboxPrimitive.Root>

      {label || help ? (
        <span className="min-w-0">
          {label ? (
            <span className="block text-[13px] font-medium leading-tight">{label}</span>
          ) : null}
          {help ? (
            <span className="mt-0.5 block text-[12px] text-muted-foreground">{help}</span>
          ) : null}
        </span>
      ) : null}
    </label>
  )
}
