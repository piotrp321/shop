import type { Metadata } from "next";
import { CheckoutPage } from "@/checkout/CheckoutPage";

// A personal page: nothing in it belongs in a search index.
export const metadata: Metadata = {
  title: "Kasa",
  robots: { index: false, follow: false },
};

export default function Checkout() {
  return <CheckoutPage />;
}
