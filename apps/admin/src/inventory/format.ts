import type { components } from "@/api/schema";

type Level = "in_stock" | "low_stock" | "out_of_stock";

/** The label and the tag severity of an availability level given by the API. Presentation only. */
export function levelLabel(level: Level): { label: string; severity: "success" | "warn" | "danger" } {
  switch (level) {
    case "in_stock":
      return { label: "Dostępny", severity: "success" };
    case "low_stock":
      return { label: "Ostatnie sztuki", severity: "warn" };
    case "out_of_stock":
      return { label: "Niedostępny", severity: "danger" };
  }
}

type MovementType = components["schemas"]["StockHistoryItemResponse"]["type"];
type MovementReason = NonNullable<components["schemas"]["StockHistoryItemResponse"]["reason"]>;
type MovementSource = components["schemas"]["StockHistoryItemResponse"]["source"];

const typeLabels: Record<MovementType, string> = {
  receipt: "Przyjęcie",
  correction: "Korekta",
  reservation: "Rezerwacja",
  release: "Zwolnienie",
  commit: "Zatwierdzenie",
};

const reasonLabels: Record<MovementReason, string> = {
  inventory_count: "Inwentaryzacja",
  damage: "Uszkodzenie",
  loss: "Zaginięcie",
  other: "Inne",
};

const sourceLabels: Record<MovementSource, string> = {
  staff: "Pracownik",
  seed: "Dane demo",
  system: "System",
};

// A value the panel does not know (the API grew) is shown as it came rather than hiding the entry.

/** Names what happened in a history entry. */
export function movementTypeLabel(type: MovementType): string {
  return typeLabels[type] ?? type;
}

/** Names the reason of a correction; an entry without a reason shows a dash. */
export function movementReasonLabel(reason: MovementReason | null): string {
  return reason === null ? "—" : (reasonLabels[reason] ?? reason);
}

/** Names what made a stock change. */
export function movementSourceLabel(source: MovementSource): string {
  return sourceLabels[source] ?? source;
}

/** Shows a change of quantity from the API with its sign. */
export function formatDelta(delta: number): string {
  if (delta > 0) {
    return `+${delta}`;
  }
  return delta < 0 ? `−${-delta}` : "0";
}
