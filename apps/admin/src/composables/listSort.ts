import type { operations } from "@/api/schema";
import type { ListParam } from "./useListUrlState";

/**
 * One order a list can be sorted in (docs/features/admin-features/spec.md, „Sortowanie”): the key the API takes as `sort`
 * (a leading `-` is descending), the label of the `Select` „Sortuj” of the toolbar and, when the option cannot be chosen
 * with the filters that are set, why (the option is then disabled and the toolbar says so).
 */
export interface SortOption<K extends string = string> {
  value: K;
  label: string;
  disabledReason?: string;
}

/** The parameter `sort` of the address of a list: one of the keys, the default key (not written to the address) otherwise. */
export function sortParam<K extends string>(options: readonly SortOption<K>[], fallback: K): ListParam<K, K> {
  return { parse: (raw) => options.find((option) => option.value === raw)?.value ?? null, fallback };
}

/** The `aria-sort` of the header of the column a sort is by: ascending for the key, descending for `-` and the key. */
export function ariaSortOf(sort: string | null, key: string): "ascending" | "descending" | undefined {
  return sort === key ? "ascending" : sort === `-${key}` ? "descending" : undefined;
}

/** The keys of `sort` of the product, price and stock lists, which all page the products of the catalog. */
export type ProductSort = NonNullable<NonNullable<operations["listBackofficeProducts"]["parameters"]["query"]>["sort"]>;

export const DEFAULT_PRODUCT_SORT: ProductSort = "name";

export const PRODUCT_SORT_OPTIONS: readonly SortOption<ProductSort>[] = [
  { value: "name", label: "Nazwa A–Z" },
  { value: "-name", label: "Nazwa Z–A" },
  { value: "-updatedAt", label: "Ostatnio zmienione" },
  { value: "updatedAt", label: "Najdawniej zmienione" },
];
