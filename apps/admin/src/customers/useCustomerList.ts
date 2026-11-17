import { computed, ref, watch } from "vue";
import { getApiClient } from "@/api/client";
import type { components, operations } from "@/api/schema";
import { type SortOption, sortParam } from "@/composables/listSort";
import { type PageRequest, type PageResult, useCursorPager } from "@/composables/useCursorPager";
import { type ListParam, useListUrlState } from "@/composables/useListUrlState";

export type CustomerListItem = components["schemas"]["BackofficeCustomerListItemResponse"];

/** The API takes a search of 3 to 100 characters once trimmed, without control characters. */
export const MIN_SEARCH_LENGTH = 3;
export const MAX_SEARCH_LENGTH = 100;

export const SEARCH_HINT = "E-mail albo imię i nazwisko (fragment od 3 znaków). Enter wyszukuje.";
export const SEARCH_TOO_SHORT_HINT = "Wpisz co najmniej 3 znaki, aby wyszukać.";
export const SEARCH_SORT_MESSAGE = "Wyszukiwanie działa tylko z sortowaniem po ostatnim zamówieniu.";

export const FORBIDDEN_MESSAGE = "Nie masz dostępu do klientów.";
export const EMPTY_MESSAGE = "Nie ma jeszcze klientów z zamówieniami.";

/** The page sizes of the list; 25 is the default of the API, so it is not sent. */
export const PER_PAGE_OPTIONS = [10, 25, 50];
export const DEFAULT_PER_PAGE = 25;

/** The keys of `sort` of the API; the newest last order first is its default, so it is not sent. */
export type CustomerSort = NonNullable<NonNullable<operations["listCustomers"]["parameters"]["query"]>["sort"]>;

export const DEFAULT_CUSTOMER_SORT: CustomerSort = "-lastOrderAt";

const CUSTOMER_SORTS: readonly SortOption<CustomerSort>[] = [
  { value: "-lastOrderAt", label: "Ostatnie zamówienie" },
  { value: "name", label: "Nazwa A–Z" },
  { value: "-orderCount", label: "Najwięcej zamówień" },
  { value: "-paidGross", label: "Najwyższa wartość" },
];

interface CustomerQuery {
  q?: string;
  sort?: CustomerSort;
  after?: string;
  perPage?: number;
}

function characters(text: string): number {
  return [...text].length;
}

/** Control characters, and the line and paragraph separators, which the API refuses in a search (`trim()` removes the rest of the white space, the no-break space too). */
const CONTROL = /[\p{Cc}\u{2028}\u{2029}]/u;

/** The trimmed term when the API takes it as a search (3 to 100 characters, no control characters), otherwise null. */
function validTerm(text: string): string | null {
  const term = text.trim();
  const length = characters(term);
  return length >= MIN_SEARCH_LENGTH && length <= MAX_SEARCH_LENGTH && !CONTROL.test(term) ? term : null;
}

function failureMessage(status: number): string {
  switch (status) {
    case 403:
      return FORBIDDEN_MESSAGE;
    case 400:
      return `Nieprawidłowe parametry wyszukiwania (HTTP ${status}).`;
    default:
      return `Nie udało się pobrać klientów (HTTP ${status}).`;
  }
}

/**
 * The customer list: a search (a fragment of the email or the name, from 3 characters, applied when the staff member
 * submits it with Enter), an order (`sort`: by the last order, the name, the number of orders or the paid value; the
 * search needs the last order, so the other orders are off while one is applied) and a page size (10, 25 or 50). They
 * live in the address (`useListUrlState`: `q`, `sort`, `perPage`) and the paging is `useCursorPager`. The panel computes
 * nothing: it shows what the API sent.
 */
