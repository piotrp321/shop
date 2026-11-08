import type { CustomerOrderStatus } from "@/api/client";
import { formatDeadlineTime } from "@/checkout/format-time";

/**
 * What an order waiting for its payment says of the term, from what the API computed (`paymentDeadlinePassed`): the
 * browser's clock decides nothing. Nothing for an order in any other status.
 */
export function OrderPaymentNote({ status, paymentDeadline, paymentDeadlinePassed }: { status: CustomerOrderStatus; paymentDeadline: string; paymentDeadlinePassed: boolean }) {
  if (status !== "new") {
    return null;
  }
  return <p className="text-sm text-muted-foreground">{paymentDeadlinePassed ? "Termin płatności minął" : `Czeka na płatność do ${formatDeadlineTime(paymentDeadline)}`}</p>;
}
