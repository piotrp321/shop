import Link from "next/link";

/**
 * What the listing pages show for a filter the URL does not allow or the API rejected (a price with a comma, a malformed
 * category id or page number). `listingHref` is the same listing without its filters and sort (which of them is wrong
 * the page does not know), so the customer gets back to the results; the home page has no such link, the catalog is it.
 */
export function InvalidFilter({ listingHref }: { listingHref?: string }) {
  return (
    <main className="mx-auto max-w-7xl px-4 py-6">
      <div className="flex flex-col items-start gap-2">
        <h1 className="text-2xl font-semibold">Nieprawidłowy filtr</h1>
        {listingHref !== undefined && (
          <Link href={listingHref} className="text-sm underline underline-offset-4">
            Pokaż wyniki bez filtrów
          </Link>
        )}
        <Link href="/" className="text-sm underline underline-offset-4">
          Zobacz cały katalog
        </Link>
      </div>
    </main>
  );
}
