import * as React from "react"
import { cn } from "@/lib/utils"

// 16 px on a phone (a smaller size makes iOS zoom the page when the field gets the focus), 15 px from `md`.
function Input({ className, type, ...props }: React.ComponentProps<"input">) {
  return (
    <input
      type={type}
      data-slot="input"
      className={cn(
        "h-11 w-full min-w-0 rounded-md border border-input bg-card px-3.5 text-base text-foreground transition-colors placeholder:text-muted-foreground hover:border-foreground disabled:cursor-not-allowed disabled:border-border disabled:bg-muted disabled:text-muted-foreground disabled:hover:border-border aria-invalid:border-2 aria-invalid:border-destructive md:text-ui",
        className
      )}
      {...props}
    />
  )
}

export { Input }
