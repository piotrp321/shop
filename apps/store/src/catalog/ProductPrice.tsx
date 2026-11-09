import type { components } from "@/api/schema";
import { AvailabilityLabel } from "./availability-label";
import { PromotionNotice } from "./PromotionNotice";

type Level = components["schemas"]["ProductOfferResponse"]["level"];

/**
 * The price block of the product page: the price as the API computed it (already formatted by the caller), the promotion
 * notice with the Omnibus line (shown only with `lowestPriorGross`) and the level. One place for the product and for the
 * chosen variant, so both look the same.
 */
export function ProductPrice({ price, lowestPriorGross, currency, level }: { price: string; lowestPriorGross: number | null | undefined; currency: string; level: Level }) {
  return (
    <div className="flex flex-col gap-1.5">
      <p data-testid="price" className="text-2xl font-semibold tracking-tight lg:text-4xl">
        {price}
      </p>
      <PromotionNotice lowestPriorGross={lowestPriorGross} currency={currency} />
      <AvailabilityLabel level={level} />
    </div>
  );
}
