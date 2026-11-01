import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";

export type PriceListItem = components["schemas"]["PriceListItemResponse"];

/** The API searches names of at least this many characters. */
export const MIN_SEARCH_LENGTH = 2;
export const SEARCH_DEBOUNCE_MS = 300;

interface PriceQuery {
  q?: string;
  category?: string;
  after?: string;
}

function failureMessage(status: number): string {
  switch (status) {
    case 404:
      return "Wybrana kategoria już nie istnieje. Wybierz inną.";
    case 400:
      return `Nieprawidłowe parametry wyszukiwania (HTTP ${status}).`;
    default:
      return `Nie udało się pobrać cen (HTTP ${status}).`;
  }
}

/**
 * The price list with a search by name (from two characters, after a pause in typing), a category filter and paging
 * with the cursors of the API. The panel keeps the stack of the cursors it has visited: `cursors[i]` is the `after`
 * of page i (null for the first), so "previous" is the cursor below the current one. Changing the search or the
 * category starts again from the first page. A failed request keeps the page that is shown and can be retried.
 */
export function usePriceList() {
  const search = ref("");
  const categoryId = ref<string | null>(null);
  const items = ref<PriceListItem[]>([]);
  const next = ref<string | null>(null);
  const cursors = ref<(string | null)[]>([null]);
  const loading = ref(false);
  const loaded = ref(false);
  const error = ref<string | null>(null);

  // The term the list was last asked for (null: all names); a pending debounce compares against it.
  let appliedTerm: string | null = null;
  let attempted: (string | null)[] = [null];
  let timer: ReturnType<typeof setTimeout> | undefined;
  // Only the newest request may change the state; an older one that finishes late is ignored.
  let latest = 0;

  const searchTooShort = computed(() => search.value.trim().length > 0 && search.value.trim().length < MIN_SEARCH_LENGTH);
  const hasPrevious = computed(() => cursors.value.length > 1);
  const hasNext = computed(() => next.value !== null);
  const pageNumber = computed(() => cursors.value.length);

  async function load(stack: (string | null)[]): Promise<void> {
    const request = ++latest;
    attempted = stack;
    loading.value = true;
    const query: PriceQuery = {};
    if (appliedTerm !== null) {
      query.q = appliedTerm;
    }
    if (categoryId.value !== null) {
      query.category = categoryId.value;
    }
    const after = stack[stack.length - 1];
    if (after) {
      query.after = after;
    }
    try {
      const result = await getApiClient().GET("/api/v1/admin/prices", { params: { query } });
      if (request !== latest) {
        return;
      }
      if (result.data) {
        items.value = result.data.items;
        next.value = result.data.next;
        cursors.value = stack;
        loaded.value = true;
        error.value = null;
      } else {
        error.value = failureMessage(result.response.status);
      }
    } catch {
      if (request === latest) {
        error.value = "Nie udało się pobrać cen. Sprawdź połączenie z API.";
      }
    } finally {
      if (request === latest) {
        loading.value = false;
      }
    }
  }

  watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
      const term = search.value.trim();
      if (term.length > 0 && term.length < MIN_SEARCH_LENGTH) {
        // Too short to ask: the list stays as it is and the hint says why.
        return;
      }
      const effective = term.length >= MIN_SEARCH_LENGTH ? term : null;
      if (effective !== appliedTerm) {
        appliedTerm = effective;
        void load([null]);
      }
    }, SEARCH_DEBOUNCE_MS);
  });

  function selectCategory(id: string | null): void {
    if (id !== categoryId.value) {
      categoryId.value = id;
      void load([null]);
    }
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
    clearTimeout(timer);
    latest++;
  });

  return { search, searchTooShort, items, loading, loaded, error, hasPrevious, hasNext, pageNumber, selectCategory, goNext, goPrevious, retry };
}
