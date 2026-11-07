"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { type AccountResult, type CustomerOrderList, type CustomerOrderListItem, loadCustomerOrders } from "@/api/client";
import { formatPrice } from "@/catalog/format-price";
import { Button } from "@/components/ui/button";
import { navigateTo } from "@/checkout/navigate";
import { HISTORY_PATH, formatOrderMoment, LOAD_FAILED, loginHref, ORDER_STATUS_LABELS } from "./order-format";
import { OrderPaymentNote } from "./OrderPaymentNote";
import { SignedOut } from "./SignedOut";

type State = {
  /** `failed` only for the first page: a failed next page keeps `ready` and says so in `more`. */
  phase: "loading" | "ready" | "failed";
  items: CustomerOrderListItem[];
  nextCursor: string | null;
  more: "idle" | "loading" | "failed";
  signedOut: boolean;
};

const INITIAL: State = { phase: "loading", items: [], nextCursor: null, more: "idle", signedOut: false };

/** What the first page came to: the page itself, a failure, or a session that is over (to the login). */
function applyFirst(result: AccountResult<CustomerOrderList>, setState: (state: State | ((current: State) => State)) => void) {
  if (result.status === "unauthorized") {
    navigateTo(loginHref(HISTORY_PATH));
    setState((current) => ({ ...current, signedOut: true }));
  } else if (result.status === "ok") {
    setState({ phase: "ready", items: result.data.items, nextCursor: result.data.nextCursor, more: "idle", signedOut: false });
  } else {
    setState({ ...INITIAL, phase: "failed" });
  }
}

/**
 * The order history of the signed-in customer: newest first, a page at a time with "Pokaż więcej". Rendered in the
 * browser from the store's `/api/account/orders`; a session that is missing or over takes the customer to the login,
 * which brings them back here. A next page that fails leaves the orders already loaded.
 */
export function OrdersPage() {
  const [state, setState] = useState<State>(INITIAL);
  // Bumped by a retry of the first page.
  const [attempt, setAttempt] = useState(0);
  // A click while a next page is loading asks for nothing more.
  const busy = useRef(false);

  useEffect(() => {
    let active = true;
    void loadCustomerOrders().then((result) => {
      if (active) applyFirst(result, setState);
    });
    return () => {
      active = false;
    };
  }, [attempt]);

  function retryFirst() {
    setState(INITIAL);
    setAttempt((current) => current + 1);
  }

  async function showMore(cursor: string) {
    if (busy.current) return;
    busy.current = true;
    setState((current) => ({ ...current, more: "loading" }));
    const result = await loadCustomerOrders(cursor);
    busy.current = false;
    if (result.status === "ok") {
      setState((current) => ({ ...current, items: [...current.items, ...result.data.items], nextCursor: result.data.nextCursor, more: "idle" }));
    } else if (result.status === "unauthorized") {
      navigateTo(loginHref(HISTORY_PATH));
      setState((current) => ({ ...current, more: "idle", signedOut: true }));
    } else {
      setState((current) => ({ ...current, more: "failed" }));
    }
  }

  return (
    <main className="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-6">
      <h1 className="text-2xl font-semibold">Moje zamówienia</h1>
      {state.phase === "loading" && !state.signedOut ? <p className="text-muted-foreground">Wczytywanie…</p> : null}
      {state.signedOut ? <SignedOut returnTo={HISTORY_PATH} /> : null}
      {state.phase === "failed" && !state.signedOut ? (
        <div role="alert" className="flex flex-col items-start gap-2">
          <p>{LOAD_FAILED}</p>
          <Button type="button" variant="outline" onClick={retryFirst}>
            Spróbuj ponownie
          </Button>
        </div>
      ) : null}
      {state.phase === "ready" && state.items.length === 0 ? <p>Nie masz jeszcze zamówień.</p> : null}
      {state.items.length > 0 ? (
        <ul className="flex flex-col gap-3">
          {state.items.map((order) => (
            <li key={order.orderId} className="flex flex-col gap-1 rounded-md border p-4">
              <div className="flex flex-wrap items-baseline justify-between gap-2">
                <Link href={`${HISTORY_PATH}/${encodeURIComponent(order.orderId)}`} className="font-medium underline underline-offset-4">
                  {order.number}
                </Link>
                <span>{ORDER_STATUS_LABELS[order.status]}</span>
              </div>
              <div className="flex flex-wrap items-baseline justify-between gap-2 text-sm text-muted-foreground">
                <span>{formatOrderMoment(order.placedAt)}</span>
                <span>{order.itemCount} szt.</span>
                <span className="text-foreground">{formatPrice(order.totalGross, order.currency)}</span>
              </div>
              <OrderPaymentNote status={order.status} paymentDeadline={order.paymentDeadline} paymentDeadlinePassed={order.paymentDeadlinePassed} />
            </li>
          ))}
        </ul>
      ) : null}
      {state.phase === "ready" && state.more === "failed" ? (
        <div role="alert" className="flex flex-col items-start gap-2">
          <p>{LOAD_FAILED}</p>
          <Button type="button" variant="outline" onClick={() => void showMore(state.nextCursor ?? "")}>
            Spróbuj ponownie
          </Button>
        </div>
      ) : null}
      {state.phase === "ready" && state.nextCursor !== null && state.more !== "failed" ? (
        <Button type="button" variant="outline" disabled={state.more === "loading"} onClick={() => void showMore(state.nextCursor ?? "")}>
          Pokaż więcej
        </Button>
      ) : null}
    </main>
  );
}
