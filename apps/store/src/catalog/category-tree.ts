import { cache } from "react";
import { createApiClient } from "@/api/client";
import type { components } from "@/api/schema";

type CategoryNode = components["schemas"]["CategoryNode"];

/**
 * The category tree (`GET /api/v1/categories`) through Varnish (`API_CATALOG_URL`) like the other catalog reads. The
 * `catalog` tag is the one the API puts in `xkey`: an ISR page that shows the menu (the product page) is revalidated
 * with it. The read sets no cache mode: in a request-time render of a dynamic page Next.js does not keep it in the data
 * cache, and in an ISR render it passes the tag to the page.
 */
export async function loadCategoryTree(): Promise<CategoryNode[]> {
  const baseUrl = process.env.API_CATALOG_URL;
  if (!baseUrl) {
    throw new Error("API_CATALOG_URL is not set.");
  }
  const { data, response } = await createApiClient({ baseUrl, tags: ["catalog"] }).GET("/api/v1/categories");
  if (data === undefined) {
    throw new Error(`GET /api/v1/categories failed with status ${response.status}.`);
  }
  if (!Array.isArray(data)) {
    throw new Error("GET /api/v1/categories returned an unexpected body.");
  }
  return data;
}

/**
 * The tree shared by the layout (the menu), `generateMetadata` and the page within one request: it is read once.
 * The call has no arguments, so every caller gets the same promise.
 */
export const loadCategoryTreeForRequest = cache(() => loadCategoryTree());

/**
 * The tree for the menu in the header. A failure is logged on the server and gives `null`: the header renders without
 * the menu and the rest of the page (the cart, for one) does not depend on the catalog.
 */
export async function loadMenuCategories(): Promise<CategoryNode[] | null> {
  try {
    return await loadCategoryTreeForRequest();
  } catch (error) {
    const reason = error instanceof Error ? error.message : "unknown error";
    console.error(`The category tree could not be read (${reason}), the header is rendered without the menu.`);
    return null;
  }
}
