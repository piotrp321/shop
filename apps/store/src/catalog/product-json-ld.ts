import type { components } from "@/api/schema";
import { catalogHref } from "./catalog-href";
import { largestVariant } from "./image-props";
import { productUrl } from "./product-metadata";

type ProductDetails = components["schemas"]["ProductDetails"];
type ProductOffer = components["schemas"]["ProductOfferResponse"];
type Level = ProductOffer["level"];

const AVAILABILITY: Record<Level, string> = {
  in_stock: "https://schema.org/InStock",
  low_stock: "https://schema.org/LimitedAvailability",
  out_of_stock: "https://schema.org/OutOfStock",
};

/** Structured data of the product (schema.org `Product`): an `Offer` for each variant that has a price. */
export function buildProductJsonLd(details: ProductDetails, offer: ProductOffer, storeUrl: string) {
  const url = productUrl(details.slug, storeUrl);
  const images = details.images.flatMap((image) => {
    const largest = largestVariant(image);
    return largest === null ? [] : [largest.url];
  });
  return {
    "@context": "https://schema.org",
    "@type": "Product",
    name: details.name,
    ...(details.shortDescription === "" ? {} : { description: details.shortDescription }),
    ...(images.length === 0 ? {} : { image: images }),
    url,
    offers: offer.variants.flatMap((variant) =>
      variant.gross === null
        ? []
        : [
            {
              "@type": "Offer",
              ...(variant.label === "" ? {} : { name: variant.label }),
              // Grosze from the API written as zloty with a dot; the amount itself is not computed here.
              price: (variant.gross / 100).toFixed(2),
              priceCurrency: offer.currency,
              availability: AVAILABILITY[variant.level],
              url,
            },
          ],
    ),
  };
}

/** The path of the product (schema.org `BreadcrumbList`): All products and the categories, with absolute addresses. */
export function buildBreadcrumbJsonLd(details: ProductDetails, storeUrl: string) {
  const absolute = (href: string) => new URL(href, storeUrl).toString();
  return {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: [
      { name: "Wszystkie produkty", href: catalogHref({}) },
      ...details.breadcrumb.map((category) => ({ name: category.name, href: catalogHref({ category: category.id }) })),
    ].map(({ name, href }, index) => ({ "@type": "ListItem", position: index + 1, name, item: absolute(href) })),
  };
}

/** JSON for a `<script type="application/ld+json">`: every `<` becomes a Unicode escape, so no text can close the element. */
export function serializeJsonLd(data: unknown): string {
  return JSON.stringify(data).replace(/</g, "\\u003c");
}
