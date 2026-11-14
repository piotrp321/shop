import type { PromotionStatus } from "./usePromotionList";

/** Promotions are priced in złoty only; the API sends the amounts in grosze without a currency. */
export const CURRENCY = "PLN";

/** The variants of `Badge` that are the status colours of the panel. */
type BadgeVariant = "info" | "success" | "neutral";

const statuses: Record<PromotionStatus, { label: string; variant: BadgeVariant }> = {
  planned: { label: "Zaplanowana", variant: "info" },
  active: { label: "Aktywna", variant: "success" },
  ended: { label: "Zakończona", variant: "neutral" },
};

/** The label and the `Badge` variant of a status the API reports. */
export function statusLabel(status: PromotionStatus): string {
  return statuses[status].label;
}

export function statusVariant(status: PromotionStatus): BadgeVariant {
  return statuses[status].variant;
}

// Until the form of a promotion leaves PrimeVue (the next commit).
export function statusSeverity(status: PromotionStatus): "info" | "success" | "secondary" {
  return status === "planned" ? "info" : status === "active" ? "success" : "secondary";
}

export const STATUS_FILTER_OPTIONS: { label: string; value: "all" | PromotionStatus }[] = [
  { label: "Wszystkie", value: "all" },
  { label: "Zaplanowane", value: "planned" },
  { label: "Aktywne", value: "active" },
  { label: "Zakończone", value: "ended" },
];
