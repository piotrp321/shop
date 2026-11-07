import { computed, onMounted, onUnmounted, ref } from "vue";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";
import type { OrderStatus } from "./format";

export type OrderListItem = components["schemas"]["BackofficeOrderListItem"];

/** The API accepts a search of 1 to 254 characters once trimmed. */
export const MAX_SEARCH_LENGTH = 254;

export const FORBIDDEN_MESSAGE = "Nie masz dostępu do zamówień.";
export const EMPTY_MESSAGE = "Brak zamówień.";
export const NOT_FOUND_MESSAGE = "Nie znaleziono zamówień.";

interface OrderQuery {
  status?: OrderStatus;
  refund?: "pending";
  q?: string;
  after?: string;
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
 * sent when the staff member submits it) and paging with the cursors of the API. The panel keeps the stack of the
 * cursors it has visited: `cursors[i]` is the `after` of page i (null for the first), so "previous" is the cursor
 * below the current one. Changing a filter or the search starts again from the first page, because a cursor belongs to
 * the filters and the sort it came from. A failed request keeps the page that is shown and can be retried; a 403 is
 * reported as such (`forbidden`), a retry would not help. The panel computes nothing: it shows what the API sent.
 */
export function useOrderList() {
  const status = ref<OrderStatus | null>(null);
  const refundPending = ref(false);
  // What the staff member typed; it reaches the list only through `submitSearch`.
  const search = ref("");
  const appliedSearch = ref<string | null>(null);
  const items = ref<OrderListItem[]>([]);
  const next = ref<string | null>(null);
  const cursors = ref<(string | null)[]>([null]);
  const loading = ref(false);
  const loaded = ref(false);
  const error = ref<string | null>(null);
  const forbidden = ref(false);

  let attempted: (string | null)[] = [null];
  // Only the newest request may change the state; an older one that finishes late is ignored.
  let latest = 0;

  const hasPrevious = computed(() => cursors.value.length > 1);
  const hasNext = computed(() => next.value !== null);
  const pageNumber = computed(() => cursors.value.length);
  const filtered = computed(() => status.value !== null || refundPending.value || appliedSearch.value !== null);
  const emptyText = computed(() => (filtered.value ? NOT_FOUND_MESSAGE : EMPTY_MESSAGE));

  async function load(stack: (string | null)[]): Promise<void> {
    const request = ++latest;
    attempted = stack;
    loading.value = true;
    const query: OrderQuery = {};
    if (status.value !== null) {
      query.status = status.value;
    }
    if (refundPending.value) {
      query.refund = "pending";
    }
    if (appliedSearch.value !== null) {
      query.q = appliedSearch.value;
    }
    const after = stack[stack.length - 1];
    if (after) {
      query.after = after;
    }
    try {
      const result = await getApiClient().GET("/api/v1/admin/orders", { params: { query } });
      if (request !== latest) {
        return;
      }
      if (result.data) {
        items.value = result.data.items;
        next.value = result.data.nextCursor;
        cursors.value = stack;
        loaded.value = true;
        error.value = null;
        forbidden.value = false;
      } else {
        error.value = failureMessage(result.response.status);
        forbidden.value = result.response.status === 403;
      }
    } catch {
      if (request === latest) {
        error.value = "Nie udało się pobrać zamówień. Sprawdź połączenie z API.";
        forbidden.value = false;
      }
    } finally {
      if (request === latest) {
        loading.value = false;
      }
    }
  }

  // The cursors and `next` belong to the list of the old filters. Dropping them now means that if the request for the
  // new filters fails, the pager cannot pair them with an old cursor: it stays off until a retry succeeds.
  function startFromFirstPage(): Promise<void> {
    cursors.value = [null];
    next.value = null;
    return load([null]);
  }

  function selectStatus(selected: OrderStatus | null): Promise<void> {
    if (selected === status.value) {
      return Promise.resolve();
    }
    status.value = selected;
    return startFromFirstPage();
  }

  function setRefundPending(enabled: boolean): Promise<void> {
    if (enabled === refundPending.value) {
      return Promise.resolve();
    }
    refundPending.value = enabled;
    return startFromFirstPage();
  }

  /** Searches for what is typed (Enter): the trimmed term, or all orders when it is blank. */
  function submitSearch(): Promise<void> {
    const term = search.value.trim();
    appliedSearch.value = term === "" ? null : term;
    return startFromFirstPage();
  }

  function goNext(): Promise<void> {
    return next.value === null ? Promise.resolve() : load([...cursors.value, next.value]);
  }

  function goPrevious(): Promise<void> {
    return hasPrevious.value ? load(cursors.value.slice(0, -1)) : Promise.resolve();
  }

  function retry(): Promise<void> {
    return load(attempted);
  }

  onMounted(() => {
    void load([null]);
  });

  onUnmounted(() => {
    latest++;
  });

  return {
    status,
    refundPending,
    search,
    items,
    loading,
    loaded,
    error,
    forbidden,
    emptyText,
    hasPrevious,
    hasNext,
    pageNumber,
    selectStatus,
    setRefundPending,
    submitSearch,
    goNext,
    goPrevious,
    retry,
  };
}
