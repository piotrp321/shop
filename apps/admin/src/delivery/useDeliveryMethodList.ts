import { computed, onMounted, ref } from "vue";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";

export type DeliveryMethodItem = components["schemas"]["DeliveryMethodResponse"];

/** The most active methods the API allows (docs/features/delivery/spec.md, P16); the API refuses the 21st. */
export const ACTIVE_LIMIT = 20;

export const FORBIDDEN_MESSAGE = "Nie masz dostępu do metod dostawy.";
export const EMPTY_MESSAGE = "Brak metod dostawy. Dodaj pierwszą, żeby sklep przyjmował zamówienia.";

function failureMessage(status: number): string {
  return status === 403 ? FORBIDDEN_MESSAGE : `Nie udało się pobrać metod dostawy (HTTP ${status}).`;
}

/**
 * The list of the delivery methods: every method in one answer, without pages, in the order of the API (the active ones
 * by position, the inactive ones below). The panel keeps that order and counts only what it shows for „Aktywne: N z 20”;
 * the limit itself is the API's.
 */
export function useDeliveryMethodList() {
  const items = ref<DeliveryMethodItem[]>([]);
  const loading = ref(false);
  const loaded = ref(false);
  const error = ref<string | null>(null);
  const forbidden = ref(false);
  // Only the newest request may change the state; an older one that finishes late is ignored.
  let latest = 0;

  const activeCount = computed(() => items.value.filter((method) => method.active).length);

  async function load(): Promise<void> {
    const request = ++latest;
    loading.value = true;
    error.value = null;
    forbidden.value = false;
    try {
      const result = await getApiClient().GET("/api/v1/admin/delivery-methods");
      if (request !== latest) {
        return;
      }
      if (result.data) {
        items.value = result.data.items;
        loaded.value = true;
      } else {
        error.value = failureMessage(result.response.status);
        forbidden.value = result.response.status === 403;
      }
    } catch {
      if (request === latest) {
        error.value = "Nie udało się pobrać metod dostawy. Sprawdź połączenie z API.";
      }
    } finally {
      if (request === latest) {
        loading.value = false;
      }
    }
  }

  onMounted(() => void load());

  return { items, loading, loaded, error, forbidden, activeCount, retry: load };
}
