import { computed, ref } from "vue";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";

export type StockHistoryItem = components["schemas"]["StockHistoryItemResponse"];

function failureMessage(status: number): string {
  switch (status) {
    case 404:
      return "Ten produkt już nie istnieje.";
    case 400:
      return `Nieprawidłowe parametry historii (HTTP ${status}).`;
    default:
      return `Nie udało się pobrać historii stanów (HTTP ${status}).`;
  }
}

/**
 * The stock history of one product, newest entry first, as the API sends it. `open` loads the first page and
 * forgets the previous product; `loadMore` appends the page after the cursor of the last one. A failed request keeps
 * the entries already shown and `retry` repeats exactly that request. An answer that arrives after another product was
 * opened, or after `close`, is ignored. The API decides on the order and the quantities, the panel only shows them.
 */
export function useStockHistory() {
  const productId = ref<string | null>(null);
  const items = ref<StockHistoryItem[]>([]);
  const next = ref<string | null>(null);
  const loading = ref(false);
  const loaded = ref(false);
  const error = ref<string | null>(null);

  // Only the newest request may change the state; an older one that finishes late is ignored.
  let latest = 0;

  const hasMore = computed(() => next.value !== null);

  async function load(after: string | null): Promise<void> {
    const id = productId.value;
    if (id === null) {
      return;
    }
    const request = ++latest;
    loading.value = true;
    try {
      const result = await getApiClient().GET("/api/v1/admin/products/{productId}/stock-history", {
        params: { path: { productId: id }, query: after === null ? {} : { after } },
      });
      if (request !== latest) {
        return;
      }
      if (result.data) {
        items.value = after === null ? result.data.items : [...items.value, ...result.data.items];
        next.value = result.data.next;
        loaded.value = true;
        error.value = null;
      } else {
        error.value = failureMessage(result.response.status);
      }
    } catch {
      if (request === latest) {
        error.value = "Nie udało się pobrać historii stanów. Sprawdź połączenie z API.";
      }
    } finally {
      if (request === latest) {
        loading.value = false;
      }
    }
  }

  function reset(id: string | null): void {
    latest++;
    productId.value = id;
    items.value = [];
    next.value = null;
    loading.value = false;
    loaded.value = false;
    error.value = null;
  }

  function open(id: string): Promise<void> {
    reset(id);
    return load(null);
  }

  function close(): void {
    reset(null);
  }

  function loadMore(): Promise<void> {
    return next.value === null || loading.value ? Promise.resolve() : load(next.value);
  }

  // After a failed first page `next` is null, so this asks for the first page again; after a failed further page it
  // asks for that page again.
  function retry(): Promise<void> {
    return loading.value ? Promise.resolve() : load(next.value);
  }

  return { productId, items, loading, loaded, error, hasMore, open, close, loadMore, retry };
}
