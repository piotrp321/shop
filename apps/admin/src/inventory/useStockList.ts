import { computed, shallowRef, watch } from "vue";
import { useCursorPager } from "@/composables/useCursorPager";
import { DEFAULT_PRODUCT_SORT, PRODUCT_SORT_OPTIONS, type SortOption, sortParam } from "@/composables/listSort";
import { type ListParam, useListUrlState } from "@/composables/useListUrlState";
import { MAX_NAME_SEARCH_LENGTH, NAME_SEARCH_DEBOUNCE_MS, useNameSearch, validNameTerm } from "@/composables/useNameSearch";
import type { CategoryLookup } from "@/products/useProductList";
import { type CorrectStockRequest, requestCorrection, requestReceipt, type StockChange } from "./stockChange";
import { fetchStockPage, type ListedStock, type StockLevelFilter, type StockListItem, stockRows } from "./stockPage";

export { type ChangeField, CONFLICT_MESSAGE, type StockChange } from "./stockChange";
export type { StockListItem, StockRow } from "./stockPage";

/** The rules of the search by name are in `useNameSearch`. */
export const MAX_SEARCH_LENGTH = MAX_NAME_SEARCH_LENGTH;
export const SEARCH_DEBOUNCE_MS = NAME_SEARCH_DEBOUNCE_MS;

/** The page sizes of the list, in products; the default of the list is 10 (the API's own default is 25, so the size is always sent). */
export const PER_PAGE_OPTIONS = [10, 25, 50];
export const DEFAULT_PER_PAGE = 10;

export const EMPTY_MESSAGE = "Nie ma jeszcze produktów z wariantami.";

/** The levels of the filter „Poziom”, in the order of its options. */
export const LEVEL_FILTERS: readonly { value: StockLevelFilter; label: string }[] = [
  { value: "out_of_stock", label: "Bez stanu" },
  { value: "low_stock", label: "Niski stan" },
];
/** Why the search and the category are off while a level is on: the API pages a level by its stock records alone. */
export const LEVEL_EXCLUDES_MESSAGE = "Filtr poziomu nie łączy się z wyszukiwaniem ani kategorią.";
export const LEVEL_SORT_MESSAGE = "Filtr poziomu ma własną kolejność.";

/**
 * The stock list: a search by name, a category filter, a level filter (`level`: the products with a variant out of stock
 * or with a low stock; it excludes the search, the category and the order, so choosing it clears them and the query
 * of the list holds only the level while it is on) and a page size (10, 25 or 50 products). The state lives in the
 * address (`useListUrlState`: `q`, `category`, `level`, `sort`: by name or by the last change, either way, and `perPage`) and the paging is `useCursorPager` over products, each with
 * all its variants, so „Pokazano produkty X–Y” counts products; `rows` has a row per variant (`stockRows`), in the order
 * of the products and of their variants. The request is `fetchStockPage`, the search by name `useNameSearch`.
 * `categories` is the tree of the filter, as in `useProductList`: a category of the address that it does not have is skipped.
 * Every number and level in a row is the API's; the panel only shows them.
 *
 * `receiveStock` and `correctStock` send a change of one variant. The stock the API answers with is an overlay over the
 * items of the pager (`savedStocks`, not written to them): a row shows it while the stock of the list is older (a smaller
 * version) or missing, so neither a request that is still running nor a late answer of the list brings the old stock
 * back (the panel computes nothing). The overlay is never cleared.
 */
