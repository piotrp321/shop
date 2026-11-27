import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent } from "vue";
import { createMemoryHistory, createRouter } from "vue-router";
import type { components } from "@/api/schema";
import { EMPTY_MESSAGE, useOrderList } from "@/orders/useOrderList";
import { requestSignal, type WithOptionalTotal } from "./listPage";

type OrderList = WithOptionalTotal<components["schemas"]["BackofficeOrderList"]>;
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

/** Mounts the composable on a router at `path` (the filters of the list are read from its query). */
async function mountList(path = "/orders") {
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/orders", name: "orders", component: empty },
      { path: "/products", name: "products", component: empty },
    ],
  });
  await router.push(path);
  let list!: ReturnType<typeof useOrderList>;
  const wrapper = mount(
    defineComponent({
      setup() {
        list = useOrderList();
        return () => null;
      },
    }),
    { global: { plugins: [router] } },
  );
  return { wrapper, list, router };
}

describe("useOrderList", () => {
  let wrapper: Awaited<ReturnType<typeof mountList>>["wrapper"] | undefined;

  beforeEach(() => {
    mocks.get.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
  });

  async function start(pages: Record<string, OrderList>, status?: number, path?: string) {
    serve(pages, status);
    const mounted = await mountList(path);
    wrapper = mounted.wrapper;
    await settle();
    return mounted.list;
  }

  it("loads the first page of all orders without filters", async () => {
    const list = await start({ "": page(["R-100002", "R-100001"], "c1") });

    expect(mocks.get).toHaveBeenCalledWith(ORDERS, { params: { query: {} }, signal: expect.any(AbortSignal) });
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

  it("asks for the overdue refunds when „Tylko po terminie” is on, and for the refunds to do when it is off", async () => {
    const list = await start({ "": page(["R-100001"]) });

    list.setRefundPending(true);
    await settle();
    list.setRefundOverdue(true);
    await settle();
    expect(list.refundPending.value).toBe(true);
    expect(list.refundOverdue.value).toBe(true);
    list.setRefundOverdue(false);
    await settle();

    expect(queries()).toEqual([{}, { refund: "pending" }, { refund: "overdue" }, { refund: "pending" }]);
    expect(list.refundOverdue.value).toBe(false);
    expect(list.refundPending.value).toBe(true);
  });

  it("drops the overdue refunds with the refunds to do, and does not turn them on by switching off what is off", async () => {
    const list = await start({ "": page(["R-100001"]) });

    list.setRefundOverdue(false);
    await settle();
    list.setRefundOverdue(true);
    await settle();
    list.setRefundPending(false);
    await settle();

    expect(queries()).toEqual([{}, { refund: "overdue" }, {}]);
    expect(list.refundPending.value).toBe(false);
    expect(list.refundOverdue.value).toBe(false);
  });

  it("combines the refund switch with the status and the search", async () => {
    const list = await start({ "": page(["R-100001"]) });

    list.selectStatus("cancelled");
    await settle();
    list.search.value = "anna.nowak@example.com";
    list.submitSearch();
    await settle();
    list.setRefundPending(true);
    await settle();

    expect(queries().at(-1)).toEqual({ status: "cancelled", q: "anna.nowak@example.com", refund: "pending" });
  });

  it("searches only when asked to submit, with the trimmed term, and an empty term clears the search", async () => {
    const list = await start({ "": page(["R-100001"]) });

    list.search.value = "  r-100001  ";
    await settle();
    expect(mocks.get).toHaveBeenCalledTimes(1);

    list.submitSearch();
    await settle();
    expect(queries().at(-1)).toEqual({ q: "r-100001" });

    list.search.value = "   ";
    list.submitSearch();
    await settle();
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
        list.submitSearch();
      },
    ],
  ])("shows no rows of the old filters when the request for %s fails", async (_name, apply) => {
    const list = await start({ "": page(["R-3", "R-2"]) });
    serve({}, 500);

    apply(list);
    await settle();

    expect(list.error.value).toBe("Nie udało się pobrać zamówień (HTTP 500).");
    expect(list.items.value).toEqual([]);
    expect(list.loaded.value).toBe(false);

    serve({ "": page(["R-9"]) });
    await list.retry();

    expect(list.items.value.map((item) => item.number)).toEqual(["R-9"]);
    expect(list.loaded.value).toBe(true);
  });

  it("is not filtered, and the empty list says there are no orders yet, without a filter or a search", async () => {
    const list = await start({ "": page([]) });

    expect(list.items.value).toEqual([]);
    expect(list.filtered.value).toBe(false);
    expect(EMPTY_MESSAGE).toBe("Nie ma jeszcze zamówień.");
  });

  it.each([
    ["a status", (list: ReturnType<typeof useOrderList>) => list.selectStatus("new")],
    ["the refund switch", (list: ReturnType<typeof useOrderList>) => list.setRefundPending(true)],
    [
      "a search",
      (list: ReturnType<typeof useOrderList>) => {
        list.search.value = "R-999999";
        list.submitSearch();
      },
    ],
  ])("is filtered with %s, and not any more once the filters are cleared", async (_name, apply) => {
    const list = await start({ "": page([]) });

    apply(list);
    await settle();
    expect(list.filtered.value).toBe(true);

    list.clearFilters();
    await settle();
    expect(list.filtered.value).toBe(false);
    expect(list.search.value).toBe("");
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
    const mounted = await mountList();
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

    // Over 100 characters that are no email: the panel sends it (a 1 or 2 characters long term it does not), the API refuses it.
    list.search.value = "x".repeat(101);
    list.submitSearch();
    await settle();

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
    const mounted = await mountList();
    wrapper = mounted.wrapper;
    mocks.get.mockResolvedValue({ data: page(["R-new"]), response: { status: 200 } });

    mounted.list.selectStatus("paid");
    await settle();
    releaseFirst();
    await settle();

    expect(mounted.list.items.value.map((item) => item.number)).toEqual(["R-new"]);
  });

  it("sends the page size only when it is not the default of 25, and starts from the first page for a new one", async () => {
    const list = await start({ "": page(["R-3"], "c1"), c1: page(["R-2"]) });
    await list.goNext();

    list.setPerPage(10);
    await settle();
    expect(queries().at(-1)).toEqual({ perPage: 10 });
    expect(list.pageNumber.value).toBe(1);

    list.setPerPage(25);
    await settle();
    expect(queries().at(-1)).toEqual({});
  });

  it("numbers the rows on the screen for the pager", async () => {
    const list = await start({ "": page(["R-3", "R-2"], "c1"), c1: page(["R-1"]) });
    expect(list.range.value).toEqual({ from: 1, to: 2 });

    await list.goNext();

    expect(list.range.value).toEqual({ from: 26, to: 26 });
  });

  it("gives the total of the first page and keeps it on the next pages", async () => {
    const list = await start({
      "": { ...page(["R-3", "R-2"], "c1"), total: { value: 3, capped: false } },
      c1: { ...page(["R-1"]), total: null },
    });
    expect(list.total.value).toEqual({ value: 3, capped: false });

    await list.goNext();

    expect(list.total.value).toEqual({ value: 3, capped: false });
  });

  it("searches again for the same term, so that Enter reloads the list", async () => {
    const list = await start({ "": page(["R-1"]) });
    list.search.value = "R-1";
    list.submitSearch();
    await settle();
    mocks.get.mockClear();

    list.submitSearch();
    await settle();

    expect(queries()).toEqual([{ q: "R-1" }]);
  });

  it("trims the white space around a search, a no-break space and U+2028 too", async () => {
    const list = await start({ "": page(["R-1"]) });

    list.search.value = "\u00a0R-1\u2028";
    list.submitSearch();
    await settle();

    expect(queries().at(-1)).toEqual({ q: "R-1" });
  });

  describe("the sort", () => {
    const one = { "": page(["R-1"], "c1"), c1: page(["R-0"]) };

    async function startAt(path: string) {
      serve(one);
      const mounted = await mountList(path);
      wrapper = mounted.wrapper;
      await settle();
      return mounted;
    }

    it("lists the newest first without a sort, and does not send the default", async () => {
      const { list } = await startAt("/orders");

      expect(queries()).toEqual([{}]);
      expect(list.sort.value).toBe("-placedAt");
      expect(list.sortOptions.value.map((option) => [option.value, option.label, option.disabledReason])).toEqual([
        ["-placedAt", "Najnowsze", undefined],
        ["placedAt", "Najstarsze", undefined],
        ["-total", "Najwyższa kwota", undefined],
        ["total", "Najniższa kwota", undefined],
      ]);
    });

    it.each(["placedAt", "-total", "total"])("reads the sort %s of the address with one request", async (sort) => {
      const { list } = await startAt(`/orders?sort=${sort}`);

      expect(queries()).toEqual([{ sort }]);
      expect(list.sort.value).toBe(sort);
    });

    it("writes the chosen sort to the address without a new entry, asks once from the first page and drops the default", async () => {
      const { list, router } = await startAt("/orders?status=paid");
      await list.goNext();
      expect(list.pageNumber.value).toBe(2);
      const push = vi.spyOn(router, "push");

      list.setSort("-total");
      await settle();

      expect(router.currentRoute.value.fullPath).toBe("/orders?status=paid&sort=-total");
      expect(queries().at(-1)).toEqual({ status: "paid", sort: "-total" });
      expect(queries().filter((query) => query.sort === "-total")).toHaveLength(1);
      expect(list.pageNumber.value).toBe(1);

      list.setSort("-placedAt");
      await settle();
      expect(router.currentRoute.value.fullPath).toBe("/orders?status=paid");
      expect(queries().at(-1)).toEqual({ status: "paid" });
      expect(push).not.toHaveBeenCalled();
    });

    it("applies a change of the address from outside with one request", async () => {
      const { router } = await startAt("/orders");
      mocks.get.mockClear();

      await router.push({ path: "/orders", query: { sort: "total" } });
      await settle();

      expect(queries()).toEqual([{ sort: "total" }]);
    });

    it.each(["/orders?sort=price", "/orders?sort=", "/orders?sort=--total", "/orders?sort=Total", "/orders?sort=refund", "/orders?sort=-name"])(
      "skips the invalid sort of %s and corrects the address",
      async (path) => {
        const { list, router } = await startAt(path);

        expect(queries()).toEqual([{}]);
        expect(list.sort.value).toBe("-placedAt");
        expect(router.currentRoute.value.fullPath).toBe("/orders");
      },
    );

    it("does not take a parameter of another name (sort[]) as the sort", async () => {
      const { list } = await startAt("/orders?sort[]=total");

      expect(queries()).toEqual([{}]);
      expect(list.sort.value).toBe("-placedAt");
    });

    it("is no filter, and clearing the filters drops it", async () => {
      const { list, router } = await startAt("/orders?sort=total");
      expect(list.filtered.value).toBe(false);

      list.clearFilters();
      await settle();

      expect(list.sort.value).toBe("-placedAt");
      expect(router.currentRoute.value.fullPath).toBe("/orders");
    });

    it("disables every sort for the refunds to do, which have an order of their own, says why, and sends none", async () => {
      const { list } = await startAt("/orders?refund=pending&sort=-total");

      expect(queries()).toEqual([{ refund: "pending" }]);
      expect(list.sort.value).toBeNull();
      expect(list.sortOptions.value).toHaveLength(4);
      for (const option of list.sortOptions.value) {
        expect(option.disabledReason, option.value).toBe("Zwroty do wykonania są w kolejności terminu zwrotu.");
      }

      list.setRefundPending(false);
      await settle();
      expect(queries().at(-1)).toEqual({ sort: "-total" });
      expect(list.sort.value).toBe("-total");
      expect(list.sortOptions.value.every((option) => option.disabledReason === undefined)).toBe(true);
    });

    it("disables the amount while a search is applied, says why, and falls back to the newest first", async () => {
      const { list } = await startAt("/orders?q=R-100001&sort=-total");

      expect(queries()).toEqual([{ q: "R-100001" }]);
      expect(list.sort.value).toBe("-placedAt");
      expect(list.sortOptions.value.map((option) => option.disabledReason)).toEqual([
        undefined,
        undefined,
        "Kwoty nie da się sortować przy wyszukiwaniu.",
        "Kwoty nie da się sortować przy wyszukiwaniu.",
      ]);

      list.setSort("placedAt");
      await settle();
      expect(queries().at(-1)).toEqual({ q: "R-100001", sort: "placedAt" });
    });

    it("keeps the sort by placement when a search is applied, and drops the one by the amount", async () => {
      const { list, router } = await startAt("/orders?sort=placedAt");
      list.search.value = "anna@example.com";
      list.submitSearch();
      await settle();
      expect(queries().at(-1)).toEqual({ q: "anna@example.com", sort: "placedAt" });

      list.clearFilters();
      list.setSort("total");
      await settle();
      list.search.value = "anna@example.com";
      list.submitSearch();
      await settle();

      expect(queries().at(-1)).toEqual({ q: "anna@example.com" });
      expect(router.currentRoute.value.fullPath).toBe("/orders?q=anna@example.com");
      expect(list.sort.value).toBe("-placedAt");
    });

    it("lets the amount back when the search is cleared, and a typed search that is not applied does not disable it", async () => {
      const { list } = await startAt("/orders");
      list.search.value = "anna";
      await settle();
      expect(list.sortOptions.value.every((option) => option.disabledReason === undefined)).toBe(true);
    });
  });

  describe("the state in the address", () => {
    /** Starts the list at `path` and keeps the router, to change the address of the open list. */
    async function startAt(path: string) {
      serve({ "": page(["R-1"]) });
      const mounted = await mountList(path);
      wrapper = mounted.wrapper;
      await settle();
      return mounted;
    }

    it("reads the status, the refund switch, the search and the page size of the address with one request", async () => {
      const { list } = await startAt("/orders?status=cancelled&refund=pending&q=anna%40example.com&perPage=10");

      expect(queries()).toEqual([{ status: "cancelled", refund: "pending", q: "anna@example.com", perPage: 10 }]);
      expect(list.status.value).toBe("cancelled");
      expect(list.refundPending.value).toBe(true);
      expect(list.search.value).toBe("anna@example.com");
      expect(list.filtered.value).toBe(true);
    });

    it("reads the status from the address with one request", async () => {
      const { list } = await startAt("/orders?status=paid");

      expect(queries()).toEqual([{ status: "paid" }]);
      expect(list.status.value).toBe("paid");
      expect(list.refundPending.value).toBe(false);
    });

    it("reads the overdue refunds from the address with one request, and writes them back", async () => {
      const { list, router } = await startAt("/orders?refund=overdue&status=returned");

      expect(queries()).toEqual([{ status: "returned", refund: "overdue" }]);
      expect(list.refundPending.value).toBe(true);
      expect(list.refundOverdue.value).toBe(true);
      expect(list.filtered.value).toBe(true);

      list.setRefundOverdue(false);
      await settle();
      expect(router.currentRoute.value.fullPath).toBe("/orders?status=returned&refund=pending");

      list.setRefundOverdue(true);
      await settle();
      expect(router.currentRoute.value.fullPath).toBe("/orders?status=returned&refund=overdue");
    });

    it("gives the overdue refunds the order and the days of the refunds to do: no sort, no range", async () => {
      const { list } = await startAt("/orders?refund=overdue&sort=-total&placedFrom=2026-11-01");

      expect(queries()).toEqual([{ refund: "overdue" }]);
      expect(list.sort.value).toBeNull();
      expect(list.rangeDisabled.value).toBe(true);
      expect(list.sortOptions.value.every((option) => option.disabledReason === "Zwroty do wykonania są w kolejności terminu zwrotu.")).toBe(true);
    });

    it("switches the open list to the overdue refunds when the address changes, from the first page with one request", async () => {
      const { list, router } = await startAt("/orders?refund=pending");
      mocks.get.mockClear();

      await router.push({ path: "/orders", query: { refund: "overdue" } });
      await settle();

      expect(queries()).toEqual([{ refund: "overdue" }]);
      expect(list.refundOverdue.value).toBe(true);
    });

    it("reads the refunds to do from the address with one request", async () => {
      const { list } = await startAt("/orders?refund=pending");

      expect(queries()).toEqual([{ refund: "pending" }]);
      expect(list.refundPending.value).toBe(true);
    });

    it.each([
      "/orders?status=nope",
      "/orders?status=",
      "/orders?refund=done",
      "/orders?refund=",
      "/orders?status=paid&status=new",
      "/orders?perPage=7",
      `/orders?q=${"a".repeat(255)}`,
      "/orders?q=%20%20",
    ])("skips an invalid value of %s, lists all orders and corrects the address", async (path) => {
      const { list, router } = await startAt(path);

      expect(queries()).toEqual([{}]);
      expect(list.status.value).toBeNull();
      expect(list.refundPending.value).toBe(false);
      expect(router.currentRoute.value.fullPath).toBe("/orders");
    });

    it("does not take a parameter of another name (status[]) as the status", async () => {
      const { list } = await startAt("/orders?status[]=paid");

      expect(queries()).toEqual([{}]);
      expect(list.status.value).toBeNull();
    });

    it("keeps the known value when the other one is unknown", async () => {
      await startAt("/orders?status=nope&refund=pending");

      expect(queries()).toEqual([{ refund: "pending" }]);
    });

    it("writes the filters, the search and the page size to the address without a new entry of the history", async () => {
      const { list, router } = await startAt("/orders");
      const push = vi.spyOn(router, "push");

      list.selectStatus("paid");
      list.setRefundPending(true);
      list.setPerPage(50);
      list.search.value = "anna@example.com";
      list.submitSearch();
      await settle();

      expect(router.currentRoute.value.fullPath).toBe("/orders?q=anna@example.com&status=paid&refund=pending&perPage=50");
      expect(push, "a change replaces the entry of the history").not.toHaveBeenCalled();
    });

    it("drops everything with clearFilters: the address is /orders again", async () => {
      const { list, router } = await startAt("/orders?status=paid&refund=pending&q=R-1&perPage=10");

      list.clearFilters();
      await settle();

      expect(router.currentRoute.value.fullPath).toBe("/orders");
      expect(queries().at(-1)).toEqual({});
      expect(list.search.value).toBe("");
    });

    it("switches the open list to the refunds to do when the address changes, from the first page and without a reload", async () => {
      serve({ "": page(["R-3"], "c1"), c1: page(["R-2"]) });
      const mounted = await mountList("/orders");
      wrapper = mounted.wrapper;
      await settle();
      await mounted.list.goNext();
      expect(mounted.list.pageNumber.value).toBe(2);
      mocks.get.mockClear();
      serve({ "": page(["R-9"]) });

      await mounted.router.push({ path: "/orders", query: { refund: "pending" } });
      await settle();

      expect(queries()).toEqual([{ refund: "pending" }]);
      expect(mounted.list.refundPending.value).toBe(true);
      expect(mounted.list.pageNumber.value).toBe(1);
      expect(mounted.list.items.value.map((item) => item.number)).toEqual(["R-9"]);
    });

    it("applies the address of the list also when it is written with a trailing slash", async () => {
      const { list, router } = await startAt("/orders");
      mocks.get.mockClear();
      serve({ "": page(["R-9"]) });

      await router.push("/orders/?refund=pending");
      await settle();

      expect(queries()).toEqual([{ refund: "pending" }]);
      expect(list.refundPending.value).toBe(true);
    });

    it("returns to all orders when the address loses the filter", async () => {
      const { list, router } = await startAt("/orders?refund=pending");
      mocks.get.mockClear();

      await router.push("/orders");
      await settle();

      expect(queries()).toEqual([{}]);
      expect(list.refundPending.value).toBe(false);
    });

    it("sets the status of a changed address and puts a new search of the address in the field", async () => {
      const { list, router } = await startAt("/orders");
      list.selectStatus("shipped");
      await settle();
      mocks.get.mockClear();

      await router.push({ path: "/orders", query: { status: "paid", q: "anna@example.com" } });
      await settle();

      expect(queries()).toEqual([{ status: "paid", q: "anna@example.com" }]);
      expect(list.status.value).toBe("paid");
      expect(list.search.value).toBe("anna@example.com");
    });

    it("does not ask again when the address changes without a known parameter changing", async () => {
      const { router } = await startAt("/orders?status=paid");
      mocks.get.mockClear();

      await router.push({ path: "/orders", query: { status: "paid", utm: "x" } });
      await settle();

      expect(mocks.get).not.toHaveBeenCalled();
    });

    it("does not ask for the list when the staff member leaves the page", async () => {
      const { router } = await startAt("/orders?status=paid");
      mocks.get.mockClear();

      await router.push("/products");
      await settle();

      expect(mocks.get).not.toHaveBeenCalled();
    });
  });
});

describe("useOrderList request abort", () => {
  let wrapper: { unmount: () => void } | undefined;

  beforeEach(() => {
    mocks.get.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
  });

  it("gives the request an abort signal and aborts it when the list is gone", async () => {
    mocks.get.mockImplementation(() => new Promise(() => {}));
    const mounted = await mountList();
    wrapper = mounted.wrapper;
    await flushPromises();

    const signal = requestSignal(mocks.get, ORDERS);
    expect(signal).toBeInstanceOf(AbortSignal);
    expect(signal!.aborted).toBe(false);

    wrapper.unmount();
    wrapper = undefined;
    expect(signal!.aborted).toBe(true);
  });
});
