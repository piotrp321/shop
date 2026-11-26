/**
 * The classes of a text field. `Input` wears them, and a field that must write its own value back while it is typed (the SKU
 * of a variant: capitals) is a plain `<input>` that wears the same ones. A read-only field (a form the staff member may not
 * change) keeps its full contrast and gets the muted background; it is not `disabled`, so it can be focused and selected.
 */
export const INPUT_CLASS =
  "file:text-foreground placeholder:text-muted-foreground selection:bg-interface selection:text-interface-foreground border-input h-10 w-full min-w-0 rounded-md border bg-card px-3 py-1 text-base text-foreground transition-colors file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 [&[readonly]]:bg-muted md:text-sm";
