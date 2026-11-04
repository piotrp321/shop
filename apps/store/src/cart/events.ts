const CART_CHANGED = "store:cart-changed";

/** After every change of the cart, with the number of units from the answer of the API; the header counter listens. */
export function announceCartChange(itemCount: number) {
  window.dispatchEvent(new CustomEvent<number>(CART_CHANGED, { detail: itemCount }));
}

/** Returns the function that unsubscribes. */
export function subscribeToCartChanges(listener: (itemCount: number) => void): () => void {
  const handle = (event: Event) => listener((event as CustomEvent<number>).detail);
  window.addEventListener(CART_CHANGED, handle);
  return () => window.removeEventListener(CART_CHANGED, handle);
}
