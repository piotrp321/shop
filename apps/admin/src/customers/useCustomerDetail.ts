import { type MaybeRefOrGetter, onMounted, onUnmounted, ref, toValue, watch } from "vue";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";

export type Customer = components["schemas"]["BackofficeCustomerResponse"];

export const NOT_FOUND_MESSAGE = "Nie znaleźliśmy klienta.";
export const FORBIDDEN_MESSAGE = "Nie masz dostępu do klientów.";

function loadFailureMessage(status: number): string {
  return status === 403 ? FORBIDDEN_MESSAGE : `Nie udało się pobrać klienta (HTTP ${status}).`;
}

/**
 * One customer card of the panel: the contact, the summary and the addresses as the API sent them. A 404 is no error to
 * retry (`notFound`); a 403 is not worth a retry either. Only the newest request may change the state; a customer of
 * another id drops the one that is shown.
 */
export function useCustomerDetail(customerId: MaybeRefOrGetter<string>) {
  const customer = ref<Customer | null>(null);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const forbidden = ref(false);
  const notFound = ref(false);

  let latest = 0;

  async function load(): Promise<void> {
    const request = ++latest;
    loading.value = true;
    try {
      const result = await getApiClient().GET("/api/v1/admin/customers/{customerId}", { params: { path: { customerId: toValue(customerId) } } });
      if (request !== latest) {
        return;
      }
      if (result.data) {
        customer.value = result.data;
        error.value = null;
        forbidden.value = false;
        notFound.value = false;
      } else if (result.response.status === 404) {
        customer.value = null;
        error.value = null;
        forbidden.value = false;
        notFound.value = true;
      } else {
        error.value = loadFailureMessage(result.response.status);
        forbidden.value = result.response.status === 403;
        notFound.value = false;
      }
    } catch {
      if (request === latest) {
        error.value = "Nie udało się pobrać klienta. Sprawdź połączenie z API.";
        forbidden.value = false;
        notFound.value = false;
      }
    } finally {
      if (request === latest) {
        loading.value = false;
      }
    }
  }

  watch(
    () => toValue(customerId),
    () => {
      customer.value = null;
      error.value = null;
      notFound.value = false;
      void load();
    },
  );

  onMounted(() => {
    void load();
  });

  onUnmounted(() => {
    latest++;
  });

  return { customer, loading, error, forbidden, notFound, notFoundMessage: NOT_FOUND_MESSAGE, retry: load };
}
