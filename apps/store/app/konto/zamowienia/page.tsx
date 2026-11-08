import type { Metadata } from "next";
import { OrdersPage } from "@/account/OrdersPage";

// A personal page: nothing in it belongs in a search index.
export const metadata: Metadata = {
  title: "Moje zamówienia",
  robots: { index: false, follow: false },
};

export default function Orders() {
  return <OrdersPage />;
}
