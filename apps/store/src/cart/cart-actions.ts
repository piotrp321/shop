/** What the customer asked for one line of the cart page. */
export type CartAction = { kind: "quantity"; quantity: number } | { kind: "remove" };

export type CartOperation = { variantId: string; action: CartAction };

/**
 * The actions of the cart page (docs/features/cart/spec.md, „Akcje w trakcie zapisu”): at most one operation on its
 * way to the API (`sent`) and, per line, at most one action waiting to be sent, in the order they were given.
 */
export type CartActions = { readonly sent: CartOperation | null; readonly waiting: readonly CartOperation[] };

export const NO_ACTIONS: CartActions = { sent: null, waiting: [] };

const isRemoval = (operation: CartOperation | null | undefined, variantId: string) =>
  operation?.variantId === variantId && operation.action.kind === "remove";

/** A removal of the line is on its way or waits: both controls of the line are inactive. */
export const isRemovalGiven = (actions: CartActions, variantId: string): boolean =>
  isRemoval(actions.sent, variantId) || actions.waiting.some((operation) => isRemoval(operation, variantId));

/** A change of the quantity of the line is on its way: its field is read-only. */
export const isQuantitySent = (actions: CartActions, variantId: string): boolean =>
  actions.sent?.variantId === variantId && actions.sent.action.kind === "quantity";

export const hasWaitingQuantity = (actions: CartActions, variantId: string): boolean =>
  actions.waiting.some((operation) => operation.variantId === variantId && operation.action.kind === "quantity");

/** Nothing is on its way and nothing waits. */
export const isIdle = (actions: CartActions): boolean => actions.sent === null && actions.waiting.length === 0;

/**
 * Adds an action, to be sent after the operation on its way. A newer action of a line replaces its waiting one and
 * takes its place in the order (so a removal replaces a quantity). Nothing changes (the same object comes back) for any
 * action of a line whose removal is given, and for a quantity of a line whose quantity is on its way.
 */
export function give(actions: CartActions, operation: CartOperation): CartActions {
  const { variantId } = operation;
  if (isRemovalGiven(actions, variantId) || (operation.action.kind === "quantity" && isQuantitySent(actions, variantId))) {
    return actions;
  }
  const index = actions.waiting.findIndex((waiting) => waiting.variantId === variantId);
  if (index === -1) {
    return { ...actions, waiting: [...actions.waiting, operation] };
  }
  return { ...actions, waiting: actions.waiting.map((waiting, at) => (at === index ? operation : waiting)) };
}

/** The customer changed the entry of the field: its waiting quantity is dropped (the same object when there is none). */
export function dropWaitingQuantity(actions: CartActions, variantId: string): CartActions {
  if (!hasWaitingQuantity(actions, variantId)) return actions;
  return { ...actions, waiting: actions.waiting.filter((operation) => operation.variantId !== variantId) };
}

// The actions outlive the cart page that was given them; a cart page opened again reads the cart only after them.
let running: Promise<void> = Promise.resolve();

/** Runs the sending of the actions of a cart page; `actionsFinished` waits for it, also after the page is left. */
export function runActions(send: () => Promise<void>): void {
  running = send().catch(() => undefined);
}

/** Settles when the actions of every cart page are sent (or dropped after a 401). */
export const actionsFinished = (): Promise<void> => running;

/** After the answer to the operation on its way: the first waiting action goes, or nothing is on its way. */
export function sendNext(actions: CartActions): CartActions {
  const [next = null, ...rest] = actions.waiting;
  return { sent: next, waiting: rest };
}
