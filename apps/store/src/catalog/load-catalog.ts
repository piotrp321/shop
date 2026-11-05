import { cache } from "react";
import { createApiClient } from "@/api/client";
import type { components } from "@/api/schema";

type CategoryNode = components["schemas"]["CategoryNode"];
type ProductPage = components["schemas"]["ProductPage"];
type ProductOffer = components["schemas"]["ProductOfferResponse"];

export type CatalogResult =
  | { kind: "ok"; categories: CategoryNode[]; products: ProductPage; offers: Record<string, ProductOffer> }
  | { kind: "invalid-filter" }
  | { kind: "unknown-category" };

function serverClient() {
  const baseUrl = process.env.API_INTERNAL_URL;
  if (!baseUrl) {
    throw new Error("API_INTERNAL_URL is not set.");
  }
  return createApiClient({ baseUrl });
}

/**
 * The offers of the products (variants, "from" price and level, all computed by the API), by product id, in one
 * request. They are an addition to the list, so a failure (status, network, unexpected body) does not fail the page:
 * it is logged on the server and the products show without prices, levels, variant choice and button.
 */
async function loadOffers(client: ReturnType<typeof serverClient>, products: ProductPage["items"]): Promise<Record<string, ProductOffer>> {
  if (products.length === 0) {
    return {};
  }
  try {
    const { data, response } = await client.GET("/api/v1/product-offers", {
      params: { query: { productId: products.map((product) => product.id) } },
    });
    if (data === undefined || !Array.isArray(data.items)) {
      console.error(`GET /api/v1/product-offers failed with status ${response.status}, the products are shown without offers.`);
      return {};
    }
    return Object.fromEntries(data.items.map((offer) => [offer.productId, offer]));
  } catch (error) {
    const reason = error instanceof Error ? error.message : "unknown error";
    console.error(`GET /api/v1/product-offers failed (${reason}), the products are shown without offers.`);
    return {};
  }
}

/**
 * Loads the category tree and one page of products in parallel, then the offers of that page. A 400 for the filter becomes `invalid-filter`
 * and a 404 for the product list (the category does not exist) becomes `unknown-category`; any other failure
 * (5xx, network) is thrown and handled by `app/error.tsx`.
 */
export async function loadCatalog(params: { category?: string; page?: string }): Promise<CatalogResult> {
  const client = serverClient();
  const [categories, products] = await Promise.all([
    client.GET("/api/v1/categories"),
    // The API validates the filter. A malformed value is sent as it came and comes back as 400.
    client.GET("/api/v1/products", {
      params: { query: { category: params.category, page: params.page as unknown as number } },
    }),
  ]);

  if (categories.data === undefined) {
    throw new Error(`GET /api/v1/categories failed with status ${categories.response.status}.`);
  }
  if (products.data === undefined) {
    if (products.response.status === 400) {
      return { kind: "invalid-filter" };
    }
    if (products.response.status === 404) {
      return { kind: "unknown-category" };
    }
    throw new Error(`GET /api/v1/products failed with status ${products.response.status}.`);
  }
  const offers = await loadOffers(client, products.data.items);
  return { kind: "ok", categories: categories.data, products: products.data, offers };
}

/**
 * `loadCatalog` shared by `generateMetadata` and the page within one request, so entering the page calls the API
 * once per endpoint. The arguments are strings (not an object) because `cache` compares them by identity.
 */
export const loadCatalogForRequest = cache((category?: string, page?: string) => loadCatalog({ category, page }));
