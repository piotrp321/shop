import type { Metadata } from "next";
import { catalogHref, categoryHref } from "./catalog-href";
import type { CatalogResult } from "./load-catalog";

const SITE = "Rytm";

type Listing = Exclude<CatalogResult, { kind: "unknown-category" }>;

/** The page number from the URL when it is a whole number above 1; anything else leaves it out of the address. */
function pageAfterFirst(raw: string | undefined): number | undefined {
  if (!raw || !/^\d+$/.test(raw)) return undefined;
  const page = Number(raw);
  return Number.isSafeInteger(page) && page > 1 ? page : undefined;
}

/**
 * Title, description, robots and canonical address shared by the listing pages. An invalid filter and an empty page
 * beyond the range are `noindex`. `subject` is the category name, or `undefined` for the whole catalogue.
 */
function listingMetadata(catalog: Listing, subject: string | undefined, canonical: string): Metadata {
  const alternates = { canonical };

  if (catalog.kind === "invalid-filter") {
    return { title: `Nieprawidłowy filtr | ${SITE}`, alternates, robots: { index: false } };
  }

  const { page, items } = catalog.products;
  const metadata: Metadata = {
    title: `${subject ?? "Wszystkie produkty"}${page > 1 ? ` – strona ${page}` : ""} | ${SITE}`,
    description: subject ? `Produkty z kategorii ${subject} w sklepie ${SITE}.` : `Wszystkie produkty w sklepie ${SITE}.`,
    alternates,
  };
  return page > 1 && items.length === 0 ? { ...metadata, robots: { index: false } } : metadata;
}

/**
 * Metadata of `/` (all products). `query` holds the first value of each parameter, like the page itself. The
 * canonical address carries only `page` (not `page=1` or extra parameters); the one exception is an invalid filter,
 * whose canonical address keeps the `category` value that was rejected.
 */
export function buildCatalogMetadata(catalog: Listing, query: { category?: string; page?: string }, storeUrl: string): Metadata {
  const page = pageAfterFirst(query.page);
  const rejected = catalog.kind === "invalid-filter" && query.category ? `/?${new URLSearchParams({ category: query.category, ...(page ? { page: String(page) } : {}) })}` : null;
  return listingMetadata(catalog, undefined, new URL(rejected ?? catalogHref({ page }), storeUrl).toString());
}

/**
 * Metadata of a category page `/c/<path>`: the title is the category name, the canonical address is the page itself
 * (`?page=n` after page 1), so extra parameters and `page=1` do not create new addresses.
 */
export function buildCategoryMetadata(catalog: Listing, category: { name: string; path: string }, rawPage: string | undefined, storeUrl: string): Metadata {
  return listingMetadata(catalog, category.name, new URL(categoryHref(category.path, pageAfterFirst(rawPage)), storeUrl).toString());
}
