import Link from "next/link";
import type { components } from "@/api/schema";
import { Card, CardContent } from "@/components/ui/card";
import { AvailabilityLabel } from "./availability-label";
import { formatPrice } from "./format-price";
import { ProductPhoto } from "./ProductPhoto";
import { TileOffer } from "./TileOffer";

type ProductListItem = components["schemas"]["ProductListItem"];
type ProductOffer = components["schemas"]["ProductOfferResponse"];

/**
 * A product of the list. The name and the photo link to the product page (`/p/<slug>`). The server renders the name, the photo, the price („od X zł” when the purchasable variants
 * cost differently) and the level of the product; the variant choice and the button are the client part (TileOffer).
 * Without an offer (the call failed) the tile has no price, level, choice or button; a product without a purchasable
 * variant is „Niedostępny” without a price, a choice or a button.
 */
export function ProductTile({ product, offer, sizes, priority }: { product: ProductListItem; offer?: ProductOffer; sizes: string; priority?: boolean }) {
  const href = `/p/${encodeURIComponent(product.slug)}`;
  return (
    <Card className="h-full pt-0">
      {/* The name is the link for keyboards and screen readers; the photo is the same link for the pointer. */}
      <Link href={href} tabIndex={-1} aria-hidden="true" className="block">
        <ProductPhoto image={product.image} sizes={sizes} priority={priority} />
      </Link>
      <CardContent className="flex flex-col gap-1">
        <h2 className="text-sm leading-snug font-medium">
          <Link href={href} className="underline-offset-4 hover:underline">
            {product.name}
          </Link>
        </h2>
        {offer === undefined ? (
          <p data-testid="price" className="text-sm text-muted-foreground">
            Cena niedostępna
          </p>
        ) : offer.fromGross === null ? (
          <AvailabilityLabel level="out_of_stock" />
        ) : (
          <TileOffer offer={offer}>
            <p data-testid="price" className="text-sm font-semibold">
              {offer.hasPriceRange ? `od ${formatPrice(offer.fromGross, offer.currency)}` : formatPrice(offer.fromGross, offer.currency)}
            </p>
            <AvailabilityLabel level={offer.level} />
          </TileOffer>
        )}
      </CardContent>
    </Card>
  );
}
