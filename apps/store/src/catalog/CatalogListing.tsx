import type { ReactNode } from "react";
import { cn } from "@/lib/utils";
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
      <div className={cn("flex flex-col gap-1.5", intro === undefined ? "mb-5 lg:mb-9" : "mb-6")}>
        {crumbs && <Breadcrumbs items={crumbs} />}
        <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1">
          <Title className={cn("font-semibold tracking-tight", intro === undefined ? "text-4xl lg:text-6xl" : "text-2xl lg:text-4xl")}>{title}</Title>
          <p className="text-lg text-muted-foreground">{formatProductCount(products.total)}</p>
        </div>
      </div>
      <ListingProvider basePath={basePath} state={state}>
        <div className="lg:grid lg:grid-cols-[16.25rem_minmax(0,1fr)] lg:items-start lg:gap-14">
          <aside>
            <ListingFilters mode={mode} facets={products.facets} />
          </aside>
          <div className="min-w-0">
            <div className="mb-6 flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
              <ActiveFilters basePath={basePath} state={state} categoryName={categoryName} />
              <div className="lg:ml-auto">
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
  // The default home page has the hero and the tiles above; the listing is then a section with more air around it.
  const wrapper = cn("mx-auto max-w-content px-gutter", intro === undefined ? "pt-4 pb-14 lg:pt-6 lg:pb-24" : "pt-12 pb-18 lg:pt-18 lg:pb-28");
  if (intro === undefined) return <main className={wrapper}>{body}</main>;
  return (
    <main>
      {intro}
      <div className={wrapper}>{body}</div>
    </main>
  );
}
