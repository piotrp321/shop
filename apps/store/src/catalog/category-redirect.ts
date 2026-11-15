import { cache } from "react";
import { createApiClient } from "@/api/client";
import { categoryHref } from "./catalog-href";
import type { SearchParams } from "./listing-query";

/**
 * The current slug path of a category address it no longer has (`GET /api/v1/categories/redirect`) through Varnish
 * (`API_CATALOG_URL`) with the `catalog` tag, like the tree: a change of the tree purges and revalidates it. `null`
 * is the 404 of the API (the address leads to no category) and its 400 (not a category path at all; the store does not
 * repeat the check of the slug format). Any other failure throws, like a failed read of the tree.
 */
export async function loadCategoryRedirect(path: string): Promise<string | null> {
  const baseUrl = process.env.API_CATALOG_URL;
  if (!baseUrl) {
    throw new Error("API_CATALOG_URL is not set.");
  }
  const { data, response } = await createApiClient({ baseUrl, tags: ["catalog"] }).GET("/api/v1/categories/redirect", { params: { query: { path } } });
  if (data === undefined) {
    if (response.status === 404 || response.status === 400) {
      return null;
    }
    throw new Error(`GET /api/v1/categories/redirect failed with status ${response.status}.`);
  }
  if (typeof data.path !== "string" || data.path === "") {
    throw new Error("GET /api/v1/categories/redirect returned an unexpected body.");
  }
  return data.path;
}

/** The redirect shared by `generateMetadata` and the page within one request: it is read once per path. */
export const loadCategoryRedirectForRequest = cache((path: string) => loadCategoryRedirect(path));

/**
 * `/c/<path>` with the parameters of the old address: all of them, every value of a repeated one, in the order they
 * came, except the listed ones (`/?category=<id>` drops `category`).
 */
export function categoryRedirectHref(path: string, query: SearchParams, skip: string[] = []): string {
  const params = new URLSearchParams();
  for (const [name, value] of Object.entries(query)) {
    if (skip.includes(name) || value === undefined) continue;
    for (const item of Array.isArray(value) ? value : [value]) params.append(name, item);
  }
  const search = params.toString();
  return search ? `${categoryHref(path)}?${search}` : categoryHref(path);
}
