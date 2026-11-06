"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { type FocusEvent, type FormEvent, useOptimistic, useRef, useState, useTransition } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { formatPrice } from "./format-price";
import { categoryFacetHref, listingHref, type ListingMode, type ListingState, type ProductFacets, toggleOption } from "./listing-query";
import { FILTER_FORM_ID } from "./SortSelect";

// Prices are in PLN: the facet has the amounts in grosze and no currency.
const CURRENCY = "PLN";

const checkboxClass = "size-4 accent-primary";

/** The filter panel built from the facets of the API: categories, the values of every axis, the price and availability. */
export function ListingFilters({ basePath, mode, state, facets }: { basePath: string; mode: ListingMode; state: ListingState; facets: ProductFacets }) {
  const router = useRouter();
  const [, startTransition] = useTransition();
  // What the panel shows until the new page arrives: the change just made.
  const [current, setCurrent] = useOptimistic(state);
  const [open, setOpen] = useState(false);
  const priceGroup = useRef<HTMLFieldSetElement>(null);

  // The price fields keep what is typed; a new price in the address (a link, a removed filter) replaces it.
  const [priceFrom, setPriceFrom] = useState(current.priceFrom ?? "");
  const [syncedFrom, setSyncedFrom] = useState(current.priceFrom);
  if (current.priceFrom !== syncedFrom) {
    setSyncedFrom(current.priceFrom);
    setPriceFrom(current.priceFrom ?? "");
  }
  const [priceTo, setPriceTo] = useState(current.priceTo ?? "");
  const [syncedTo, setSyncedTo] = useState(current.priceTo);
  if (current.priceTo !== syncedTo) {
    setSyncedTo(current.priceTo);
    setPriceTo(current.priceTo ?? "");
  }

  const go = (next: ListingState) => {
    startTransition(() => {
      setCurrent(next);
      router.push(listingHref(basePath, next));
    });
  };

  // Whole zloty exactly as typed (trimmed); the conversion to grosze and the judgement are for the page and the API.
  const applyPrice = () => {
    const from = priceFrom.trim() || undefined;
    const to = priceTo.trim() || undefined;
    if (from === current.priceFrom && to === current.priceTo) return;
    go({ ...current, priceFrom: from, priceTo: to, page: undefined });
  };

  const leavePriceField = (event: FocusEvent<HTMLInputElement>) => {
    // Moving from one price field to the other is not leaving the price.
    if (event.relatedTarget instanceof Node && priceGroup.current?.contains(event.relatedTarget)) return;
    applyPrice();
  };

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    applyPrice();
  };

  return (
    <div className="flex flex-col gap-4">
      <Button
        type="button"
        id={`${FILTER_FORM_ID}-toggle`}
        variant="outline"
        className="self-start lg:hidden"
        aria-expanded={open}
        aria-controls={FILTER_FORM_ID}
        onClick={() => setOpen((value) => !value)}
      >
        Filtry
      </Button>
      <form id={FILTER_FORM_ID} method="get" action={basePath} onSubmit={submit} className={`${open ? "flex" : "hidden lg:flex"} flex-col gap-5`}>
        {current.q !== undefined && <input type="hidden" name="q" value={current.q} />}
        {current.category !== undefined && <input type="hidden" name="kategoria" value={current.category} />}

        {facets.categories.length > 0 && (
          <nav aria-label="Kategorie" className="flex flex-col gap-1">
            <h2 className="text-sm font-semibold">Kategorie</h2>
            <ul className="flex flex-col gap-1">
              {facets.categories.map((category) => (
                <li key={category.id}>
                  <Link href={categoryFacetHref(mode, category.path, current)} className="text-sm underline-offset-4 hover:underline">
                    {`${category.name} (${category.count})`}
                  </Link>
                </li>
              ))}
            </ul>
          </nav>
        )}

        {facets.options.map((facet) => (
          <fieldset key={facet.axis} className="flex flex-col gap-1">
            <legend className="mb-1 text-sm font-semibold">{facet.axis}</legend>
            {facet.values.map((value) => {
              const option = `${facet.axis}:${value.value}`;
              return (
                <label key={value.value} className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    name="o"
                    value={option}
                    checked={current.options.includes(option)}
                    onChange={() => go(toggleOption(current, option))}
                    className={checkboxClass}
                  />
                  {`${value.value} (${value.count})`}
                </label>
              );
            })}
          </fieldset>
        ))}

        <fieldset ref={priceGroup} className="flex flex-col gap-2">
          <legend className="mb-1 text-sm font-semibold">Cena (zł)</legend>
          <div className="flex items-center gap-2">
            <label className="flex flex-1 flex-col gap-1 text-sm">
              Cena od
              <Input name="cena-od" type="text" inputMode="numeric" value={priceFrom} onChange={(event) => setPriceFrom(event.target.value)} onBlur={leavePriceField} />
            </label>
            <label className="flex flex-1 flex-col gap-1 text-sm">
              Cena do
              <Input name="cena-do" type="text" inputMode="numeric" value={priceTo} onChange={(event) => setPriceTo(event.target.value)} onBlur={leavePriceField} />
            </label>
          </div>
          {facets.price !== null && (
            <p className="text-xs text-muted-foreground">{`Ceny od ${formatPrice(facets.price.min, CURRENCY)} do ${formatPrice(facets.price.max, CURRENCY)}`}</p>
          )}
        </fieldset>

        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            name="dostepne"
            value="1"
            checked={current.available === "1"}
            onChange={() => go({ ...current, available: current.available === "1" ? undefined : "1", page: undefined })}
            className={checkboxClass}
          />
          {`Tylko dostępne (${facets.availableCount})`}
        </label>

        {/* Without JavaScript the form is sent with this button; the panel and the filter button of narrow screens are then
            shown as they are. */}
        <noscript>
          <style>{`#${FILTER_FORM_ID} { display: flex !important; } #${FILTER_FORM_ID}-toggle { display: none !important; }`}</style>
          <Button type="submit">Pokaż wyniki</Button>
        </noscript>
      </form>
    </div>
  );
}
