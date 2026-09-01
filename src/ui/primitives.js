/**
 * Layout and text primitives: card, badge, alert, spinner, empty state.
 *
 * Presentational only — no state, no behaviour. Anything interactive lives in
 * its own module alongside the Radix primitive it wraps.
 */

import { forwardRef } from '@wordpress/element'
import { cva } from 'class-variance-authority'
import { Loader2 } from 'lucide-react'
import { cn } from './utils'

/**
 * A surface panel.
 *
 * @param {Object} props
 * @param {Object} ref
 * @return {JSX.Element} The card.
 */
export const Card = forwardRef(({ className, ...props }, ref) => (
  <div
    ref={ref}
    className={cn(
      'rounded-lg border border-border bg-card text-card-foreground shadow-sm',
      className
    )}
    {...props}
  />
))

Card.displayName = 'Card'

/**
 * Card padding wrapper.
 *
 * @param {Object} props
 * @return {JSX.Element} The card body.
 */
export function CardBody({ className, ...props }) {
  return <div className={cn('p-4', className)} {...props} />
}

/**
 * Section heading used above lists and inside panels.
 *
 * @param {Object} props
 * @return {JSX.Element} The heading.
 */
export function Heading({ className, children, ...props }) {
  return (
    <h3
      className={cn(
        'text-[11px] font-semibold uppercase tracking-wider text-muted-foreground',
        className
      )}
      {...props}
    >
      {children}
    </h3>
  )
}

const badgeVariants = cva(
  'inline-flex items-center rounded-md px-1.5 py-0.5 text-[11px] font-medium leading-none',
  {
    variants: {
      variant: {
        default: 'bg-secondary text-secondary-foreground',
        outline: 'border border-border text-muted-foreground',
        mono: 'bg-muted font-mono text-muted-foreground',
        success: 'bg-emerald-100 text-emerald-800',
        warning: 'bg-amber-100 text-amber-900'
      }
    },
    defaultVariants: { variant: 'default' }
  }
)

/**
 * A small status or metadata chip.
 *
 * @param {Object} props
 * @return {JSX.Element} The badge.
 */
export function Badge({ className, variant, ...props }) {
  return <span className={cn(badgeVariants({ variant }), className)} {...props} />
}

const alertVariants = cva('rounded-md border px-3 py-2.5 text-[13px]', {
  variants: {
    variant: {
      info: 'border-sky-200 bg-sky-50 text-sky-900',
      warning: 'border-amber-200 bg-amber-50 text-amber-900',
      error: 'border-red-200 bg-red-50 text-red-900',
      success: 'border-emerald-200 bg-emerald-50 text-emerald-900'
    }
  },
  defaultVariants: { variant: 'info' }
})

/**
 * An inline message.
 *
 * @param {Object} props
 * @return {JSX.Element} The alert.
 */
export function Alert({ className, variant, children, action, ...props }) {
  return (
    <div className={cn(alertVariants({ variant }), className)} {...props}>
      <div className="flex items-center justify-between gap-3">
        <div className="min-w-0">{children}</div>
        {action ? <div className="shrink-0">{action}</div> : null}
      </div>
    </div>
  )
}

/**
 * Indeterminate loading indicator.
 *
 * @param {Object} props
 * @return {JSX.Element} The spinner.
 */
export function Spinner({ className }) {
  return <Loader2 className={cn('size-4 animate-spin', className)} aria-hidden="true" />
}

/**
 * Centred loading state for a whole view.
 *
 * @param {Object} props
 * @return {JSX.Element} The loading state.
 */
export function Loading({ label }) {
  return (
    <div className="flex items-center justify-center gap-2 py-16 text-sm text-muted-foreground">
      <Spinner />
      {label}
    </div>
  )
}

/**
 * Placeholder shown where a list has no entries.
 *
 * @param {Object} props
 * @return {JSX.Element} The empty state.
 */
export function Empty({ icon: Icon, title, description, action, className }) {
  return (
    <div
      className={cn(
        'flex flex-col items-center gap-2 rounded-lg border border-dashed border-border px-6 py-10 text-center',
        className
      )}
    >
      {Icon ? <Icon className="size-6 text-muted-foreground/60" aria-hidden="true" /> : null}
      <p className="text-sm font-medium">{title}</p>
      {description ? (
        <p className="max-w-sm text-[13px] text-muted-foreground">{description}</p>
      ) : null}
      {action ? <div className="mt-1">{action}</div> : null}
    </div>
  )
}
