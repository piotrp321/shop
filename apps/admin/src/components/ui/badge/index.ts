import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Badge } from "./Badge.vue"

/** Labels; the `success` … `neutral` variants are the pastel status labels of the spec (4 px radius). */
export const badgeVariants = cva(
  "inline-flex items-center justify-center rounded-sm border px-2 py-0.5 text-xs font-semibold w-fit whitespace-nowrap shrink-0 [&>svg]:size-3 gap-1 [&>svg]:pointer-events-none aria-invalid:border-destructive transition-colors overflow-hidden",
  {
    variants: {
      variant: {
        default:
          "border-transparent bg-primary text-primary-foreground [a&]:hover:bg-primary-hover",
        secondary:
          "border-transparent bg-secondary text-secondary-foreground [a&]:hover:bg-separator",
        destructive:
          "border-transparent bg-destructive text-destructive-foreground [a&]:hover:bg-destructive/90",
        outline:
          "border-input-border text-foreground [a&]:hover:bg-accent [a&]:hover:text-accent-foreground",
        success: "border-transparent bg-status-success-soft text-status-success",
        warning: "border-transparent bg-status-warning-soft text-status-warning",
        danger: "border-transparent bg-status-danger-soft text-status-danger",
        info: "border-transparent bg-status-info-soft text-status-info",
        neutral: "border-transparent bg-status-neutral-soft text-status-neutral",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  },
)
export type BadgeVariants = VariantProps<typeof badgeVariants>
