import { computed, ref, watch } from "vue";
import { getApiClient } from "@/api/client";
import type { components, operations } from "@/api/schema";
import { type PageRequest, type PageResult, useCursorPager } from "@/composables/useCursorPager";
import { type SortOption, sortParam } from "@/composables/listSort";
import { type ListParam, useListUrlState } from "@/composables/useListUrlState";
import { type OrderStatus, STATUS_OPTIONS } from "./format";

export type OrderListItem = components["schemas"]["BackofficeOrderListItem"];

/** The API takes a search of 3 to 254 characters once trimmed (an order number, a complete email or a fragment of 3 to 100). */
export const MIN_SEARCH_LENGTH = 3;
export const MAX_SEARCH_LENGTH = 254;

export const SEARCH_HINT = "Numer, e-mail albo fragment (od 3 znaków) numeru, e-maila lub nazwiska. Enter wyszukuje.";
export const SEARCH_TOO_SHORT_HINT = "Wpisz co najmniej 3 znaki, aby wyszukać.";
export const RANGE_ERROR = "Data „Do” nie może być wcześniejsza niż data „Od”.";
export const RANGE_REFUND_MESSAGE = "Zwroty do wykonania nie łączą się z zakresem dat.";

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
export const TOTAL_RANGE_SORT_MESSAGE = "Kwoty nie da się sortować przy zakresie dat.";

const byTotal = (sort: OrderSort) => sort === "-total" || sort === "total";

interface OrderQuery {
  status?: OrderStatus;
  refund?: "pending" | "overdue";
  q?: string;
  placedFrom?: string;
  placedTo?: string;
  sort?: OrderSort;
  after?: string;
  perPage?: number;
}

function characters(text: string): number {
  return [...text].length;
}

/** The trimmed term when the API takes it as a search (3 to 254 characters), otherwise null. `trim()` knows the no-break space and U+2028. */
function validTerm(text: string): string | null {
  const term = text.trim();
  const length = characters(term);
  return length >= MIN_SEARCH_LENGTH && length <= MAX_SEARCH_LENGTH ? term : null;
}

/** The first and the last day the API takes. */
const FIRST_DAY = "2000-01-01";
const LAST_DAY = "2999-12-31";

