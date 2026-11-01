import type { Metadata } from "next";
import { catalogHref } from "./catalog-href";
import { findCategoryPath } from "./category-path";
import type { CatalogResult } from "./load-catalog";

const SITE = "Rytm";

/** The page number from the URL when it is a whole number above 1; anything else leaves it out of the address. */
function pageAfterFirst(raw: string | undefined): number | undefined {
  if (!raw || !/^\d+$/.test(raw)) return undefined;
  const page = Number(raw);
  return Number.isSafeInteger(page) && page > 1 ? page : undefined;
}

/**
 * Title, description, canonical address and robots for the catalogue page. `query` holds the first value of each
 * parameter, like the page itself. The canonical address carries only `category` and `page`, so extra parameters
 * and `page=1` do not create new addresses. An invalid filter and an empty page beyond the range are `noindex`.
 */
export function buildCatalogMetadata(
  catalog: Exclude<CatalogResult, { kind: "unknown-category" }>,
  query: { category?: string; page?: string },
  storeUrl: string,
): Metadata {
  const canonical = new URL(catalogHref({ category: query.category, page: pageAfterFirst(query.page) }), storeUrl).toString();
  const alternates = { canonical };

  if (catalog.kind === "invalid-filter") {
    return { title: `Nieprawidłowy filtr | ${SITE}`, alternates, robots: { index: false } };
  }

  const { page, items } = catalog.products;
  const categoryName = findCategoryPath(catalog.categories, query.category).at(-1)?.name;
  const subject = categoryName ?? "Wszystkie produkty";
  const metadata: Metadata = {
    title: `${subject}${page > 1 ? ` – strona ${page}` : ""} | ${SITE}`,
    description: categoryName
      ? `Produkty z kategorii ${categoryName} w sklepie ${SITE}.`
      : `Wszystkie produkty w sklepie ${SITE}.`,
    alternates,
  };
  return page > 1 && items.length === 0 ? { ...metadata, robots: { index: false } } : metadata;
}
