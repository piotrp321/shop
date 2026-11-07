import { cache } from "react";
import { createApiClient } from "@/api/client";
import type { components } from "@/api/schema";

type ProductDetails = components["schemas"]["ProductDetails"];
type ProductOffer = components["schemas"]["ProductOfferResponse"];

export type ProductResult = { kind: "ok"; details: ProductDetails; offer: ProductOffer } | { kind: "not-found" };

/**
 * The format of a product slug (`Slug` in the API): lower-case letters and digits in groups joined by single dashes,
 * at most 200 characters. `/api/revalidate` accepts `product-slug-<slug>` in this format only.
 */
const PRODUCT_SLUG = /^[a-z0-9]+(-[a-z0-9]+)*$/;
const PRODUCT_SLUG_MAX_LENGTH = 200;

export function isProductSlug(value: string): boolean {
  return value.length <= PRODUCT_SLUG_MAX_LENGTH && PRODUCT_SLUG.test(value);
}

/**
 * The reads of the product page go through Varnish (`API_CATALOG_URL`) like the other catalog reads. The tags are the
 * ones the API puts in `xkey` (`catalog`, `product-slug-<slug>`, `product-<id>`): the page is revalidated by the same
 * names that purge Varnish.
 */
function catalogClient(tags: string[]) {
  const baseUrl = process.env.API_CATALOG_URL;
  if (!baseUrl) {
    throw new Error("API_CATALOG_URL is not set.");
  }
  return createApiClient({ baseUrl, tags });
}

const isList = (value: unknown) => Array.isArray(value);

function isDetails(value: ProductDetails | undefined): value is ProductDetails {
  return (
    typeof value === "object" &&
    value !== null &&
    typeof value.id === "string" &&
    typeof value.slug === "string" &&
    typeof value.name === "string" &&
    typeof value.shortDescription === "string" &&
    isList(value.breadcrumb) &&
    isList(value.images) &&
    isList(value.variantAxes) &&
    isList(value.variants)
  );
}

/**
 * The details of the product by its slug, then its offer (price, level, variants). A 404 for the slug is `not-found`.
 * The read of the details carries the tag of the slug (only for a slug in the format of a product slug, which is the
 * only one a product can have), so the publication of a product revalidates the 404 page of its slug.
 * Any other failure (status, network, an unexpected body) of the details or of the offer is thrown, so the page is an
 * error page that the ISR cache does not keep, not a page without a price.
 */
export async function loadProduct(slug: string): Promise<ProductResult> {
  const detailsTags = isProductSlug(slug) ? ["catalog", `product-slug-${slug}`] : ["catalog"];
  const detailsResponse = await catalogClient(detailsTags).GET("/api/v1/products/{slug}", { params: { path: { slug } } });
  if (detailsResponse.response.status === 404) {
    return { kind: "not-found" };
  }
  if (detailsResponse.data === undefined) {
    throw new Error(`GET /api/v1/products/{slug} failed with status ${detailsResponse.response.status}.`);
  }
  const details = detailsResponse.data;
  if (!isDetails(details)) {
    throw new Error("GET /api/v1/products/{slug} returned an unexpected body.");
  }

  const offerResponse = await catalogClient(["catalog", `product-${details.id.toLowerCase()}`]).GET("/api/v1/product-offers", {
    params: { query: { productId: [details.id] } },
  });
  if (offerResponse.data === undefined) {
    throw new Error(`GET /api/v1/product-offers failed with status ${offerResponse.response.status}.`);
  }
  const offer = Array.isArray(offerResponse.data.items) ? offerResponse.data.items.find((item) => item.productId === details.id) : undefined;
  if (offer === undefined || !isList(offer.variants)) {
    throw new Error("GET /api/v1/product-offers returned an unexpected body.");
  }
  return { kind: "ok", details, offer };
}

/** `loadProduct` shared by `generateMetadata` and the page within one request: the product is read once. */
export const loadProductForRequest = cache((slug: string) => loadProduct(slug));
