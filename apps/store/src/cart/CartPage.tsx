"use client";

import Link from "next/link";
import { type ReactNode, useEffect, useRef, useState } from "react";
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
  dropWaitingQuantity,
  give,
  hasWaitingQuantity,
  isQuantitySent,
  isRemovalGiven,
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

/** How an operation of the cart page ended; a 401 drops the actions still waiting. */
type Outcome = "saved" | "refused" | "unauthorized";

/**
 * The cart of the browser, loaded from `/api/cart`. Every view comes from the last answer of the API; nothing is
 * computed here, and a refused change leaves the last view as it was. One operation goes to the API at a time; an
 * action given meanwhile waits at its line (`cart-actions.ts`) and goes after the answer, also when the page is left.
 */
export function CartPage() {
  const [loaded, setLoaded] = useState<Loaded>({ status: "loading" });
  const [lineErrors, setLineErrors] = useState<Record<string, string>>({});
  const [notice, setNotice] = useState<string | null>(null);
  // Bumped after the answer to an operation of the line, so that its quantity field shows the quantity of the last
  // view (also after a refused change).
  const [resets, setResets] = useState<Record<string, number>>({});
  // The actions are read and changed through the ref only: an answer must not see the actions of the render that sent
  // the request. The state is the copy the render shows.
  const actions = useRef<CartActions>(NO_ACTIONS);
  const [shownActions, setShownActions] = useState<CartActions>(NO_ACTIONS);

  useEffect(() => {
    let active = true;
    void loadCart().then((result) => {
      if (active) setLoaded(toLoaded(result));
    });
    return () => {
      active = false;
    };
  }, []);

  function retry() {
    setLoaded({ status: "loading" });
    void loadCart().then((result) => setLoaded(toLoaded(result)));
  }

  function show(cart: CartView) {
    setLoaded({ status: "ready", cart });
    announceCartChange(cart.totals.itemCount);
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

  /** Sends the operation at once when nothing is on its way; otherwise it waits at its line. */
  function request(operation: CartOperation) {
    const before = actions.current;
    const after = give(before, operation);
    if (after === before) return;
    setActions(after);
    if (before.sent === null) void sendAll();
  }

  /** The customer changed the entry of a field: its waiting quantity is not sent. */
  function editQuantity(variantId: string) {
    const after = dropWaitingQuantity(actions.current, variantId);
    if (after !== actions.current) setActions(after);
  }

  /** Sends the waiting operations one by one, in their order, until none is left or the session expires. */
  async function sendAll() {
    for (;;) {
      const next = sendNext(actions.current);
      setActions(next);
      if (next.sent === null) return;
      if ((await send(next.sent)) === "unauthorized") {
        setActions(NO_ACTIONS);
        setLoaded({ status: "unauthorized" });
        return;
      }
    }
  }

  async function send({ variantId, action }: CartOperation): Promise<Outcome> {
    showLineError(variantId, null);
    setNotice(null);
    const result = action.kind === "remove" ? await removeCartItem(variantId) : await changeCartItemQuantity(variantId, action.quantity);
    if (result.status === "ok") {
      show(result.data);
      // The field shows the quantity the API answered with, also when it is not the one typed.
      resetQuantityField(variantId);
      return "saved";
    }
    if (result.status === "unauthorized") return "unauthorized";
    if (result.status === "not_found") {
      // A 404 does not prove the line is gone (an unavailable product answers 404 to a change), so the reloaded cart decides.
      const reloaded = await loadCart();
      if (reloaded.status === "unauthorized") return "unauthorized";
      if (reloaded.status === "ok") {
        show(reloaded.data);
        if (!reloaded.data.lines.some((line) => line.variantId === variantId)) {
          setNotice("Pozycja została już usunięta");
          return "refused";
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

  return (
    <ScreenMain>
      <ScreenTitle>Koszyk</ScreenTitle>
      {loaded.status === "loading" ? <p className="text-muted-foreground">Wczytywanie koszyka…</p> : null}
      {loaded.status === "unauthorized" ? (
        <Alert variant="destructive">
          <AlertDescription>
            <SessionExpired returnTo="/cart" />
          </AlertDescription>
        </Alert>
      ) : null}
      {loaded.status === "error" ? (
        <Alert variant="destructive" className="justify-items-start gap-3">
          <AlertDescription>Nie udało się wczytać koszyka</AlertDescription>
          <Button type="button" variant="outline" onClick={retry}>
            Spróbuj ponownie
          </Button>
        </Alert>
      ) : null}
      {notice ? (
        <Alert role="status">
          <AlertDescription>{notice}</AlertDescription>
        </Alert>
      ) : null}
      {loaded.status === "ready" ? (
        loaded.cart.lines.length === 0 ? (
          <div className="flex flex-col items-start gap-1">
            <p className="text-lg">Twój koszyk jest pusty</p>
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
                  error={lineErrors[line.variantId]}
                  resetKey={resets[line.variantId] ?? 0}
                  onEditQuantity={() => editQuantity(line.variantId)}
                  onChangeQuantity={(quantity) => request({ variantId: line.variantId, action: { kind: "quantity", quantity } })}
                  onRemove={() => request({ variantId: line.variantId, action: { kind: "remove" } })}
                />
              ))}
            </ul>
            <CartSummary cart={loaded.cart} />
            <CheckoutButton cart={loaded.cart} />
          </>
        )
      ) : null}
    </ScreenMain>
  );
}

function toLoaded(result: CartResult<CartView>): Loaded {
  if (result.status === "ok") return { status: "ready", cart: result.data };
  return { status: result.status === "unauthorized" ? "unauthorized" : "error" };
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

/** The way to the checkout; a cart with a line that cannot be bought (`hasExcludedLines` of the API) cannot be ordered. */
function CheckoutButton({ cart }: { cart: CartView }) {
  if (!cart.hasExcludedLines) {
    return (
      <Button asChild className="self-end">
        <Link href="/checkout">Przejdź do kasy</Link>
      </Button>
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
 * the operation of another line.
 */
function CartLineRow({
  line,
  quantitySaving,
  removalGiven,
  quantityWaiting,
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
            label={name ? `Ilość: ${variantLabel ? `${name}, ${variantLabel}` : name}` : "Ilość"}
            quantity={line.quantity}
            resetKey={resetKey}
            locked={quantitySaving || removalGiven}
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
            type="button"
            variant="ghost"
            size="sm"
            aria-disabled={removalGiven || undefined}
            className="aria-disabled:cursor-not-allowed aria-disabled:text-muted-foreground"
            onClick={() => {
              if (!removalGiven) onRemove();
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
  label,
  quantity,
  resetKey,
  locked,
  waiting,
  onEdit,
  onCommit,
}: {
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
