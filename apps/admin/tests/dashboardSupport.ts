import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { Component } from "vue";
import { createMemoryHistory, createRouter } from "vue-router";
import type { components } from "@/api/schema";
import { type StaffMe, useAuthStore } from "@/stores/auth";
import { mocks } from "./dashboardMocks";

export type Dashboard = components["schemas"]["DashboardResponse"];
export type System = NonNullable<Dashboard["system"]>;
export type OrderList = components["schemas"]["BackofficeOrderList"];
export type OrderItem = components["schemas"]["BackofficeOrderListItem"];

export const DASHBOARD = "/api/v1/admin/dashboard";
export const ORDERS = "/api/v1/admin/orders";

export const serviceNames = ["postgres", "redis", "elasticsearch", "rabbitmq", "keycloak", "garage", "imgproxy", "mailpit"];

export function system(overrides: Partial<System> = {}): System {
  return {
    services: serviceNames.map((name, index) => ({ name, status: "up" as const, latencyMs: index + 1 })),
    outbox: { pending: 3 },
    queues: [{ name: "events", messages: 7, consumers: 1 }],
    searchIndex: { indexedProducts: 412, catalogProducts: 412, inSync: true },
    ...overrides,
  };
}

export function dashboard(overrides: Partial<Dashboard> = {}): Dashboard {
  return {
    catalog: { productCount: 412, categoryCount: 36, productsWithoutImages: 0 },
    system: system(),
    ...overrides,
  };
}

export function order(number: string, overrides: Partial<OrderItem> = {}): OrderItem {
  return {
    orderId: `id-${number}`,
    number,
    placedAt: "2026-11-05T12:30:00+00:00",
    status: "paid",
    email: "anna.nowak@example.com",
    fullName: "Anna Nowak",
    totalGross: 39998,
    currency: "PLN",
    itemCount: 3,
    refund: null,
    ...overrides,
  };
}

export { mocks };

/** The calls of the API that asked for `path`. */
export function callsTo(path: string) {
  return mocks.get.mock.calls.filter(([called]) => called === path);
}

/** Answers the dashboard (and the orders, when the view asks for them) with the given data. */
export function serve(data: Dashboard, orders: OrderList = { items: [], nextCursor: null }) {
  mocks.get.mockImplementation(async (path: string) =>
    path === ORDERS ? { data: orders, response: { status: 200 } } : { data, response: { status: 200 } },
  );
}

export const empty = { template: "<div />" };

export async function mountScreen(view: Component, roles: StaffMe["roles"] | null, path = "/") {
  const pinia = createPinia();
  setActivePinia(pinia);
  if (roles !== null) {
    useAuthStore().staff = { id: "u1", username: "anna", roles };
  }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/", name: "dashboard", component: empty },
      { path: "/system", name: "system", component: empty },
      { path: "/products", name: "products", component: empty },
      { path: "/orders", name: "orders", component: empty },
      { path: "/orders/:orderId", name: "order-detail", component: empty },
      { path: "/inventory", name: "inventory", component: empty },
      { path: "/promotions", name: "promotions", component: empty },
      { path: "/discount-codes", name: "discount-codes", component: empty },
    ],
  });
  await router.push(path);
  const wrapper = mount(view, { global: { plugins: [pinia, router] }, attachTo: document.body });
  await flushPromises();
  return Object.assign(wrapper, { router });
}

interface FreshnessScreen {
  /** The screen under test, mounted as a staff member who may see it. */
  mount: () => Promise<VueWrapper>;
  /** A response whose visible number is `value`, and how to read that number off the screen. */
  withNumber: (value: number) => Dashboard;
  readNumber: (wrapper: VueWrapper) => string;
  /** What the screen shows once it has data. */
  shown: string;
}

/**
 * The behaviour of docs/features/dashboard-data-freshness/spec.md, which the dashboard and the system screen share:
 * the load and the refresh, the time of the data, the error that names it, the pause of the hidden tab, the cleanup.
 */
