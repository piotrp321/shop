import type { components } from "@/api/schema";

export type OrderStatus = components["schemas"]["BackofficeOrderStatus"];
export type OrderTransition = components["schemas"]["BackofficeOrderTransition"];
type StatusChange = components["schemas"]["BackofficeOrderStatusChange"];
type CancellationReason = components["schemas"]["BackofficeOrder"]["cancellationReason"];

export type TagSeverity = "secondary" | "info" | "success" | "warn" | "danger" | "contrast";

const statusLabels: Record<OrderStatus, { label: string; severity: TagSeverity }> = {
  new: { label: "Czeka na płatność", severity: "warn" },
  paid: { label: "Opłacone", severity: "info" },
  shipped: { label: "Wysłane", severity: "info" },
  delivered: { label: "Dostarczone", severity: "success" },
  cancelled: { label: "Anulowane", severity: "secondary" },
  returned: { label: "Zwrócone", severity: "danger" },
};

/** The label and the colour of a status of an order; a status the panel does not know is shown as the API sent it. */
export function statusLabel(status: OrderStatus): { label: string; severity: TagSeverity } {
  return statusLabels[status] ?? { label: status, severity: "secondary" };
}

/** The statuses of the filter of the order list, in the order of the lifecycle. */
export const STATUS_OPTIONS: { label: string; value: OrderStatus }[] = (Object.keys(statusLabels) as OrderStatus[]).map((status) => ({
  label: statusLabels[status].label,
  value: status,
}));

/** Who made a change of the status: the staff member by user name, else the customer or the system. */
export function actorLabel(change: Pick<StatusChange, "actorType" | "staffUsername">): string {
  switch (change.actorType) {
    case "staff":
      return change.staffUsername ?? "Pracownik";
    case "customer":
      return "Klient";
    case "system":
      return "System";
    default:
      return String(change.actorType);
  }
}

const transitionLabels: Record<OrderTransition, string> = {
  ship: "Oznacz jako wysłane",
  deliver: "Oznacz jako dostarczone",
  cancel: "Anuluj zamówienie",
  return: "Przyjmij zwrot",
};

/** The label of the button (and the title of the dialog) of a change of the status. */
export function transitionLabel(transition: OrderTransition): string {
  return transitionLabels[transition] ?? transition;
}

/** The API requires a note to cancel and to register a return; the dialog asks for it before sending. */
export function noteRequired(transition: OrderTransition): boolean {
  return transition === "cancel" || transition === "return";
}

/** Why an order was cancelled, as the API names it; null for an order that was not cancelled. */
export function cancellationReasonLabel(reason: CancellationReason): string | null {
  switch (reason) {
    case "payment_timeout":
      return "Brak płatności w terminie";
    case "staff":
      return "Anulowane przez pracownika";
    default:
      return null;
  }
}
