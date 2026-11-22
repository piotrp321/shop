"use client";

import Link from "next/link";
import { type MouseEvent, type ReactNode, useEffect, useRef, useState } from "react";
import { type CartResult, type CartView, changeCartItemQuantity, loadCart, removeCartItem } from "@/api/client";
import { formatPrice } from "@/catalog/format-price";
import { ProductPhoto } from "@/catalog/ProductPhoto";
import { ScreenMain, ScreenTitle } from "@/components/ScreenLayout";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import {
  type CartActions,
  type CartOperation,
  NO_ACTIONS,
  actionsFinished,
  dropWaitingQuantity,
  give,
  hasWaitingQuantity,
  isIdle,
  isQuantitySent,
  isRemovalGiven,
  runActions,
  sendNext,
} from "./cart-actions";
import { announceCartChange } from "./events";
import { CART_RATE_LIMITED, SAVE_FAILED, SessionExpired } from "./messages";

type CartLine = CartView["lines"][number];

type Loaded =
  | { status: "loading" }
  | { status: "error" }
  | { status: "unauthorized" }
  | { status: "ready"; cart: CartView };

// Shown instead of a price or next to it; the API decides the state, the store only names it.
const STATE_LABELS: Record<Exclude<CartLine["state"], "purchasable">, string> = {
  no_price: "Cena niedostępna",
  out_of_stock: "Niedostępny",
  insufficient_stock: "Dostępna ilość jest mniejsza niż w koszyku",
  unavailable_product: "Produkt nie jest już dostępny",
};

const PHOTO_SIZES = "96px";
const MIN_QUANTITY = 1;
const MAX_QUANTITY = 99;

const TITLE_ID = "cart-title";
const RETRY_ID = "cart-retry";
const EMPTY_ID = "cart-empty";
const CHECKOUT_ID = "cart-checkout";
const CHECKOUT_PATH = "/checkout";
// The controls an action came from; the focus is looked up by them after the answer.
const QUANTITY_ID_PREFIX = "cart-quantity-";
const removeId = (variantId: string) => `cart-remove-${variantId}`;
const quantityId = (variantId: string) => `${QUANTITY_ID_PREFIX}${variantId}`;

// What the status region says. The region is one element for every state of the screen and only its text changes.
const LOADING = "Wczytywanie koszyka…";
const LINE_GONE = "Pozycja została już usunięta";
const REMOVED_UNNAMED = "Usunięto pozycję z koszyka.";
const SAVING = "Zapisujemy zmiany w koszyku…";
// At "Przejdź do kasy" when a change it waited for was not saved.
const NOT_SAVED = "Nie udało się zapisać zmian w koszyku. Sprawdź pozycje i spróbuj ponownie.";

/**
 * How an operation of the cart page ended: saved, refused (a message at the line), gone (a 404 of a line that is not in
 * the cart any more) or unauthorized (a 401 drops the actions still waiting).
 */
type Outcome = "saved" | "refused" | "gone" | "unauthorized";

/**
 * "Przejdź do kasy" activated while operations were on their way or waited: `waiting` for them (the controls of the
 * lines are locked), `failed` when one of them was not saved (a message at the link), `none` otherwise.
 */
type Checkout = "none" | "waiting" | "failed";

/**
 * The cart of the browser, loaded from `/api/cart`. Every view comes from the last answer of the API; nothing is
 * computed here, and a refused change leaves the last view as it was. One operation goes to the API at a time; an
 * action given meanwhile waits at its line (`cart-actions.ts`) and goes after the answer, also when the page is left.
 *
 * The one status region (`cart-status`) says what the page is doing or did: it reads the cart, a line was removed, a
 * line was gone already. Its text stays until the next operation the customer starts; an operation that waited for
 * the one before it does not clear the text, it only replaces it with its own. After a removal the focus goes to the
 * next line, and after a retry of the load to the title, but only when the customer did not move it meanwhile.
 *
 * "Przejdź do kasy" first commits the entry of the focused quantity field. With nothing on its way and nothing waiting
 * the link works as a link; otherwise the page waits for the operations, with the controls locked, and opens the
 * checkout only when all of them were saved and the cart can be ordered (docs/features/cart/spec.md, „Przejście do
 * kasy”). A failure, a 404, a 401 or leaving the page ends the waiting without the checkout.
 */
