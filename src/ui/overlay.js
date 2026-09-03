/**
 * Overlay primitives: popover, collapsible and a confirm dialog.
 *
 * All of them portal through the scoped container so Tailwind utilities still
 * reach them once Radix moves them out of the React tree.
 */

import * as PopoverPrimitive from '@radix-ui/react-popover'
import * as CollapsiblePrimitive from '@radix-ui/react-collapsible'
import * as DialogPrimitive from '@radix-ui/react-dialog'
import { __ } from '@wordpress/i18n'
import { Button } from './button'
import { cn, portalContainer, LAYERS } from './utils'

/**
 * A popover anchored to its trigger.
 *
 * @param {Object} props
 * @return {JSX.Element} The popover.
 */
export function Popover({ open, onOpenChange, trigger, children, align = 'start', className }) {
  return (
    <PopoverPrimitive.Root open={open} onOpenChange={onOpenChange}>
      <PopoverPrimitive.Trigger asChild>{trigger}</PopoverPrimitive.Trigger>
      <PopoverPrimitive.Portal container={portalContainer()}>
        <PopoverPrimitive.Content
          align={align}
          sideOffset={6}
          className={cn(
            LAYERS.transient,
            'rounded-lg border border-border bg-popover p-1 text-popover-foreground shadow-lg animate-sp-in',
            className
          )}
        >
          {children}
        </PopoverPrimitive.Content>
      </PopoverPrimitive.Portal>
    </PopoverPrimitive.Root>
  )
}

/**
 * A disclosure with animated height.
 *
 * @param {Object} props
 * @return {JSX.Element} The collapsible.
 */
export function Collapsible({ open, onOpenChange, trigger, children }) {
  return (
    <CollapsiblePrimitive.Root open={open} onOpenChange={onOpenChange}>
      {trigger}
      <CollapsiblePrimitive.Content className="overflow-hidden data-[state=closed]:animate-sp-collapse-up data-[state=open]:animate-sp-collapse-down">
        {children}
      </CollapsiblePrimitive.Content>
    </CollapsiblePrimitive.Root>
  )
}

/**
 * A destructive-action confirmation.
 *
 * Replaces window.confirm, which cannot be styled and reads as a browser
 * warning rather than part of the application.
 *
 * @param {Object} props
 * @return {JSX.Element} The dialog.
 */
export function ConfirmDialog({
  open,
  onOpenChange,
  title,
  description,
  confirmLabel,
  onConfirm,
  destructive = true
}) {
  return (
    <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
      <DialogPrimitive.Portal container={portalContainer()}>
        <DialogPrimitive.Overlay
          className={cn('fixed inset-0 bg-black/40', LAYERS.confirmOverlay)}
        />
        {/* centred by flex rather than by a transform, which the open
            animation would otherwise overwrite — see Dialog */}
        <div
          className={cn(
            'pointer-events-none fixed inset-0 flex items-center justify-center p-4',
            LAYERS.confirmContent
          )}
        >
          <DialogPrimitive.Content
            className="pointer-events-auto w-[min(28rem,calc(100vw-2rem))] rounded-lg border border-border bg-background p-5 shadow-xl animate-sp-in focus:outline-none"
          >
            <DialogPrimitive.Title className="text-base font-semibold">
              {title}
            </DialogPrimitive.Title>
            {description ? (
              <DialogPrimitive.Description className="mt-1.5 text-[13px] text-muted-foreground">
                {description}
              </DialogPrimitive.Description>
            ) : null}

            <div className="mt-5 flex justify-end gap-2">
              <DialogPrimitive.Close asChild>
                <Button variant="outline" size="sm">
                  {__('Cancel', 'schemapress')}
                </Button>
              </DialogPrimitive.Close>
              <Button
                size="sm"
                variant={destructive ? 'destructive' : 'default'}
                onClick={() => {
                  onConfirm()
                  onOpenChange(false)
                }}
              >
                {confirmLabel}
              </Button>
            </div>
          </DialogPrimitive.Content>
        </div>
      </DialogPrimitive.Portal>
    </DialogPrimitive.Root>
  )
}
