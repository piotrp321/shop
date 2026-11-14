import { type ComponentPublicInstance, type Ref, watch } from "vue";

/**
 * The focus of a pager with two buttons (docs/features/admin-design/spec.md, „Standard listy”). The focus stays on the
 * pressed button, and when the page that came disables it (the last or the first one) it moves to the other button,
 * never to the body. A button is never disabled while the page is requested, so it keeps the focus meanwhile.
 *
 * `press(which)` goes into the click handler of a button (before the action), `onFocusOut` into `focusout` of the element
 * that holds both buttons. The buttons are template refs (a component or an element).
 */
type ButtonRef = Ref<ComponentPublicInstance | HTMLElement | null>;

export function usePagerFocus(options: { hasPrevious: Ref<boolean>; hasNext: Ref<boolean>; previous: ButtonRef; next: ButtonRef }) {
  // The button the staff member pressed last, while the focus is in the pager.
  let pressed: "previous" | "next" | null = null;

  const focusOf = (button: ButtonRef) => {
    const target = button.value;
    (target instanceof HTMLElement ? target : (target?.$el as HTMLElement | undefined))?.focus();
  };

  function press(which: "previous" | "next"): void {
    pressed = which;
  }

  // The focus moved to another element of the page: the staff member is no longer in the pager. A focus that is lost
  // without a target is not that: it is what a browser may do as the pressed button is disabled.
  function onFocusOut(event: FocusEvent): void {
    const holder = event.currentTarget as HTMLElement;
    if (event.relatedTarget instanceof Node && !holder.contains(event.relatedTarget)) {
      pressed = null;
    }
  }

  // Runs after the DOM is patched, when the pressed button is disabled already.
  watch(
    () => [options.hasPrevious.value, options.hasNext.value],
    () => {
      if (pressed === "next" && !options.hasNext.value && options.hasPrevious.value) {
        focusOf(options.previous);
      } else if (pressed === "previous" && !options.hasPrevious.value && options.hasNext.value) {
        focusOf(options.next);
      }
    },
    { flush: "post" },
  );

  return { press, onFocusOut };
}
