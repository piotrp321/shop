import Link from "next/link";

/**
 * What the listing pages show for a filter the URL does not allow or the API rejected (a price with a comma, a malformed
 * category id or page number). `exit` is the way back to the results (`invalidFilterExit`): the listing without the
 * malformed price, or without its filters and sort.
 */
export function InvalidFilter({ exit }: { exit?: { href: string; label: string } }) {
  return (
    <main className="mx-auto max-w-content px-gutter pt-4 pb-14 lg:pt-6 lg:pb-24">
      <div className="flex flex-col items-start gap-2">
        <h1 className="mb-3 text-4xl font-semibold tracking-tight lg:text-6xl">Nieprawidłowy filtr</h1>
        {exit !== undefined && (
          <Link href={exit.href} className="inline-block py-1.5 text-ui underline underline-offset-4">
            {exit.label}
          </Link>
        )}
        <Link href="/" className="inline-block py-1.5 text-ui underline underline-offset-4">
          Zobacz cały katalog
        </Link>
      </div>
    </main>
  );
}
