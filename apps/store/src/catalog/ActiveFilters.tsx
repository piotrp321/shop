import Link from "next/link";
import { X } from "lucide-react";
import { clearedFilters, listingHref, type ListingState } from "./listing-query";

/** An option `<axis>:<value>` (the axis has no colon, so the first one splits) as „Kolor: czarne”. */
function optionLabel(option: string): string {
  const colon = option.indexOf(":");
  return colon < 0 ? option : `${option.slice(0, colon)}: ${option.slice(colon + 1)}`;
}

/**
 * The filters in force as links that remove one filter each, and „Wyczyść filtry” that removes them all but keeps the
 * phrase and the sort. Plain links, so they work without JavaScript. `categoryName` is the name of the category of a
 * search (`state.category` is its slug path).
 */
export function ActiveFilters({ basePath, state, categoryName }: { basePath: string; state: ListingState; categoryName?: string }) {
  const chips: { label: string; href: string }[] = [];
  state.options.forEach((option) => {
    chips.push({ label: optionLabel(option), href: listingHref(basePath, state, { options: state.options.filter((other) => other !== option) }) });
  });
  if (state.priceFrom !== undefined) chips.push({ label: `Cena od ${state.priceFrom} zł`, href: listingHref(basePath, state, { priceFrom: undefined }) });
  if (state.priceTo !== undefined) chips.push({ label: `Cena do ${state.priceTo} zł`, href: listingHref(basePath, state, { priceTo: undefined }) });
  if (state.available !== undefined) chips.push({ label: "Tylko dostępne", href: listingHref(basePath, state, { available: undefined }) });
  if (state.category !== undefined) chips.push({ label: `Kategoria: ${categoryName ?? state.category}`, href: listingHref(basePath, state, { category: undefined }) });

  if (chips.length === 0) return null;
  return (
    <div className="flex flex-wrap items-center gap-2">
      <ul aria-label="Aktywne filtry" className="flex flex-wrap items-center gap-2">
        {chips.map((chip) => (
          <li key={chip.label}>
            <Link
              href={chip.href}
              aria-label={`Usuń filtr: ${chip.label}`}
              className="inline-flex items-center gap-1 rounded-full border border-border bg-muted px-3 py-1 text-sm hover:bg-accent"
            >
              {chip.label}
              <X aria-hidden="true" className="size-3" />
            </Link>
          </li>
        ))}
      </ul>
      <Link href={listingHref(basePath, clearedFilters(state))} className="text-sm underline underline-offset-4">
        Wyczyść filtry
      </Link>
    </div>
  );
}
