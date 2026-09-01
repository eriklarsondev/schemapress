/**
 * Form control primitives.
 *
 * Every control is wrapped by Field, which owns the label/help/error layout and
 * the generated id that ties them together — so no caller has to remember the
 * accessibility wiring.
 */

import { forwardRef, useId } from '@wordpress/element'
import { cn } from './utils'

/**
 * Label, control and help text as one block.
 *
 * @param {Object} props
 * @return {JSX.Element} The field.
 */
export function Field({ label, help, error, required, className, children, htmlFor }) {
  const generated = useId()
  const id = htmlFor || generated

  return (
    <div className={cn('flex flex-col gap-1.5', className)}>
      {label ? (
        <label
          htmlFor={id}
          className="text-[13px] font-medium leading-none text-foreground"
        >
          {label}
          {required ? <span className="ml-0.5 text-destructive">*</span> : null}
        </label>
      ) : null}

      {typeof children === 'function' ? children(id) : children}

      {error ? (
        <p className="text-[12px] text-destructive">{error}</p>
      ) : help ? (
        <p className="text-[12px] text-muted-foreground">{help}</p>
      ) : null}
    </div>
  )
}

/**
 * Text input.
 *
 * @param {Object} props
 * @param {Object} ref
 * @return {JSX.Element} The input.
 */
export const Input = forwardRef(({ className, ...props }, ref) => (
  <input
    ref={ref}
    className={cn(
      'block w-full rounded-md border border-input bg-background px-3 py-2 text-[13px] transition-shadow placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50',
      className
    )}
    {...props}
  />
))

Input.displayName = 'Input'

/**
 * Multi-line text input.
 *
 * @param {Object} props
 * @param {Object} ref
 * @return {JSX.Element} The textarea.
 */
export const Textarea = forwardRef(({ className, ...props }, ref) => (
  <textarea
    ref={ref}
    className={cn(
      'block w-full resize-y rounded-md border border-input bg-background px-3 py-2 text-[13px] transition-shadow placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50',
      className
    )}
    {...props}
  />
))

Textarea.displayName = 'Textarea'
