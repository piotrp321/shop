"use client";

import { useListing } from "./listing-context";
import { defaultSort } from "./listing-query";

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
export function SortSelect() {
  const { state, go } = useListing();
  // The sort chosen shows at once, until the new page arrives.
  const selected = state.sort ?? defaultSort(state);
  const choose = (value: string) => go({ ...state, sort: value === defaultSort(state) ? undefined : value, page: undefined });
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
