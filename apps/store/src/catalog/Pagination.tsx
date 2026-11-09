import { Pagination, PaginationContent, PaginationItem, PaginationNext, PaginationPrevious } from "@/components/ui/pagination";

/**
 * Previous and next links in the addresses `hrefFor` builds for a page number (it carries the other parameters of the
 * listing). Renders nothing when there is a single page. From a page past the end, "previous" leads to the last real page.
 */
export function CatalogPagination({ page, perPage, total, hrefFor }: { page: number; perPage: number; total: number; hrefFor: (page: number) => string }) {
  const pageCount = Math.ceil(total / perPage);
  if (pageCount <= 1) return null;

  return (
    <Pagination>
      <PaginationContent className="flex-wrap justify-center gap-x-3.5 gap-y-3 lg:gap-x-5">
        {page > 1 && (
          <PaginationItem>
            <PaginationPrevious href={hrefFor(Math.min(page - 1, pageCount))} />
          </PaginationItem>
        )}
        <PaginationItem className="order-first basis-full text-center text-sm text-muted-foreground sm:order-none sm:basis-auto">
          Strona {page} z {pageCount}
        </PaginationItem>
        {page < pageCount && (
          <PaginationItem>
            <PaginationNext href={hrefFor(page + 1)} />
          </PaginationItem>
        )}
      </PaginationContent>
    </Pagination>
  );
}
