import { onMounted, onUnmounted, ref } from "vue";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";
import { formatDateTime } from "@/prices/format";

export type Dashboard = components["schemas"]["DashboardResponse"];

/** The moment of a fetch in Polish time, whatever the time zone of the browser („01.11.2026, 14:03:12”). */
export function formatFetchTime(date: Date): string {
  return formatDateTime(date.toISOString());
}

/**
 * Loads the dashboard on mount and every `intervalMs` after, except while the tab is hidden.
 * Returning to the tab refreshes at once when the last successful fetch (before the first one, the last
 * attempt) is at least `intervalMs` old, and the interval then counts from that refresh.
 * A refresh keeps the last data on screen; a failed one sets `error` (naming the time of the data it keeps)
 * and keeps the data. `updatedAt` is the time of the last successful fetch. `alongside` is called with every refresh
 * (the first load, the timer, the return to the tab and „Odśwież”), for the data of the screen that comes from other
 * requests and keeps its own state.
 */
export function useDashboard(intervalMs = 30000, alongside?: () => void) {
  const data = ref<Dashboard | null>(null);
  const error = ref<string | null>(null);
  const loading = ref(false);
  const updatedAt = ref<Date | null>(null);
  let lastAttemptAt = 0;
  let timer: ReturnType<typeof setInterval> | undefined;
  // Only the newest request may change the state; an older one that finishes late is ignored.
  let latest = 0;

  function withStaleNote(message: string): string {
    return updatedAt.value ? `${message} Pokazane dane z ${formatFetchTime(updatedAt.value)} mogą być nieaktualne.` : message;
  }

  async function refresh(): Promise<void> {
    const request = ++latest;
    lastAttemptAt = Date.now();
    loading.value = true;
    alongside?.();
    try {
      const result = await getApiClient().GET("/api/v1/admin/dashboard");
      if (request !== latest) {
        return;
      }
      if (result.data) {
        data.value = result.data;
        updatedAt.value = new Date();
        error.value = null;
      } else {
        error.value = withStaleNote(`Nie udało się pobrać danych dashboardu (HTTP ${result.response.status}).`);
      }
    } catch {
      if (request === latest) {
        error.value = withStaleNote("Nie udało się pobrać danych dashboardu. Sprawdź połączenie z API.");
      }
    } finally {
      if (request === latest) {
        loading.value = false;
      }
    }
  }

  function startTimer() {
    clearInterval(timer);
    timer = setInterval(() => {
      if (document.visibilityState !== "hidden") {
        void refresh();
      }
    }, intervalMs);
  }

  function onVisibilityChange() {
    if (document.visibilityState !== "visible") {
      return;
    }
    const lastFetchAt = updatedAt.value?.getTime() ?? lastAttemptAt;
    if (Date.now() - lastFetchAt >= intervalMs) {
      void refresh();
      startTimer();
    }
  }

  onMounted(() => {
    void refresh();
    startTimer();
    document.addEventListener("visibilitychange", onVisibilityChange);
  });

  onUnmounted(() => {
    clearInterval(timer);
    document.removeEventListener("visibilitychange", onVisibilityChange);
    latest++;
  });

  return { data, error, loading, updatedAt, refresh };
}
