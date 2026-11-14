import { computed, shallowRef, watch } from "vue";
import { getApiClient } from "@/api/client";
import type { CategoryLookup } from "@/products/useProductList";
import { useCursorPager } from "@/composables/useCursorPager";
import { type ListParam, useListUrlState } from "@/composables/useListUrlState";
import { MAX_NAME_SEARCH_LENGTH, NAME_SEARCH_DEBOUNCE_MS, useNameSearch, validNameTerm } from "@/composables/useNameSearch";
import { fetchPricePage, type ListedPrice, type PriceListItem, priceRows } from "./pricePage";

export type { PriceListItem, PriceRow } from "./pricePage";

/** How a price change ended; the messages are for the staff member. */
export type PriceChange =
  | { status: "saved" }
  | { status: "invalid"; message: string }
  | { status: "conflict"; message: string }
  | { status: "locked"; message: string }
  | { status: "failed"; message: string };

export const CONFLICT_MESSAGE = "Cena została w międzyczasie zmieniona – odśwież";
const PRICE_LOCKED_TYPE = "tag:piotrp321.github.io,2026:shop/problems/price-locked-by-promotion";

/** The rules of the search by name are in `useNameSearch`. */
export const MAX_SEARCH_LENGTH = MAX_NAME_SEARCH_LENGTH;
export const SEARCH_DEBOUNCE_MS = NAME_SEARCH_DEBOUNCE_MS;

/** The page sizes of the list, in products; the default of the list is 10 (the API's own default is 25, so the size is always sent). */
export const PER_PAGE_OPTIONS = [10, 25, 50];
export const DEFAULT_PER_PAGE = 10;

export const EMPTY_MESSAGE = "Nie ma jeszcze produktów z wariantami.";

/** The reason of a rejected request from a problem details body (`violations[].title`, else `detail`), if it has one. */
function problemMessage(body: unknown): string | null {
  if (typeof body !== "object" || body === null) {
    return null;
  }
  const { violations, detail } = body as { violations?: unknown; detail?: unknown };
  if (Array.isArray(violations)) {
    const titles = violations
      .map((violation) => (violation as { title?: unknown } | null)?.title)
      .filter((title): title is string => typeof title === "string" && title !== "");
    if (titles.length > 0) {
      return titles.join(" ");
    }
  }
  return typeof detail === "string" && detail !== "" ? detail : null;
}

/** The `detail` of a 409 saying the variant is in an active promotion; null for any other 409. */
function lockedDetail(body: unknown): string | null {
  if (typeof body !== "object" || body === null) {
    return null;
  }
  const { type, detail } = body as { type?: unknown; detail?: unknown };
  if (type !== PRICE_LOCKED_TYPE) {
    return null;
  }
  return typeof detail === "string" && detail !== "" ? detail : "Cena wariantu w aktywnej promocji nie może się zmienić.";
}

function changeFailure(status: number, body: unknown): PriceChange {
  switch (status) {
    case 422:
      // The rules of the amount are the API's; the panel only shows the reason it gives.
      return { status: "invalid", message: problemMessage(body) ?? `API odrzuciło cenę (HTTP ${status}).` };
    case 403:
      return { status: "failed", message: "Nie masz uprawnień do zmiany cen." };
    case 404:
      return { status: "failed", message: "Ten wariant już nie istnieje." };
    default:
      return { status: "failed", message: `Nie udało się zapisać ceny (HTTP ${status}).` };
  }
}

/**
 * The price list: a search by name, a category filter and a page size (10, 25 or 50 products). The state lives in the
 * address (`useListUrlState`: `q`, `category`, `perPage`) and the paging is `useCursorPager` over products, each with
 * all its variants, so „Pokazano produkty X–Y” counts products; `rows` has a row per variant (`priceRows`), in the order
 * of the products and of their variants. The request is `fetchPricePage`, the search by name `useNameSearch`.
 * `categories` is the tree of the filter, as in `useProductList`: a category of the address that it does not have is skipped.
 *
 * `changePrice` sends a new gross (in grosze) of one variant with the version its row shows. The saved price from the
 * API is an overlay over the items of the pager (`savedPrices`, not written to them): a row shows it while the price of
 * the list is older (a smaller version) or missing, so neither a request that is still running nor a late answer of the
 * list brings the old price back. A conflict (the price changed meanwhile) refreshes the page shown. A 409 of the type
 * `price-locked-by-promotion` (the variant is in an active promotion) is told apart by its `type`: it is `locked`, with
 * the `detail` of the API, and does not refresh the page. The API decides on the amount and the permission, the panel
 * only shows its answer.
 */
export function usePriceList(categories?: CategoryLookup) {
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
    perPage: {
      parse: (raw) => PER_PAGE_OPTIONS.find((size) => String(size) === raw) ?? null,
      fallback: DEFAULT_PER_PAGE,
    } satisfies ListParam<number, number>,
  });

  const name = useNameSearch({ initial: url.address.value.q, apply: (term) => url.set({ q: term }) });
  const pager = useCursorPager(fetchPricePage, url.query, { ready: url.ready });

  // The address changed from outside: it is the new truth, also for what is in the field.
  watch(url.address, (address) => name.setFromAddress(address.q));

  // The prices saved in this view, by variant. Never cleared: a price of the list with a version that is not older wins.
  const savedPrices = shallowRef(new Map<string, ListedPrice>());

  const items = computed<PriceListItem[]>(() =>
    savedPrices.value.size === 0
      ? pager.items.value
      : pager.items.value.map((item) => ({
          ...item,
          variants: item.variants.map((variant) => {
            const saved = savedPrices.value.get(variant.variantId);
            return saved && (variant.price === null || saved.version > variant.price.version) ? { ...variant, price: saved } : variant;
          }),
        })),
  );
  const rows = computed(() => priceRows(items.value));

  const filtered = computed(() => url.query.value.q !== null || url.query.value.category !== null);

  function selectCategory(id: string | null): void {
    url.set({ category: id });
  }

  function setPerPage(size: number): void {
    url.set({ perPage: size });
  }

  /** Drops the search, the category and the page size: the address is `/prices` again. */
  function clearFilters(): void {
    name.reset();
    url.clear();
  }

  async function changePrice(variantId: string, gross: number, version: number | null): Promise<PriceChange> {
    try {
      const result = await getApiClient().PUT("/api/v1/admin/variants/{variantId}/price", {
        params: { path: { variantId } },
        body: { gross, version },
      });
      if (result.data) {
        savedPrices.value = new Map(savedPrices.value).set(variantId, result.data);
        return { status: "saved" };
      }
      if (result.response.status === 409) {
        // The price did not change meanwhile, it is locked by a promotion: the row is current, nothing to reload.
        const locked = lockedDetail(result.error);
        if (locked !== null) {
          return { status: "locked", message: locked };
        }
        await pager.refresh();
        return { status: "conflict", message: CONFLICT_MESSAGE };
      }
      return changeFailure(result.response.status, result.error);
    } catch {
      return { status: "failed", message: "Nie udało się zapisać ceny. Sprawdź połączenie z API." };
    }
  }

  return {
    search: name.search,
    searchTooShort: name.tooShort,
    searchHint: name.hint,
    query: url.query,
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
    selectCategory,
    setPerPage,
    clearFilters,
    goNext: pager.goNext,
    goPrevious: pager.goPrevious,
    retry: pager.retry,
    changePrice,
  };
}
