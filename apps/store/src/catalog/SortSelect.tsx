"use client";

import { useRouter } from "next/navigation";
import { useOptimistic, useTransition } from "react";
import { defaultSort, listingHref, type ListingState } from "./listing-query";

export const FILTER_FORM_ID = "listing-filters";

const SORTS = [
  { value: "trafnosc", label: "Trafność" },
  { value: "nazwa", label: "Nazwa A–Z" },
  { value: "cena-rosnaco", label: "Cena rosnąco" },
  { value: "cena-malejaco", label: "Cena malejąco" },
];

/**
 * The sort list. A native select that belongs to the filter form (`form` attribute), so „Pokaż wyniki” sends it
 * without JavaScript; with JavaScript a change navigates at once. „Trafność” is offered only with a phrase, and the
 * default sort leaves `sort` out of the address.
 */
export function SortSelect({ basePath, state }: { basePath: string; state: ListingState }) {
  const router = useRouter();
  const [, startTransition] = useTransition();
  const fallback = defaultSort(state);
  // The chosen sort shows at once, until the new page arrives.
  const [selected, setSelected] = useOptimistic(state.sort ?? fallback);
  const choose = (value: string) => {
    startTransition(() => {
      setSelected(value);
      router.push(listingHref(basePath, state, { sort: value === fallback ? undefined : value }));
    });
  };
  return (
    <div className="flex items-center gap-2 text-sm">
      <label htmlFor="listing-sort">Sortuj</label>
      <select
        id="listing-sort"
        name="sort"
        form={FILTER_FORM_ID}
        value={selected}
        onChange={(event) => choose(event.target.value)}
        className="h-8 rounded-lg border border-input bg-background px-2 text-sm"
      >
        {SORTS.filter((sort) => sort.value !== "trafnosc" || state.q !== undefined).map((sort) => (
          <option key={sort.value} value={sort.value}>
            {sort.label}
          </option>
        ))}
      </select>
    </div>
  );
}
