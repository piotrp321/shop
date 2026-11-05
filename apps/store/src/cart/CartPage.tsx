"use client";

import Link from "next/link";
import { type ReactNode, useEffect, useState } from "react";
import { type CartResult, type CartView, changeCartItemQuantity, loadCart, removeCartItem } from "@/api/client";
import { formatPrice } from "@/catalog/format-price";
import { ProductPhoto } from "@/catalog/ProductPhoto";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { announceCartChange } from "./events";
import { SAVE_FAILED, SessionExpired } from "./messages";

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

/**
 * The cart of the browser, loaded from `/api/cart`. Every view comes from the last answer of the API; nothing is
 * computed here, and a refused change leaves the last view as it was.
 */
export function CartPage() {
  const [loaded, setLoaded] = useState<Loaded>({ status: "loading" });
  const [busy, setBusy] = useState(false);
  const [lineErrors, setLineErrors] = useState<Record<string, string>>({});
  const [notice, setNotice] = useState<string | null>(null);
  const [sessionExpired, setSessionExpired] = useState(false);
  // Bumped after a refused change, so that the quantity field shows the quantity of the last view again.
  const [resets, setResets] = useState<Record<string, number>>({});

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

  async function change(variantId: string, run: () => Promise<CartResult<CartView>>) {
    setBusy(true);
    setLineErrors({});
    setNotice(null);
    setSessionExpired(false);
    const result = await run();
    if (result.status === "ok") {
      show(result.data);
    } else if (result.status === "not_found") {
      // A 404 does not prove the line is gone (an unavailable product answers 404 to a change), so the reloaded cart decides.
      const reloaded = await loadCart();
      if (reloaded.status === "ok") {
        show(reloaded.data);
        if (reloaded.data.lines.some((line) => line.variantId === variantId)) {
          setLineErrors({ [variantId]: result.detail || SAVE_FAILED });
          resetQuantityField(variantId);
        } else {
          setNotice("Pozycja została już usunięta");
        }
      } else {
        if (reloaded.status === "unauthorized") setSessionExpired(true);
        else setLineErrors({ [variantId]: SAVE_FAILED });
        resetQuantityField(variantId);
      }
    } else {
      if (result.status === "unauthorized") {
        setSessionExpired(true);
      } else {
        setLineErrors({ [variantId]: (result.status === "conflict" && result.detail) || SAVE_FAILED });
      }
      resetQuantityField(variantId);
    }
    setBusy(false);
  }

  return (
    <main className="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-6">
      <h1 className="text-2xl font-semibold">Koszyk</h1>
      {loaded.status === "loading" ? <p className="text-muted-foreground">Wczytywanie koszyka…</p> : null}
      {loaded.status === "unauthorized" ? (
        <p role="alert">
          <SessionExpired returnTo="/cart" />
        </p>
      ) : null}
      {loaded.status === "error" ? (
        <div role="alert" className="flex flex-col items-start gap-2">
          <p>Nie udało się wczytać koszyka</p>
          <Button type="button" variant="outline" onClick={retry}>
            Spróbuj ponownie
          </Button>
        </div>
      ) : null}
      {sessionExpired ? (
        <p role="alert" className="text-destructive">
          <SessionExpired returnTo="/cart" />
        </p>
      ) : null}
      {notice ? <p role="status">{notice}</p> : null}
      {loaded.status === "ready" ? (
        loaded.cart.lines.length === 0 ? (
          <div className="flex flex-col items-start gap-2">
            <p>Twój koszyk jest pusty</p>
            <Link href="/" className="text-sm underline underline-offset-4">
              Zobacz produkty
            </Link>
          </div>
        ) : (
          <>
            <ul className="flex flex-col divide-y divide-border">
              {loaded.cart.lines.map((line) => (
                <CartLineRow
                  key={line.variantId}
                  line={line}
                  busy={busy}
                  error={lineErrors[line.variantId]}
                  resetKey={resets[line.variantId] ?? 0}
                  onChangeQuantity={(quantity) => void change(line.variantId, () => changeCartItemQuantity(line.variantId, quantity))}
                  onRemove={() => void change(line.variantId, () => removeCartItem(line.variantId))}
                />
              ))}
            </ul>
            <CartSummary cart={loaded.cart} />
          </>
        )
      ) : null}
    </main>
  );
}

function toLoaded(result: CartResult<CartView>): Loaded {
  if (result.status === "ok") return { status: "ready", cart: result.data };
  return { status: result.status === "unauthorized" ? "unauthorized" : "error" };
}

function CartSummary({ cart }: { cart: CartView }) {
  return (
    <section aria-label="Podsumowanie" className="flex flex-col gap-1 border-t border-border pt-4">
      <p className="flex justify-between font-semibold">
        <span>Wartość brutto</span>
        <span data-testid="cart-total">{formatPrice(cart.totals.gross, cart.totals.currency)}</span>
      </p>
      <p className="flex justify-between text-sm text-muted-foreground">
        <span>Liczba sztuk</span>
        <span data-testid="cart-item-count">{cart.totals.itemCount}</span>
      </p>
      {cart.hasExcludedLines ? <p className="text-sm text-destructive">Część produktów nie jest wliczona</p> : null}
    </section>
  );
}

function CartLineRow({
  line,
  busy,
  error,
  resetKey,
  onChangeQuantity,
  onRemove,
}: {
  line: CartLine;
  busy: boolean;
  error?: string;
  resetKey: number;
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
          {name ? <h2 className="text-sm font-medium">{name}</h2> : null}
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
            key={`${line.quantity}:${resetKey}`}
            label={name ? `Ilość: ${variantLabel ? `${name}, ${variantLabel}` : name}` : "Ilość"}
            quantity={line.quantity}
            disabled={busy}
            onCommit={onChangeQuantity}
          />
          {line.lineTotal ? (
            <p data-testid="line-total" className="text-sm font-semibold">
              {formatPrice(line.lineTotal.gross, line.lineTotal.currency)}
            </p>
          ) : null}
          <Button type="button" variant="ghost" size="sm" disabled={busy} onClick={onRemove} aria-label={name ? `Usuń: ${name}` : "Usuń"}>
            Usuń
          </Button>
        </div>
      </div>
      {error ? (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      ) : null}
    </li>
  );
}

/** Commits a changed number on blur or Enter; anything but a whole number from 1 to 99 goes back to the quantity. */
function QuantityField({
  label,
  quantity,
  disabled,
  onCommit,
}: {
  label: string;
  quantity: number;
  disabled: boolean;
  onCommit: (quantity: number) => void;
}): ReactNode {
  const [draft, setDraft] = useState(String(quantity));

  function commit() {
    if (disabled) return;
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
      className="w-20"
      value={draft}
      disabled={disabled}
      onChange={(event) => setDraft(event.target.value)}
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
