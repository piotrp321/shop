import Link from "next/link";
import { Button } from "@/components/ui/button";
import { clearedFilters, hasFilters, listingHref, type ListingState } from "./listing-query";

/** What the listing shows when nothing was found: the way out is to clear the filters or to leave the search. */
export function EmptyResults({ basePath, state }: { basePath: string; state: ListingState }) {
  return (
    <div className="flex flex-col items-start gap-4 py-8">
      <p className="text-lg">Nie znaleźliśmy produktów.</p>
      {hasFilters(state) && (
        <Button asChild variant="outline">
          <Link href={listingHref(basePath, clearedFilters(state))}>Wyczyść filtry</Link>
        </Button>
      )}
      {state.q !== undefined && (
        <Link href="/" className="inline-block py-1.5 text-ui underline underline-offset-4">
          Zobacz wszystkie produkty
        </Link>
      )}
    </div>
  );
}
