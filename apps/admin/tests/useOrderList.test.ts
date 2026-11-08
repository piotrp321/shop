import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent } from "vue";
import type { components } from "@/api/schema";
import { useOrderList } from "@/orders/useOrderList";

type OrderList = components["schemas"]["BackofficeOrderList"];
type OrderListItem = components["schemas"]["BackofficeOrderListItem"];

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

const ORDERS = "/api/v1/admin/orders";

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

function page(numbers: string[], nextCursor: string | null = null): OrderList {
  return { items: numbers.map((number) => order(number)), nextCursor };
}

/** Answers the list by the `after` cursor of the request ("" for the first page). */
function serve(pages: Record<string, OrderList>, status?: number) {
  mocks.get.mockImplementation(async (_path: string, options?: { params?: { query?: { after?: string } } }) =>
    status ? { data: undefined, response: { status } } : { data: pages[options?.params?.query?.after ?? ""], response: { status: 200 } },
  );
}

function queries(): Record<string, unknown>[] {
  return mocks.get.mock.calls.map(([, options]) => (options as { params: { query: Record<string, unknown> } }).params.query);
}

async function settle() {
  await flushPromises();
}

function mountList() {
  let list!: ReturnType<typeof useOrderList>;
  const wrapper = mount(
    defineComponent({
      setup() {
        list = useOrderList();
        return () => null;
      },
    }),
  );
  return { wrapper, list };
}

