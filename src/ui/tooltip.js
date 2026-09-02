/**
 * Tooltip.
 *
 * For naming a control that shows only an icon. A tooltip is a label you have
 * to go looking for, so it is never the only place something important is
 * said — but for a row of icon buttons it is the difference between three
 * shapes and three verbs.
 *
 * Radix rather than the native `title` attribute because that one appears after
 * a second of stillness, never on keyboard focus, and cannot be styled.
 */

import * as TooltipPrimitive from '@radix-ui/react-tooltip'
import { portalContainer } from './utils'

/**
 * Wraps the app so tooltips share one open-delay timer: once any tooltip has
 * been shown, moving along a row of them shows the rest immediately, which is
 * what makes scanning a toolbar possible.
 *
 * @param {Object} props
 * @return {JSX.Element} The provider.
 */
export function TooltipProvider({ children, ...props }) {
  return (
    <TooltipPrimitive.Provider delayDuration={300} skipDelayDuration={200} {...props}>
      {children}
    </TooltipPrimitive.Provider>
  )
}

/**
 * A label attached to whatever it wraps.
 *
 * The child must accept a ref and spread props — every control in this kit
 * does. A disabled button stops emitting pointer events, so one of those is
 * wrapped in a span that can still be hovered; a tooltip explaining why a
 * button is unavailable is precisely when you most need it.
 *
 * @param {Object}      props
 * @param {string}      props.label    The tooltip text.
 * @param {string}      props.side     top, right, bottom or left.
 * @param {boolean}     props.disabled Whether the wrapped control is disabled.
 * @param {JSX.Element} props.children The control being labelled.
 * @return {JSX.Element} The tooltip.
 */
export function Tooltip({ label, side = 'top', disabled = false, children }) {
  if (!label) {
    return children
  }

  return (
    <TooltipPrimitive.Root>
      <TooltipPrimitive.Trigger asChild>
        {disabled ? <span className="inline-flex">{children}</span> : children}
      </TooltipPrimitive.Trigger>

      <TooltipPrimitive.Portal container={portalContainer()}>
        <TooltipPrimitive.Content
          side={side}
          sideOffset={6}
          collisionPadding={8}
          className="z-[100000] max-w-56 rounded-md bg-foreground px-2 py-1 text-[11px] font-medium leading-snug text-background shadow-md"
        >
          {label}
          <TooltipPrimitive.Arrow className="fill-foreground" width={9} height={4} />
        </TooltipPrimitive.Content>
      </TooltipPrimitive.Portal>
    </TooltipPrimitive.Root>
  )
}
