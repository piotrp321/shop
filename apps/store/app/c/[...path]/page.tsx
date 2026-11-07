import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { buildBreadcrumbListJsonLd, crumbsOf } from "@/catalog/breadcrumb";
import { categoryHref } from "@/catalog/catalog-href";
import { resolveCategoryPath } from "@/catalog/category-path";
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
 * `generateMetadata`). `null` means 404: no such path in the tree, or the API no longer knows the category. A sort or
 * filter the URL does not allow is an `invalid-filter` listing without a call to the API.
 */
async function read(path: string[], query: SearchParams) {
  const chain = resolveCategoryPath(await loadCategoryTreeForRequest(), path);
  const category = chain.at(-1);
  if (category === undefined) {
    return null;
  }
  const state = readListingState(query, "list");
  const apiQuery = toApiQuery(state, category.id);
  const listing = apiQuery === null ? ({ kind: "invalid-filter" } as const) : await loadCatalogForRequest(apiQuery);
  return listing.kind === "unknown-category" ? null : { chain, category, state, listing };
}

export async function generateMetadata({ params, searchParams }: PageProps<"/c/[...path]">): Promise<Metadata> {
  const { path } = await params;
  const query = await searchParams;
  const found = await read(path, query);
  if (found === null) {
    return {}; // The page answers 404.
  }
  return buildCategoryMetadata(found.listing, found.category, readListingState(query, "list").page, storeUrl(), isRefined(found.state));
}

export default async function CategoryPage({ params, searchParams }: PageProps<"/c/[...path]">) {
  const { path } = await params;
  const found = await read(path, await searchParams);
  if (found === null) {
    notFound();
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
