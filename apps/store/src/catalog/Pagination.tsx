import { Pagination, PaginationContent, PaginationItem, PaginationNext, PaginationPrevious } from "@/components/ui/pagination";
import { catalogHref, categoryHref } from "./catalog-href";

/**
 * Previous and next links in the addresses of the page (`/?page=n`, or `/c/<path>?page=n` for a category page). Renders nothing when there is a single page.
 * From a page past the end, "previous" leads to the last real page.
 */
export function CatalogPagination({ page, perPage, total, categoryPath }: { page: number; perPage: number; total: number; categoryPath?: string }) {
  const pageCount = Math.ceil(total / perPage);
  const href = (n: number) => (categoryPath ? categoryHref(categoryPath, n) : catalogHref({ page: n }));
  if (pageCount <= 1) return null;

  return (
    <Pagination>
      <PaginationContent>
        <PaginationItem>{page > 1 && <PaginationPrevious href={href(Math.min(page - 1, pageCount))} />}</PaginationItem>
        <PaginationItem className="px-2 text-sm text-muted-foreground">
          Strona {page} z {pageCount}
        </PaginationItem>
        <PaginationItem>{page < pageCount && <PaginationNext href={href(page + 1)} />}</PaginationItem>
      </PaginationContent>
    </Pagination>
  );
}
