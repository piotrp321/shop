import { Pagination, PaginationContent, PaginationItem, PaginationNext, PaginationPrevious } from "@/components/ui/pagination";
import { catalogHref } from "./catalog-href";

/**
 * Previous and next links that keep the category filter. Renders nothing when there is a single page.
 * From a page past the end, "previous" leads to the last real page.
 */
export function CatalogPagination({ page, perPage, total, category }: { page: number; perPage: number; total: number; category?: string }) {
  const pageCount = Math.ceil(total / perPage);
  if (pageCount <= 1) return null;

  return (
    <Pagination>
      <PaginationContent>
        <PaginationItem>{page > 1 && <PaginationPrevious href={catalogHref({ category, page: Math.min(page - 1, pageCount) })} />}</PaginationItem>
        <PaginationItem className="px-2 text-sm text-muted-foreground">
          Strona {page} z {pageCount}
        </PaginationItem>
        <PaginationItem>{page < pageCount && <PaginationNext href={catalogHref({ category, page: page + 1 })} />}</PaginationItem>
      </PaginationContent>
    </Pagination>
  );
}
