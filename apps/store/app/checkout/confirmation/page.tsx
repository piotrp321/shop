import type { Metadata } from "next";
import { cookies } from "next/headers";
import Link from "next/link";
import { SESSION_COOKIE } from "@/auth/cookies";
import { readAuthConfig } from "@/auth/config";
import { logFailure } from "@/auth/responses";
import { getCartDeps } from "@/cart/deps";
import { CONFIRMATION_COOKIE, type OrderConfirmation, readOrderConfirmation } from "@/checkout/cookies";
import { listPaymentProviders, type PaymentProvider } from "@/checkout/payment-api";
import { readPaymentStatus } from "@/checkout/payment-bff";
import { PaymentConfirmation, type StatusView } from "@/checkout/PaymentConfirmation";

// A personal page: nothing in it belongs in a search index.
export const metadata: Metadata = {
  title: "Potwierdzenie zamówienia",
  robots: { index: false, follow: false },
};

async function readConfirmation(signed: string | undefined): Promise<OrderConfirmation | null> {
  if (signed === undefined) {
    return null;
  }
  try {
    return readOrderConfirmation(signed, readAuthConfig().sessionSecret);
  } catch (error) {
    logFailure("The order confirmation cannot be read", error);
    return null;
  }
}

/** The payment status of the order and the providers to pay with; a failure of either is a value, never an exception. */
async function loadPayment(confirmation: OrderConfirmation, sessionCookie: string | undefined): Promise<{ initial: StatusView; providers: PaymentProvider[] | null }> {
  try {
    const deps = await getCartDeps();
    const [read, providers] = await Promise.all([readPaymentStatus(confirmation, sessionCookie, deps), listPaymentProviders(deps.api())]);
    // A server component cannot end the session (no cookie writes while rendering): the `GET /api/checkout/payment` of
    // the page does that, should the customer log in again from here.
    return { initial: read.kind === "ok" ? { kind: "ok", status: read.status } : { kind: read.kind }, providers };
  } catch (error) {
    logFailure("The payment status cannot be loaded", error);
    return { initial: { kind: "failed" }, providers: null };
  }
}

/**
 * The page after the checkout: what the signed `store_order_confirmation` cookie says of the order just placed and what
 * the API says of its payment (the guest reads it with the access token of the cookie, a customer with the session).
 * The access token and the order id stay on the server; the page of the browser gets only the number, the e-mail and
 * the total, the status and the providers.
 */
export default async function OrderConfirmationPage() {
  const jar = await cookies();
  const confirmation = await readConfirmation(jar.get(CONFIRMATION_COOKIE)?.value);

  if (confirmation === null) {
    return (
      <main className="mx-auto flex max-w-3xl flex-col items-start gap-4 px-4 py-6">
        <p>Nie znaleźliśmy informacji o zamówieniu.</p>
        <Link href="/" className="text-sm underline underline-offset-4">
          Wróć do sklepu
        </Link>
      </main>
    );
  }

  const { initial, providers } = await loadPayment(confirmation, jar.get(SESSION_COOKIE)?.value);
  const { number, email, totalGross, currency } = confirmation;
  return <PaymentConfirmation order={{ number, email, totalGross, currency }} initial={initial} providers={providers} />;
}
