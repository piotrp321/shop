import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { cn } from "@/lib/utils"
import { Slot } from "radix-ui"

// The focus ring comes from the global `:focus-visible` rule in tokens.css (3 px, deep accent); nothing here removes it.
const buttonVariants = cva(
  "group/button inline-flex shrink-0 items-center justify-center rounded-md border border-transparent text-ui leading-tight font-semibold whitespace-nowrap transition-colors select-none disabled:cursor-not-allowed aria-invalid:border-destructive [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-5",
  {
    variants: {
      variant: {
        default:
          "border-primary bg-primary text-primary-foreground hover:bg-primary/85 disabled:border-border disabled:bg-muted disabled:text-muted-foreground",
        outline:
          "border-foreground bg-transparent text-foreground hover:bg-muted aria-expanded:bg-muted disabled:border-border disabled:bg-transparent disabled:text-muted-foreground",
        secondary:
          "bg-secondary text-secondary-foreground hover:bg-secondary/70 aria-expanded:bg-secondary disabled:text-muted-foreground",
        ghost:
          "text-foreground hover:bg-muted aria-expanded:bg-muted disabled:text-muted-foreground",
        destructive:
          "border-destructive bg-transparent text-destructive hover:bg-muted disabled:border-border disabled:text-muted-foreground",
        link: "text-link underline-offset-4 hover:underline disabled:text-muted-foreground",
      },
      size: {
        // 44 px: the touch target of the main controls.
        default:
          "h-11 gap-2 px-5 has-data-[icon=inline-end]:pr-3.5 has-data-[icon=inline-start]:pl-3.5",
        xs: "h-7 min-w-7 gap-1 px-2 text-xs [&_svg:not([class*='size-'])]:size-3.5",
        sm: "h-9 min-w-9 gap-1.5 px-3 [&_svg:not([class*='size-'])]:size-4",
        lg: "h-14 gap-2 px-6 text-base",
        icon: "size-11",
        "icon-xs": "size-7 [&_svg:not([class*='size-'])]:size-3.5",
        "icon-sm": "size-9 [&_svg:not([class*='size-'])]:size-4",
        "icon-lg": "size-14",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

function Button({
  className,
  variant = "default",
  size = "default",
  asChild = false,
  ...props
}: React.ComponentProps<"button"> &
  VariantProps<typeof buttonVariants> & {
    asChild?: boolean
  }) {
  const Comp = asChild ? Slot.Root : "button"

  return (
    <Comp
      data-slot="button"
      data-variant={variant}
      data-size={size}
      className={cn(buttonVariants({ variant, size, className }))}
      {...props}
    />
  )
}

export { Button, buttonVariants }
