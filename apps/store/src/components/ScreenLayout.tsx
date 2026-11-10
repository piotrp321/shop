import type { ComponentProps } from "react";
import { cn } from "@/lib/utils";

/**
 * The page frame of the cart, checkout, confirmation and account screens: the margin of the page (`px-gutter`) and the
 * width of the content (`max-w-content`) like every other screen, so the title stands at the same left edge as the one of
 * a listing; the working column inside it stays narrow (`max-w-3xl`), because a form or a list does not need 1440 px.
 */
export function ScreenMain({ className, children, ...props }: ComponentProps<"main">) {
  return (
    <main className="mx-auto w-full max-w-content px-gutter pt-4 pb-16 lg:pt-7 lg:pb-28" {...props}>
      <div className={cn("flex max-w-3xl flex-col gap-6", className)}>{children}</div>
    </main>
  );
}

/** The `h1` of those screens: one step below the title of a listing, same weight and tracking. */
export function ScreenTitle({ className, ...props }: ComponentProps<"h1">) {
  return <h1 className={cn("text-3xl font-semibold tracking-tight lg:text-4xl", className)} {...props} />;
}
