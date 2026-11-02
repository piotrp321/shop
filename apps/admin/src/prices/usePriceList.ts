import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";

export type PriceListItem = components["schemas"]["PriceListItemResponse"];

/** How a price change ended; the messages are for the staff member. */
export type PriceChange =
  | { status: "saved" }
  | { status: "invalid"; message: string }
  | { status: "conflict"; message: string }
  | { status: "failed"; message: string };

export const CONFLICT_MESSAGE = "Cena została w międzyczasie zmieniona – odśwież";

/** The API searches names of 2 to 100 characters (code points, as it counts them), not UTF-16 units. */
export const MIN_SEARCH_LENGTH = 2;
export const MAX_SEARCH_LENGTH = 100;
export const SEARCH_DEBOUNCE_MS = 300;

interface PriceQuery {
  q?: string;
  category?: string;
  after?: string;
}

function characters(text: string): number {
  return [...text].length;
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

/** The reason of a rejected request from a problem details body (`violations[].title`, else `detail`), if it has one. */
function problemMessage(body: unknown): string | null {
  if (typeof body !== "object" || body === null) {
    return null;
  }
  const { violations, detail } = body as { violations?: unknown; detail?: unknown };
  if (Array.isArray(violations)) {
    const titles = violations
      .map((violation) => (violation as { title?: unknown } | null)?.title)
      .filter((title): title is string => typeof title === "string" && title !== "");
    if (titles.length > 0) {
      return titles.join(" ");
    }
  }
  return typeof detail === "string" && detail !== "" ? detail : null;
}

function changeFailure(status: number, body: unknown): PriceChange {
  switch (status) {
    case 422:
      // The rules of the amount are the API's; the panel only shows the reason it gives.
      return { status: "invalid", message: problemMessage(body) ?? `API odrzuciło cenę (HTTP ${status}).` };
    case 403:
      return { status: "failed", message: "Nie masz uprawnień do zmiany cen." };
    case 404:
      return { status: "failed", message: "Ten produkt już nie istnieje." };
    default:
      return { status: "failed", message: `Nie udało się zapisać ceny (HTTP ${status}).` };
  }
}

/**
 * The price list with a search by name (from two characters, after a pause in typing), a category filter and paging
 * with the cursors of the API. The panel keeps the stack of the cursors it has visited: `cursors[i]` is the `after`
 * of page i (null for the first), so "previous" is the cursor below the current one. Changing the search or the
 * category starts again from the first page. A failed request keeps the page that is shown and can be retried.
 *
 * `changePrice` sends a new gross (in grosze) with the version the row shows. The saved price from the API replaces
 * the one in the row; a conflict (the price changed meanwhile) reloads the page shown, so the row has the current
 * price and version. The API decides on the amount and the permission, the panel only shows its answer.
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
  // The prices saved since the newest list request started. Its answer can predate such a save, so a row it brings
  // is replaced by the saved price when that is newer (a greater version). Cleared when a request starts: the next
  // one is asked after those saves, so it already has them.
  const savedSinceLoad = new Map<string, PriceListItem["price"]>();

  const searchTooShort = computed(() => {
    const length = characters(search.value.trim());
    return length > 0 && length < MIN_SEARCH_LENGTH;
  });
  const hasPrevious = computed(() => cursors.value.length > 1);
  const hasNext = computed(() => next.value !== null);
  const pageNumber = computed(() => cursors.value.length);

  async function load(stack: (string | null)[]): Promise<void> {
    const request = ++latest;
    savedSinceLoad.clear();
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
        items.value = result.data.items.map(withSavedPrice);
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

  function withSavedPrice(item: PriceListItem): PriceListItem {
    const saved = savedSinceLoad.get(item.productId);
    return saved && (item.price === null || saved.version > item.price.version) ? { ...item, price: saved } : item;
  }

  watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
      const term = search.value.trim();
      const length = characters(term);
      if (length > 0 && length < MIN_SEARCH_LENGTH) {
        // Too short to ask: the list stays as it is and the hint says why.
        return;
      }
      const effective = length >= MIN_SEARCH_LENGTH ? term : null;
      if (effective !== appliedTerm) {
        appliedTerm = effective;
        startFromFirstPage();
        void load([null]);
      }
    }, SEARCH_DEBOUNCE_MS);
  });

  // The cursors and `next` belong to the list of the old filter. Dropping them now means that if the request for the
  // new filter fails, the pager cannot pair the new filter with an old cursor: it stays off until a retry succeeds.
  function startFromFirstPage(): void {
    cursors.value = [null];
    next.value = null;
  }

  function selectCategory(id: string | null): void {
    if (id !== categoryId.value) {
      categoryId.value = id;
      startFromFirstPage();
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

  async function changePrice(productId: string, gross: number, version: number | null): Promise<PriceChange> {
    try {
      const result = await getApiClient().PUT("/api/v1/admin/products/{productId}/price", {
        params: { path: { productId } },
        body: { gross, version },
      });
      if (result.data) {
        const saved = result.data;
        savedSinceLoad.set(productId, saved);
        items.value = items.value.map((item) => (item.productId === productId ? { ...item, price: saved } : item));
        return { status: "saved" };
      }
      if (result.response.status === 409) {
        await load(cursors.value);
        return { status: "conflict", message: CONFLICT_MESSAGE };
      }
      return changeFailure(result.response.status, result.error);
    } catch {
      return { status: "failed", message: "Nie udało się zapisać ceny. Sprawdź połączenie z API." };
    }
  }

  onMounted(() => {
    void load([null]);
  });

  onUnmounted(() => {
    clearTimeout(timer);
    latest++;
  });

  return {
    search,
    searchTooShort,
    items,
    loading,
    loaded,
    error,
    hasPrevious,
    hasNext,
    pageNumber,
    selectCategory,
    goNext,
    goPrevious,
    retry,
    changePrice,
  };
}
