import { createApiClient } from "@/api/client";
import type { components } from "@/api/schema";

type CategoryNode = components["schemas"]["CategoryNode"];
type ProductPage = components["schemas"]["ProductPage"];

export type CatalogResult =
  | { kind: "ok"; categories: CategoryNode[]; products: ProductPage }
  | { kind: "invalid-filter" };

function serverClient() {
  const baseUrl = process.env.API_INTERNAL_URL;
  if (!baseUrl) {
    throw new Error("API_INTERNAL_URL is not set.");
  }
  return createApiClient({ baseUrl });
}

/**
 * Loads the category tree and one page of products in parallel. A 400 for the filter becomes `invalid-filter`;
 * any other failure (5xx, network) is thrown and handled by `app/error.tsx`.
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
    throw new Error(`GET /api/v1/products failed with status ${products.response.status}.`);
  }
  return { kind: "ok", categories: categories.data, products: products.data };
}
