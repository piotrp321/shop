import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { buildBreadcrumbListJsonLd, crumbsOf } from "@/catalog/breadcrumb";
import { resolveCategoryPath } from "@/catalog/category-path";
import { loadCategoryTreeForRequest } from "@/catalog/category-tree";
import { CatalogListing } from "@/catalog/CatalogListing";
import { InvalidFilter } from "@/catalog/InvalidFilter";
import { loadCatalogForRequest } from "@/catalog/load-catalog";
import { buildCategoryMetadata } from "@/catalog/page-metadata";
import { serializeJsonLd } from "@/catalog/product-json-ld";

// A repeated parameter (?page=1&page=2) counts as its first value.
const first = (value: string | string[] | undefined) => (Array.isArray(value) ? value[0] : value);

function storeUrl(): string {
  const url = process.env.STORE_URL;
  if (!url) {
    throw new Error("STORE_URL is not set.");
  }
  return url;
}

/**
 * The category the slug path leads to in the tree, with its ancestors, and the list of its products (and of its
 * subcategories') for the page number. The list needs the id of the category, so it is read after the tree (one read
 * per request, shared with the layout and `generateMetadata`). `null` means 404: no such path in the tree, or the API
 * no longer knows the category.
 */
async function read(path: string[], page: string | undefined) {
  const chain = resolveCategoryPath(await loadCategoryTreeForRequest(), path);
  const category = chain.at(-1);
  if (category === undefined) {
    return null;
  }
  const listing = await loadCatalogForRequest(category.id, page);
  return listing.kind === "unknown-category" ? null : { chain, category, listing };
}

export async function generateMetadata({ params, searchParams }: PageProps<"/c/[...path]">): Promise<Metadata> {
  const { path } = await params;
  const page = first((await searchParams).page);
  const found = await read(path, page);
  if (found === null) {
    return {}; // The page answers 404.
  }
  return buildCategoryMetadata(found.listing, found.category, page, storeUrl());
}

export default async function CategoryPage({ params, searchParams }: PageProps<"/c/[...path]">) {
  const { path } = await params;
  const found = await read(path, first((await searchParams).page));
  if (found === null) {
    notFound();
  }
  const { chain, category, listing } = found;
  if (listing.kind === "invalid-filter") {
    return <InvalidFilter />;
  }
  // The last step is the page itself: text, not a link.
  const crumbs = crumbsOf(chain).map((crumb, index, all) => (index === all.length - 1 ? { name: crumb.name } : crumb));

  return (
    <>
      <CatalogListing title={category.name} crumbs={crumbs} categoryPath={category.path} listing={listing} />
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: serializeJsonLd(buildBreadcrumbListJsonLd(crumbsOf(chain), storeUrl())) }} />
    </>
  );
}
