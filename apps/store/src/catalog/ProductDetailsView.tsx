import type { components } from "@/api/schema";
import { AvailabilityLabel } from "./availability-label";
import { crumbsOf } from "./breadcrumb";
import { Breadcrumbs } from "./Breadcrumbs";
import { formatPrice } from "./format-price";
import { ProductGallery } from "./ProductGallery";
import { ProductPrice } from "./ProductPrice";
import { VariantChoice } from "./VariantChoice";

type ProductDetails = components["schemas"]["ProductDetails"];
type ProductOffer = components["schemas"]["ProductOfferResponse"];

/**
 * The product page: the path, then the gallery (`ProductGallery`: a strip of photos with thumbnails, a counter and the
 * enlargement) beside the data on a desktop and above it on a phone. The data: the name, the short description, and the
 * price and level of the product as the API computed them („od X zł” when the purchasable variants cost differently).
 * Below them `VariantChoice` (a Client Component) takes the choice of the variant and the „Do koszyka” button and
 * replaces the price and level with those of the chosen variant. A product without a purchasable variant
 * (`fromGross = null`) is „Niedostępny” without a price, a choice and a button. Nothing here depends on the session, so
 * the server can keep the HTML in the ISR cache.
 */
export function ProductDetailsView({ details, offer }: { details: ProductDetails; offer: ProductOffer }) {
  return (
    <main className="mx-auto flex w-full max-w-content flex-col gap-4 px-gutter pt-4 pb-16 lg:gap-7 lg:pt-7 lg:pb-28">
      <Breadcrumbs items={crumbsOf(details.breadcrumb)} />
      <div className="flex flex-col gap-7 lg:grid lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)] lg:items-start lg:gap-18">
        <ProductGallery name={details.name} images={details.images} zoomImages={details.zoomImages} />
        <div className="flex min-w-0 flex-col gap-5">
          <h1 className="text-3xl font-semibold tracking-tight lg:max-w-[14ch] lg:text-5xl">{details.name}</h1>
          {details.shortDescription !== "" && (
            <p data-testid="description" className="max-w-[44ch] text-lg text-muted-foreground">
              {details.shortDescription}
            </p>
          )}
          {offer.fromGross === null ? (
            <AvailabilityLabel level="out_of_stock" />
          ) : (
            <VariantChoice details={details} offer={offer}>
              <ProductPrice
                price={offer.hasPriceRange ? `od ${formatPrice(offer.fromGross, offer.currency)}` : formatPrice(offer.fromGross, offer.currency)}
                lowestPriorGross={offer.fromPromotion?.lowestPriorGross}
                currency={offer.currency}
                level={offer.level}
              />
            </VariantChoice>
          )}
        </div>
      </div>
    </main>
  );
}
