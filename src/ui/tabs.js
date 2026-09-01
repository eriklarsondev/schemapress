/**
 * Tabs.
 */

import * as TabsPrimitive from '@radix-ui/react-tabs'
import { cn } from './utils'

/**
 * Tab set. Each tab is { value, label, icon?, badge? }.
 *
 * @param {Object} props
 * @return {JSX.Element} The tabs.
 */
export function Tabs({ tabs, value, onValueChange, className, children }) {
  return (
    <TabsPrimitive.Root
      value={value}
      onValueChange={onValueChange}
      className={cn('flex flex-col gap-4', className)}
    >
      <TabsPrimitive.List className="flex w-full gap-1 rounded-md bg-muted p-1">
        {tabs.map((tab) => (
          <TabsPrimitive.Trigger
            key={tab.value}
            value={tab.value}
            className="flex flex-1 items-center justify-center gap-1.5 rounded-sm px-3 py-1.5 text-[13px] font-medium text-muted-foreground transition-colors data-[state=active]:bg-background data-[state=active]:text-foreground data-[state=active]:shadow-sm"
          >
            {tab.icon ? <tab.icon className="size-3.5" /> : null}
            {tab.label}
            {tab.badge}
          </TabsPrimitive.Trigger>
        ))}
      </TabsPrimitive.List>

      {children}
    </TabsPrimitive.Root>
  )
}

/**
 * The panel for one tab value.
 *
 * @param {Object} props
 * @return {JSX.Element} The panel.
 */
export function TabPanel({ value, className, children }) {
  return (
    <TabsPrimitive.Content value={value} className={cn('focus:outline-none', className)}>
      {children}
    </TabsPrimitive.Content>
  )
}