export function useStockList(categories?: CategoryLookup) {
  const known = computed(() => {
    const ids = new Set<string>();
    const walk = (level: CategoryLookup["nodes"]["value"]) => {
      for (const node of level) {
        ids.add(node.key);
        walk(node.children);
      }
    };
    walk(categories?.nodes.value ?? []);
    return ids;
  });

  const url = useListUrlState({
    q: { parse: validNameTerm, fallback: null } satisfies ListParam<string, null>,
    category: {
      parse: (raw) => raw,
      fallback: null,
      confirm: categories ? (id) => (categories.loaded.value ? known.value.has(id) : undefined) : undefined,
    } satisfies ListParam<string, null>,
    level: {
      parse: (raw) => LEVEL_FILTERS.find((filter) => filter.value === raw)?.value ?? null,
      fallback: null,
    } satisfies ListParam<StockLevelFilter, null>,
    sort: sortParam(PRODUCT_SORT_OPTIONS, DEFAULT_PRODUCT_SORT),
    perPage: {
      parse: (raw) => PER_PAGE_OPTIONS.find((size) => String(size) === raw) ?? null,
      fallback: DEFAULT_PER_PAGE,
    } satisfies ListParam<number, number>,
  });

  const level = computed(() => url.query.value.level);

  /**
   * The query of the list: the one of the address, but a level excludes the search, the category and the order (the API
   * refuses them with it, and the address may still hold them), so they are empty while it is on.
   */
  const query = computed(() =>
    url.query.value.level === null ? url.query.value : { ...url.query.value, q: null, category: null, sort: DEFAULT_PRODUCT_SORT },
  );

  const name = useNameSearch({ initial: level.value === null ? url.address.value.q : null, apply: (term) => url.set({ q: term }) });
  const pager = useCursorPager(fetchStockPage, query, { ready: url.ready });

  // The address changed from outside: it is the new truth, also for what is in the field.
  watch(url.address, (address) => name.setFromAddress(level.value === null ? address.q : null));

  // The stocks saved in this view, by variant. Never cleared: a stock of the list with a version that is not older wins.
  const savedStocks = shallowRef(new Map<string, ListedStock>());

  const items = computed<StockListItem[]>(() =>
    savedStocks.value.size === 0
      ? pager.items.value
      : pager.items.value.map((item) => ({
          ...item,
          variants: item.variants.map((variant) => {
            const saved = savedStocks.value.get(variant.variantId);
            // The mark of the level is the API's for the stock it read: a saved stock replaces it, so the mark is dropped
            // (the panel does not work out the level itself).
            return saved && (variant.stock === null || saved.version > variant.stock.version) ? { ...variant, stock: saved, matchesLevel: false } : variant;
          }),
        })),
  );
  const rows = computed(() => stockRows(items.value));

  const filtered = computed(() => url.query.value.q !== null || url.query.value.category !== null || url.query.value.level !== null);

  function selectCategory(id: string | null): void {
    url.set({ category: id });
  }

  /** The level filter: choosing one clears the search, the category and the order, dropping it leaves them empty. */
  function selectLevel(selected: StockLevelFilter | null): void {
    if (selected === null) {
      url.set({ level: null });
      return;
    }
    name.reset();
    url.set({ level: selected, q: null, category: null, sort: DEFAULT_PRODUCT_SORT });
  }

  /** The order of the list: none while a level is on (the products come in the order of their ids). */
  const sort = computed(() => (level.value === null ? url.query.value.sort : null));
  const sortOptions = computed<SortOption[]>(() =>
    level.value === null ? [...PRODUCT_SORT_OPTIONS] : PRODUCT_SORT_OPTIONS.map((option) => ({ ...option, disabledReason: LEVEL_SORT_MESSAGE })),
  );

  function setSort(sort: string): void {
    url.set({ sort: PRODUCT_SORT_OPTIONS.find((option) => option.value === sort)?.value ?? DEFAULT_PRODUCT_SORT });
  }

  function setPerPage(size: number): void {
    url.set({ perPage: size });
  }

  /** Drops the search, the category, the order and the page size: the address is `/inventory` again. */
  function clearFilters(): void {
    name.reset();
    url.clear();
  }

  function saveStock(variantId: string, stock: ListedStock): void {
    savedStocks.value = new Map(savedStocks.value).set(variantId, stock);
  }

  /** A receipt of one variant; see `requestReceipt`. A reused key (409) reloads the page shown. */
  function receiveStock(variantId: string, input: { quantity: number; note: string | null; key: string }): Promise<StockChange> {
    return requestReceipt(variantId, input, { save: saveStock, refresh: pager.refresh });
  }

  /**
   * A correction based on the `version` the row shows (null: no stock record); see `requestCorrection`. On a 409 the page
   * shown is reloaded and the row is read again from it: a row that is no longer there, or a reload that failed (a
   * failed reload keeps the old page, so its row would look unchanged: the error of the pager says it failed), cannot be reread.
   */
  function correctStock(
    variantId: string,
    input: { physical: number; reason: CorrectStockRequest["reason"]; note: string | null; version: number | null },
  ): Promise<StockChange> {
    return requestCorrection(variantId, input, {
      save: saveStock,
      reread: async () => {
        await pager.refresh();
        const row = rows.value.find((candidate) => candidate.variantId === variantId);
        return pager.error.value !== null || !row ? null : { version: row.stock?.version ?? null };
      },
    });
  }

  return {
    search: name.search,
    searchTooShort: name.tooShort,
    searchHint: name.hint,
    query,
    level,
    selectLevel,
    sort,
    filtered,
    items,
    rows,
    loading: pager.loading,
    loaded: pager.loaded,
    error: pager.error,
    forbidden: pager.forbidden,
    hasPrevious: pager.hasPrevious,
    hasNext: pager.hasNext,
    pageNumber: pager.pageNumber,
    range: pager.range,
    total: pager.total,
    selectCategory,
    sortOptions,
    sortDisabled: computed(() => level.value !== null),
    setSort,
    setPerPage,
    clearFilters,
    goNext: pager.goNext,
    goPrevious: pager.goPrevious,
    retry: pager.retry,
    receiveStock,
    correctStock,
  };
}
