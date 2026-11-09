import type { components } from "@/api/schema";
import { cn } from "@/lib/utils";

type Level = components["schemas"]["ProductOfferResponse"]["level"];

// The level is decided by the API; the store only names it. The colour comes from the semantic tokens and never alone:
// the marker has a shape of its own (disc, diamond, ring) and the text says the same.
const AVAILABILITY_LABELS: Record<Level, { text: string; className: string; markerClassName: string }> = {
  in_stock: { text: "Dostępny", className: "text-success", markerClassName: "rounded-full bg-current" },
  low_stock: { text: "Ostatnie sztuki", className: "text-warning", markerClassName: "rotate-45 rounded-sm bg-current" },
  out_of_stock: { text: "Niedostępny", className: "text-muted-foreground", markerClassName: "rounded-full border-2 border-current" },
};

/** The text of the level, as the label shows it. */
export function availabilityText(level: Level): string {
  return AVAILABILITY_LABELS[level].text;
}

export function AvailabilityLabel({ level }: { level: Level }) {
  const label = AVAILABILITY_LABELS[level];
  return (
    <p data-testid="availability" className={cn("flex items-center gap-1.75 text-xs leading-snug font-semibold", label.className)}>
      <span aria-hidden="true" className={cn("size-2.25 shrink-0", label.markerClassName)} />
      {label.text}
    </p>
  );
}
