/**
 * A large, scrollable dialog for configuration.
 *
 * Section and field configuration is dense but rarely open, so it belongs
 * behind a dialog rather than inline: the page stays a readable list of the
 * components that make up a page, instead of growing to the combined height of
 * every panel it contains.
 */

import * as DialogPrimitive from "@radix-ui/react-dialog";
import { X } from "lucide-react";
import { __ } from "@wordpress/i18n";
import { Button } from "./button";
import { cn, portalContainer, LAYERS } from "./utils";

const SIZES = {
  md: "w-[min(40rem,calc(100vw-2rem))]",
  lg: "w-[min(56rem,calc(100vw-2rem))]",
  xl: "w-[min(72rem,calc(100vw-2rem))]",
};

/**
 * Modal dialog with a sticky header and footer around a scrolling body.
 *
 * @param {Object} props
 * @return {JSX.Element} The dialog.
 */
export function Dialog({
  open,
  onOpenChange,
  title,
  description,
  badge,
  size = "lg",
  footer,
  children,
}) {
  return (
    <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
      <DialogPrimitive.Portal container={portalContainer()}>
        <DialogPrimitive.Overlay
          className={cn("fixed inset-0 bg-black/40", LAYERS.dialogOverlay)}
        />
        {/* centred by flex, not by a transform. a transform would be the
            obvious way, but the open animation animates `transform` too and
            the two cannot both hold it — the panel would spend the animation
            with its top-left corner at the middle of the screen, drifting up
            and left into place from the bottom right. this also lets a tall
            dialog stay reachable instead of hanging off the top */}
        <div
          className={cn(
            "pointer-events-none fixed inset-0 flex items-center justify-center p-4",
            LAYERS.dialogContent,
          )}
        >
          <DialogPrimitive.Content
            className={cn(
              "pointer-events-auto flex max-h-[calc(100vh-4rem)] flex-col overflow-hidden rounded-lg border border-border bg-background shadow-2xl animate-sp-in focus:outline-none",
              SIZES[size],
            )}
            // configuration dialogs are dense; closing on an accidental
            // outside click would discard a half-finished edit
            onPointerDownOutside={(event) => event.preventDefault()}
          >
            <header className="flex items-start gap-3 border-b border-border px-5 py-3.5">
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                  <DialogPrimitive.Title className="truncate text-[15px] font-semibold">
                    {title}
                  </DialogPrimitive.Title>
                  {badge}
                </div>
                {description ? (
                  <DialogPrimitive.Description className="mt-0.5 text-[12px] text-muted-foreground">
                    {description}
                  </DialogPrimitive.Description>
                ) : null}
              </div>

              <DialogPrimitive.Close asChild>
                <Button
                  size="icon-sm"
                  variant="ghost"
                  aria-label={__("Close", "schemapress")}
                >
                  <X />
                </Button>
              </DialogPrimitive.Close>
            </header>

            <div className="flex-1 overflow-y-auto px-5 py-4">{children}</div>

            {footer ? (
              <footer className="flex items-center justify-end gap-2 border-t border-border bg-muted/30 px-5 py-3">
                {footer}
              </footer>
            ) : null}
          </DialogPrimitive.Content>
        </div>
      </DialogPrimitive.Portal>
    </DialogPrimitive.Root>
  );
}
