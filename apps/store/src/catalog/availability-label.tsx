import type { components } from "@/api/schema";

type Level = components["schemas"]["ProductOfferResponse"]["level"];

// The level is decided by the API; the store only names it.
const AVAILABILITY_LABELS: Record<Level, { text: string; className: string }> = {
  in_stock: { text: "Dostępny", className: "text-primary" },
  low_stock: { text: "Ostatnie sztuki", className: "text-destructive" },
  out_of_stock: { text: "Niedostępny", className: "text-muted-foreground" },
};

export function AvailabilityLabel({ level }: { level: Level }) {
  return (
    <p data-testid="availability" className={`text-xs font-medium ${AVAILABILITY_LABELS[level].className}`}>
      {AVAILABILITY_LABELS[level].text}
    </p>
  );
}
