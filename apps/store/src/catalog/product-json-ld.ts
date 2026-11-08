import type { components } from "@/api/schema";
import { buildBreadcrumbListJsonLd, crumbsOf } from "./breadcrumb";
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

const WARSAW_DAY = new Intl.DateTimeFormat("en-CA", { timeZone: "Europe/Warsaw", year: "numeric", month: "2-digit", day: "2-digit" });

/**
 * The last day of a promotion: the Warsaw calendar date (`YYYY-MM-DD`) of the moment one second before `endsAt`, because
 * the promotion lasts until before its end (an end at 00:00 means the previous day). Only the date is formatted here.
 */
function lastDayOfPromotion(endsAt: string): string {
  const parts = WARSAW_DAY.formatToParts(new Date(new Date(endsAt).getTime() - 1000));
  const part = (type: string) => parts.find((candidate) => candidate.type === type)?.value ?? "";
  return `${part("year")}-${part("month")}-${part("day")}`;
}

/** Structured data of the product (schema.org `Product`): an `Offer` for each variant that has a price (the price the API gives, the effective one; in a promotion with `priceValidUntil`). */
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
              ...(variant.promotion === null ? {} : { priceValidUntil: lastDayOfPromotion(variant.promotion.endsAt) }),
              availability: AVAILABILITY[variant.level],
              url,
            },
          ],
    ),
  };
}

/** The path of the product (schema.org `BreadcrumbList`): All products and the categories, with absolute addresses. */
export function buildBreadcrumbJsonLd(details: ProductDetails, storeUrl: string) {
  return buildBreadcrumbListJsonLd(crumbsOf(details.breadcrumb), storeUrl);
}

/** JSON for a `<script type="application/ld+json">`: every `<` becomes a Unicode escape, so no text can close the element. */
export function serializeJsonLd(data: unknown): string {
  return JSON.stringify(data).replace(/</g, "\\u003c");
}
