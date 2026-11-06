import type { Metadata } from "next";
import { notFound, permanentRedirect } from "next/navigation";
import { loadCategoryTreeForRequest } from "@/catalog/category-tree";
import { findCategoryPath } from "@/catalog/category-path";
import { CatalogListing } from "@/catalog/CatalogListing";
import { InvalidFilter } from "@/catalog/InvalidFilter";
import { type CatalogResult, loadCatalogForRequest } from "@/catalog/load-catalog";
import { buildCatalogMetadata } from "@/catalog/page-metadata";

type SearchParams = Record<string, string | string[] | undefined>;

// A repeated parameter (?page=1&page=2) counts as its first value.
const first = (value: string | string[] | undefined) => (Array.isArray(value) ? value[0] : value);

type Home = Exclude<CatalogResult, { kind: "ok" }> | { kind: "ok"; listing: Extract<CatalogResult, { kind: "ok" }> } | { kind: "redirect"; path: string };

/**
 * What `/` shows. Without `category` it is the list of all products. The old address `/?category=<id>` reads the tree
 * and the list together and, when the tree has the category, gives its address `/c/<path>` for a permanent redirect.
 * Otherwise the list answers as before: a rejected value is `invalid-filter`, an unknown category `unknown-category`.
 * A list that is fine for a category the tree does not know (a stale tree) is not a page either.
 */
async function readHome(category: string | undefined, page: string | undefined): Promise<Home> {
  if (category === undefined) {
    const listing = await loadCatalogForRequest(undefined, page);
    return listing.kind === "ok" ? { kind: "ok", listing } : listing;
  }
  const [tree, listing] = await Promise.all([loadCategoryTreeForRequest(), loadCatalogForRequest(category, page)]);
  const found = findCategoryPath(tree, category).at(-1);
  if (found !== undefined) {
    return { kind: "redirect", path: found.path };
  }
  return listing.kind === "ok" ? { kind: "unknown-category" } : listing;
}

/** `/c/<path>` with the other parameters of the old address (every value of a repeated one) and without `category`. */
function categoryRedirectUrl(path: string, query: SearchParams): string {
  const params = new URLSearchParams();
  for (const [name, value] of Object.entries(query)) {
    if (name === "category" || value === undefined) continue;
    for (const item of Array.isArray(value) ? value : [value]) params.append(name, item);
  }
  const search = params.toString();
  return search ? `/c/${path}?${search}` : `/c/${path}`;
}

export async function generateMetadata({ searchParams }: PageProps<"/">): Promise<Metadata> {
  const query: SearchParams = await searchParams;
  const category = first(query.category);
  const page = first(query.page);
  // Shares the reads with the page through the cached loaders, so the metadata adds no API calls.
  const home = await readHome(category, page);
  if (home.kind === "unknown-category" || home.kind === "redirect") {
    return {}; // The page answers 404 or redirects.
  }
  const storeUrl = process.env.STORE_URL;
  if (!storeUrl) {
    throw new Error("STORE_URL is not set.");
  }
  return buildCatalogMetadata(home.kind === "ok" ? home.listing : home, { category, page }, storeUrl);
}

export default async function Home({ searchParams }: PageProps<"/">) {
  const query: SearchParams = await searchParams;
  const home = await readHome(first(query.category), first(query.page));
  if (home.kind === "unknown-category") {
    notFound();
  }
  if (home.kind === "redirect") {
    permanentRedirect(categoryRedirectUrl(home.path, query));
  }
  if (home.kind === "invalid-filter") {
    return <InvalidFilter />;
  }
  return <CatalogListing title="Wszystkie produkty" listing={home.listing} />;
}
