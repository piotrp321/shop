import { computed, ref, watch } from "vue";
import { getApiClient } from "@/api/client";
import type { components, operations } from "@/api/schema";
import { type PageRequest, type PageResult, useCursorPager } from "@/composables/useCursorPager";
import { type SortOption, sortParam } from "@/composables/listSort";
import { type ListParam, useListUrlState } from "@/composables/useListUrlState";
import { type OrderStatus, STATUS_OPTIONS } from "./format";

export type OrderListItem = components["schemas"]["BackofficeOrderListItem"];

/** The API accepts a search of 1 to 254 characters once trimmed. */
export const MAX_SEARCH_LENGTH = 254;

export const FORBIDDEN_MESSAGE = "Nie masz dostępu do zamówień.";
export const EMPTY_MESSAGE = "Nie ma jeszcze zamówień.";

/** The page sizes of the list; 25 is the default of the API, so it is not sent. */
export const PER_PAGE_OPTIONS = [10, 25, 50];
export const DEFAULT_PER_PAGE = 25;

/** The keys of `sort` of the API; the newest first is its default, so it is not sent. */
export type OrderSort = NonNullable<NonNullable<operations["listOrders"]["parameters"]["query"]>["sort"]>;

export const DEFAULT_ORDER_SORT: OrderSort = "-placedAt";

const ORDER_SORTS: readonly SortOption<OrderSort>[] = [
  { value: "-placedAt", label: "Najnowsze" },
  { value: "placedAt", label: "Najstarsze" },
  { value: "-total", label: "Najwyższa kwota" },
  { value: "total", label: "Najniższa kwota" },
];

export const REFUND_SORT_MESSAGE = "Zwroty do wykonania są w kolejności terminu zwrotu.";
export const TOTAL_SORT_MESSAGE = "Kwoty nie da się sortować przy wyszukiwaniu.";

const byTotal = (sort: OrderSort) => sort === "-total" || sort === "total";

interface OrderQuery {
  status?: OrderStatus;
  refund?: "pending";
  q?: string;
  sort?: OrderSort;
  after?: string;
  perPage?: number;
}

/** The trimmed term when the API takes it as a search (1 to 254 characters), otherwise null. `trim()` knows the no-break space and U+2028. */
function validTerm(text: string): string | null {
  const term = text.trim();
  const length = [...term].length;
  return length >= 1 && length <= MAX_SEARCH_LENGTH ? term : null;
}

function failureMessage(status: number): string {
  switch (status) {
    case 403:
      return FORBIDDEN_MESSAGE;
    case 400:
      return `Nieprawidłowe parametry wyszukiwania (HTTP ${status}).`;
    default:
      return `Nie udało się pobrać zamówień (HTTP ${status}).`;
  }
}

/**
 * The order list with a status filter, the switch for the refunds to do, a search (an exact order number or email,
 * applied when the staff member submits it with Enter), an order (`sort`: by placement or by the amount, either way; not a
 * filter) and a page size (10, 25 or 50). The search, the filters, the order and the
 * page size live in the address (`useListUrlState`: `q`, `status`, `refund=pending`, `sort`, `perPage`; read on entering and
 * on a change from outside, such as the menu item „Zwroty do wykonania” on the open list, the link „Inne zamówienia
 * klienta” or Back, written with `replace` when changed; only the applied search goes there, not what is being typed)
 * and the paging is `useCursorPager` (cursor stack, late answers, retry, what a failure leaves on the screen). What stays
 * here is the request to the API with its messages and the rule of the search. The panel computes nothing: it shows
 * what the API sent.
 */
