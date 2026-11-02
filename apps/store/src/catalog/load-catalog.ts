import { cache } from "react";
import { createApiClient } from "@/api/client";
import type { components } from "@/api/schema";

type CategoryNode = components["schemas"]["CategoryNode"];
type ProductPage = components["schemas"]["ProductPage"];
type PriceItem = components["schemas"]["PriceItem"];
type AvailabilityItem = components["schemas"]["AvailabilityItemResponse"];

export type CatalogResult =
  | { kind: "ok"; categories: CategoryNode[]; products: ProductPage; prices: Record<string, PriceItem>; availability: Record<string, AvailabilityItem> }
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
 * The gross prices of the products, by product id, in one request. A product without a price is absent. The prices
 * are an addition to the list, so a failure (status, network, unexpected body) does not fail the page: it is logged
 * on the server and the products show without prices.
 */
async function loadPrices(client: ReturnType<typeof serverClient>, products: ProductPage["items"]): Promise<Record<string, PriceItem>> {
  if (products.length === 0) {
    return {};
  }
  try {
    const { data, response } = await client.GET("/api/v1/prices", {
      params: { query: { productId: products.map((product) => product.id) } },
    });
    if (data === undefined || !Array.isArray(data.items)) {
      console.error(`GET /api/v1/prices failed with status ${response.status}, the products are shown without prices.`);
      return {};
    }
    return Object.fromEntries(data.items.map((price) => [price.productId, price]));
  } catch (error) {
    const reason = error instanceof Error ? error.message : "unknown error";
    console.error(`GET /api/v1/prices failed (${reason}), the products are shown without prices.`);
    return {};
  }
}

/**
 * The availability levels of the products, by product id, in one request. Like the prices, they are an addition to
 * the list: a failure is logged on the server and the products show without an availability label.
 */
async function loadAvailability(client: ReturnType<typeof serverClient>, products: ProductPage["items"]): Promise<Record<string, AvailabilityItem>> {
  if (products.length === 0) {
    return {};
  }
  try {
    const { data, response } = await client.GET("/api/v1/availability", {
      params: { query: { productId: products.map((product) => product.id) } },
    });
    if (data === undefined || !Array.isArray(data.items)) {
      console.error(`GET /api/v1/availability failed with status ${response.status}, the products are shown without availability.`);
      return {};
    }
    return Object.fromEntries(data.items.map((item) => [item.productId, item]));
  } catch (error) {
    const reason = error instanceof Error ? error.message : "unknown error";
    console.error(`GET /api/v1/availability failed (${reason}), the products are shown without availability.`);
    return {};
  }
}

/**
 * Loads the category tree and one page of products in parallel, then the prices and the availability of that page in parallel. A 400 for the filter becomes `invalid-filter`
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
  const [prices, availability] = await Promise.all([
    loadPrices(client, products.data.items),
    loadAvailability(client, products.data.items),
  ]);
  return { kind: "ok", categories: categories.data, products: products.data, prices, availability };
}

/**
 * `loadCatalog` shared by `generateMetadata` and the page within one request, so entering the page calls the API
 * once per endpoint. The arguments are strings (not an object) because `cache` compares them by identity.
 */
export const loadCatalogForRequest = cache((category?: string, page?: string) => loadCatalog({ category, page }));
