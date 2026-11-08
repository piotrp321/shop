import { formatDateTime, formatMoney } from "@/prices/format";
import { CURRENCY } from "@/promotions/format";
import type { DiscountCodeListItem, DiscountCodeStatus } from "./useDiscountCodeList";

type Severity = "info" | "success" | "secondary" | "warn";

const statuses: Record<DiscountCodeStatus, { label: string; severity: Severity }> = {
  disabled: { label: "Wyłączony", severity: "warn" },
  planned: { label: "Zaplanowany", severity: "info" },
  active: { label: "Aktywny", severity: "success" },
  expired: { label: "Wygasły", severity: "secondary" },
};

/** The label and the colour of a status the API reports. */
export function statusLabel(status: DiscountCodeStatus): string {
  return statuses[status].label;
}

export function statusSeverity(status: DiscountCodeStatus): Severity {
  return statuses[status].severity;
}

export const STATUS_FILTER_OPTIONS: { label: string; value: "all" | DiscountCodeStatus }[] = [
  { label: "Wszystkie", value: "all" },
  { label: "Zaplanowane", value: "planned" },
  { label: "Aktywne", value: "active" },
  { label: "Wygasłe", value: "expired" },
  { label: "Wyłączone", value: "disabled" },
];

/** The discount of a code as the API describes it: a percent, or an amount in grosze. */
export function formatDiscount(kind: DiscountCodeListItem["kind"], value: number): string {
  return kind === "percent" ? `${value}%` : formatMoney(value, CURRENCY);
}

/** The period of a code in Polish time; no end is said so. */
export function formatPeriod(startsAt: string, endsAt: string | null): string {
  return `${formatDateTime(startsAt)} – ${endsAt === null ? "bez końca" : formatDateTime(endsAt)}`;
}

/** The active uses against the limit the API reports. */
export function formatUses(activeUses: number, usageLimit: number | null): string {
  return usageLimit === null ? `${activeUses} (bez limitu)` : `${activeUses} / ${usageLimit}`;
}

/**
 * Writes an amount in grosze as the text of a złoty field ("123,45"), on integers: the division is a split of the
 * digits, not arithmetic on money, and it is the shape `parseGross` reads back.
 */
export function groszeToInput(grosze: number): string {
  return `${Math.trunc(grosze / 100)},${String(grosze % 100).padStart(2, "0")}`;
}
