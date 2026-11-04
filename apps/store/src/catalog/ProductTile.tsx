import type { components } from "@/api/schema";
import { Card, CardContent } from "@/components/ui/card";
import { AvailabilityLabel } from "./availability-label";
import { formatPrice } from "./format-price";
import { ProductPhoto } from "./ProductPhoto";
import { TileOffer } from "./TileOffer";

type ProductListItem = components["schemas"]["ProductListItem"];
type ProductOffer = components["schemas"]["ProductOfferResponse"];

/**
 * A product of the list. The server renders the name, the photo, the price („od X zł” when the purchasable variants
 * cost differently) and the level of the product; the variant choice and the button are the client part (TileOffer).
 * Without an offer (the call failed) the tile has no price, level, choice or button; a product without a purchasable
 * variant is „Niedostępny” without a price, a choice or a button.
 */
export function ProductTile({ product, offer, sizes, priority }: { product: ProductListItem; offer?: ProductOffer; sizes: string; priority?: boolean }) {
  return (
    <Card className="h-full pt-0">
      <ProductPhoto image={product.image} sizes={sizes} priority={priority} />
      <CardContent className="flex flex-col gap-1">
        <h2 className="text-sm leading-snug font-medium">{product.name}</h2>
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