export function useCustomerList() {
  const url = useListUrlState({
    q: { parse: validTerm, fallback: null } satisfies ListParam<string, null>,
    sort: sortParam(CUSTOMER_SORTS, DEFAULT_CUSTOMER_SORT),
    perPage: {
      parse: (raw) => PER_PAGE_OPTIONS.find((size) => String(size) === raw) ?? null,
      fallback: DEFAULT_PER_PAGE,
    } satisfies ListParam<number, number>,
  });

  /** The order the list is in: a search is always by the last order, whatever the address says. */
  const effectiveSort = (query: { q: string | null; sort: CustomerSort }): CustomerSort => (query.q !== null ? DEFAULT_CUSTOMER_SORT : query.sort);

  const sort = computed(() => effectiveSort(url.query.value));

  /** Every order, each with the reason it cannot be chosen now (only the last order with a search). */
  const sortOptions = computed<SortOption<CustomerSort>[]>(() =>
    CUSTOMER_SORTS.map((option) => (url.query.value.q !== null && option.value !== DEFAULT_CUSTOMER_SORT ? { ...option, disabledReason: SEARCH_SORT_MESSAGE } : option)),
  );

  async function fetchPage({ query, after }: PageRequest<typeof url.query.value>): Promise<PageResult<CustomerListItem>> {
    const apiQuery: CustomerQuery = {};
    if (query.q !== null) {
      apiQuery.q = query.q;
    }
    const effective = effectiveSort(query);
    if (effective !== DEFAULT_CUSTOMER_SORT) {
      apiQuery.sort = effective;
    }
    if (query.perPage !== DEFAULT_PER_PAGE) {
      apiQuery.perPage = query.perPage;
    }
    if (after) {
      apiQuery.after = after;
    }
    try {
      const result = await getApiClient().GET("/api/v1/admin/customers", { params: { query: apiQuery } });
      if (result.data) {
        return { items: result.data.items, next: result.data.nextCursor, total: result.data.total };
      }
      return { error: failureMessage(result.response.status), forbidden: result.response.status === 403 };
    } catch {
      return { error: "Nie udało się pobrać klientów. Sprawdź połączenie z API." };
    }
  }

  const pager = useCursorPager(fetchPage, url.query, { ready: url.ready });

  // What the staff member typed; it reaches the list only through `submitSearch`, or from the address.
  const search = ref(url.address.value.q ?? "");

  // The address changed from outside: it is the new truth, also for what is in the field.
  watch(url.address, (address) => {
    search.value = address.q ?? "";
  });

  /** Says what the field takes; while the text is 1 or 2 characters long it says that the search needs 3. */
  const searchHint = computed(() => {
    const length = characters(search.value.trim());
    return length > 0 && length < MIN_SEARCH_LENGTH ? SEARCH_TOO_SHORT_HINT : SEARCH_HINT;
  });

  const filtered = computed(() => url.query.value.q !== null);

  function setSort(selected: string): void {
    url.set({ sort: CUSTOMER_SORTS.find((option) => option.value === selected)?.value ?? DEFAULT_CUSTOMER_SORT });
  }

  function setPerPage(size: number): void {
    url.set({ perPage: size });
  }

  /**
   * Searches for what is typed (Enter): the trimmed term, or all customers when it is blank; the same term loads the list
   * again. A term the API would refuse (1 or 2 characters, too long, control characters) is no search: nothing changes.
   * A search applied over another order returns to the last order.
   */
  function submitSearch(): void {
    const term = validTerm(search.value);
    if (term === null && search.value.trim() !== "") {
      return;
    }
    if (term === url.query.value.q) {
      void pager.refresh();
      return;
    }
    url.set(term !== null ? { q: term, sort: DEFAULT_CUSTOMER_SORT } : { q: term });
  }

  /** Drops the search, the order and the page size: the address is `/customers` again. */
  function clearFilters(): void {
    search.value = "";
    url.clear();
  }

  return {
    search,
    searchHint,
    query: url.query,
    sort,
    sortOptions,
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
    total: pager.total,
    setSort,
    setPerPage,
    submitSearch,
    clearFilters,
    goNext: pager.goNext,
    goPrevious: pager.goPrevious,
    retry: pager.retry,
  };
}
