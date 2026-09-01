/**
 * A segmented control.
 *
 * Layout options are short, closed sets, and every choice matters — so they
 * are shown as one row of buttons rather than hidden behind a dropdown. The
 * whole vocabulary is visible, and choosing takes one click instead of two.
 */

import { cn } from './utils'

/**
 * Row of mutually exclusive options.
 *
 * @param {Object} props
 * @return {JSX.Element} The control.
 */
export function Segmented({ value, onChange, options, id, className, stretch = false }) {
  return (
    <div
      id={id}
      role="radiogroup"
      // sized to its options by default. a three-word control stretched across
      // a whole column reads as a mistake, and the empty space says nothing
      className={cn(
        'inline-flex gap-0.5 rounded-md bg-muted p-0.5',
        stretch ? 'w-full' : 'w-fit',
        className
      )}
    >
      {options.map((option) => {
        const active = String(value) === String(option.value)

        return (
          <button
            key={option.value}
            type="button"
            role="radio"
            aria-checked={active}
            onClick={() => onChange(option.value)}
            className={cn(
              'rounded-sm px-3 py-1.5 text-[12px] font-medium transition-colors',
              stretch && 'flex-1',
              active
                ? 'bg-background text-foreground shadow-sm'
                : 'text-muted-foreground hover:text-foreground'
            )}
          >
            {option.label}
          </button>
        )
      })}
    </div>
  )
}