export function CartPage() {
  const [loaded, setLoaded] = useState<Loaded>({ status: "loading" });
  const [lineErrors, setLineErrors] = useState<Record<string, string>>({});
  // The text of the status region once the cart is read; "" says nothing.
  const [status, setStatus] = useState("");
  // "Spróbuj ponownie" reads the cart again while the error stays (the button keeps the focus). The ref stops a second
  // activation before the render that shows the state.
  const [retrying, setRetrying] = useState(false);
  const retryRunning = useRef(false);
  // Where the focus goes after an answer (an element id): set only when the customer left it where the action came from.
  const [attention, setAttention] = useState<{ id: string; seq: number } | null>(null);
  // The view the page shows, for an answer that comes after a render: the operations that waited for another one run
  // in the closure of the render that started the sending, whose `loaded` is old by then.
  const view = useRef<CartView | null>(null);
  // Bumped after the answer to an operation of the line, so that its quantity field shows the quantity of the last
  // view (also after a refused change).
  const [resets, setResets] = useState<Record<string, number>>({});
  // The actions are read and changed through the ref only: an answer must not see the actions of the render that sent
  // the request. The state is the copy the render shows.
  const actions = useRef<CartActions>(NO_ACTIONS);
  const [shownActions, setShownActions] = useState<CartActions>(NO_ACTIONS);
  // Read and changed through the ref like the actions; the state is the copy the render shows.
  const checkout = useRef<Checkout>("none");
  const [shownCheckout, setShownCheckout] = useState<Checkout>("none");
  // The sending goes on after the page is left (`runActions`); a late answer must not open the checkout then.
  const mounted = useRef(false);
  // The waiting ended with a cart that can be ordered: the link is clicked once, after the render that shows it. The
  // state brings the render, the ref makes the click happen once.
  const [openings, setOpenings] = useState(0);
  const openRequested = useRef(false);

  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, []);

  useEffect(() => {
    let active = true;
    // Actions given on a cart page that was left may still run: the cart is read after them, not before.
    void actionsFinished()
      .then(() => loadCart())
      .then((result) => {
        if (active) applyLoaded(toLoaded(result));
      });
    return () => {
      active = false;
    };
  }, []);

  useEffect(() => {
    if (attention) document.getElementById(attention.id)?.focus();
  }, [attention]);

  // The checkout opens through the link once the render of the last answer shows it: an earlier answer may have put
  // the inactive button in its place. The page is idle by then, so the click goes to `next/link`, as without the
  // waiting (a navigation of the client), and the page is not locked when that navigation does not happen.
  useEffect(() => {
    if (!openRequested.current) return;
    openRequested.current = false;
    document.getElementById(CHECKOUT_ID)?.click();
  }, [openings]);

  function applyLoaded(next: Loaded) {
    view.current = next.status === "ready" ? next.cart : null;
    setLoaded(next);
  }

  /** The focus is still on the control the action came from, or was lost with it (F2). */
  function focusStays(originId: string): boolean {
    const focus = document.activeElement;
    return focus === null || focus === document.body || focus === document.getElementById(originId);
  }

  function focusAt(id: string) {
    setAttention((current) => ({ id, seq: (current?.seq ?? 0) + 1 }));
  }

  async function retry() {
    if (retryRunning.current) return;
    retryRunning.current = true;
    setRetrying(true);
    const result = toLoaded(await loadCart());
    const keepsFocus = focusStays(RETRY_ID);
    retryRunning.current = false;
    setRetrying(false);
    applyLoaded(result);
    if (keepsFocus) focusAt(result.status === "error" ? RETRY_ID : TITLE_ID);
  }

  function show(cart: CartView) {
    applyLoaded({ status: "ready", cart });
    announceCartChange(cart.totals.itemCount);
  }

  /**
   * A line the page showed is not in the cart of the answer: the focus it had goes to a line that is left (F3). A
   * quantity is committed by leaving its field, also with Tab to "Usuń" of the same line, so the focus there counts as
   * where the change came from.
   */
  function moveFocusFromGoneLine({ variantId, action }: CartOperation, before: CartView | null, after: CartView) {
    const target = focusAfterGone(before, after, variantId);
    const origins = action.kind === "remove" ? [removeId(variantId)] : [quantityId(variantId), removeId(variantId)];
    if (target !== null && origins.some((origin) => focusStays(origin))) focusAt(target);
  }

  /**
   * The cart of the answer has no link to the checkout any more (it is empty or has a line that cannot be bought): the
   * focus the link had goes to the text of the empty cart or to the title (F3), however the waiting for it ended.
   */
  function moveFocusFromGoneLink(before: CartView | null, after: CartView) {
    if (before === null || !canBeOrdered(before) || canBeOrdered(after)) return;
    if (focusStays(CHECKOUT_ID)) focusAt(after.lines.length === 0 ? EMPTY_ID : TITLE_ID);
  }

  function resetQuantityField(variantId: string) {
    setResets((current) => ({ ...current, [variantId]: (current[variantId] ?? 0) + 1 }));
  }

  function showLineError(variantId: string, message: string | null) {
    setLineErrors((current) => {
      const next = { ...current };
      if (message === null) delete next[variantId];
      else next[variantId] = message;
      return next;
    });
  }

  function setActions(next: CartActions) {
    actions.current = next;
    setShownActions(next);
  }

  function setCheckout(next: Checkout) {
    checkout.current = next;
    setShownCheckout(next);
  }

  /** An entry not committed yet is committed the way a click elsewhere does it: by leaving the field. */
  function commitFocusedEntry() {
    const focused = document.activeElement;
    if (focused instanceof HTMLInputElement && focused.id.startsWith(QUANTITY_ID_PREFIX)) focused.blur();
  }

  /**
   * "Przejdź do kasy": the link itself when nothing is on its way and nothing waits; otherwise the page waits for the
   * operations and keeps the focus on the link (F1). A click with a modifier key is left to the browser (a new tab or
   * window), outside the guarantee of the page.
   */
  function goToCheckout(event: MouseEvent<HTMLAnchorElement>) {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (checkout.current === "waiting") {
      event.preventDefault();
      return;
    }
    const link = event.currentTarget;
    commitFocusedEntry();
    if (isIdle(actions.current)) {
      // `next/link` opens the checkout.
      if (checkout.current === "failed") setCheckout("none");
      return;
    }
    event.preventDefault();
    setStatus("");
    setCheckout("waiting");
    if (document.activeElement !== link) link.focus();
  }

  /**
   * Every operation is answered: the checkout the customer asked for opens when the cart can be ordered, through the link
   * itself after the render of this cart (the effect above), so a cart page the browser keeps for "Wstecz" waits for
   * nothing. A page that was left opens nothing.
   */
  function finishCheckout() {
    const cart = view.current;
    if (checkout.current !== "waiting") return;
    setCheckout("none");
    if (!mounted.current || cart === null || !canBeOrdered(cart)) return;
    openRequested.current = true;
    setOpenings((count) => count + 1);
  }

  /** Sends the operation at once when nothing is on its way; otherwise it waits at its line. */
  function request(operation: CartOperation) {
    const before = actions.current;
    const after = give(before, operation);
    if (after === before) return;
    setActions(after);
    if (before.sent === null) runActions(sendAll);
  }

  /** The customer changed the entry of a field: its waiting quantity is not sent. */
  function editQuantity(variantId: string) {
    const after = dropWaitingQuantity(actions.current, variantId);
    if (after !== actions.current) setActions(after);
  }

  /** Sends the waiting operations one by one, in their order, until none is left or the session expires. */
  async function sendAll() {
    // The first operation was given by the customer after the earlier ones ended, so it clears the text of the status
    // region; the ones that waited for another operation only replace it with their own text.
    let givenNow = true;
    for (;;) {
      const next = sendNext(actions.current);
      setActions(next);
      if (next.sent === null) {
        finishCheckout();
        return;
      }
      const outcome = await send(next.sent, givenNow);
      givenNow = false;
      if (outcome === "unauthorized") {
        setActions(NO_ACTIONS);
        if (checkout.current === "waiting") setCheckout("none");
        applyLoaded({ status: "unauthorized" });
        return;
      }
      // An operation that was not saved ends the waiting for good: a later success does not bring it back.
      if (outcome !== "saved" && checkout.current === "waiting") setCheckout(outcome === "gone" ? "none" : "failed");
    }
  }

  async function send(operation: CartOperation, givenNow: boolean): Promise<Outcome> {
    const { variantId, action } = operation;
    showLineError(variantId, null);
    if (givenNow) setStatus("");
    const result = action.kind === "remove" ? await removeCartItem(variantId) : await changeCartItemQuantity(variantId, action.quantity);
    if (result.status === "ok") {
      const before = view.current;
      show(result.data);
      // The field shows the quantity the API answered with, also when it is not the one typed.
      resetQuantityField(variantId);
      if (action.kind === "remove") setStatus(removedText(before, variantId));
      moveFocusFromGoneLink(before, result.data);
      moveFocusFromGoneLine(operation, before, result.data);
      return "saved";
    }
    if (result.status === "unauthorized") return "unauthorized";
    if (result.status === "not_found") {
      // A 404 does not prove the line is gone (an unavailable product answers 404 to a change), so the reloaded cart decides.
      const reloaded = await loadCart();
      if (reloaded.status === "unauthorized") return "unauthorized";
      if (reloaded.status === "ok") {
        const before = view.current;
        show(reloaded.data);
        moveFocusFromGoneLink(before, reloaded.data);
        if (!reloaded.data.lines.some((line) => line.variantId === variantId)) {
          setStatus(LINE_GONE);
          moveFocusFromGoneLine(operation, before, reloaded.data);
          return "gone";
        }
      }
      showLineError(variantId, (reloaded.status === "ok" && result.detail) || SAVE_FAILED);
    } else if (result.status === "rate_limited") {
      showLineError(variantId, CART_RATE_LIMITED);
    } else {
      showLineError(variantId, (result.status === "conflict" && result.detail) || SAVE_FAILED);
    }
    resetQuantityField(variantId);
    return "refused";
  }

  const linesLocked = shownCheckout === "waiting";
  // What the status region says now; the texts of the other operations of the screen are chosen here too.
  const statusText = loaded.status === "loading" || retrying ? LOADING : linesLocked ? SAVING : status;

  return (
    <ScreenMain>
      <ScreenTitle id={TITLE_ID} tabIndex={-1}>
        Koszyk
      </ScreenTitle>
      {loaded.status === "unauthorized" ? (
        <Alert variant="destructive">
          <AlertDescription>
            <SessionExpired returnTo="/cart" />
          </AlertDescription>
        </Alert>
      ) : null}
      {loaded.status === "error" ? (
        <div className="flex flex-col items-start gap-3">
          {retrying ? null : (
            <Alert variant="destructive">
              <AlertDescription>Nie udało się wczytać koszyka</AlertDescription>
            </Alert>
          )}
          {/* A running read blocks with `aria-disabled`, not `disabled`: a disabled button loses the keyboard focus. */}
          <Button
            id={RETRY_ID}
            type="button"
            variant="outline"
            aria-disabled={retrying || undefined}
            className="aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
            onClick={() => void retry()}
          >
            Spróbuj ponownie
          </Button>
        </div>
      ) : null}
      {loaded.status === "ready" ? (
        loaded.cart.lines.length === 0 ? (
          <div className="flex flex-col items-start gap-1">
            <p id={EMPTY_ID} tabIndex={-1} className="text-lg">
              Twój koszyk jest pusty
            </p>
            <Button asChild variant="link" className="px-0 underline">
              <Link href="/">Zobacz produkty</Link>
            </Button>
          </div>
        ) : (
          <>
            <ul className="flex flex-col divide-y divide-border">
              {loaded.cart.lines.map((line) => (
                <CartLineRow
                  key={line.variantId}
                  line={line}
                  quantitySaving={isQuantitySent(shownActions, line.variantId)}
                  removalGiven={isRemovalGiven(shownActions, line.variantId)}
                  quantityWaiting={hasWaitingQuantity(shownActions, line.variantId)}
                  locked={linesLocked}
                  error={lineErrors[line.variantId]}
                  resetKey={resets[line.variantId] ?? 0}
                  onEditQuantity={() => editQuantity(line.variantId)}
                  onChangeQuantity={(quantity) => request({ variantId: line.variantId, action: { kind: "quantity", quantity } })}
                  onRemove={() => request({ variantId: line.variantId, action: { kind: "remove" } })}
                />
              ))}
            </ul>
            <CartSummary cart={loaded.cart} />
            <CheckoutButton cart={loaded.cart} checkout={shownCheckout} onActivate={goToCheckout} />
          </>
        )
      ) : null}
      {/*
        The last element of the screen, so that its text appearing and going (also at the mouse down that starts an
        operation, when the focus leaves a field) never moves a control the customer is about to press. An empty region
        takes no room: the negative margin cancels the gap above it.
      */}
      <p role="status" data-testid="cart-status" className="break-words text-muted-foreground empty:-mt-6">
        {statusText}
      </p>
    </ScreenMain>
  );
}

