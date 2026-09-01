/**
 * The workflow's progress indicator.
 *
 * Completed steps stay clickable so the setup can be revisited; steps ahead of
 * the furthest reached point are disabled, because choosing a schema before a
 * template exists has nothing to bind to.
 */

import { Check } from 'lucide-react'
import { cn } from '../../ui'

/**
 * Horizontal step indicator.
 *
 * @param {Object} props
 * @return {JSX.Element} The stepper.
 */
export function Stepper({ steps, current, reached, onSelect }) {
  return (
    <ol className="flex items-center gap-1">
      {steps.map((step, index) => {
        const active = step.key === current
        const complete = step.complete
        const reachable = index <= reached

        return (
          <li key={step.key} className="flex flex-1 items-center gap-1">
            <button
              type="button"
              disabled={!reachable}
              onClick={() => reachable && onSelect(step.key)}
              className={cn(
                'flex flex-1 items-center gap-2.5 rounded-md px-3 py-2 text-left transition-colors',
                active && 'bg-background shadow-sm ring-1 ring-border',
                !active && reachable && 'hover:bg-background/60',
                !reachable && 'cursor-not-allowed opacity-50'
              )}
            >
              <span
                className={cn(
                  'flex size-6 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold',
                  complete
                    ? 'bg-emerald-600 text-white'
                    : active
                      ? 'bg-primary text-primary-foreground'
                      : 'bg-muted text-muted-foreground'
                )}
              >
                {complete ? <Check className="size-3.5" strokeWidth={3} /> : index + 1}
              </span>

              <span className="min-w-0">
                <span
                  className={cn(
                    'block truncate text-[13px] font-medium',
                    !active && !complete && 'text-muted-foreground'
                  )}
                >
                  {step.label}
                </span>
                <span className="block truncate text-[11px] text-muted-foreground">
                  {step.summary}
                </span>
              </span>
            </button>

            {index < steps.length - 1 ? (
              <span className="h-px w-4 shrink-0 bg-border" aria-hidden="true" />
            ) : null}
          </li>
        )
      })}
    </ol>
  )
}
