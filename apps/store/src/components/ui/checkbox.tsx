"use client"

import * as React from "react"
import { cn } from "@/lib/utils"
import { Checkbox as CheckboxPrimitive } from "radix-ui"
import { CheckIcon } from "lucide-react"

// 22 px box like the mockup; the `after` area widens the touch target without changing the layout.
function Checkbox({
  className,
  ...props
}: React.ComponentProps<typeof CheckboxPrimitive.Root>) {
  return (
    <CheckboxPrimitive.Root
      data-slot="checkbox"
      className={cn(
        "peer relative flex size-5.5 shrink-0 items-center justify-center rounded-sm border-[1.5px] border-input bg-card transition-colors after:absolute after:-inset-x-3 after:-inset-y-2 hover:border-foreground disabled:cursor-not-allowed disabled:border-border disabled:bg-muted aria-invalid:border-destructive data-[state=checked]:border-inverse data-[state=checked]:bg-inverse data-[state=checked]:text-inverse-foreground",
        className
      )}
      {...props}
    >
      <CheckboxPrimitive.Indicator
        data-slot="checkbox-indicator"
        className="grid place-content-center text-current transition-none [&>svg]:size-4"
      >
        <CheckIcon />
      </CheckboxPrimitive.Indicator>
    </CheckboxPrimitive.Root>
  )
}

export { Checkbox }
