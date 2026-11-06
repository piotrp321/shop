"use client";

import Form from "next/form";
import { usePathname, useSearchParams } from "next/navigation";
import { type RefObject, Suspense, useEffect, useRef } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

/**
 * Puts the phrase of `/szukaj` into the field, which is otherwise uncontrolled. It renders nothing, so the field is one
 * element from the page HTML on: what the user typed before the hydration stays. The field is written only when the
 * phrase changes (a new search, leaving `/szukaj`), not when other parameters do, and on the first run only when empty.
 */
function CurrentPhrase({ field }: { field: RefObject<HTMLInputElement | null> }) {
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const phrase = pathname === "/szukaj" ? (searchParams.get("q") ?? "") : "";
  // `null` until the first run.
  const applied = useRef<string | null>(null);
  useEffect(() => {
    const input = field.current;
    if (!input || applied.current === phrase) return;
    if (applied.current !== null || input.value === "") input.value = phrase;
    applied.current = phrase;
  }, [field, phrase]);
  return null;
}

/**
 * The search box of the header: a GET form to `/szukaj`. The page HTML is the same for everybody (product pages are
 * ISR), so the field is empty in it and the phrase of `/szukaj` is put in by the browser. The form and the field are
 * outside `Suspense`, which only holds the part that reads the address.
 */
export function SearchBox() {
  const field = useRef<HTMLInputElement>(null);
  return (
    <Form action="/szukaj" role="search" className="flex items-center gap-2">
      <label htmlFor="header-search" className="sr-only">
        Szukaj produktów
      </label>
      <Input ref={field} id="header-search" type="search" name="q" placeholder="Szukaj produktów" className="w-40 sm:w-64" />
      <Button type="submit" variant="outline" size="sm">
        Szukaj
      </Button>
      <Suspense>
        <CurrentPhrase field={field} />
      </Suspense>
    </Form>
  );
}
