import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, ref } from "vue";
import type { components } from "@/api/schema";
import { useCustomerOrders } from "@/customers/useCustomerOrders";
import type { WithOptionalTotal } from "./listPage";

type OrderList = WithOptionalTotal<components["schemas"]["BackofficeOrderList"]>;
type OrderListItem = components["schemas"]["BackofficeOrderListItem"];

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

const ORDERS = "/api/v1/admin/orders";

function order(number: string): OrderListItem {
  return { orderId: `id-${number}`, number, placedAt: "2026-11-05T12:30:00+00:00", status: "paid", email: "anna.nowak@example.com", fullName: "Anna Nowak", totalGross: 39998, currency: "PLN", itemCount: 3, refund: null };
}

function serve(pages: Record<string, OrderList>, status?: number) {
  mocks.get.mockImplementation(async (_path: string, options?: { params?: { query?: { after?: string } } }) =>
    status ? { data: undefined, response: { status } } : { data: pages[options?.params?.query?.after ?? ""], response: { status: 200 } },
  );
}

function queries(): Record<string, unknown>[] {
  return mocks.get.mock.calls.map(([, options]) => (options as { params: { query: Record<string, unknown> } }).params.query);
}

function mountOrders(customerId = ref("c-1")) {
  let orders!: ReturnType<typeof useCustomerOrders>;
  const wrapper = mount(
    defineComponent({
      setup() {
        orders = useCustomerOrders(() => customerId.value);
        return () => null;
      },
    }),
  );
  return { wrapper, orders, customerId };
}

describe("useCustomerOrders", () => {
  let wrapper: ReturnType<typeof mountOrders>["wrapper"] | undefined;

  beforeEach(() => {
    mocks.get.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
  });

  it("loads the orders of the customer with the customerId and the default page size only", async () => {
    serve({ "": { items: [order("R-2"), order("R-1")], nextCursor: "n1", total: { value: 12, capped: false } }, n1: { items: [order("R-0")], nextCursor: null } });
    const mounted = mountOrders();
    wrapper = mounted.wrapper;
    await flushPromises();

    expect(mocks.get).toHaveBeenCalledWith(ORDERS, { params: { query: { customerId: "c-1" } } });
    expect(mounted.orders.items.value.map((item) => item.number)).toEqual(["R-2", "R-1"]);
    expect(mounted.orders.total.value).toEqual({ value: 12, capped: false });

    await mounted.orders.goNext();
    expect(queries().at(-1)).toEqual({ customerId: "c-1", after: "n1" });
    expect(mounted.orders.pageNumber.value).toBe(2);
    expect(mounted.orders.total.value).toEqual({ value: 12, capped: false });

    await mounted.orders.goPrevious();
    expect(mounted.orders.pageNumber.value).toBe(1);
  });

  it("sends the page size when it is not the default, from the first page", async () => {
    serve({ "": { items: [order("R-1")], nextCursor: null } });
    const mounted = mountOrders();
    wrapper = mounted.wrapper;
    await flushPromises();

    mounted.orders.setPerPage(50);
    await flushPromises();

    expect(queries().at(-1)).toEqual({ customerId: "c-1", perPage: 50 });
    expect(mounted.orders.perPage.value).toBe(50);
  });

  it("starts again for another customer", async () => {
    serve({ "": { items: [order("R-1")], nextCursor: null } });
    const mounted = mountOrders();
    wrapper = mounted.wrapper;
    await flushPromises();

    mounted.customerId.value = "c-2";
    await flushPromises();

    expect(queries().at(-1)).toEqual({ customerId: "c-2" });
  });

  it.each([
    [403, "Nie masz dostępu do zamówień.", true],
    [500, "Nie udało się pobrać zamówień klienta (HTTP 500).", false],
  ])("reports a %s in Polish", async (status, message, forbidden) => {
    serve({}, status);
    const mounted = mountOrders();
    wrapper = mounted.wrapper;
    await flushPromises();

    expect(mounted.orders.error.value).toBe(message);
    expect(mounted.orders.forbidden.value).toBe(forbidden);
  });

  it("reports a failed request in Polish", async () => {
    mocks.get.mockRejectedValue(new Error("network"));
    const mounted = mountOrders();
    wrapper = mounted.wrapper;
    await flushPromises();

    expect(mounted.orders.error.value).toBe("Nie udało się pobrać zamówień klienta. Sprawdź połączenie z API.");
  });
});
