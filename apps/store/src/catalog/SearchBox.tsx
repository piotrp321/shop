"use client";

import Form from "next/form";
import { usePathname, useSearchParams } from "next/navigation";
import { Suspense } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

function SearchForm({ phrase }: { phrase?: string }) {
  return (
    <Form action="/szukaj" role="search" className="flex items-center gap-2">
      <label htmlFor="header-search" className="sr-only">
        Szukaj produktów
      </label>
      {/* `key` puts the phrase of a new search into the field, which is otherwise uncontrolled. */}
      <Input key={phrase} id="header-search" type="search" name="q" defaultValue={phrase} placeholder="Szukaj produktów" className="w-40 sm:w-64" />
      <Button type="submit" variant="outline" size="sm">
        Szukaj
      </Button>
    </Form>
  );
}

function CurrentSearch() {
  const pathname = usePathname();
  const searchParams = useSearchParams();
  return <SearchForm phrase={pathname === "/szukaj" ? (searchParams.get("q") ?? undefined) : undefined} />;
}

/**
 * The search box of the header: a GET form to `/szukaj`. The page HTML is the same for everybody (product pages are
 * ISR), so the field is empty in it and the phrase of `/szukaj` is put in by the browser.
 */
export function SearchBox() {
  return (
    <Suspense fallback={<SearchForm />}>
      <CurrentSearch />
    </Suspense>
  );
}