/** The cart has the link to the checkout: lines, all of which can be bought. */
const canBeOrdered = (cart: CartView): boolean => cart.lines.length > 0 && !cart.hasExcludedLines;

function toLoaded(result: CartResult<CartView>): Loaded {
  if (result.status === "ok") return { status: "ready", cart: result.data };
  return { status: result.status === "unauthorized" ? "unauthorized" : "error" };
}

/** The status text after a removal: the name of the line like the label of its quantity field, or none for a line without a name. */
function removedText(before: CartView | null, variantId: string): string {
  const product = before?.lines.find((line) => line.variantId === variantId)?.product;
  if (!product?.name) return REMOVED_UNNAMED;
  return `Usunięto z koszyka: ${product.variantLabel ? `${product.name}, ${product.variantLabel}` : product.name}.`;
}

/**
 * The id of the element that takes the focus of a line that is not in the cart any more: "Usuń" of the next line that
 * is left, else of the previous one; the text of the empty cart when nothing is left. Null when the line is still there
 * (or was not shown), so the focus stays.
 */
function focusAfterGone(before: CartView | null, after: CartView, variantId: string): string | null {
  const index = before?.lines.findIndex((line) => line.variantId === variantId) ?? -1;
  if (before === null || index === -1 || after.lines.some((line) => line.variantId === variantId)) return null;
  if (after.lines.length === 0) return EMPTY_ID;
  const left = (lines: CartLine[]) => lines.filter((line) => after.lines.some((kept) => kept.variantId === line.variantId));
  const target = left(before.lines.slice(index + 1))[0] ?? left(before.lines.slice(0, index)).at(-1) ?? after.lines[0];
  return removeId(target.variantId);
}

