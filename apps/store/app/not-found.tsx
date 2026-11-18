import Link from "next/link";
import { Button } from "@/components/ui/button";

// Prerendered at build time, when no API answers: the HTML of the image has the header without the menu. The tag
// `catalog` of the tree read (purge-caches after a deployment) or, after a restart of the store, this hour at most
// brings the menu back.
export const revalidate = 3600;

/**
 * The 404 page of an address no route has and of every `notFound()` (a product that does not exist): inside the layout
 * of the store, so with the header and the footer. Next.js adds `noindex` to a 404 itself.
 */
export default function NotFound() {
  return (
    <main className="mx-auto flex max-w-content flex-col items-start gap-6 px-gutter py-16 lg:py-28">
      <h1 className="text-4xl font-semibold tracking-tight lg:text-6xl">Nie znaleźliśmy tej strony.</h1>
      <Button asChild size="lg">
        <Link href="/">Wróć do sklepu</Link>
      </Button>
    </main>
  );
}
