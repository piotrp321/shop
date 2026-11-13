import { onUnmounted, type Ref, ref, watch } from "vue";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";

export type RecentOrder = components["schemas"]["BackofficeOrderListItem"];

export const RECENT_ORDERS = 5;
export const FORBIDDEN_MESSAGE = "Nie masz dostępu do zamówień.";

function failureMessage(status: number): string {
  return status === 403 ? FORBIDDEN_MESSAGE : `Nie udało się pobrać zamówień (HTTP ${status}).`;
}

/**
 * The newest orders for the card „Ostatnie zamówienia” (`GET /orders?perPage=5`). Asked for only while `allowed` (the
 * roles with access to the orders, known from `/me`); it loads when the dashboard refreshes (`reload`) and when
 * `allowed` turns true. It has its own state, so a failure stays in the card and the rest of the dashboard is not
 * touched; a failed refresh keeps the orders already shown. A 403 is reported as such (`forbidden`): a retry would not
 * help. Only the newest request may change the state.
 */
export function useRecentOrders(allowed: Ref<boolean>) {
  const items = ref<RecentOrder[]>([]);
  const loaded = ref(false);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const forbidden = ref(false);
  let latest = 0;

  async function reload(): Promise<void> {
    if (!allowed.value) {
      return;
    }
    const request = ++latest;
    loading.value = true;
    try {
      const result = await getApiClient().GET("/api/v1/admin/orders", { params: { query: { perPage: RECENT_ORDERS } } });
      if (request !== latest) {
        return;
      }
      if (result.data) {
        items.value = result.data.items.slice(0, RECENT_ORDERS);
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

  watch(allowed, (isAllowed) => {
    if (isAllowed) {
      void reload();
    }
  });

  onUnmounted(() => {
    latest++;
  });

  return { items, loaded, loading, error, forbidden, reload };
}
