import { computed, type MaybeRefOrGetter, ref, toValue } from "vue";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";
import { type PageRequest, type PageResult, useCursorPager } from "@/composables/useCursorPager";

export type CustomerOrderItem = components["schemas"]["BackofficeOrderListItem"];

/** The page sizes of the list; 25 is the default of the API, so it is not sent. */
export const PER_PAGE_OPTIONS = [10, 25, 50];
export const DEFAULT_PER_PAGE = 25;

export const FORBIDDEN_MESSAGE = "Nie masz dostępu do zamówień.";
export const EMPTY_MESSAGE = "Ten klient nie ma zamówień.";

function failureMessage(status: number): string {
  return status === 403 ? FORBIDDEN_MESSAGE : `Nie udało się pobrać zamówień klienta (HTTP ${status}).`;
}

/**
 * The orders of one customer, newest first (`GET /admin/orders?customerId=`: no search, no sort, no days), paged by
 * `useCursorPager` with the total of the first page. The page size is the only thing the staff member chooses and it is
 * not kept in the address, which belongs to the card. Another customer starts again from the first page.
 */
export function useCustomerOrders(customerId: MaybeRefOrGetter<string>) {
  const perPage = ref(DEFAULT_PER_PAGE);
  const query = computed(() => ({ customerId: toValue(customerId), perPage: perPage.value }));

  async function fetchPage({ query: current, after, signal }: PageRequest<{ customerId: string; perPage: number }>): Promise<PageResult<CustomerOrderItem>> {
    const apiQuery: { customerId: string; perPage?: number; after?: string } = { customerId: current.customerId };
    if (current.perPage !== DEFAULT_PER_PAGE) {
      apiQuery.perPage = current.perPage;
    }
    if (after) {
      apiQuery.after = after;
    }
    try {
      const result = await getApiClient().GET("/api/v1/admin/orders", { params: { query: apiQuery }, signal });
      if (result.data) {
        return { items: result.data.items, next: result.data.nextCursor, total: result.data.total };
      }
      return { error: failureMessage(result.response.status), forbidden: result.response.status === 403 };
    } catch {
      return { error: "Nie udało się pobrać zamówień klienta. Sprawdź połączenie z API." };
    }
  }

  const pager = useCursorPager(fetchPage, query);

  function setPerPage(size: number): void {
    perPage.value = size;
  }

  return {
    perPage,
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
    setPerPage,
    goNext: pager.goNext,
    goPrevious: pager.goPrevious,
    retry: pager.retry,
  };
}
