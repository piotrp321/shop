import Link from "next/link";

/**
 * What the listing pages show for a filter the URL does not allow or the API rejected (a price with a comma, a malformed
 * category id or page number). `exit` is the way back to the results (`invalidFilterExit`): the listing without the
 * malformed price, or without its filters and sort.
 */
export function InvalidFilter({ exit }: { exit?: { href: string; label: string } }) {
  return (
    <main className="mx-auto max-w-7xl px-4 py-6">
      <div className="flex flex-col items-start gap-2">
        <h1 className="text-2xl font-semibold">Nieprawidłowy filtr</h1>
        {exit !== undefined && (
          <Link href={exit.href} className="text-sm underline underline-offset-4">
            {exit.label}
          </Link>
        )}
        <Link href="/" className="text-sm underline underline-offset-4">
          Zobacz cały katalog
        </Link>
      </div>
    </main>
  );
}
