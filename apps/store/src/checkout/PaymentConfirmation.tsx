"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { getPaymentStatus, type PaymentStatusReadResult, type PaymentStatusView, startPayment } from "@/api/client";
import { SessionExpired } from "@/cart/messages";
import { formatPrice } from "@/catalog/format-price";
import { Button } from "@/components/ui/button";
import { formatDeadlineTime } from "./format-time";
import { navigateTo } from "./navigate";
import type { PaymentProvider } from "./payment-api";

/** What the page knows of the order's payment: the status of the API, or why there is none. */
export type StatusView = { kind: "ok"; status: PaymentStatusView } | { kind: "failed" } | { kind: "unauthorized" } | { kind: "not_found" };

/** What the checkout cookie says of the order (the access token stays on the server). */
export type PlacedOrder = { number: string; email: string; totalGross: number; currency: string };

type Phase = "paid" | "cancelled" | "after_deadline" | "pending" | "retry";

const POLL_INTERVAL_MS = 2_000;
const POLL_WINDOW_MS = 30_000;
const CONFIRMATION_PATH = "/checkout/confirmation";

const CHOOSE_PROVIDER = "Wybierz sposób płatności.";
const START_FAILED = "Nie udało się rozpocząć płatności. Spróbuj ponownie.";
const READ_FAILED = "Nie udało się sprawdzić statusu płatności.";
const NOT_FOUND = "Nie znaleźliśmy zamówienia.";
const PROVIDER_PREFIX = "payment-provider-";
const PROVIDER_ERROR_ID = "payment-provider-error";
const MESSAGE_ID = "payment-message";
const HEADING_ID = "payment-heading";

/**
 * The phase is told by the API: `canStartPayment` is false for a new order only after its deadline (before the
 * cancellation), so the browser's clock decides nothing.
 */
function phaseOf(status: PaymentStatusView): Phase {
  if (status.orderStatus === "paid") return "paid";
  if (status.orderStatus === "cancelled") return "cancelled";
  if (!status.canStartPayment) return "after_deadline";
  return status.latestPayment?.status === "pending" ? "pending" : "retry";
}

/** A payment is awaited (the order may still be paid by a webhook that has not come yet). */
const isAwaited = (view: StatusView) => view.kind === "ok" && (phaseOf(view.status) === "pending" || phaseOf(view.status) === "after_deadline");

/** The view a read leads to; a failed read leads to none (what was shown stays). */
function viewOf(result: PaymentStatusReadResult): StatusView | null {
  switch (result.status) {
    case "ok":
      return { kind: "ok", status: result.data };
    case "not_found":
      return { kind: "not_found" };
    case "unauthorized":
      return { kind: "unauthorized" };
    case "failed":
      return null;
  }
}

/**
 * The confirmation page of an order: what became of its payment, from the status the server read (`initial`) and
 * refreshed through `/api/checkout/payment`. A pending payment is awaited with a refresh every 2 seconds for 30 seconds;
 * after that the customer can check again, go back to the payment of the provider or pay another way. All texts and
 * states follow the API; the browser's clock is not used.
 */
