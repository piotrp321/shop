import { cache } from "react";
import { createApiClient } from "@/api/client";
import type { components } from "@/api/schema";
import type { ApiListQuery } from "./listing-query";

type ProductPage = components["schemas"]["ProductPage"];
type ProductOffer = components["schemas"]["ProductOfferResponse"];

export type CatalogResult =
  | { kind: "ok"; products: ProductPage; offers: Record<string, ProductOffer> }
  | { kind: "invalid-filter" }
  | { kind: "unknown-category" };

/**
 * The catalog reads go through Varnish (`API_CATALOG_URL`), never straight to the API: they are public and cached.
 * The cart, the merge and the login use `API_INTERNAL_URL` (see `src/auth/deps.ts`).
 *
 * The listing pages render on every request and Varnish is their only cache, so each list read says
 * `cache: "no-store"` (no Next.js data cache, no `next.revalidate`, no tags).
 */
function catalogClient() {
  const baseUrl = process.env.API_CATALOG_URL;
  if (!baseUrl) {
    throw new Error("API_CATALOG_URL is not set.");
  }
  return createApiClient({ baseUrl });
}

/**
 * The offers of the products (variants, "from" price and level, all computed by the API), by product id, in one
 * request. They are an addition to the list, so a failure (status, network, unexpected body) does not fail the page:
 * it is logged on the server and the products show without prices, levels, variant choice and button.
 */
async function loadOffers(client: ReturnType<typeof catalogClient>, products: ProductPage["items"]): Promise<Record<string, ProductOffer>> {
  if (products.length === 0) {
    return {};
  }
  try {
    const { data, response } = await client.GET("/api/v1/product-offers", {
      params: { query: { productId: products.map((product) => product.id) } },
      cache: "no-store",
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
 * Loads one page of products, then the offers of that page. `category` is the id of the category (the page resolved it
 * in the category tree) or, on `/`, the raw `category` parameter; the other parameters are the phrase, the filters and
 * the sort, already in the words of the API (`toApiQuery`). A 400 for the filter becomes `invalid-filter` and a
 * 404 for the product list (the category does not exist) becomes `unknown-category`; any other failure (5xx,
 * network) is thrown and handled by `app/error.tsx`.
 */
export async function loadCatalog(params: ApiListQuery): Promise<CatalogResult> {
  const client = catalogClient();
  // The API validates the filter. A malformed value is sent as it came and comes back as 400, so the numbers (typed
  // as numbers in the schema) go on as the strings they are.
  const products = await client.GET("/api/v1/products", {
    params: {
      query: {
        category: params.category,
        q: params.q,
        option: params.option,
        priceMin: params.priceMin as unknown as number,
        priceMax: params.priceMax as unknown as number,
        available: params.available,
        sort: params.sort,
        page: params.page as unknown as number,
      },
    },
    cache: "no-store",
  });

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
  return { kind: "ok", products: products.data, offers };
}

const loadCatalogByKey = cache((key: string) => loadCatalog(JSON.parse(key) as ApiListQuery));

/**
 * `loadCatalog` shared by `generateMetadata` and the page within one request, so entering the page calls the API
 * once per endpoint. `cache` compares arguments by identity, so the query goes in as its JSON (`toApiQuery` always
 * builds the keys in the same order).
 */
export const loadCatalogForRequest = (query: ApiListQuery) => loadCatalogByKey(JSON.stringify(query));
