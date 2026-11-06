import type { Metadata } from "next";
import { cookies } from "next/headers";
import { logFailure } from "@/auth/responses";
import { getCartDeps } from "@/cart/deps";
import { CheckoutPage } from "@/checkout/CheckoutPage";
import { CONFIRMATION_COOKIE, readOrderConfirmation } from "@/checkout/cookies";
import { listPaymentProviders, type PaymentProvider } from "@/checkout/payment-api";

// A personal page: nothing in it belongs in a search index.
export const metadata: Metadata = {
  title: "Kasa",
  robots: { index: false, follow: false },
};

/** The providers of the API, or null when the list or the store's configuration is not available. */
async function loadProviders(): Promise<{ providers: PaymentProvider[] | null; pendingOrderNumber: string | null }> {
  try {
    const { api, config } = await getCartDeps();
    const signed = (await cookies()).get(CONFIRMATION_COOKIE)?.value;
    return {
      providers: await listPaymentProviders(api()),
      pendingOrderNumber: readOrderConfirmation(signed, config.sessionSecret)?.number ?? null,
    };
  } catch (error) {
    logFailure("The checkout cannot load the payment providers", error);
    return { providers: null, pendingOrderNumber: null };
  }
}

/**
 * The server reads what the page cannot: the payment providers of the API (a public list, read on every request: the
 * page is dynamic because it reads cookies) and the number of the order of the signed `store_order_confirmation`
 * cookie, which an empty cart turns into a link to pay it.
 */
export default async function Checkout() {
  return <CheckoutPage {...await loadProviders()} />;
}