export function useOrderList() {
  const url = useListUrlState({
    q: { parse: validTerm, fallback: null } satisfies ListParam<string, null>,
    status: {
      parse: (raw) => STATUS_OPTIONS.find((option) => option.value === raw)?.value ?? null,
      fallback: null,
    } satisfies ListParam<OrderStatus, null>,
    refund: {
      parse: (raw) => (raw === "pending" ? true : null),
      format: () => "pending",
      fallback: false,
    } as ListParam<boolean, boolean>,
    sort: sortParam(ORDER_SORTS, DEFAULT_ORDER_SORT),
    perPage: {
      parse: (raw) => PER_PAGE_OPTIONS.find((size) => String(size) === raw) ?? null,
      fallback: DEFAULT_PER_PAGE,
    } satisfies ListParam<number, number>,
  });

  /**
   * The order the list is in: none for the refunds to do (their own, by due date), the newest first for an amount while a
   * search is applied (the API refuses it, no index serves both), the sort of the address otherwise.
   */
  const sort = computed<OrderSort | null>(() => {
    const { refund, q, sort: chosen } = url.query.value;
    if (refund) {
      return null;
    }
    return q !== null && byTotal(chosen) ? DEFAULT_ORDER_SORT : chosen;
  });

  /** Every order, each with the reason it cannot be chosen now (the refunds to do take none, the amount no search). */
  const sortOptions = computed<SortOption<OrderSort>[]>(() =>
    ORDER_SORTS.map((option) => {
      if (url.query.value.refund) {
        return { ...option, disabledReason: REFUND_SORT_MESSAGE };
      }
      return url.query.value.q !== null && byTotal(option.value) ? { ...option, disabledReason: TOTAL_SORT_MESSAGE } : option;
    }),
  );

  async function fetchPage({ query, after }: PageRequest<typeof url.query.value>): Promise<PageResult<OrderListItem>> {
    const apiQuery: OrderQuery = {};
    if (query.status !== null) {
      apiQuery.status = query.status;
    }
    if (query.refund) {
      apiQuery.refund = "pending";
    }
    if (query.q !== null) {
      apiQuery.q = query.q;
    }
    // The refunds to do take no sort and the amount no search, whatever the address says: the API refuses both.
    const effective = query.refund ? null : query.q !== null && byTotal(query.sort) ? DEFAULT_ORDER_SORT : query.sort;
    if (effective !== null && effective !== DEFAULT_ORDER_SORT) {
      apiQuery.sort = effective;
    }
    if (query.perPage !== DEFAULT_PER_PAGE) {
      apiQuery.perPage = query.perPage;
    }
    if (after) {
      apiQuery.after = after;
    }
    try {
      const result = await getApiClient().GET("/api/v1/admin/orders", { params: { query: apiQuery } });
      if (result.data) {
        return { items: result.data.items, next: result.data.nextCursor, total: result.data.total };
      }
      return { error: failureMessage(result.response.status), forbidden: result.response.status === 403 };
    } catch {
      return { error: "Nie udało się pobrać zamówień. Sprawdź połączenie z API." };
    }
  }

  const pager = useCursorPager(fetchPage, url.query, { ready: url.ready });

  // What the staff member typed; it reaches the list only through `submitSearch`, or from the address.
  const search = ref(url.address.value.q ?? "");

  // The address changed from outside: it is the new truth, also for what is in the field.
  watch(url.address, (address) => {
    search.value = address.q ?? "";
  });

  const status = computed(() => url.query.value.status);
  const refundPending = computed(() => url.query.value.refund);
  const filtered = computed(() => url.query.value.q !== null || url.query.value.status !== null || url.query.value.refund);

  function selectStatus(selected: OrderStatus | null): void {
    url.set({ status: selected });
  }

  function setRefundPending(enabled: boolean): void {
    url.set({ refund: enabled });
  }

  function setSort(selected: string): void {
    url.set({ sort: ORDER_SORTS.find((option) => option.value === selected)?.value ?? DEFAULT_ORDER_SORT });
  }

  function setPerPage(size: number): void {
    url.set({ perPage: size });
  }

  /** Searches for what is typed (Enter): the trimmed term, or all orders when it is blank; the same term loads the list again. */
  function submitSearch(): void {
    const term = validTerm(search.value);
    if (term === url.query.value.q) {
      void pager.refresh();
      return;
    }
    // The amount cannot be sorted with a search: a search applied over it returns to the newest first.
    url.set(term !== null && byTotal(url.query.value.sort) ? { q: term, sort: DEFAULT_ORDER_SORT } : { q: term });
  }

  /** Drops the search, the filters, the order and the page size: the address is `/orders` again. */
  function clearFilters(): void {
    search.value = "";
    url.clear();
  }

  return {
    search,
    query: url.query,
    status,
    refundPending,
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
    selectStatus,
    setRefundPending,
    setSort,
    setPerPage,
    submitSearch,
    clearFilters,
    goNext: pager.goNext,
    goPrevious: pager.goPrevious,
    retry: pager.retry,
  };
}
