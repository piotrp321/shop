import { onMounted, onUnmounted, ref } from "vue";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";

export type Dashboard = components["schemas"]["DashboardResponse"];

/**
 * Loads the dashboard on mount and every `intervalMs` after. A refresh keeps the last data on screen;
 * a failed one sets `error` and keeps the data it had.
 */
export function useDashboard(intervalMs = 30000) {
  const data = ref<Dashboard | null>(null);
  const error = ref<string | null>(null);
  const loading = ref(false);
  let timer: ReturnType<typeof setInterval> | undefined;

  async function refresh(): Promise<void> {
    loading.value = true;
    try {
      const result = await getApiClient().GET("/api/v1/admin/dashboard");
      if (result.data) {
        data.value = result.data;
        error.value = null;
      } else {
        error.value = `Nie udało się pobrać danych dashboardu (HTTP ${result.response.status}).`;
      }
    } catch {
      error.value = "Nie udało się pobrać danych dashboardu. Sprawdź połączenie z API.";
    } finally {
      loading.value = false;
    }
  }

  onMounted(() => {
    void refresh();
    timer = setInterval(() => void refresh(), intervalMs);
  });

  onUnmounted(() => clearInterval(timer));

  return { data, error, loading, refresh };
}
