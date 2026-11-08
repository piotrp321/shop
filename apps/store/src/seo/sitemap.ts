import { createApiClient } from "@/api/client";
import type { components } from "@/api/schema";
import { buildSitemapIndex, buildUrlSet } from "./xml";
import { readStoreUrl } from "./store-url";

type CategoryNode = components["schemas"]["CategoryNode"];
type SitemapProduct = components["schemas"]["SitemapProduct"];

/**
 * The sitemap routes of the store. Every call reads the API again (`cache: "no-store"`: no Next.js data cache; the
 * caches are Varnish and the pool of the API) and builds the XML from what it got. A sitemap is whole or it is an error:
 * a failure of the API, the network or the shape of the body gives a 503 with `no-store` and a line in the server log,
 * never an empty or a partial map. A missing `STORE_URL` or `API_CATALOG_URL` is a configuration error and is thrown.
 */

const XML_HEADERS = { "Content-Type": "application/xml; charset=utf-8", "Cache-Control": "public, max-age=300" };
const SUFFIX = ".xml";

/** The API answered, but not with what the sitemap needs. */
class Unusable extends Error {}

function xml(body: string): Response {
  return new Response(body, { status: 200, headers: XML_HEADERS });
}

function notFound(): Response {
  return new Response("Not found", { status: 404, headers: { "Content-Type": "text/plain; charset=utf-8", "Cache-Control": "no-store" } });
}

function unavailable(what: string, error: unknown): Response {
  const reason = error instanceof Error ? error.message : "unknown error";
  console.error(`The ${what} could not be built (${reason}).`);
  return new Response("Service unavailable", { status: 503, headers: { "Content-Type": "text/plain; charset=utf-8", "Cache-Control": "no-store" } });
}

function catalogClient() {
  const baseUrl = process.env.API_CATALOG_URL;
  if (!baseUrl) {
    throw new Error("API_CATALOG_URL is not set.");
  }
  return createApiClient({ baseUrl });
}

const isRecord = (value: unknown): value is Record<string, unknown> => typeof value === "object" && value !== null && !Array.isArray(value);
const isText = (value: unknown): value is string => typeof value === "string" && value !== "";

function isCategoryList(value: unknown): value is CategoryNode[] {
  return Array.isArray(value) && value.every((node) => isRecord(node) && isText(node.path) && isCategoryList(node.children));
}

function isProductList(value: unknown): value is SitemapProduct[] {
  return Array.isArray(value) && value.every((item) => isRecord(item) && isText(item.slug) && isText(item.updatedAt));
}

/** The paths of the tree, every level, parents before their children. */
function categoryPaths(categories: CategoryNode[]): string[] {
  return categories.flatMap((category) => [category.path, ...categoryPaths(category.children)]);
}

const encodePath = (path: string) => path.split("/").map(encodeURIComponent).join("/");

/** `/sitemap.xml`: the listing map, then one map per chunk of the products, in the order of the API. */
export async function sitemapIndexResponse(): Promise<Response> {
  const storeUrl = readStoreUrl();
  const client = catalogClient();
  try {
    const { data, response } = await client.GET("/api/v1/sitemap/product-chunks", { cache: "no-store" });
    if (data === undefined) {
      throw new Unusable(`GET /api/v1/sitemap/product-chunks failed with status ${response.status}`);
    }
    const chunks: unknown = (data as { chunks?: unknown }).chunks;
    if (!Array.isArray(chunks) || !chunks.every((chunk) => isRecord(chunk) && isText(chunk.key))) {
      throw new Unusable("GET /api/v1/sitemap/product-chunks returned an unexpected body");
    }
    return xml(
      buildSitemapIndex([
        `${storeUrl}/sitemaps/listing.xml`,
        ...(chunks as { key: string }[]).map(({ key }) => `${storeUrl}/sitemaps/products/${key}.xml`),
      ]),
    );
  } catch (error) {
    return unavailable("sitemap index", error);
  }
}

/** `/sitemaps/listing.xml`: the home page and every category of the tree. */
export async function listingSitemapResponse(): Promise<Response> {
  const storeUrl = readStoreUrl();
  const client = catalogClient();
  try {
    const { data, response } = await client.GET("/api/v1/categories", { cache: "no-store" });
    if (data === undefined) {
      throw new Unusable(`GET /api/v1/categories failed with status ${response.status}`);
    }
    if (!isCategoryList(data)) {
      throw new Unusable("GET /api/v1/categories returned an unexpected body");
    }
    return xml(buildUrlSet([{ loc: `${storeUrl}/` }, ...categoryPaths(data).map((path) => ({ loc: `${storeUrl}/c/${encodePath(path)}` }))]));
  } catch (error) {
    return unavailable("listing sitemap", error);
  }
}

/**
 * `/sitemaps/products/<key>.xml`: the published products of one chunk. `file` is the last segment of the address; the
 * key is handed to the API as it is. An address without `.xml` and a key the API refuses (400) are 404.
 */
export async function productChunkSitemapResponse(file: string): Promise<Response> {
  const storeUrl = readStoreUrl();
  const client = catalogClient();
  if (!file.endsWith(SUFFIX) || file.length === SUFFIX.length) {
    return notFound();
  }
  const chunk = file.slice(0, -SUFFIX.length);
  try {
    const { data, response } = await client.GET("/api/v1/sitemap/products", { params: { query: { chunk } }, cache: "no-store" });
    if (response.status === 400) {
      return notFound();
    }
    if (data === undefined) {
      throw new Unusable(`GET /api/v1/sitemap/products failed with status ${response.status}`);
    }
    const items: unknown = (data as { items?: unknown }).items;
    if (!isProductList(items)) {
      throw new Unusable("GET /api/v1/sitemap/products returned an unexpected body");
    }
    return xml(buildUrlSet(items.map(({ slug, updatedAt }) => ({ loc: `${storeUrl}/p/${encodeURIComponent(slug)}`, lastmod: updatedAt }))));
  } catch (error) {
    return unavailable("product sitemap", error);
  }
}
