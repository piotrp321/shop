import type { Metadata } from "next";
import { cookies } from "next/headers";
import Link from "next/link";
import { readAuthConfig } from "@/auth/config";
import { logFailure } from "@/auth/responses";
import { formatPrice } from "@/catalog/format-price";
import { CONFIRMATION_COOKIE, type OrderConfirmation, readOrderConfirmation } from "@/checkout/cookies";

// A personal page: nothing in it belongs in a search index.
export const metadata: Metadata = {
  title: "Potwierdzenie zamówienia",
  robots: { index: false, follow: false },
};

async function readConfirmation(): Promise<OrderConfirmation | null> {
  const signed = (await cookies()).get(CONFIRMATION_COOKIE)?.value;
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

/**
 * What the checkout put in the signed `store_order_confirmation` cookie: the number, the e-mail and the gross total of
 * the order just placed. The store keeps nothing else of the order; the details are in the e-mail.
 */
export default async function OrderConfirmationPage() {
  const confirmation = await readConfirmation();
  const backToStore = (
    <Link href="/" className="text-sm underline underline-offset-4">
      Wróć do sklepu
    </Link>
  );

  if (confirmation === null) {
    return (
      <main className="mx-auto flex max-w-3xl flex-col items-start gap-4 px-4 py-6">
        <p>Nie znaleźliśmy informacji o zamówieniu.</p>
        {backToStore}
      </main>
    );
  }

  return (
    <main className="mx-auto flex max-w-3xl flex-col items-start gap-4 px-4 py-6">
      <h1 className="text-2xl font-semibold">Dziękujemy za zamówienie</h1>
      <p>
        Numer zamówienia: <strong data-testid="order-number">{confirmation.number}</strong>
      </p>
      <p>Potwierdzenie wysłaliśmy na adres {confirmation.email}.</p>
      <p>
        Kwota brutto: <strong data-testid="order-total">{formatPrice(confirmation.totalGross, confirmation.currency)}</strong>
      </p>
      {backToStore}
    </main>
  );
}