function CartSummary({ cart }: { cart: CartView }) {
  return (
    <section aria-label="Podsumowanie">
      <Card>
        <CardContent className="flex flex-col gap-1">
          <p className="flex justify-between text-lg font-semibold">
            <span>Wartość brutto</span>
            <span data-testid="cart-total">{formatPrice(cart.totals.gross, cart.totals.currency)}</span>
          </p>
          <p className="flex justify-between text-sm text-muted-foreground">
            <span>Liczba sztuk</span>
            <span data-testid="cart-item-count">{cart.totals.itemCount}</span>
          </p>
          {cart.hasExcludedLines ? <p className="text-sm text-destructive">Część produktów nie jest wliczona</p> : null}
          <CartDelivery delivery={cart.delivery} currency={cart.totals.currency} />
        </CardContent>
      </Card>
    </section>
  );
}

/**
 * The delivery rows under the total, as the API computed them: the cheapest cost ("Dostawa od", or "Darmowa dostawa"
 * when it is 0) and what is missing to free delivery. The method is chosen in the checkout. The region is there
 * before the rows change, so a screen reader announces the change after every edit of the cart.
 */
function CartDelivery({ delivery, currency }: { delivery: CartView["delivery"]; currency: string }) {
  const fromGross = delivery?.fromGross ?? null;
  const missingToFreeGross = delivery?.missingToFreeGross ?? null;
  return (
    <div data-testid="cart-delivery" aria-live="polite" aria-atomic="true" className="flex flex-col gap-1 text-sm text-muted-foreground">
      {fromGross === null ? null : (
        <p data-testid="cart-delivery-from">{fromGross === 0 ? "Darmowa dostawa" : `Dostawa od ${formatPrice(fromGross, currency)}`}</p>
      )}
      {missingToFreeGross === null ? null : (
        <p data-testid="cart-delivery-missing">Do darmowej dostawy brakuje {formatPrice(missingToFreeGross, currency)}</p>
      )}
    </div>
  );
}

