"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { type AccountResult, type CustomerOrder, loadCustomerOrder } from "@/api/client";
import { formatPrice } from "@/catalog/format-price";
import { Button } from "@/components/ui/button";
import { navigateTo } from "@/checkout/navigate";
import { HISTORY_PATH, formatOrderMoment, LOAD_FAILED, loginHref, ORDER_STATUS_LABELS } from "./order-format";
import { OrderPaymentNote } from "./OrderPaymentNote";
import { SignedOut } from "./SignedOut";

type State = { kind: "loading" } | { kind: "ready"; order: CustomerOrder } | { kind: "not_found" } | { kind: "failed" } | { kind: "signed_out" };

const NOT_FOUND = "Nie znaleźliśmy zamówienia.";

/** The moments of the statuses an order can have, in the order they come; the ones it has not reached are left out. */
const DATES = [
  ["placedAt", "Złożone"],
  ["paidAt", "Opłacone"],
  ["shippedAt", "Wysłane"],
  ["deliveredAt", "Dostarczone"],
  ["cancelledAt", "Anulowane"],
  ["returnedAt", "Zwrócone"],
] as const;

function stateOf(result: AccountResult<CustomerOrder>): State {
  switch (result.status) {
    case "ok":
      return { kind: "ready", order: result.data };
    case "not_found":
      return { kind: "not_found" };
    case "unauthorized":
      return { kind: "signed_out" };
    case "failed":
      return { kind: "failed" };
  }
}

/**
 * One order of the signed-in customer: its lines, totals, address and the dates of its statuses, all as the API
 * gives them. Rendered in the browser from the store's `/api/account/orders/<id>`. A session that is missing or over
 * takes the customer to the login, which brings them back to this order.
 */
export function OrderDetailPage({ orderId }: { orderId: string }) {
  const [state, setState] = useState<State>({ kind: "loading" });
  // Bumped by a retry.
  const [attempt, setAttempt] = useState(0);
  const here = `${HISTORY_PATH}/${encodeURIComponent(orderId)}`;

  useEffect(() => {
    let active = true;
    void loadCustomerOrder(orderId).then((result) => {
      if (!active) return;
      if (result.status === "unauthorized") {
        navigateTo(loginHref(`${HISTORY_PATH}/${encodeURIComponent(orderId)}`));
      }
      setState(stateOf(result));
    });
    return () => {
      active = false;
    };
  }, [orderId, attempt]);

  function retry() {
    setState({ kind: "loading" });
    setAttempt((current) => current + 1);
  }

  const order = state.kind === "ready" ? state.order : null;

  return (
    <main className="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-6">
      <h1 className="text-2xl font-semibold">{order ? `Zamówienie ${order.number}` : "Zamówienie"}</h1>
      {state.kind === "loading" ? <p className="text-muted-foreground">Wczytywanie…</p> : null}
      {state.kind === "signed_out" ? <SignedOut returnTo={here} /> : null}
      {state.kind === "not_found" ? <p role="alert">{NOT_FOUND}</p> : null}
      {state.kind === "failed" ? (
        <div role="alert" className="flex flex-col items-start gap-2">
          <p>{LOAD_FAILED}</p>
          <Button type="button" variant="outline" onClick={retry}>
            Spróbuj ponownie
          </Button>
        </div>
      ) : null}
      {order ? <OrderContent order={order} /> : null}
      {state.kind !== "loading" && state.kind !== "signed_out" ? (
        <Link href={HISTORY_PATH} className="text-sm underline underline-offset-4">
          Wróć do zamówień
        </Link>
      ) : null}
    </main>
  );
}

function OrderContent({ order }: { order: CustomerOrder }) {
  return (
    <>
      <div className="flex flex-col gap-1">
        <p>
          Status: <strong data-testid="order-status">{ORDER_STATUS_LABELS[order.status]}</strong>
        </p>
        <OrderPaymentNote status={order.status} paymentDeadline={order.paymentDeadline} paymentDeadlinePassed={order.paymentDeadlinePassed} />
      </div>

      <ul aria-label="Pozycje zamówienia" className="flex flex-col gap-3">
        {order.lines.map((line) => (
          <li key={line.variantId} className="flex flex-wrap items-baseline justify-between gap-2 rounded-md border p-4">
            <div className="flex flex-col">
              <span className="font-medium">{line.productName}</span>
              {line.variantLabel === "" ? null : <span className="text-sm text-muted-foreground">{line.variantLabel}</span>}
              <span className="text-sm text-muted-foreground">
                {line.quantity} × <span data-testid="unit-price">{formatPrice(line.unitGross, order.totals.currency)}</span>
              </span>
            </div>
            <span data-testid="line-total">{formatPrice(line.lineGross, order.totals.currency)}</span>
          </li>
        ))}
      </ul>

      <dl className="flex flex-col gap-1 self-end text-sm">
        <div className="flex justify-between gap-6">
          <dt>Wartość netto</dt>
          <dd data-testid="total-net">{formatPrice(order.totals.net, order.totals.currency)}</dd>
        </div>
        <div className="flex justify-between gap-6">
          <dt>VAT</dt>
          <dd data-testid="total-vat">{formatPrice(order.totals.vat, order.totals.currency)}</dd>
        </div>
        <div className="flex justify-between gap-6 text-base font-semibold">
          <dt>Razem brutto</dt>
          <dd data-testid="total-gross">{formatPrice(order.totals.gross, order.totals.currency)}</dd>
        </div>
      </dl>

      <div role="group" aria-label="Adres dostawy" className="flex flex-col gap-1">
        <h2 className="font-medium">Adres dostawy</h2>
        <address className="not-italic">
          <div>{order.shippingAddress.street}</div>
          <div>
            {order.shippingAddress.postalCode} {order.shippingAddress.city}
          </div>
        </address>
      </div>

      <div role="group" aria-label="Daty zamówienia" className="flex flex-col gap-1">
        <dl className="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-1 text-sm">
          {DATES.flatMap(([field, label]) => {
            const moment = order[field];
            return moment === null ? [] : [<dt key={`${field}-label`}>{label}</dt>, <dd key={field}>{formatOrderMoment(moment)}</dd>];
          })}
        </dl>
      </div>
    </>
  );
}
