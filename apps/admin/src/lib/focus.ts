/**
 * Where the focus goes after an action whose trigger may be gone (a dialog closed, a button that the action replaced):
 * back to the trigger while it is still on the page and can take the focus, otherwise to the heading of the card the
 * action belonged to (`tabindex="-1"`), and never to `body`.
 */
export function focusAfterAction(trigger: HTMLElement | null, fallback: HTMLElement | null): void {
  if (trigger !== null && trigger.isConnected && !isDisabled(trigger)) {
    trigger.focus();
    return;
  }
  fallback?.focus();
}

function isDisabled(element: HTMLElement): boolean {
  return element.hasAttribute("disabled") || element.getAttribute("aria-disabled") === "true";
}
