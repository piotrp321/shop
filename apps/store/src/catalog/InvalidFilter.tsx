import Link from "next/link";

/** What the listing pages show for a filter the API rejected (a malformed category id or page number). */
export function InvalidFilter() {
  return (
    <main className="mx-auto max-w-7xl px-4 py-6">
      <div className="flex flex-col items-start gap-2">
        <h1 className="text-2xl font-semibold">Nieprawidłowy filtr</h1>
        <Link href="/" className="text-sm underline underline-offset-4">
          Zobacz cały katalog
        </Link>
      </div>
    </main>
  );
}
