import { computed } from "vue";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";
import { type PageRequest, type PageResult, useCursorPager } from "@/composables/useCursorPager";
import { type ListParam, useListUrlState } from "@/composables/useListUrlState";
import { STATUS_FILTER_OPTIONS } from "./format";

export type PromotionListItem = components["schemas"]["PromotionListItemResponse"];
export type PromotionStatus = PromotionListItem["status"];

export const FORBIDDEN_MESSAGE = "Nie masz dostępu do promocji.";
export const EMPTY_MESSAGE = "Nie ma jeszcze promocji.";

/** The page sizes of the list; 25 is the default of the API, so it is not sent. */
export const PER_PAGE_OPTIONS = [10, 25, 50];
export const DEFAULT_PER_PAGE = 25;

interface PromotionQuery {
  status?: PromotionStatus;
  after?: string;
  perPage?: number;
}

function failureMessage(status: number): string {
  switch (status) {
    case 403:
      return FORBIDDEN_MESSAGE;
    case 400:
      return `Nieprawidłowe parametry listy (HTTP ${status}).`;
    default:
      return `Nie udało się pobrać promocji (HTTP ${status}).`;
  }
}

/**
 * The promotion list: a status filter and a page size (10, 25 or 50). Both live in the address (`useListUrlState`:
 * `status` and `perPage`; read on entering and on a change from outside, such as the link of the dashboard, the menu or
 * Back, written with `replace` when changed) and the paging is `useCursorPager` (cursor stack, late answers, retry, what
 * a failure leaves on the screen). What stays here is the request to the API with its messages. The API has no search
 * of promotions. The status of a row is the API's; the panel computes nothing.
 */
export function usePromotionList() {
  const url = useListUrlState({
    status: {
      parse: (raw) => {
        const option = STATUS_FILTER_OPTIONS.find((candidate) => candidate.value === raw);
        return option === undefined || option.value === "all" ? null : option.value;
      },
      fallback: null,
    } satisfies ListParam<PromotionStatus, null>,
    perPage: {
      parse: (raw) => PER_PAGE_OPTIONS.find((size) => String(size) === raw) ?? null,
      fallback: DEFAULT_PER_PAGE,
    } satisfies ListParam<number, number>,
  });

  async function fetchPage({ query, after }: PageRequest<typeof url.query.value>): Promise<PageResult<PromotionListItem>> {
    const apiQuery: PromotionQuery = {};
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
      const result = await getApiClient().GET("/api/v1/admin/promotions", { params: { query: apiQuery } });
      if (result.data) {
        return { items: result.data.items, next: result.data.next, total: result.data.total };
      }
      return { error: failureMessage(result.response.status), forbidden: result.response.status === 403 };
    } catch {
      return { error: "Nie udało się pobrać promocji. Sprawdź połączenie z API." };
    }
  }

  const pager = useCursorPager(fetchPage, url.query, { ready: url.ready });

  const filtered = computed(() => url.query.value.status !== null);

  function selectStatus(selected: PromotionStatus | null): void {
    url.set({ status: selected });
  }

  function setPerPage(size: number): void {
    url.set({ perPage: size });
  }

  /** Drops the filter and the page size: the address is `/promotions` again. */
  function clearFilters(): void {
    url.clear();
  }

  return {
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
    total: pager.total,
    selectStatus,
    setPerPage,
    clearFilters,
    goNext: pager.goNext,
    goPrevious: pager.goPrevious,
    retry: pager.retry,
  };
}