const EXCLUDED_LINES_HINT = "Usuń albo popraw oznaczone pozycje, żeby złożyć zamówienie.";

/**
 * The way to the checkout; a cart with a line that cannot be bought (`hasExcludedLines` of the API) cannot be ordered.
 * The link stays a link while it waits for the changes of the cart: `aria-disabled`, not a button with `disabled`, so it
 * keeps the focus. The message of a change it waited for in vain is under it, so it never moves the link.
 */
function CheckoutButton({
  cart,
  checkout,
  onActivate,
}: {
  cart: CartView;
  checkout: Checkout;
  onActivate: (event: MouseEvent<HTMLAnchorElement>) => void;
}) {
  if (!cart.hasExcludedLines) {
    return (
      <div className="flex flex-col items-end gap-2">
        <Button asChild className="aria-disabled:cursor-not-allowed aria-disabled:opacity-50">
          <Link id={CHECKOUT_ID} href={CHECKOUT_PATH} aria-disabled={checkout === "waiting" || undefined} onClick={onActivate}>
            Przejdź do kasy
          </Link>
        </Button>
        {checkout === "failed" ? (
          <Alert variant="destructive">
            <AlertDescription>{NOT_SAVED}</AlertDescription>
          </Alert>
        ) : null}
      </div>
    );
  }
  return (
    <div className="flex flex-col items-end gap-2">
      <Button type="button" disabled aria-describedby="checkout-blocked">
        Przejdź do kasy
      </Button>
      <p id="checkout-blocked" className="text-sm text-muted-foreground">
        {EXCLUDED_LINES_HINT}
      </p>
    </div>
  );
}

