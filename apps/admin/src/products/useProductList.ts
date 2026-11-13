import { computed, onUnmounted, type Ref, ref, watch } from "vue";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";
import type { CategoryTreeNode } from "@/composables/useCategoryTree";
import { type PageRequest, type PageResult, useCursorPager } from "@/composables/useCursorPager";
import { type ListParam, useListUrlState } from "@/composables/useListUrlState";

export type ProductListItem = components["schemas"]["ProductListItemResponse"];
export type ProductStatus = ProductListItem["status"];

/** The API searches names of 2 to 100 characters (code points, as it counts them), not UTF-16 units. */
export const MIN_SEARCH_LENGTH = 2;
export const MAX_SEARCH_LENGTH = 100;
export const SEARCH_DEBOUNCE_MS = 300;

/** The page sizes of the list; 25 is the default of the API, so it is not sent. */
export const PER_PAGE_OPTIONS = [10, 25, 50];
export const DEFAULT_PER_PAGE = 25;

export const FORBIDDEN_MESSAGE = "Nie masz dostępu do produktów.";
export const EMPTY_MESSAGE = "Nie ma jeszcze produktów.";
const TOO_SHORT_HINT = "Wpisz co najmniej 2 znaki, aby wyszukać.";
const TOO_LONG_HINT = "Wpisz najwyżej 100 znaków, aby wyszukać.";

/** The tree of the category filter, as `useCategoryTree` gives it: `loaded` is true when the attempt to load it is over. */
export interface CategoryLookup {
  nodes: Ref<CategoryTreeNode[]>;
  loaded: Ref<boolean>;
}

interface ProductQuery {
  q?: string;
  category?: string;
  status?: ProductStatus;
  after?: string;
  perPage?: number;
}

function characters(text: string): number {
  return [...text].length;
}

function validTerm(text: string): string | null {
  const term = text.trim();
  const length = characters(term);
  return length >= MIN_SEARCH_LENGTH && length <= MAX_SEARCH_LENGTH ? term : null;
}

function failureMessage(status: number): string {
  switch (status) {
    case 403:
      return FORBIDDEN_MESSAGE;
    case 404:
      return "Wybrana kategoria już nie istnieje. Wybierz inną.";
    case 400:
      return `Nieprawidłowe parametry wyszukiwania (HTTP ${status}).`;
    default:
      return `Nie udało się pobrać produktów (HTTP ${status}).`;
  }
}

function categoryIds(nodes: CategoryTreeNode[]): Set<string> {
  const ids = new Set<string>();
  const walk = (level: CategoryTreeNode[]) => {
    for (const node of level) {
      ids.add(node.key);
      walk(node.children);
    }
  };
  walk(nodes);
  return ids;
}

/**
 * The product list: a search by name (from two characters, after a pause in typing), a category filter (also its
 * subcategories: the API does that), a status filter (null: all) and a page size (10, 25 or 50). The search, the filters
 * and the page size live in the address (`useListUrlState`: read on entering and on a change from outside, written
 * when changed; only the applied search goes there, not what is being typed) and the paging is `useCursorPager`
 * (cursor stack, late answers, retry, what a failure leaves on the screen). What stays here is the request to the API,
 * its messages and the search with its pause and length rules. `categories` is the tree of the filter: a category of
 * the address that it does not have is skipped (the API would answer 404), and the list waits for it only when the address
 * has a category. Without it a category is taken as it comes.
 */
export function useProductList(categories?: CategoryLookup) {
  const known = computed(() => (categories ? categoryIds(categories.nodes.value) : null));

  const url = useListUrlState({
    q: { parse: validTerm, fallback: null } satisfies ListParam<string, null>,
    category: {
      parse: (raw) => raw,
      fallback: null,
      confirm: categories ? (id) => (categories.loaded.value ? (known.value?.has(id) ?? false) : undefined) : undefined,
    } satisfies ListParam<string, null>,
    status: {
      parse: (raw) => (raw === "draft" || raw === "published" ? raw : null),
      fallback: null,
    } satisfies ListParam<ProductStatus, null>,
    perPage: {
      parse: (raw) => PER_PAGE_OPTIONS.find((size) => String(size) === raw) ?? null,
      fallback: DEFAULT_PER_PAGE,
    } satisfies ListParam<number, number>,
  });

  // What is typed in the field; only a term that passes the rules, after the pause, reaches the query and the address.
  const search = ref(url.address.value.q ?? "");
  let timer: ReturnType<typeof setTimeout> | undefined;

  const searchTooShort = computed(() => {
    const length = characters(search.value.trim());
    return length > 0 && length < MIN_SEARCH_LENGTH;
  });
  const searchHint = computed(() => {
    if (searchTooShort.value) {
      return TOO_SHORT_HINT;
    }
    return characters(search.value.trim()) > MAX_SEARCH_LENGTH ? TOO_LONG_HINT : null;
  });

  async function fetchPage({ query, after }: PageRequest<typeof url.query.value>): Promise<PageResult<ProductListItem>> {
    const apiQuery: ProductQuery = {};
    if (query.q !== null) {
      apiQuery.q = query.q;
    }
    if (query.category !== null) {
      apiQuery.category = query.category;
    }
    if (query.status !== null) {
      apiQuery.status = query.status;
    }
    if (query.perPage !== DEFAULT_PER_PAGE) {
      apiQuery.perPage = query.perPage;
    }
    if (after) {
      apiQuery.after = after;
    }
    try {
      const result = await getApiClient().GET("/api/v1/admin/products", { params: { query: apiQuery } });
      if (result.data) {
        return { items: result.data.items, next: result.data.next };
      }
      return { error: failureMessage(result.response.status), forbidden: result.response.status === 403 };
    } catch {
      return { error: "Nie udało się pobrać produktów. Sprawdź połączenie z API." };
    }
  }

  const pager = useCursorPager(fetchPage, url.query, { ready: url.ready });

  watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
      const term = search.value.trim();
      const length = characters(term);
      if (length > 0 && (length < MIN_SEARCH_LENGTH || length > MAX_SEARCH_LENGTH)) {
        // Not a search the API takes: the list stays as it is and the hint says why.
        return;
      }
      url.set({ q: length === 0 ? null : term });
    }, SEARCH_DEBOUNCE_MS);
  });

  // The address changed from outside: it is the new truth, also for what is in the field.
  watch(url.address, (address) => {
    clearTimeout(timer);
    search.value = address.q ?? "";
  });

  const filtered = computed(() => url.query.value.q !== null || url.query.value.category !== null || url.query.value.status !== null);

  function selectCategory(id: string | null): void {
    url.set({ category: id });
  }

  function selectStatus(value: ProductStatus | null): void {
    url.set({ status: value });
  }

  function setPerPage(size: number): void {
    url.set({ perPage: size });
  }

  /** Drops the search, the filters and the page size: the address is `/products` again. */
  function clearFilters(): void {
    clearTimeout(timer);
    search.value = "";
    url.clear();
  }

  onUnmounted(() => {
    clearTimeout(timer);
  });

  return {
    search,
    searchTooShort,
    searchHint,
    query: url.query,
    filtered,
    items: pager.items,
    loading: pager.loading,
    loaded: pager.loaded,
    error: pager.error,
    forbidden: pager.forbidden,
    hasPrevious: pager.hasPrevious,
    hasNext: pager.hasNext,
    pageNumber: pager.pageNumber,
    range: pager.range,
    selectCategory,
    selectStatus,
    setPerPage,
    clearFilters,
    goNext: pager.goNext,
    goPrevious: pager.goPrevious,
    retry: pager.retry,
  };
}
