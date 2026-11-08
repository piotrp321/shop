import { flushPromises, mount } from "@vue/test-utils";
import PrimeVue from "primevue/config";
import Select from "primevue/select";
import ToggleSwitch from "primevue/toggleswitch";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import type { components } from "@/api/schema";
import { primeVueOptions } from "@/primevue";
import OrdersView from "@/views/OrdersView.vue";

type OrderList = components["schemas"]["BackofficeOrderList"];
type OrderListItem = components["schemas"]["BackofficeOrderListItem"];

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

function order(number: string, overrides: Partial<OrderListItem> = {}): OrderListItem {
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

function serve(page: OrderList | number) {
  mocks.get.mockResolvedValue(typeof page === "number" ? { data: undefined, response: { status: page } } : { data: page, response: { status: 200 } });
}

function queries(): Record<string, unknown>[] {
  return mocks.get.mock.calls.map(([, options]) => (options as { params: { query: Record<string, unknown> } }).params.query);
}

async function mountView() {
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/orders", name: "orders", component: empty },
      { path: "/orders/:orderId", name: "order-detail", component: empty },
    ],
  });
  await router.push("/orders");
  const wrapper = mount(OrdersView, { global: { plugins: [router, [PrimeVue, primeVueOptions]] as never[] } });
  await flushPromises();
  return wrapper;
}

describe("OrdersView", () => {
  let wrapper: Awaited<ReturnType<typeof mountView>> | undefined;

  beforeEach(() => {
    mocks.get.mockReset();
    // jsdom has no matchMedia, which the Select of PrimeVue asks for when it is mounted.
    vi.stubGlobal("matchMedia", () => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }));
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    wrapper?.unmount();
    wrapper = undefined;
  });

  it("shows the number as a link to the detail, the date, the customer, the email, the status and the amount", async () => {
    serve({ items: [order("R-100001", { status: "shipped" })], nextCursor: null });
    wrapper = await mountView();

    const row = wrapper.get("[data-testid=order-row]");
    expect(row.get("[data-testid=number]").text()).toBe("R-100001");
    expect(row.get("[data-testid=number]").attributes("href")).toBe("/orders/id-R-100001");
    expect(row.get("[data-testid=placed-at]").text()).toBe("05.11.2026, 13:30:00");
    expect(row.get("[data-testid=full-name]").text()).toBe("Anna Nowak");
    expect(row.get("[data-testid=email]").text()).toBe("anna.nowak@example.com");
    expect(row.get("[data-testid=status]").text()).toBe("Wysłane");
    expect(row.get("[data-testid=total]").text().replace(/\s/g, " ")).toBe("399,98 zł");
    expect(row.find("[data-testid=refund-due]").exists()).toBe(false);
  });

  it("searches on Enter, not while typing", async () => {
    serve({ items: [order("R-100001")], nextCursor: null });
    wrapper = await mountView();

    await wrapper.get("[data-testid=search]").setValue("R-100001");
    expect(queries()).toEqual([{}]);
    await wrapper.get("[data-testid=search]").trigger("keydown.enter");
    await flushPromises();

    expect(queries()).toEqual([{}, { q: "R-100001" }]);
  });

  it("filters by status", async () => {
    serve({ items: [], nextCursor: null });
    wrapper = await mountView();

    wrapper.getComponent(Select).vm.$emit("update:modelValue", "cancelled");
    await flushPromises();

    expect(queries()).toEqual([{}, { status: "cancelled" }]);
  });

  it("shows the amount and the due date of the refunds to do, with 'Po terminie' for an overdue one", async () => {
    serve({ items: [], nextCursor: null });
    wrapper = await mountView();
    serve({
      items: [
        order("R-1", { status: "cancelled", refund: { amount: 39998, dueAt: "2026-11-01T12:30:00+00:00", completedAt: null, overdue: true } }),
        order("R-2", { status: "returned", refund: { amount: 1000, dueAt: "2026-11-19T12:30:00+00:00", completedAt: null, overdue: false } }),
      ],
      nextCursor: null,
    });

    wrapper.getComponent(ToggleSwitch).vm.$emit("update:modelValue", true);
    await flushPromises();

    expect(queries().at(-1)).toEqual({ refund: "pending" });
    const rows = wrapper.findAll("[data-testid=order-row]");
    expect(rows[0]!.get("[data-testid=refund-amount]").text().replace(/\s/g, " ")).toBe("399,98 zł");
    expect(rows[0]!.get("[data-testid=refund-due]").text()).toBe("01.11.2026, 13:30:00");
    expect(rows[0]!.find("[data-testid=refund-overdue]").text()).toBe("Po terminie");
    expect(rows[1]!.find("[data-testid=refund-overdue]").exists()).toBe(false);
  });

  it("pages with the cursors of the API", async () => {
    serve({ items: [order("R-2")], nextCursor: "c1" });
    wrapper = await mountView();

    expect(wrapper.get("[data-testid=previous-page]").attributes("disabled")).toBeDefined();
    await wrapper.get("[data-testid=next-page]").trigger("click");
    await flushPromises();

    expect(queries().at(-1)).toEqual({ after: "c1" });
    expect(wrapper.get("[data-testid=page-number]").text()).toBe("Strona 2");
  });

  it("says there are no orders, and no orders were found for a search", async () => {
    serve({ items: [], nextCursor: null });
    wrapper = await mountView();
    expect(wrapper.get("[data-testid=empty]").text()).toBe("Brak zamówień.");

    await wrapper.get("[data-testid=search]").setValue("nikt@example.com");
    await wrapper.get("[data-testid=search]").trigger("keydown.enter");
    await flushPromises();

    expect(wrapper.get("[data-testid=empty]").text()).toBe("Nie znaleziono zamówień.");
  });

  it("shows an error with a retry that loads the list again", async () => {
    serve(500);
    wrapper = await mountView();
    expect(wrapper.get("[data-testid=orders-error]").text()).toContain("Nie udało się pobrać zamówień (HTTP 500).");
    expect(wrapper.find("[data-testid=order-row]").exists()).toBe(false);

    serve({ items: [order("R-100001")], nextCursor: null });
    await wrapper.get("[data-testid=retry]").trigger("click");
    await flushPromises();

    expect(wrapper.find("[data-testid=orders-error]").exists()).toBe(false);
    expect(wrapper.findAll("[data-testid=order-row]")).toHaveLength(1);
  });

  it("tells a role without access that it has none, without a retry", async () => {
    serve(403);
    wrapper = await mountView();

    expect(wrapper.get("[data-testid=orders-error]").text()).toBe("Nie masz dostępu do zamówień.");
    expect(wrapper.find("[data-testid=retry]").exists()).toBe(false);
  });
});
