import type { Metadata } from "next";
import type { components } from "@/api/schema";
import type { BreadcrumbItem } from "@/catalog/Breadcrumbs";
import { catalogHref } from "@/catalog/catalog-href";
import { resolveCategoryPath } from "@/catalog/category-path";
import { loadCategoryTreeForRequest } from "@/catalog/category-tree";
import { CatalogListing } from "@/catalog/CatalogListing";
import { InvalidFilter } from "@/catalog/InvalidFilter";
import { isShortPhrase, listingHref, type ListingState, readListingState, type SearchParams, toApiQuery } from "@/catalog/listing-query";
import { type CatalogResult, loadCatalogForRequest } from "@/catalog/load-catalog";
import { buildSearchMetadata } from "@/catalog/page-metadata";

type CategoryNode = components["schemas"]["CategoryNode"];

type Search =
  | { kind: "short-phrase"; state: ListingState }
  | { kind: "invalid-filter"; state: ListingState }
  | { kind: "ok"; state: ListingState; listing: Extract<CatalogResult, { kind: "ok" }>; chain: CategoryNode[] };

/**
 * What `/szukaj` shows. A phrase shorter than 2 characters is not searched (no call for products, no tree). `kategoria`
 * is the slug path of a category; it is resolved in the category tree (read once per request, shared with the layout) and
 * the API gets its id. A path the tree does not know, a sort or filter the URL does not allow and a 400 of the API are
 * an `invalid-filter`, and so is a category the API no longer knows (a stale tree).
 */
async function read(query: SearchParams): Promise<Search> {
  const state = readListingState(query, "search");
  if (isShortPhrase(state.q)) return { kind: "short-phrase", state };

  let chain: CategoryNode[] = [];
  if (state.category !== undefined) {
    chain = resolveCategoryPath(await loadCategoryTreeForRequest(), state.category.split("/"));
    if (chain.length === 0) return { kind: "invalid-filter", state };
  }
  const apiQuery = toApiQuery(state, chain.at(-1)?.id);
  if (apiQuery === null) return { kind: "invalid-filter", state };

  const listing = await loadCatalogForRequest(apiQuery);
  return listing.kind === "ok" ? { kind: "ok", state, listing, chain } : { kind: "invalid-filter", state };
}

export async function generateMetadata({ searchParams }: PageProps<"/szukaj">): Promise<Metadata> {
  // Shares the reads with the page through the cached loaders, so the metadata adds no API calls.
  const search = await read(await searchParams);
  return buildSearchMetadata(search.kind === "ok" ? search.listing : { kind: search.kind }, search.state.q);
}

/** The path above the results: all products, the search (a link when a category narrows it) and the category chain. */
function crumbsOf(state: ListingState, chain: CategoryNode[]): BreadcrumbItem[] {
  const crumbs: BreadcrumbItem[] = [{ name: "Wszystkie produkty", href: catalogHref() }];
  if (chain.length === 0) return [...crumbs, { name: "Wyniki wyszukiwania" }];
  const phraseOnly: ListingState = { q: state.q, options: [] };
  crumbs.push({ name: "Wyniki wyszukiwania", href: listingHref("/szukaj", phraseOnly) });
  chain.forEach((category, index) => {
    crumbs.push(index === chain.length - 1 ? { name: category.name } : { name: category.name, href: listingHref("/szukaj", phraseOnly, { category: category.path }) });
  });
  return crumbs;
}

export default async function SearchPage({ searchParams }: PageProps<"/szukaj">) {
  const search = await read(await searchParams);
  if (search.kind === "invalid-filter") {
    return <InvalidFilter />;
  }
  if (search.kind === "short-phrase") {
    return (
      <main className="mx-auto flex max-w-7xl flex-col gap-2 px-4 py-6">
        <h1 className="text-2xl font-semibold">Wyszukiwanie</h1>
        <p>Wpisz co najmniej 2 znaki.</p>
      </main>
    );
  }
  const { state, listing, chain } = search;
  return (
    <CatalogListing
      title={`Wyniki dla „${state.q}”`}
      crumbs={crumbsOf(state, chain)}
      basePath="/szukaj"
      state={state}
      mode="search"
      categoryName={chain.at(-1)?.name}
      listing={listing}
    />
  );
}