export function describeFreshness(name: string, screen: FreshnessScreen) {
  describe(`${name}: data freshness`, () => {
    let wrapper: VueWrapper | undefined;
    let visibility: DocumentVisibilityState = "visible";

    const dashboardCalls = () => callsTo(DASHBOARD).length;
    const setVisibility = (state: DocumentVisibilityState) => {
      visibility = state;
      document.dispatchEvent(new Event("visibilitychange"));
    };
    const failure = { error: { status: 503 }, response: { status: 503 } };
    const failDashboard = () =>
      mocks.get.mockImplementation(async (path: string) =>
        path === ORDERS ? { data: { items: [], nextCursor: null }, response: { status: 200 } } : failure,
      );

    async function start() {
      wrapper = await screen.mount();
      return wrapper;
    }

    beforeEach(() => {
      mocks.get.mockReset();
      visibility = "visible";
      vi.spyOn(document, "visibilityState", "get").mockImplementation(() => visibility);
      vi.useFakeTimers();
      // 13:03:12 UTC is 14:03:12 in Poland in November.
      vi.setSystemTime(new Date(Date.UTC(2026, 10, 1, 13, 3, 12)));
    });

    afterEach(() => {
      wrapper?.unmount();
      wrapper = undefined;
      vi.restoreAllMocks();
      vi.useRealTimers();
    });

    it("loads the data once on mount", async () => {
      serve(dashboard());
      await start();

      expect(dashboardCalls()).toBe(1);
      expect(wrapper?.text()).toContain(screen.shown);
    });

    it("shows a skeleton while loading and no data", async () => {
      mocks.get.mockReturnValue(new Promise(() => {}));
      await start();

      expect(wrapper?.find("[data-testid=dashboard-loading]").exists()).toBe(true);
      expect(wrapper?.text()).not.toContain(screen.shown);
    });

    it("shows an alert when the API answers with an error, and no skeleton", async () => {
      failDashboard();
      await start();

      const message = wrapper?.get("[data-testid=dashboard-error]");
      expect(message?.attributes("role")).toBe("alert");
      expect(wrapper?.find("[data-testid=dashboard-loading]").exists()).toBe(false);
    });

    it("shows an alert without the technical message when the request fails", async () => {
      mocks.get.mockRejectedValue(new TypeError("Failed to fetch"));
      await start();

      expect(wrapper?.get("[data-testid=dashboard-error]").text()).toBe("Nie udało się pobrać danych dashboardu. Sprawdź połączenie z API.");
    });

    it("reloads when „Odśwież” is clicked and clears the error on success", async () => {
      failDashboard();
      await start();
      expect(wrapper?.find("[data-testid=dashboard-error]").exists()).toBe(true);

      serve(dashboard());
      await wrapper?.get("[data-testid=refresh]").trigger("click");
      await flushPromises();

      expect(dashboardCalls()).toBe(2);
      expect(wrapper?.find("[data-testid=dashboard-error]").exists()).toBe(false);
      expect(wrapper?.text()).toContain(screen.shown);
    });

    it("keeps the last data on screen during a refresh", async () => {
      serve(dashboard());
      await start();

      mocks.get.mockReturnValue(new Promise(() => {}));
      await wrapper?.get("[data-testid=refresh]").trigger("click");

      expect(wrapper?.find("[data-testid=dashboard-loading]").exists()).toBe(false);
      expect(wrapper?.text()).toContain(screen.shown);
    });

    it("refreshes every 30 seconds and stops after unmount", async () => {
      serve(dashboard());
      await start();
      expect(dashboardCalls()).toBe(1);

      await vi.advanceTimersByTimeAsync(30_000);
      expect(dashboardCalls()).toBe(2);

      wrapper?.unmount();
      await vi.advanceTimersByTimeAsync(90_000);
      expect(dashboardCalls()).toBe(2);
    });

    it("ignores a stale response that arrives after a newer refresh", async () => {
      let resolveFirst: (value: unknown) => void = () => {};
      mocks.get.mockReturnValueOnce(new Promise((resolve) => (resolveFirst = resolve)));
      mocks.get.mockImplementation(async (path: string) =>
        path === ORDERS ? { data: { items: [], nextCursor: null }, response: { status: 200 } } : { data: screen.withNumber(2), response: { status: 200 } },
      );
      await start();

      await vi.advanceTimersByTimeAsync(30_000);
      expect(screen.readNumber(wrapper as VueWrapper)).toBe("2");

      resolveFirst({ data: screen.withNumber(1), response: { status: 200 } });
      await flushPromises();

      expect(screen.readNumber(wrapper as VueWrapper)).toBe("2");
    });

    it("ignores a stale failure that arrives after a newer successful refresh", async () => {
      let rejectFirst: (reason: unknown) => void = () => {};
      mocks.get.mockReturnValueOnce(new Promise((_resolve, reject) => (rejectFirst = reject)));
      serve(dashboard());
      await start();

      await vi.advanceTimersByTimeAsync(30_000);
      rejectFirst(new TypeError("Failed to fetch"));
      await flushPromises();

      expect(wrapper?.find("[data-testid=dashboard-error]").exists()).toBe(false);
      expect(wrapper?.text()).toContain(screen.shown);
    });

    it("shows the time of the last successful fetch in Polish time, in the muted colour", async () => {
      serve(dashboard());
      await start();

      const updatedAt = wrapper?.get("[data-testid=updated-at]");
      expect(updatedAt?.text()).toMatch(/^Dane z 01\.11\.2026.*14:03:12$/);
      expect(updatedAt?.classes()).toContain("text-muted-foreground");
    });

    it("announces the time of the data politely", async () => {
      serve(dashboard());
      await start();

      const live = wrapper?.get("[data-testid=updated-at]").element.closest("[aria-live]");
      expect(live?.getAttribute("aria-live")).toBe("polite");
    });

    it("shows no fetch time before the first successful fetch", async () => {
      failDashboard();
      await start();

      expect(wrapper?.find("[data-testid=updated-at]").exists()).toBe(false);
    });

    it("keeps the data and names its time when a refresh fails", async () => {
      serve(dashboard());
      await start();

      vi.setSystemTime(new Date(Date.UTC(2026, 10, 1, 13, 3, 42)));
      failDashboard();
      await wrapper?.get("[data-testid=refresh]").trigger("click");
      await flushPromises();

      expect(wrapper?.get("[data-testid=dashboard-error]").text()).toMatch(/Pokazane dane z 01\.11\.2026.*14:03:12 mogą być nieaktualne\.$/);
      expect(wrapper?.get("[data-testid=updated-at]").text()).toMatch(/^Dane z 01\.11\.2026.*14:03:12$/);
      expect(wrapper?.text()).toContain(screen.shown);
    });

    it("does not call the API on intervals while the tab is hidden", async () => {
      serve(dashboard());
      await start();
      expect(dashboardCalls()).toBe(1);

      visibility = "hidden";
      await vi.advanceTimersByTimeAsync(90_000);

      expect(dashboardCalls()).toBe(1);
    });

    it("refreshes once on return to the tab after at least 30 seconds", async () => {
      serve(dashboard());
      await start();

      visibility = "hidden";
      await vi.advanceTimersByTimeAsync(95_000);
      setVisibility("visible");
      await flushPromises();

      expect(dashboardCalls()).toBe(2);
      expect(wrapper?.get("[data-testid=updated-at]").text()).toMatch(/^Dane z 01\.11\.2026.*14:04:47$/);
    });

    it("does not refresh on return to the tab after less than 30 seconds", async () => {
      serve(dashboard());
      await start();

      visibility = "hidden";
      await vi.advanceTimersByTimeAsync(10_000);
      setVisibility("visible");
      await flushPromises();

      expect(dashboardCalls()).toBe(1);
    });

    it("keeps refreshing every 30 seconds counted from the refresh on return", async () => {
      serve(dashboard());
      await start();

      visibility = "hidden";
      await vi.advanceTimersByTimeAsync(75_000);
      await vi.advanceTimersByTimeAsync(10_000);
      setVisibility("visible");
      await flushPromises();
      expect(dashboardCalls()).toBe(2);

      await vi.advanceTimersByTimeAsync(29_000);
      expect(dashboardCalls()).toBe(2);
      await vi.advanceTimersByTimeAsync(1_000);
      expect(dashboardCalls()).toBe(3);
    });

    it("does not refresh when the tab becomes hidden", async () => {
      serve(dashboard());
      await start();

      await vi.advanceTimersByTimeAsync(20_000);
      setVisibility("hidden");
      await flushPromises();

      expect(dashboardCalls()).toBe(1);
    });

    it("removes the timer and the visibilitychange listener on unmount", async () => {
      const added = vi.spyOn(document, "addEventListener");
      const removed = vi.spyOn(document, "removeEventListener");
      serve(dashboard());
      await start();
      expect(vi.getTimerCount()).toBe(1);
      const listener = added.mock.calls.find(([type]) => type === "visibilitychange")?.[1];
      expect(listener).toBeDefined();

      wrapper?.unmount();

      expect(vi.getTimerCount()).toBe(0);
      expect(removed).toHaveBeenCalledWith("visibilitychange", listener);
      setVisibility("visible");
      await vi.advanceTimersByTimeAsync(60_000);
      expect(dashboardCalls()).toBe(1);
    });
  });
}