/** The day as `YYYY-MM-DD` when it is a date of 2000 to 2999 (the API takes no other), otherwise null. */
function validDay(text: string): string | null {
  const match = /^([0-9]{4})-([0-9]{2})-([0-9]{2})$/.exec(text);
  if (match === null) {
    return null;
  }
  const [year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])];
  const date = new Date(Date.UTC(year, month - 1, day));
  const real = date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day;
  return real && year >= 2000 && year <= 2999 ? text : null;
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
 * The order list with a status filter, the switch for the refunds to do, a search (an order number, a complete email or
 * a fragment of the number, the email or the name, from 3 characters, applied when the staff member submits it with
 * Enter), a range of days of the placement (`placedFrom`, `placedTo`: applied as soon as a day is chosen; an end before
 * the start is not applied and says why; not with the refunds to do, which the API refuses), an order (`sort`: by placement or by the amount, either way; not a
 * filter) and a page size (10, 25 or 50). The search, the filters, the order and the
 * page size live in the address (`useListUrlState`: `q`, `status`, `refund` (`pending` or `overdue`), `placedFrom`, `placedTo`, `sort`, `perPage`; read on entering and
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
    placedFrom: { parse: validDay, fallback: null } satisfies ListParam<string, null>,
    placedTo: { parse: validDay, fallback: null } satisfies ListParam<string, null>,
    // The refunds to do (`pending`) or only those past their due date (`overdue`, a subset with the same order); none by default.
    refund: {
      parse: (raw) => (raw === "pending" || raw === "overdue" ? raw : null),
      fallback: null,
    } satisfies ListParam<"pending" | "overdue", null>,
    sort: sortParam(ORDER_SORTS, DEFAULT_ORDER_SORT),
    perPage: {
      parse: (raw) => PER_PAGE_OPTIONS.find((size) => String(size) === raw) ?? null,
      fallback: DEFAULT_PER_PAGE,
    } satisfies ListParam<number, number>,
  });

  /** True while a search or a range of days is applied: the amount cannot be sorted then, the API refuses it (no index serves both). */
  const hasRange = (query: { placedFrom: string | null; placedTo: string | null }) => query.placedFrom !== null || query.placedTo !== null;
  const refusesTotal = (query: { q: string | null; placedFrom: string | null; placedTo: string | null }) => query.q !== null || hasRange(query);

  /**
   * The order the list is in: none for the refunds to do (their own, by due date), the newest first for an amount while a
   * search or a range of days is applied, the sort of the address otherwise.
   */
  const sort = computed<OrderSort | null>(() => {
    const { refund, sort: chosen } = url.query.value;
    if (refund) {
      return null;
    }
    return refusesTotal(url.query.value) && byTotal(chosen) ? DEFAULT_ORDER_SORT : chosen;
  });

  /** Every order, each with the reason it cannot be chosen now (the refunds to do take none, the amount no search and no days). */
  const sortOptions = computed<SortOption<OrderSort>[]>(() =>
    ORDER_SORTS.map((option) => {
      if (url.query.value.refund) {
        return { ...option, disabledReason: REFUND_SORT_MESSAGE };
      }
      if (!byTotal(option.value) || !refusesTotal(url.query.value)) {
        return option;
      }
      return { ...option, disabledReason: url.query.value.q !== null ? TOTAL_SORT_MESSAGE : TOTAL_RANGE_SORT_MESSAGE };
    }),
  );

  async function fetchPage({ query, after }: PageRequest<typeof url.query.value>): Promise<PageResult<OrderListItem>> {
    const apiQuery: OrderQuery = {};
    if (query.status !== null) {
      apiQuery.status = query.status;
    }
    if (query.refund) {
      apiQuery.refund = query.refund;
    }
    if (query.q !== null) {
      apiQuery.q = query.q;
    }
    // The refunds to do take no sort and no days, the amount no search and no days, whatever the address says: the API refuses them.
    if (!query.refund && query.placedFrom !== null) {
      apiQuery.placedFrom = query.placedFrom;
    }
    if (!query.refund && query.placedTo !== null) {
      apiQuery.placedTo = query.placedTo;
    }
    const effective = query.refund ? null : refusesTotal(query) && byTotal(query.sort) ? DEFAULT_ORDER_SORT : query.sort;
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

  // The days in the fields: what was chosen, also an end before the start that is not applied (and says why).
  const placedFromField = ref(url.query.value.placedFrom ?? "");
  const placedToField = ref(url.query.value.placedTo ?? "");

  // The address changed from outside: it is the new truth, also for what is in the fields.
  watch(url.address, (address) => {
    search.value = address.q ?? "";
    placedFromField.value = url.query.value.placedFrom ?? "";
    placedToField.value = url.query.value.placedTo ?? "";
  });

  /** Says what the field takes; while the text is 1 or 2 characters long it says that the search needs 3. */
  const searchHint = computed(() => {
    const length = characters(search.value.trim());
    return length > 0 && length < MIN_SEARCH_LENGTH ? SEARCH_TOO_SHORT_HINT : SEARCH_HINT;
  });

  /**
   * The day a field stands for: none when it is empty, the day when it is one, and what is applied now while it holds
   * something that is no day the API takes. Chrome passes through such values while a year is typed (0002, 0020, 0202,
   * then 2026), and each of them must neither clear the day nor ask for a list.
   */
  const dayOf = (field: string, applied: string | null) => (field === "" ? null : (validDay(field) ?? applied));

  /** The days the fields stand for: what would be applied, and what the range is checked and the fields are bounded by. */
  const fromDay = computed(() => dayOf(placedFromField.value, url.query.value.placedFrom));
  const toDay = computed(() => dayOf(placedToField.value, url.query.value.placedTo));

  const rangeError = computed(() => (fromDay.value !== null && toDay.value !== null && fromDay.value > toDay.value ? RANGE_ERROR : null));

  /** `min` and `max` of the fields: the years the API takes, and the other end of the range. */
  const dayBounds = computed(() => ({
    fromMin: FIRST_DAY,
    fromMax: toDay.value ?? LAST_DAY,
    toMin: fromDay.value ?? FIRST_DAY,
    toMax: LAST_DAY,
  }));
  const rangeDisabled = computed(() => url.query.value.refund !== null);
  const rangeDisabledReason = computed(() => (url.query.value.refund !== null ? RANGE_REFUND_MESSAGE : null));

  const status = computed(() => url.query.value.status);
  const refundPending = computed(() => url.query.value.refund !== null);
  const refundOverdue = computed(() => url.query.value.refund === "overdue");
  const filtered = computed(() => url.query.value.q !== null || url.query.value.status !== null || url.query.value.refund !== null || hasRange(url.query.value));

  function selectStatus(selected: OrderStatus | null): void {
    url.set({ status: selected });
  }

  /** The switch „Zwroty do wykonania”: on shows the refunds to do, off drops them and the overdue ones with them. */
  function setRefundPending(enabled: boolean): void {
    if (enabled !== refundPending.value) {
      url.set({ refund: enabled ? "pending" : null });
    }
  }

  /** The switch „Tylko po terminie”: the refunds to do that are past their due date; it turns the refunds to do on. */
  function setRefundOverdue(enabled: boolean): void {
    if (enabled !== refundOverdue.value && (enabled || refundPending.value)) {
      url.set({ refund: enabled ? "overdue" : "pending" });
    }
  }

  /** Applies the days in the fields, unless the end is before the start; the amount cannot be sorted with days, so it returns to the newest first. */
  function applyRange(): void {
    const [from, to] = [fromDay.value, toDay.value];
    if (rangeError.value !== null) {
      return;
    }
    url.set(byTotal(url.query.value.sort) && (from !== null || to !== null) ? { placedFrom: from, placedTo: to, sort: DEFAULT_ORDER_SORT } : { placedFrom: from, placedTo: to });
  }

  /** A day chosen in „Od” (`YYYY-MM-DD`, or "" when it is cleared). */
  function setPlacedFrom(day: string): void {
    placedFromField.value = day;
    applyRange();
  }

  /** A day chosen in „Do” (`YYYY-MM-DD`, or "" when it is cleared). */
  function setPlacedTo(day: string): void {
    placedToField.value = day;
    applyRange();
  }

  function setSort(selected: string): void {
    url.set({ sort: ORDER_SORTS.find((option) => option.value === selected)?.value ?? DEFAULT_ORDER_SORT });
  }

  function setPerPage(size: number): void {
    url.set({ perPage: size });
  }

  /**
   * Searches for what is typed (Enter): the trimmed term, or all orders when it is blank; the same term loads the list
   * again. A term of 1 or 2 characters is no search: nothing changes and the hint says why.
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
    // The amount cannot be sorted with a search: a search applied over it returns to the newest first.
    url.set(term !== null && byTotal(url.query.value.sort) ? { q: term, sort: DEFAULT_ORDER_SORT } : { q: term });
  }

  /** Drops the search, the filters, the order and the page size: the address is `/orders` again. */
  function clearFilters(): void {
    search.value = "";
    placedFromField.value = "";
    placedToField.value = "";
    url.clear();
  }

  return {
    search,
    searchHint,
    placedFromField,
    placedToField,
    placedFrom: computed(() => url.query.value.placedFrom),
    placedTo: computed(() => url.query.value.placedTo),
    rangeError,
    dayBounds,
    rangeDisabled,
    rangeDisabledReason,
    query: url.query,
    status,
    refundPending,
    refundOverdue,
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
    setRefundOverdue,
    setPlacedFrom,
    setPlacedTo,
    setSort,
    setPerPage,
    submitSearch,
    clearFilters,
    goNext: pager.goNext,
    goPrevious: pager.goPrevious,
    retry: pager.retry,
  };
}