export function PaymentConfirmation({ order, initial, providers }: { order: PlacedOrder; initial: StatusView; providers: PaymentProvider[] | null }) {
  const [view, setView] = useState<StatusView>(initial);
  // The 30 seconds of refreshing: closed when they pass, opened again (new `windowId`) by an action that waits for the
  // payment once more.
  const [windowOpen, setWindowOpen] = useState(true);
  const [windowId, setWindowId] = useState(0);
  const [provider, setProvider] = useState<string | null>(null);
  const [providerError, setProviderError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [attention, setAttention] = useState<{ id: string; seq: number } | null>(null);
  // The key of one payment attempt, kept for a retry of an uncertain result with the same provider.
  const attempt = useRef<{ provider: string; key: string } | null>(null);
  // Only the newest read may change the page.
  const newestRead = useRef(0);

  const awaited = isAwaited(view);

  useEffect(() => {
    if (!awaited || !windowOpen) return;
    const interval = setInterval(() => {
      const read = ++newestRead.current;
      void getPaymentStatus().then((result) => {
        const next = viewOf(result);
        if (read === newestRead.current && next !== null) setView(next);
      });
    }, POLL_INTERVAL_MS);
    const stop = setTimeout(() => setWindowOpen(false), POLL_WINDOW_MS);
    return () => {
      clearInterval(interval);
      clearTimeout(stop);
    };
  }, [awaited, windowOpen, windowId]);

  useEffect(() => {
    if (attention) document.getElementById(attention.id)?.focus();
  }, [attention]);

  const focus = (id: string) => setAttention((current) => ({ id, seq: (current?.seq ?? 0) + 1 }));
  const firstProviderId = providers?.length ? `${PROVIDER_PREFIX}${providers[0].code}` : MESSAGE_ID;

  /** Reads the status now. The page shows the answer, except for a failed read, which leaves the page as it was. */
  async function refresh(): Promise<StatusView | null> {
    const read = ++newestRead.current;
    const next = viewOf(await getPaymentStatus());
    if (read === newestRead.current && next !== null) setView(next);
    return next;
  }

  async function checkAgain() {
    if (busy) return;
    setBusy(true);
    setMessage(null);
    const next = await refresh();
    if (next === null) {
      // A page that already has a status keeps it; one that has none says so.
      setMessage(view.kind === "ok" ? READ_FAILED : null);
    }
    setBusy(false);
  }

  async function pay() {
    if (busy) return;
    if (provider === null) {
      setProviderError(CHOOSE_PROVIDER);
      focus(firstProviderId);
      return;
    }
    const key = attempt.current?.provider === provider ? attempt.current.key : crypto.randomUUID();
    attempt.current = { provider, key };
    setBusy(true);
    setMessage(null);
    setProviderError(null);
    const result = await startPayment(provider, key);
    let attentionTarget = MESSAGE_ID;
    switch (result.status) {
      case "ok":
        attempt.current = null;
        if (result.redirectUrl !== null) {
          // The page of the provider is on its way: the button stays locked.
          navigateTo(result.redirectUrl);
          return;
        }
        await refresh();
        // No message to point at: the page itself changed, so the focus goes to its heading.
        attentionTarget = HEADING_ID;
        break;
      case "conflict":
        // Only `reason` (told by the type of the problem) decides; `unknown` is an uncertain result like a failure.
        if (result.reason === "unknown") {
          setMessage(START_FAILED);
          break;
        }
        attempt.current = null;
        if (result.reason === "in_progress") {
          setWindowOpen(true);
          setWindowId((id) => id + 1);
        }
        if (result.reason === "key_reused") {
          setMessage(result.detail || START_FAILED);
          break;
        }
        {
          // The order changed under the customer: what the API says now is the answer; the text only when it still
          // helps (a paid or cancelled order says it itself).
          const next = await refresh();
          const settled = next?.kind === "ok" && (phaseOf(next.status) === "paid" || phaseOf(next.status) === "cancelled");
          setMessage(settled ? null : result.detail || START_FAILED);
        }
        break;
      case "not_found":
        attempt.current = null;
        setView({ kind: "not_found" });
        break;
      case "unauthorized":
        attempt.current = null;
        setView({ kind: "unauthorized" });
        break;
      case "invalid": {
        attempt.current = null;
        const forProvider = result.violations.find((violation) => violation.propertyPath === "provider");
        const others = result.violations.filter((violation) => violation.propertyPath !== "provider").map((violation) => violation.title);
        if (forProvider) {
          setProviderError(forProvider.title);
          attentionTarget = firstProviderId;
        }
        if (others.length > 0 || !forProvider) {
          setMessage(others.length > 0 ? others.join(" ") : result.detail || START_FAILED);
          attentionTarget = MESSAGE_ID;
        }
        break;
      }
      case "failed":
        setMessage(START_FAILED);
        break;
    }
    setBusy(false);
    focus(attentionTarget);
  }

  const status = view.kind === "ok" ? view.status : null;
  const phase = status ? phaseOf(status) : null;
  const total = status ? { gross: status.totalGross, currency: status.currency } : { gross: order.totalGross, currency: order.currency };
  const showsFacts = view.kind === "ok" || view.kind === "failed";
  const closed = phase === "cancelled" || view.kind === "unauthorized" || view.kind === "not_found";

  const payForm = (label: string) => (
    <PayForm
      providers={providers}
      value={provider}
      error={providerError}
      busy={busy}
      label={label}
      onChange={(code) => {
        setProvider(code);
        setProviderError(null);
      }}
      onPay={() => void pay()}
    />
  );
  const checkButton = (
    <Button type="button" variant="outline" disabled={busy} onClick={() => void checkAgain()}>
      Sprawdź ponownie
    </Button>
  );

  return (
    <main className="mx-auto flex max-w-3xl flex-col items-start gap-4 px-4 py-6">
      <h1 id={HEADING_ID} tabIndex={-1} className="text-2xl font-semibold">{closed ? "Zamówienie" : "Dziękujemy za zamówienie"}</h1>
      {showsFacts ? (
        <>
          <p>
            Numer zamówienia: <strong data-testid="order-number">{status?.orderNumber ?? order.number}</strong>
          </p>
          <p>
            Kwota brutto: <strong data-testid="order-total">{formatPrice(total.gross, total.currency)}</strong>
          </p>
        </>
      ) : null}
      {message ? (
        <p id={MESSAGE_ID} role="alert" tabIndex={-1} className="text-destructive">
          {message}
        </p>
      ) : null}

      {view.kind === "failed" ? (
        <div className="flex flex-col items-start gap-2">
          <p>{READ_FAILED}</p>
          {checkButton}
        </div>
      ) : null}
      {view.kind === "unauthorized" ? (
        <p id={MESSAGE_ID} role="alert" tabIndex={-1}>
          <SessionExpired returnTo={CONFIRMATION_PATH} />
        </p>
      ) : null}
      {view.kind === "not_found" ? (
        <p id={MESSAGE_ID} role="alert" tabIndex={-1}>
          {NOT_FOUND}
        </p>
      ) : null}

      {status && phase === "paid" ? <p>Płatność przyjęta. Potwierdzenie wysłaliśmy na adres {order.email}.</p> : null}
      {status && phase === "cancelled" ? <p>Zamówienie zostało anulowane.</p> : null}
      {status && phase === "pending" ? (
        <>
          <p>Opłać do {formatDeadlineTime(status.paymentDeadline)}</p>
          <p>Czekamy na potwierdzenie płatności…</p>
          {status.latestPayment?.resumeUrl ? (
            <a href={status.latestPayment.resumeUrl} className="text-sm underline underline-offset-4">
              Wróć do płatności
            </a>
          ) : null}
          {windowOpen ? null : (
            <div className="flex flex-col items-start gap-3">
              <p>Nie mamy jeszcze potwierdzenia płatności.</p>
              {checkButton}
              {payForm("Zapłać innym sposobem")}
            </div>
          )}
        </>
      ) : null}
      {status && phase === "after_deadline" ? (
        <>
          <p>Minął termin płatności. Jeśli zapłaciłeś, czekamy na potwierdzenie; w przeciwnym razie zamówienie zostanie anulowane.</p>
          {windowOpen ? null : checkButton}
        </>
      ) : null}
      {status && phase === "retry" ? (
        <>
          <p>Opłać do {formatDeadlineTime(status.paymentDeadline)}</p>
          {status.latestPayment?.status === "failed" ? <p>Płatność nie powiodła się.</p> : null}
          {payForm("Zapłać")}
        </>
      ) : null}

      <Link href="/" className="text-sm underline underline-offset-4">
        Wróć do sklepu
      </Link>
    </main>
  );
}

function PayForm({
  providers,
  value,
  error,
  busy,
  label,
  onChange,
  onPay,
}: {
  providers: PaymentProvider[] | null;
  value: string | null;
  error: string | null;
  busy: boolean;
  label: string;
  onChange: (code: string) => void;
  onPay: () => void;
}) {
  if (providers === null || providers.length === 0) {
    return (
      <p role="status" className="text-sm text-muted-foreground">
        Płatności są chwilowo niedostępne. Spróbuj za chwilę.
      </p>
    );
  }
  return (
    <div className="flex flex-col items-start gap-3">
      <div
        role="radiogroup"
        aria-labelledby="payment-provider-label"
        aria-required="true"
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? PROVIDER_ERROR_ID : undefined}
        className="flex flex-col gap-2"
      >
        <span id="payment-provider-label" className="text-sm font-medium">
          Sposób płatności
        </span>
        {providers.map(({ code, name }) => (
          <label key={code} htmlFor={`${PROVIDER_PREFIX}${code}`} className="flex items-center gap-2 text-sm">
            <input
              id={`${PROVIDER_PREFIX}${code}`}
              type="radio"
              name="payment-provider"
              value={code}
              checked={value === code}
              onChange={() => onChange(code)}
              className="accent-primary"
            />
            {name}
          </label>
        ))}
        {error ? (
          <p id={PROVIDER_ERROR_ID} className="text-sm text-destructive">
            {error}
          </p>
        ) : null}
      </div>
      <Button type="button" disabled={busy} onClick={onPay}>
        {label}
      </Button>
    </div>
  );
}