/**
 * `quantitySaving`: the change of this line's quantity is on its way, so its field is read-only. `removalGiven`: its
 * removal is on its way or waits, so both controls are inactive. `quantityWaiting`: a quantity of this line waits for
 * the operation of another line. `locked`: the page waits for its operations to open the checkout, so both controls are
 * inactive.
 */
function CartLineRow({
  line,
  quantitySaving,
  removalGiven,
  quantityWaiting,
  locked,
  error,
  resetKey,
  onEditQuantity,
  onChangeQuantity,
  onRemove,
}: {
  line: CartLine;
  quantitySaving: boolean;
  removalGiven: boolean;
  quantityWaiting: boolean;
  locked: boolean;
  error?: string;
  resetKey: number;
  onEditQuantity: () => void;
  onChangeQuantity: (quantity: number) => void;
  onRemove: () => void;
}) {
  const name = line.product?.name;
  const variantLabel = line.product?.variantLabel;
  return (
    <li className="flex flex-col gap-2 py-4">
      <div className="flex gap-4">
        {line.product ? (
          <div className="w-24 shrink-0 overflow-hidden rounded-lg">
            <ProductPhoto image={line.product.image} sizes={PHOTO_SIZES} />
          </div>
        ) : null}
        <div className="flex min-w-0 flex-1 flex-col gap-1">
          {name ? <h2 className="text-base font-semibold">{name}</h2> : null}
          {variantLabel ? (
            <p data-testid="variant-label" className="text-sm text-muted-foreground">
              {variantLabel}
            </p>
          ) : null}
          {line.unitPrice ? (
            <p data-testid="unit-price" className="text-sm text-muted-foreground">
              {formatPrice(line.unitPrice.gross, line.unitPrice.currency)}
            </p>
          ) : null}
          {line.state === "purchasable" ? null : <p className="text-sm font-medium text-destructive">{STATE_LABELS[line.state]}</p>}
        </div>
        <div className="flex flex-col items-end gap-2">
          <QuantityField
            id={quantityId(line.variantId)}
            label={name ? `Ilość: ${variantLabel ? `${name}, ${variantLabel}` : name}` : "Ilość"}
            quantity={line.quantity}
            resetKey={resetKey}
            locked={quantitySaving || removalGiven || locked}
            waiting={quantityWaiting}
            onEdit={onEditQuantity}
            onCommit={onChangeQuantity}
          />
          {line.lineTotal ? (
            <p data-testid="line-total" className="text-base font-semibold">
              {formatPrice(line.lineTotal.gross, line.lineTotal.currency)}
            </p>
          ) : null}
          {/* aria-disabled, not disabled: a disabled button would drop the keyboard focus while the removal is on its way. */}
          <Button
            id={removeId(line.variantId)}
            type="button"
            variant="ghost"
            size="sm"
            aria-disabled={removalGiven || locked || undefined}
            className="aria-disabled:cursor-not-allowed aria-disabled:text-muted-foreground"
            onClick={() => {
              if (!removalGiven && !locked) onRemove();
            }}
            aria-label={name ? `Usuń: ${name}` : "Usuń"}
          >
            Usuń
          </Button>
        </div>
      </div>
      {error ? (
        <Alert variant="destructive">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      ) : null}
    </li>
  );
}

