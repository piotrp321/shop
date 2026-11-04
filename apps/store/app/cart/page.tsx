import type { Metadata } from "next";
import { CartPage } from "@/cart/CartPage";

// A personal page: nothing in it belongs in a search index.
export const metadata: Metadata = {
  title: "Koszyk",
  robots: { index: false, follow: false },
};

export default function Cart() {
  return <CartPage />;
}
