import type { PromotionStatus } from "./usePromotionList";

/** Promotions are priced in złoty only; the API sends the amounts in grosze without a currency. */
export const CURRENCY = "PLN";

const statuses: Record<PromotionStatus, { label: string; severity: "info" | "success" | "secondary" }> = {
  planned: { label: "Zaplanowana", severity: "info" },
  active: { label: "Aktywna", severity: "success" },
  ended: { label: "Zakończona", severity: "secondary" },
};

/** The label and the colour of a status the API reports. */
export function statusLabel(status: PromotionStatus): string {
  return statuses[status].label;
}

export function statusSeverity(status: PromotionStatus): "info" | "success" | "secondary" {
  return statuses[status].severity;
}

export const STATUS_FILTER_OPTIONS: { label: string; value: "all" | PromotionStatus }[] = [
  { label: "Wszystkie", value: "all" },
  { label: "Zaplanowane", value: "planned" },
  { label: "Aktywne", value: "active" },
  { label: "Zakończone", value: "ended" },
];