/**
 * Commits a changed number on blur or Enter; anything but a whole number from 1 to 99 goes back to the quantity.
 * It is the same element for the whole life of the line, so the keyboard focus stays where the customer put it: while
 * the change of its quantity is saved or its removal is given, the field is `aria-disabled` and read-only (not
 * `disabled`, which would drop the focus) and ignores every commit. A committed quantity that waits for the operation
 * of another line stays in the field, which stays editable: a new entry drops it (`onEdit`). `resetKey` brings the
 * quantity of the line back after the answer to its own operation; an answer for another line does not overwrite an
 * entry not committed yet or a waiting quantity.
 */
function QuantityField({
  id,
  label,
  quantity,
  resetKey,
  locked,
  waiting,
  onEdit,
  onCommit,
}: {
  id: string;
  label: string;
  quantity: number;
  resetKey: number;
  locked: boolean;
  waiting: boolean;
  onEdit: () => void;
  onCommit: (quantity: number) => void;
}): ReactNode {
  const [draft, setDraft] = useState(String(quantity));
  const [shown, setShown] = useState({ quantity, resetKey });

  if (shown.resetKey !== resetKey) {
    // The answer to the line's own operation: the field shows its quantity, also after a refused change.
    setShown({ quantity, resetKey });
    setDraft(String(quantity));
  } else if (shown.quantity !== quantity) {
    // The answer to another line's operation brings a new quantity of this one: only an untouched field shows it.
    setShown({ quantity, resetKey });
    if (!waiting && draft === String(shown.quantity)) setDraft(String(quantity));
  }

  function commit() {
    if (locked) return;
    const value = draft.trim() === "" ? Number.NaN : Number(draft);
    if (!Number.isInteger(value) || value < MIN_QUANTITY || value > MAX_QUANTITY || value === quantity) {
      setDraft(String(quantity));
      return;
    }
    onCommit(value);
  }

  return (
    <Input
      id={id}
      type="number"
      inputMode="numeric"
      min={MIN_QUANTITY}
      max={MAX_QUANTITY}
      aria-label={label}
      aria-disabled={locked || undefined}
      readOnly={locked}
      className="w-20 aria-disabled:cursor-not-allowed aria-disabled:border-border aria-disabled:bg-muted aria-disabled:text-muted-foreground"
      value={draft}
      onChange={(event) => {
        if (locked) return;
        setDraft(event.target.value);
        onEdit();
      }}
      onBlur={commit}
      onKeyDown={(event) => {
        if (event.key === "Enter") {
          event.preventDefault();
          commit();
        }
      }}
    />
  );
}
