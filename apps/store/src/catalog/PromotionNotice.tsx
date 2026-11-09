import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { formatPrice } from "./format-price";

/**
 * The badge „Promocja” and the line with the lowest price of the 30 days before the reduction (the Omnibus price), always
 * together: a promotion without that price (`lowestPriorGross` is null or absent) shows neither. The text carries the
 * meaning, the colour only emphasises it. Both values come from the API; there is no regular price to strike out.
 */
export function PromotionNotice({ lowestPriorGross, currency, className }: { lowestPriorGross: number | null | undefined; currency: string; className?: string }) {
  if (lowestPriorGross === null || lowestPriorGross === undefined) {
    return null;
  }
  return (
    <div className={cn("flex flex-col items-start gap-1", className)}>
      <Badge data-testid="promotion-badge">Promocja</Badge>
      <p data-testid="omnibus" className="text-xs text-muted-foreground">
        Najniższa cena z 30 dni przed obniżką: {formatPrice(lowestPriorGross, currency)}
      </p>
    </div>
  );
}
