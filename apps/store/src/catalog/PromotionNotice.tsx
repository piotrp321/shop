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
    <div className={`flex flex-col items-start gap-1 ${className ?? ""}`}>
      <span data-testid="promotion-badge" className="rounded-sm bg-primary px-1.5 py-0.5 text-xs font-semibold text-primary-foreground">
        Promocja
      </span>
      <p data-testid="omnibus" className="text-xs text-muted-foreground">
        Najniższa cena z 30 dni przed obniżką: {formatPrice(lowestPriorGross, currency)}
      </p>
    </div>
  );
}
