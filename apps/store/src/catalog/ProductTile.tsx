import Link from "next/link";
import type { components } from "@/api/schema";
import { cn } from "@/lib/utils";
import { AvailabilityLabel } from "./availability-label";
import { formatPrice } from "./format-price";
import { ProductPhoto } from "./ProductPhoto";
import { PromotionNotice } from "./PromotionNotice";
import { TileOffer } from "./TileOffer";

type ProductListItem = components["schemas"]["ProductListItem"];
type ProductOffer = components["schemas"]["ProductOfferResponse"];

/**
 * A product of the list: no frame, the photo over the whole width of the tile and the text under it. The name and the
 * photo link to the product page (`/p/<slug>`). The server renders the name, the photo, the price („od X zł” when the
 * purchasable variants cost differently; the effective one, with the badge „Promocja” and the Omnibus line when the variant
 * of that price is in a promotion that has the Omnibus price) and the level of the product; the variant choice and the
 * button are the client part (TileOffer). Without an offer (the call failed) the tile has no price, level, choice or
 * button; a product without a purchasable variant is „Niedostępny” without a price, a choice or a button, and its photo
 * is faded.
 */
export function ProductTile({
  product,
  offer,
  sizes,
  priority,
  headingLevel: Heading = "h2",
}: {
  product: ProductListItem;
  offer?: ProductOffer;
  sizes: string;
  priority?: boolean;
  /** `h3` under the „Wszystkie produkty” h2 of the home page, `h2` under the h1 of every other listing. */
  headingLevel?: "h2" | "h3";
}) {
  const href = `/p/${encodeURIComponent(product.slug)}`;
  const unavailable = offer !== undefined && offer.fromGross === null;
  return (
    <div className="flex h-full flex-col">
      {/* The name is the link for keyboards and screen readers; the photo is the same link for the pointer. */}
      <Link href={href} tabIndex={-1} aria-hidden="true" className="block">
        <ProductPhoto image={product.image} sizes={sizes} priority={priority} className={cn("rounded-md", unavailable && "opacity-60 grayscale")} />
      </Link>
      <div className="flex flex-1 flex-col gap-1.5 pt-3">
        <Heading className="text-ui leading-snug font-medium lg:text-base">
          <Link href={href} className="underline-offset-4 hover:underline">
            {product.name}
          </Link>
        </Heading>
        {offer === undefined ? (
          <p data-testid="price" className="text-sm text-muted-foreground">
            Cena niedostępna
          </p>
        ) : offer.fromGross === null ? (
          <AvailabilityLabel level="out_of_stock" />
        ) : (
          <TileOffer offer={offer}>
            <p data-testid="price" className="text-lg leading-snug font-semibold">
              {offer.hasPriceRange ? `od ${formatPrice(offer.fromGross, offer.currency)}` : formatPrice(offer.fromGross, offer.currency)}
            </p>
            <PromotionNotice lowestPriorGross={offer.fromPromotion?.lowestPriorGross} currency={offer.currency} />
            <AvailabilityLabel level={offer.level} />
          </TileOffer>
        )}
      </div>
    </div>
  );
}
