import { computed, onUnmounted, ref, watch } from "vue";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";
import { type PageRequest, type PageResult, useCursorPager } from "@/composables/useCursorPager";
import { type ListParam, useListUrlState } from "@/composables/useListUrlState";
import { STATUS_FILTER_OPTIONS } from "./format";

export type DiscountCodeListItem = components["schemas"]["DiscountCodeResponse"];
export type DiscountCodeStatus = DiscountCodeListItem["status"];

export const FORBIDDEN_MESSAGE = "Nie masz dostępu do kodów rabatowych.";
export const EMPTY_MESSAGE = "Nie ma jeszcze kodów rabatowych.";

/** A code has 3 to 32 characters, so a longer search can match nothing and the API refuses it. */
export const MAX_SEARCH_LENGTH = 32;
export const SEARCH_TOO_LONG_HINT = `Kod ma najwyżej ${MAX_SEARCH_LENGTH} znaki.`;
export const SEARCH_DEBOUNCE_MS = 300;

/** The page sizes of the list; 25 is the default of the API, so it is not sent. */
export const PER_PAGE_OPTIONS = [10, 25, 50];
export const DEFAULT_PER_PAGE = 25;

interface CodeQuery {
  status?: DiscountCodeStatus;
  q?: string;
  after?: string;
  perPage?: number;
}

// The API trims a search of white space, separators and invisible format characters (U+200B, U+00AD, U+2060, ...), so a
// search of only those is an empty one. `trim()` does not remove the format characters.
const SEARCH_EDGES = /^[\s\p{Z}\p{Cf}]+|[\s\p{Z}\p{Cf}]+$/gu;

function normalise(text: string): string {
  return text.replace(SEARCH_EDGES, "");
}

function characters(text: string): number {
  return [...text].length;
}

/** The trimmed term when the API takes it as a search (1 to 32 code points), otherwise null. */
function validTerm(text: string): string | null {
  const term = normalise(text);
  const length = characters(term);
  return length >= 1 && length <= MAX_SEARCH_LENGTH ? term : null;
}

function failureMessage(status: number): string {
  switch (status) {
    case 403:
      return FORBIDDEN_MESSAGE;
    case 400:
      return `Nieprawidłowe parametry listy (HTTP ${status}).`;
    default:
      return `Nie udało się pobrać kodów rabatowych (HTTP ${status}).`;
  }
}

/**
 * The discount code list: a status filter, a search by the beginning of the code (after a pause in typing, from one
 * character) and a page size (10, 25 or 50). The search, the filter and the page size live in the address
 * (`useListUrlState`: `q`, `status`, `perPage`; read on entering and on a change from outside, such as the link of the
 * dashboard or Back, written with `replace` when changed; only the applied search goes there, not what is being typed)
 * and the paging is `useCursorPager` (cursor stack, late answers, retry, what a failure leaves on the screen). What stays
 * here is the request to the API with its messages, and the rules of the search of codes (1 to 32 code points after
 * trimming the characters the API trims; the longer text is not sent and the hint says why). The status and the uses of a
 * row are the API's; the panel computes nothing.
 */
export function useDiscountCodeList() {
  const url = useListUrlState({
    q: { parse: validTerm, fallback: null } satisfies ListParam<string, null>,
    status: {
      parse: (raw) => {
        const option = STATUS_FILTER_OPTIONS.find((candidate) => candidate.value === raw);
        return option === undefined || option.value === "all" ? null : option.value;
      },
      fallback: null,
    } satisfies ListParam<DiscountCodeStatus, null>,
    perPage: {
      parse: (raw) => PER_PAGE_OPTIONS.find((size) => String(size) === raw) ?? null,
      fallback: DEFAULT_PER_PAGE,
    } satisfies ListParam<number, number>,
  });

  async function fetchPage({ query, after }: PageRequest<typeof url.query.value>): Promise<PageResult<DiscountCodeListItem>> {
    const apiQuery: CodeQuery = {};
    if (query.status !== null) {
      apiQuery.status = query.status;
    }
    if (query.q !== null) {
      apiQuery.q = query.q;
    }
    if (query.perPage !== DEFAULT_PER_PAGE) {
      apiQuery.perPage = query.perPage;
    }
    if (after) {
      apiQuery.after = after;
    }
    try {
      const result = await getApiClient().GET("/api/v1/admin/discount-codes", { params: { query: apiQuery } });
      if (result.data) {
        return { items: result.data.items, next: result.data.next };
      }
      return { error: failureMessage(result.response.status), forbidden: result.response.status === 403 };
    } catch {
      return { error: "Nie udało się pobrać kodów rabatowych. Sprawdź połączenie z API." };
    }
  }

  const pager = useCursorPager(fetchPage, url.query, { ready: url.ready });

  // What is typed in the field; only a term that passes the rules, after the pause, reaches the address.
  const search = ref(url.address.value.q ?? "");
  // The term that is already applied (or came from outside): typing it again is no change to apply.
  let synced = normalise(search.value);
  let timer: ReturnType<typeof setTimeout> | undefined;

  const searchHint = computed(() => (characters(normalise(search.value)) > MAX_SEARCH_LENGTH ? SEARCH_TOO_LONG_HINT : null));

  watch(search, (value) => {
    clearTimeout(timer);
    if (normalise(value) === synced) {
      return;
    }
    timer = setTimeout(() => {
      const term = normalise(search.value);
      if (characters(term) > MAX_SEARCH_LENGTH) {
        // No code is that long: the list stays as it is and the hint says why.
        return;
      }
      synced = term;
      url.set({ q: term === "" ? null : term });
    }, SEARCH_DEBOUNCE_MS);
  });

  function setSearch(term: string | null): void {
    clearTimeout(timer);
    search.value = term ?? "";
    synced = normalise(search.value);
  }

  // The address changed from outside: it is the new truth, also for what is in the field.
  watch(url.address, (address) => setSearch(address.q));

  onUnmounted(() => clearTimeout(timer));

  const filtered = computed(() => url.query.value.q !== null || url.query.value.status !== null);

  function selectStatus(selected: DiscountCodeStatus | null): void {
    url.set({ status: selected });
  }

  function setPerPage(size: number): void {
    url.set({ perPage: size });
  }

  /** Drops the search, the filter and the page size: the address is `/discount-codes` again. */
  function clearFilters(): void {
    setSearch(null);
    url.clear();
  }

  return {
    search,
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
    selectStatus,
    setPerPage,
    clearFilters,
    goNext: pager.goNext,
    goPrevious: pager.goPrevious,
    retry: pager.retry,
  };
}
