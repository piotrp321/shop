import Link from "next/link";
import type { components } from "@/api/schema";
import { AvailabilityLabel } from "./availability-label";
import { catalogHref } from "./catalog-href";
import { formatPrice } from "./format-price";
import { ProductPhoto } from "./ProductPhoto";
import { VariantChoice } from "./VariantChoice";

type ProductDetails = components["schemas"]["ProductDetails"];
type ProductOffer = components["schemas"]["ProductOfferResponse"];

// One column of photos from `md` (768px) up, half of the content width of at most 72rem; the whole width below.
const PHOTO_SIZES = "(min-width: 1152px) 560px, (min-width: 768px) 50vw, 100vw";

/**
 * The product page: the path, the name, the photos one under the other (the first is the main one), the short
 * description, and the price and level of the product as the API computed them („od X zł” when the purchasable
 * variants cost differently). Below them `VariantChoice` (a Client Component) takes the choice of the variant and the
 * „Do koszyka” button and replaces the price and level with those of the chosen variant. A product without a purchasable
 * variant (`fromGross = null`) is „Niedostępny” without a price, a choice and a button. Nothing here depends on the
 * session, so the server can keep the HTML in the ISR cache.
 */
export function ProductDetailsView({ details, offer }: { details: ProductDetails; offer: ProductOffer }) {
  return (
    <main className="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-6">
      <nav aria-label="Ścieżka">
        <ol className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground">
          <li>
            <Link href={catalogHref({})} className="underline-offset-4 hover:underline">
              Wszystkie produkty
            </Link>
          </li>
          {details.breadcrumb.map((category) => (
            <li key={category.id} className="flex items-center gap-x-2">
              <span aria-hidden="true">›</span>
              <Link href={catalogHref({ category: category.id })} className="underline-offset-4 hover:underline">
                {category.name}
              </Link>
            </li>
          ))}
        </ol>
      </nav>
      <div className="grid gap-8 md:grid-cols-2">
        <div className="flex flex-col gap-4 md:col-start-2 md:row-start-1">
          <h1 className="text-2xl font-semibold">{details.name}</h1>
          {details.shortDescription !== "" && (
            <p data-testid="description" className="text-muted-foreground">
              {details.shortDescription}
            </p>
          )}
          {offer.fromGross === null ? (
            <AvailabilityLabel level="out_of_stock" />
          ) : (
            <VariantChoice details={details} offer={offer}>
              <div className="flex flex-col gap-1">
                <p data-testid="price" className="text-xl font-semibold">
                  {offer.hasPriceRange ? `od ${formatPrice(offer.fromGross, offer.currency)}` : formatPrice(offer.fromGross, offer.currency)}
                </p>
                <AvailabilityLabel level={offer.level} />
              </div>
            </VariantChoice>
          )}
        </div>
        <div className="flex flex-col gap-4 md:col-start-1 md:row-start-1">
          {details.images.length === 0 ? (
            <ProductPhoto image={null} sizes={PHOTO_SIZES} />
          ) : (
            details.images.map((image, index) => (
              // The photos have no ids and never reorder in the page, so the position is the key.
              <ProductPhoto key={index} image={image} sizes={PHOTO_SIZES} priority={index === 0} />
            ))
          )}
        </div>
      </div>
    </main>
  );
}
