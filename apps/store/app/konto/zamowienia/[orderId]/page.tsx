import type { Metadata } from "next";
import { OrderDetailPage } from "@/account/OrderDetailPage";

// A personal page: nothing in it belongs in a search index.
export const metadata: Metadata = {
  title: "Zamówienie",
  robots: { index: false, follow: false },
};

export default async function Order({ params }: { params: Promise<{ orderId: string }> }) {
  return <OrderDetailPage orderId={(await params).orderId} />;
}