describe("useOrderList", () => {
  let wrapper: ReturnType<typeof mountList>["wrapper"] | undefined;

  beforeEach(() => {
    mocks.get.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
  });

  async function start(pages: Record<string, OrderList>, status?: number) {
    serve(pages, status);
    const mounted = mountList();
    wrapper = mounted.wrapper;
    await settle();
    return mounted.list;
  }

  it("loads the first page of all orders without filters", async () => {
    const list = await start({ "": page(["R-100002", "R-100001"], "c1") });

    expect(mocks.get).toHaveBeenCalledWith(ORDERS, { params: { query: {} } });
    expect(list.items.value.map((item) => item.number)).toEqual(["R-100002", "R-100001"]);
    expect(list.loaded.value).toBe(true);
    expect(list.error.value).toBeNull();
    expect(list.hasNext.value).toBe(true);
    expect(list.hasPrevious.value).toBe(false);
    expect(list.pageNumber.value).toBe(1);
  });

  it("filters by status from the first page and clears the filter", async () => {
    const list = await start({ "": page(["R-100001"]) });

    list.selectStatus("shipped");
    await settle();
    list.selectStatus(null);
    await settle();

    expect(queries()).toEqual([{}, { status: "shipped" }, {}]);
  });

  it("does not ask again for the status that is already selected", async () => {
    const list = await start({ "": page(["R-100001"]) });
    list.selectStatus("paid");
    await settle();

    list.selectStatus("paid");
    await settle();

    expect(mocks.get).toHaveBeenCalledTimes(2);
  });

  it("asks for the refunds to do when the switch is on and for all orders when it is off", async () => {
    const list = await start({ "": page(["R-100001"]) });

    list.setRefundPending(true);
    await settle();
    list.setRefundPending(false);
    await settle();

    expect(queries()).toEqual([{}, { refund: "pending" }, {}]);
    expect(list.refundPending.value).toBe(false);
  });

  it("combines the refund switch with the status and the search", async () => {
    const list = await start({ "": page(["R-100001"]) });

    list.selectStatus("cancelled");
    await settle();
    list.search.value = "anna.nowak@example.com";
    await list.submitSearch();
    list.setRefundPending(true);
    await settle();

    expect(queries().at(-1)).toEqual({ status: "cancelled", q: "anna.nowak@example.com", refund: "pending" });
  });

  it("searches only when asked to submit, with the trimmed term, and an empty term clears the search", async () => {
    const list = await start({ "": page(["R-100001"]) });

    list.search.value = "  r-100001  ";
    await settle();
    expect(mocks.get).toHaveBeenCalledTimes(1);

    await list.submitSearch();
    expect(queries().at(-1)).toEqual({ q: "r-100001" });

    list.search.value = "   ";
    await list.submitSearch();
    expect(queries().at(-1)).toEqual({});
  });

  it("keeps a cursor stack: next pages send the cursors and previous goes back down the stack", async () => {
    const list = await start({
      "": page(["R-3", "R-2"], "c1"),
      c1: page(["R-1"], "c2"),
      c2: page(["R-0"]),
    });

    await list.goNext();
    expect(list.items.value.map((item) => item.number)).toEqual(["R-1"]);
    expect(list.pageNumber.value).toBe(2);
    expect(list.hasPrevious.value).toBe(true);

    await list.goNext();
    expect(list.items.value.map((item) => item.number)).toEqual(["R-0"]);
    expect(list.hasNext.value).toBe(false);
    expect(list.pageNumber.value).toBe(3);

    await list.goPrevious();
    expect(list.items.value.map((item) => item.number)).toEqual(["R-1"]);
    expect(list.pageNumber.value).toBe(2);
    await list.goPrevious();
    expect(list.items.value.map((item) => item.number)).toEqual(["R-3", "R-2"]);
    expect(list.hasPrevious.value).toBe(false);

    expect(queries()).toEqual([{}, { after: "c1" }, { after: "c2" }, { after: "c1" }, {}]);
  });

  it("starts again from the first page when a filter changes and drops the cursors of the old list", async () => {
    const list = await start({ "": page(["R-3"], "c1"), c1: page(["R-2"], "c2") });
    await list.goNext();

    list.setRefundPending(true);
    await settle();

    expect(queries().at(-1)).toEqual({ refund: "pending" });
    expect(list.pageNumber.value).toBe(1);
    expect(list.hasPrevious.value).toBe(false);
  });

  it("does not pair a new filter with an old cursor when its request fails", async () => {
    const list = await start({ "": page(["R-3"], "c1"), c1: page(["R-2"], "c2") });
    await list.goNext();
    serve({}, 500);

    list.selectStatus("shipped");
    await settle();

    expect(list.hasPrevious.value).toBe(false);
    expect(list.hasNext.value).toBe(false);
    expect(list.error.value).toBe("Nie udało się pobrać zamówień (HTTP 500).");
  });

  it.each([
    ["a status", (list: ReturnType<typeof useOrderList>) => list.selectStatus("shipped")],
    ["the refund switch", (list: ReturnType<typeof useOrderList>) => list.setRefundPending(true)],
    [
      "a search",
      (list: ReturnType<typeof useOrderList>) => {
        list.search.value = "R-999999";
        return list.submitSearch();
      },
    ],
  ])("shows no rows of the old filters when the request for %s fails", async (_name, apply) => {
    const list = await start({ "": page(["R-3", "R-2"]) });
    serve({}, 500);

    await apply(list);

    expect(list.error.value).toBe("Nie udało się pobrać zamówień (HTTP 500).");
    expect(list.items.value).toEqual([]);
    expect(list.loaded.value).toBe(false);

    serve({ "": page(["R-9"]) });
    await list.retry();

    expect(list.items.value.map((item) => item.number)).toEqual(["R-9"]);
    expect(list.loaded.value).toBe(true);
  });

  it("says there are no orders when the list is empty without a filter or a search", async () => {
    const list = await start({ "": page([]) });

    expect(list.items.value).toEqual([]);
    expect(list.emptyText.value).toBe("Brak zamówień.");
  });

  it.each([
    ["a status", (list: ReturnType<typeof useOrderList>) => list.selectStatus("new")],
    ["the refund switch", (list: ReturnType<typeof useOrderList>) => list.setRefundPending(true)],
    [
      "a search",
      (list: ReturnType<typeof useOrderList>) => {
        list.search.value = "R-999999";
        void list.submitSearch();
      },
    ],
  ])("says no orders were found when the list is empty with %s", async (_name, apply) => {
    const list = await start({ "": page([]) });

    apply(list);
    await settle();

    expect(list.emptyText.value).toBe("Nie znaleziono zamówień.");
  });

  it("goes back to the text for no orders once the filters are cleared", async () => {
    const list = await start({ "": page([]) });
    list.selectStatus("new");
    await settle();
    list.selectStatus(null);
    await settle();

    expect(list.emptyText.value).toBe("Brak zamówień.");
  });

  it("shows an error with a retry of the same page and keeps the page that is shown", async () => {
    const list = await start({ "": page(["R-2"], "c1"), c1: page(["R-1"]) });
    serve({}, 503);

    await list.goNext();

    expect(list.error.value).toBe("Nie udało się pobrać zamówień (HTTP 503).");
    expect(list.forbidden.value).toBe(false);
    expect(list.items.value.map((item) => item.number)).toEqual(["R-2"]);
    expect(list.pageNumber.value).toBe(1);

    serve({ c1: page(["R-1"]) });
    await list.retry();

    expect(list.error.value).toBeNull();
    expect(list.items.value.map((item) => item.number)).toEqual(["R-1"]);
    expect(list.pageNumber.value).toBe(2);
    expect(queries().at(-1)).toEqual({ after: "c1" });
  });

  it("explains a request that did not reach the API", async () => {
    mocks.get.mockRejectedValue(new TypeError("Failed to fetch"));
    const mounted = mountList();
    wrapper = mounted.wrapper;
    await settle();

    expect(mounted.list.error.value).toBe("Nie udało się pobrać zamówień. Sprawdź połączenie z API.");
    expect(mounted.list.loading.value).toBe(false);
  });

  it("tells a role without access that it has none (403) and marks it as not worth retrying", async () => {
    const list = await start({}, 403);

    expect(list.error.value).toBe("Nie masz dostępu do zamówień.");
    expect(list.forbidden.value).toBe(true);
    expect(list.loaded.value).toBe(false);
  });

  it("explains a rejected search (400)", async () => {
    const list = await start({ "": page(["R-1"]) });
    serve({}, 400);

    list.search.value = "x";
    await list.submitSearch();

    expect(list.error.value).toBe("Nieprawidłowe parametry wyszukiwania (HTTP 400).");
  });

  it("ignores an answer of an older request that arrives after a newer one", async () => {
    let releaseFirst!: () => void;
    mocks.get.mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          releaseFirst = () => resolve({ data: page(["R-old"]), response: { status: 200 } });
        }),
    );
    const mounted = mountList();
    wrapper = mounted.wrapper;
    mocks.get.mockResolvedValue({ data: page(["R-new"]), response: { status: 200 } });

    mounted.list.selectStatus("paid");
    await settle();
    releaseFirst();
    await settle();

    expect(mounted.list.items.value.map((item) => item.number)).toEqual(["R-new"]);
  });
});
