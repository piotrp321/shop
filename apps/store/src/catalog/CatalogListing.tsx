import type { ReactNode } from "react";
import { ActiveFilters } from "./ActiveFilters";
import { type BreadcrumbItem, Breadcrumbs } from "./Breadcrumbs";
import { EmptyResults } from "./EmptyResults";
import { ListingFilters } from "./ListingFilters";
import { ListingProvider } from "./listing-context";
import { listingHref, type ListingMode, type ListingState } from "./listing-query";
import type { CatalogResult } from "./load-catalog";
import { CatalogPagination } from "./Pagination";
import { formatProductCount } from "./product-count";
import { ProductGrid } from "./ProductGrid";
import { SortSelect } from "./SortSelect";

/**
 * One page of a listing (`/`, `/c/<path>` or `/szukaj`): the path, the title with the number of products, the filter
 * panel, the active filters and the sort, the tiles and the pagination. `basePath` is the address of the listing without
 * parameters and `state` its parameters; every link of the page is built from them. `categoryName` is the name of the
 * category of a search (`state.category`). `intro` is what the home page puts above the listing (the hero and the
 * department tiles): the listing is then a section of the page, so its title is an h2 and the names of the products h3
 * (the hero has the h1), and no photo of the grid loads with priority.
 */
export function CatalogListing({
  title,
  crumbs,
  basePath,
  state,
  mode,
  categoryName,
  listing,
  intro,
}: {
  title: string;
  crumbs?: BreadcrumbItem[];
  basePath: string;
  state: ListingState;
  mode: ListingMode;
  categoryName?: string;
  listing: Extract<CatalogResult, { kind: "ok" }>;
  intro?: ReactNode;
}) {
  const { products, offers } = listing;
  const Title = intro === undefined ? "h1" : "h2";
  const body = (
    <>
      {crumbs && <Breadcrumbs items={crumbs} />}
      <div className="flex flex-wrap items-baseline gap-x-3">
        <Title className="text-2xl font-semibold">{title}</Title>
        <p className="text-base text-muted-foreground">{formatProductCount(products.total)}</p>
      </div>
      <ListingProvider basePath={basePath} state={state}>
        <div className="flex flex-col gap-6 lg:flex-row">
          <aside className="lg:w-64 lg:shrink-0">
            <ListingFilters mode={mode} facets={products.facets} />
          </aside>
          <div className="flex min-w-0 flex-1 flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <ActiveFilters basePath={basePath} state={state} categoryName={categoryName} />
              <div className="ml-auto">
                <SortSelect />
              </div>
            </div>
            {products.items.length === 0 ? <EmptyResults basePath={basePath} state={state} /> : <ProductGrid products={products.items} offers={offers} home={intro !== undefined} />}
            <CatalogPagination
              page={products.page}
              perPage={products.perPage}
              total={products.total}
              hrefFor={(page) => listingHref(basePath, state, { page: page > 1 ? String(page) : undefined })}
            />
          </div>
        </div>
      </ListingProvider>
    </>
  );
  const wrapper = "mx-auto flex max-w-7xl flex-col gap-6 px-4 py-6";
  if (intro === undefined) return <main className={wrapper}>{body}</main>;
  return (
    <main>
      {intro}
      <div className={wrapper}>{body}</div>
    </main>
  );
}
