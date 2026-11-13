import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { type LocationQuery, useRoute } from "vue-router";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";
import { STATUS_FILTER_OPTIONS } from "./format";

export type PromotionListItem = components["schemas"]["PromotionListItemResponse"];
export type PromotionStatus = PromotionListItem["status"];

export const FORBIDDEN_MESSAGE = "Nie masz dostępu do promocji.";
export const EMPTY_MESSAGE = "Brak promocji.";
export const FILTERED_EMPTY_MESSAGE = "Nie znaleziono promocji.";

interface PromotionQuery {
  status?: PromotionStatus;
  after?: string;
}

/** The status of an address (`?status=active`): a known one; an unknown, empty or repeated value is skipped. */
function statusOf(query: LocationQuery): PromotionStatus | null {
  const option = STATUS_FILTER_OPTIONS.find((candidate) => candidate.value === query.status);
  return option === undefined || option.value === "all" ? null : option.value;
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
 * The promotion list with a status filter and paging with the cursors of the API. The panel keeps the stack of the
 * cursors it has visited: `cursors[i]` is the `after` of page i (null for the first), so "previous" is the cursor below
 * the current one. Changing the filter starts again from the first page and drops the rows and the cursors, because a
 * cursor belongs to the filter it came from: after a failed request for a new filter the table and the pager stay off
 * until a retry succeeds. A failed move to a neighbouring page keeps the page that is shown. A 403 is reported as such
 * (`forbidden`), a retry would not help. The status of a row is the API's; the panel computes nothing.
 *
 * The status filter is also read from the address (`?status=active`: the dashboard links to it): on entering and
 * whenever the query changes while the list is open. Such a change sets the filter from the address and starts from the
 * first page. A status chosen in the list is not written to the address (ADMIN-SCREENS).
 */
export function usePromotionList() {
  const route = useRoute();
  const routeName = route.name;
  const status = ref<PromotionStatus | null>(statusOf(route.query));
  const items = ref<PromotionListItem[]>([]);
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
  const emptyText = computed(() => (status.value === null ? EMPTY_MESSAGE : FILTERED_EMPTY_MESSAGE));

  async function load(stack: (string | null)[]): Promise<void> {
    const request = ++latest;
    attempted = stack;
    loading.value = true;
    const query: PromotionQuery = {};
    if (status.value !== null) {
      query.status = status.value;
    }
    const after = stack[stack.length - 1];
    if (after) {
      query.after = after;
    }
    try {
      const result = await getApiClient().GET("/api/v1/admin/promotions", { params: { query } });
      if (request !== latest) {
        return;
      }
      if (result.data) {
        items.value = result.data.items;
        next.value = result.data.next;
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
        error.value = "Nie udało się pobrać promocji. Sprawdź połączenie z API.";
        forbidden.value = false;
      }
    } finally {
      if (request === latest) {
        loading.value = false;
      }
    }
  }

  function startFromFirstPage(): Promise<void> {
    items.value = [];
    loaded.value = false;
    cursors.value = [null];
    next.value = null;
    return load([null]);
  }

  function selectStatus(selected: PromotionStatus | null): Promise<void> {
    if (selected === status.value) {
      return Promise.resolve();
    }
    status.value = selected;
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

  // A change of the query that leaves the page (the route is already the next one while this view is still mounted)
  // must not ask for a list nobody will see.
  // The source is a new object on every change of the query, so navigating to the same address again (the menu does
  // it, because a list does not write its filter to the address) applies the address over a filter chosen in the list.
  watch(
    () => ({ status: statusOf(route.query) }),
    ({ status: fromAddress }) => {
      if (route.name !== routeName || fromAddress === status.value) {
        return;
      }
      status.value = fromAddress;
      void startFromFirstPage();
    },
  );

  onMounted(() => {
    void load([null]);
  });

  onUnmounted(() => {
    latest++;
  });

  return {
    status,
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
    goNext,
    goPrevious,
    retry,
  };
}
