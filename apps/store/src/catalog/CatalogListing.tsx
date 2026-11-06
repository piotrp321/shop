import { type BreadcrumbItem, Breadcrumbs } from "./Breadcrumbs";
import type { CatalogResult } from "./load-catalog";
import { CatalogPagination } from "./Pagination";
import { formatProductCount } from "./product-count";
import { ProductGrid } from "./ProductGrid";

/**
 * One page of the listing: the path (category pages), the title with the number of products, the tiles and the
 * pagination. `categoryPath` is the slug path of the category page, so the pagination links stay under `/c/`.
 */
export function CatalogListing({
  title,
  crumbs,
  categoryPath,
  listing,
}: {
  title: string;
  crumbs?: BreadcrumbItem[];
  categoryPath?: string;
  listing: Extract<CatalogResult, { kind: "ok" }>;
}) {
  const { products, offers } = listing;
  return (
    <main className="mx-auto flex max-w-7xl flex-col gap-6 px-4 py-6">
      {crumbs && <Breadcrumbs items={crumbs} />}
      <div className="flex flex-wrap items-baseline gap-x-3">
        <h1 className="text-2xl font-semibold">{title}</h1>
        <p className="text-base text-muted-foreground">{formatProductCount(products.total)}</p>
      </div>
      <ProductGrid products={products.items} offers={offers} />
      <CatalogPagination page={products.page} perPage={products.perPage} total={products.total} categoryPath={categoryPath} />
    </main>
  );
}
