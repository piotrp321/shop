import type { Metadata } from "next";
import type { components } from "@/api/schema";
import { STORE_NAME } from "@/site";
import { largestVariant } from "./image-props";

type ProductDetails = components["schemas"]["ProductDetails"];

/** The canonical address of the product page: `<STORE_URL>/p/<slug>`. */
export function productUrl(slug: string, storeUrl: string): string {
  return new URL(`/p/${encodeURIComponent(slug)}`, storeUrl).toString();
}

/**
 * Title, description, canonical address and Open Graph of the product page. The Open Graph image is the first photo
 * in its largest width; a product without photos has none.
 */
export function buildProductMetadata(details: ProductDetails, storeUrl: string): Metadata {
  const url = productUrl(details.slug, storeUrl);
  const title = `${details.name} | ${STORE_NAME}`;
  const description = details.shortDescription === "" ? undefined : details.shortDescription;
  const first = details.images[0];
  const image = first === undefined ? null : largestVariant(first);
  return {
    title,
    ...(description === undefined ? {} : { description }),
    alternates: { canonical: url },
    openGraph: {
      title,
      ...(description === undefined ? {} : { description }),
      url,
      ...(image === null || first === undefined ? {} : { images: [{ url: image.url, width: image.width, height: image.height, alt: first.alt }] }),
    },
  };
}
