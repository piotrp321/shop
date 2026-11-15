import { type ComponentPublicInstance, type Ref, watch } from "vue";

/**
 * The focus of a pager with two buttons (docs/features/admin-design/spec.md, „Standard listy”). The focus stays on the
 * pressed button, and when the page that came disables it (the last or the first one) it moves to the other button,
 * never to the body. A button is never disabled while the page is requested, so it keeps the focus meanwhile. The focus
 * moves only when it is free (on the body) or still in the pager, and only once for a press: a staff member who has
 * gone on to another field is never pulled back by a later response.
 *
 * `press(which)` goes into the click handler of a button (before the action), `onFocusOut` into `focusout` of the element
 * that holds both buttons. The buttons are template refs (a component or an element).
 */
type ButtonRef = Ref<ComponentPublicInstance | HTMLElement | null>;

export function usePagerFocus(options: { hasPrevious: Ref<boolean>; hasNext: Ref<boolean>; previous: ButtonRef; next: ButtonRef }) {
  // The button the staff member pressed last, while the focus is in the pager.
  let pressed: "previous" | "next" | null = null;

  const elementOf = (button: ButtonRef) => {
    const target = button.value;
    return target instanceof HTMLElement ? target : (target?.$el as HTMLElement | undefined);
  };

  // The focus is free (nothing has it, as after the pressed button was disabled) or the staff member is in the pager.
  // The buttons share a parent (the element that holds both).
  const focusIsFree = () => {
    const active = document.activeElement;
    if (active === null || active === document.body) {
      return true;
    }
    return [options.previous, options.next].some((button) => elementOf(button)?.parentElement?.contains(active) === true);
  };

  function moveFocusTo(button: ButtonRef): void {
    pressed = null;
    if (focusIsFree()) {
      elementOf(button)?.focus();
    }
  }

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

  // Runs after the DOM is patched, when the pressed button is disabled already. The press is used up by the move, so a
  // later change of the buttons (a response to something else, e.g. a search) does not take the focus again.
  watch(
    () => [options.hasPrevious.value, options.hasNext.value],
    () => {
      if (pressed === "next" && !options.hasNext.value && options.hasPrevious.value) {
        moveFocusTo(options.previous);
      } else if (pressed === "previous" && !options.hasPrevious.value && options.hasNext.value) {
        moveFocusTo(options.next);
      }
    },
    { flush: "post" },
  );

  return { press, onFocusOut };
}
