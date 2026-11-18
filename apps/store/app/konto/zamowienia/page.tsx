import type { Metadata } from "next";
import { OrdersPage } from "@/account/OrdersPage";

// Prerendered at build time, when no API answers: the HTML of the image has the header without the menu. The tag
// `catalog` of the tree read (purge-caches after a deployment) or, after a restart of the store, this hour at most
// brings the menu back.
export const revalidate = 3600;

// A personal page: nothing in it belongs in a search index.
export const metadata: Metadata = {
  title: "Moje zamówienia",
  robots: { index: false, follow: false },
};

export default function Orders() {
  return <OrdersPage />;
}
