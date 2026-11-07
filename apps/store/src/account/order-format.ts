import type { CustomerOrderStatus } from "@/api/client";
import { formatDeadlineTime } from "@/checkout/format-time";

/** The labels of the statuses of an order, the same as in the panel. */
export const ORDER_STATUS_LABELS: Record<CustomerOrderStatus, string> = {
  new: "Czeka na płatność",
  paid: "Opłacone",
  shipped: "Wysłane",
  delivered: "Dostarczone",
  cancelled: "Anulowane",
  returned: "Zwrócone",
};

const warsawDate = new Intl.DateTimeFormat("pl-PL", { timeZone: "Europe/Warsaw", day: "numeric", month: "long", year: "numeric" });

/**
 * The date and the time of a moment from the API (RFC 3339, usually UTC) in Warsaw, e.g. "5 listopada 2026, 14:35". The
 * zone is fixed, so the server and the browser of a customer abroad show the same text.
 */
export function formatOrderMoment(iso: string): string {
  const moment = new Date(iso);
  return Number.isNaN(moment.getTime()) ? "" : `${warsawDate.format(moment)}, ${formatDeadlineTime(iso)}`;
}

/** Where the login takes the customer back to. The login checks the path again (`sanitizeReturnTo`). */
export const loginHref = (returnTo: string) => `/auth/login?returnTo=${encodeURIComponent(returnTo)}`;

export const LOAD_FAILED = "Nie udało się wczytać zamówień. Spróbuj ponownie.";
export const HISTORY_PATH = "/konto/zamowienia";
