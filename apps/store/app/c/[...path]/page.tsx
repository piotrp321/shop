import type { Metadata } from "next";
import { notFound, permanentRedirect } from "next/navigation";
import { buildBreadcrumbListJsonLd, crumbsOf } from "@/catalog/breadcrumb";
import { categoryHref } from "@/catalog/catalog-href";
import { resolveCategoryPath } from "@/catalog/category-path";
import { categoryRedirectHref, loadCategoryRedirectForRequest } from "@/catalog/category-redirect";
import { loadCategoryTreeForRequest } from "@/catalog/category-tree";
import { CatalogListing } from "@/catalog/CatalogListing";
import { InvalidFilter } from "@/catalog/InvalidFilter";
import { invalidFilterExit, isRefined, readListingState, type SearchParams, toApiQuery } from "@/catalog/listing-query";
import { loadCatalogForRequest } from "@/catalog/load-catalog";
import { buildCategoryMetadata } from "@/catalog/page-metadata";
import { serializeJsonLd } from "@/catalog/product-json-ld";

function storeUrl(): string {
  const url = process.env.STORE_URL;
  if (!url) {
    throw new Error("STORE_URL is not set.");
  }
  return url;
}

/**
 * The category the slug path leads to in the tree, with its ancestors, and the list of its products (and of its
 * subcategories') for the sort, the filters and the page of the address (the phrase and `kategoria` are ignored). The
 * list needs the id of the category, so it is read after the tree (one read per request, shared with the layout and
 * `generateMetadata`). A path that is not in the tree is asked of the API as an old address of a category
 * (`redirect`, one read per request too): it answers with the current path, or `null` (404) when there is none.
 * `null` means 404: no such path anywhere, or the tree has it and the API no longer knows the category. A sort or
 * filter the URL does not allow is an `invalid-filter` listing without a call to the API.
 */
async function read(path: string[], query: SearchParams) {
  const chain = resolveCategoryPath(await loadCategoryTreeForRequest(), path);
  const category = chain.at(-1);
  if (category === undefined) {
    const current = await loadCategoryRedirectForRequest(path.join("/"));
    return current === null ? null : ({ kind: "redirect", path: current } as const);
  }
  const state = readListingState(query, "list");
  const apiQuery = toApiQuery(state, category.id);
  const listing = apiQuery === null ? ({ kind: "invalid-filter" } as const) : await loadCatalogForRequest(apiQuery);
  return listing.kind === "unknown-category" ? null : { kind: "category", chain, category, state, listing } as const;
}

export async function generateMetadata({ params, searchParams }: PageProps<"/c/[...path]">): Promise<Metadata> {
  const { path } = await params;
  const query = await searchParams;
  const found = await read(path, query);
  if (found === null || found.kind === "redirect") {
    return {}; // The page answers 404 or redirects.
  }
  return buildCategoryMetadata(found.listing, found.category, readListingState(query, "list").page, storeUrl(), isRefined(found.state));
}

export default async function CategoryPage({ params, searchParams }: PageProps<"/c/[...path]">) {
  const { path } = await params;
  const query = await searchParams;
  const found = await read(path, query);
  if (found === null) {
    notFound();
  }
  if (found.kind === "redirect") {
    permanentRedirect(categoryRedirectHref(found.path, query));
  }
  const { chain, category, state, listing } = found;
  if (listing.kind === "invalid-filter") {
    return <InvalidFilter exit={invalidFilterExit(categoryHref(category.path), state)} />;
  }
  // The last step is the page itself: text, not a link.
  const crumbs = crumbsOf(chain).map((crumb, index, all) => (index === all.length - 1 ? { name: crumb.name } : crumb));

  return (
    <>
      <CatalogListing title={category.name} crumbs={crumbs} basePath={categoryHref(category.path)} state={state} mode="list" listing={listing} />
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: serializeJsonLd(buildBreadcrumbListJsonLd(crumbsOf(chain), storeUrl())) }} />
    </>
  );
}
