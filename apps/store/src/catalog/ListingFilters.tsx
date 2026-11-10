"use client";

import Link from "next/link";
import { type FocusEvent, type FormEvent, type KeyboardEvent, useRef, useState } from "react";
import { SlidersHorizontal } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";
import { formatPrice } from "./format-price";
import { useListing } from "./listing-context";
import { categoryFacetHref, type ListingMode, type ProductFacets, toggleOption } from "./listing-query";
import { FILTER_FORM_ID } from "./SortSelect";

// Prices are in PLN: the facet has the amounts in grosze and no currency.
const CURRENCY = "PLN";

// The native checkbox keeps the filter working without JavaScript; its mark takes the graphite of the direction.
const checkboxClass = "size-5.5 shrink-0 accent-foreground";
const optionClass = "flex min-h-8.5 cursor-pointer items-center gap-3 text-ui";
const headingClass = "mb-1.5 text-ui font-semibold";
// The groups of the panel are told apart by hairlines, not by frames.
const groupClass = "flex flex-col py-4 first:pt-0 last:pb-0";

/** The filter panel built from the facets of the API: categories, the values of every axis, the price and availability. */
export function ListingFilters({ mode, facets }: { mode: ListingMode; facets: ProductFacets }) {
  // `current` is what the panel shows until the new page arrives: the change just made, here or in the sort.
  const { basePath, state: current, go } = useListing();
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

  // A form with several text fields and no submit button is not submitted by Enter, so the key is handled here.
  const priceKey = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key !== "Enter") return;
    event.preventDefault();
    applyPrice();
  };

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    applyPrice();
  };

  return (
    <div className="mb-4 flex flex-col gap-3 lg:mb-0">
      <Button
        type="button"
        id={`${FILTER_FORM_ID}-toggle`}
        variant="outline"
        className="self-start lg:hidden"
        aria-expanded={open}
        aria-controls={FILTER_FORM_ID}
        onClick={() => setOpen((value) => !value)}
      >
        <SlidersHorizontal aria-hidden="true" />
        Filtry
      </Button>
      <form
        id={FILTER_FORM_ID}
        method="get"
        action={basePath}
        onSubmit={submit}
        className={cn(open ? "flex" : "hidden lg:flex", "flex-col rounded-md border border-input bg-card p-4 lg:rounded-none lg:border-0 lg:bg-transparent lg:p-0")}
      >
        {current.q !== undefined && <input type="hidden" name="q" value={current.q} />}
        {current.category !== undefined && <input type="hidden" name="kategoria" value={current.category} />}

        <div className="flex flex-col divide-y divide-border">
          {facets.categories.length > 0 && (
            <nav aria-label="Kategorie" className={groupClass}>
              <h2 className={headingClass}>Kategorie</h2>
              <ul className="flex flex-col">
                {facets.categories.map((category) => (
                  <li key={category.id}>
                    <Link href={categoryFacetHref(mode, category.path, current)} className="inline-block py-1.25 text-ui underline-offset-4 hover:underline">
                      {`${category.name} (${category.count})`}
                    </Link>
                  </li>
                ))}
              </ul>
            </nav>
          )}

          {facets.options.map((facet) => (
            <fieldset key={facet.axis} className={groupClass}>
              <legend className={cn(headingClass, "float-left w-full")}>{facet.axis}</legend>
              <div className="clear-both flex flex-col">
                {facet.values.map((value) => {
                  const option = `${facet.axis}:${value.value}`;
                  return (
                    <label key={value.value} className={optionClass}>
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
              </div>
            </fieldset>
          ))}

          <fieldset ref={priceGroup} className={groupClass}>
            <legend className={cn(headingClass, "float-left w-full")}>Cena (zł)</legend>
            <div className="clear-both grid grid-cols-2 gap-2.5">
              <label className="flex flex-col gap-1 text-sm">
                Cena od
                <Input name="cena-od" type="text" inputMode="numeric" value={priceFrom} onChange={(event) => setPriceFrom(event.target.value)} onKeyDown={priceKey} onBlur={leavePriceField} />
              </label>
              <label className="flex flex-col gap-1 text-sm">
                Cena do
                <Input name="cena-do" type="text" inputMode="numeric" value={priceTo} onChange={(event) => setPriceTo(event.target.value)} onKeyDown={priceKey} onBlur={leavePriceField} />
              </label>
            </div>
            {facets.price !== null && (
              <p className="mt-2 text-xs text-muted-foreground">{`Ceny od ${formatPrice(facets.price.min, CURRENCY)} do ${formatPrice(facets.price.max, CURRENCY)}`}</p>
            )}
          </fieldset>

          <div className={groupClass}>
            <label className={optionClass}>
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
          </div>
        </div>

        {/* Without JavaScript the form is sent with this button; the panel and the filter button of narrow screens are then
            shown as they are. */}
        <noscript>
          <style>{`#${FILTER_FORM_ID} { display: flex !important; } #${FILTER_FORM_ID}-toggle { display: none !important; }`}</style>
          <Button type="submit" className="mt-4">
            Pokaż wyniki
          </Button>
        </noscript>
      </form>
    </div>
  );
}
