import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent } from "vue";
import { createMemoryHistory, createRouter } from "vue-router";
import type { components } from "@/api/schema";
import { EMPTY_MESSAGE, usePromotionList } from "@/promotions/usePromotionList";
import { requestSignal, type WithOptionalTotal } from "./listPage";

type PromotionList = WithOptionalTotal<components["schemas"]["PromotionListResponse"]>;
type PromotionListItem = components["schemas"]["PromotionListItemResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

const PROMOTIONS = "/api/v1/admin/promotions";

function promotion(name: string, overrides: Partial<PromotionListItem> = {}): PromotionListItem {
  return {
    id: `id-${name}`,
    name,
    percentOff: 20,
    startsAt: "2026-11-10T23:00:00+00:00",
    endsAt: "2026-11-20T23:00:00+00:00",
    status: "planned",
    variantCount: 2,
    version: 1,
    ...overrides,
  };
}

function page(names: string[], next: string | null = null): PromotionList {
  return { items: names.map((name) => promotion(name)), next };
}

/** Answers the list by the `after` cursor of the request ("" for the first page). */
function serve(pages: Record<string, PromotionList>, status?: number) {
  mocks.get.mockImplementation(async (_path: string, options?: { params?: { query?: { after?: string } } }) =>
    status ? { data: undefined, response: { status } } : { data: pages[options?.params?.query?.after ?? ""], response: { status: 200 } },
  );
}

function queries(): Record<string, unknown>[] {
  return mocks.get.mock.calls.map(([, options]) => (options as { params: { query: Record<string, unknown> } }).params.query);
}

/** Mounts the composable on a router at `path` (the status and the page size of the list live in its query). */
async function mountList(path = "/promotions") {
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/promotions", name: "promotions", component: empty },
      { path: "/products", name: "products", component: empty },
    ],
  });
  await router.push(path);
  let list!: ReturnType<typeof usePromotionList>;
  const wrapper = mount(
    defineComponent({
      setup() {
        list = usePromotionList();
        return () => null;
      },
    }),
    { global: { plugins: [router] } },
  );
  return { wrapper, list, router };
}

describe("usePromotionList", () => {
  let wrapper: Awaited<ReturnType<typeof mountList>>["wrapper"] | undefined;

  beforeEach(() => {
    mocks.get.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
  });

  async function mounted(pages: Record<string, PromotionList> = { "": page(["A"]) }, path?: string) {
    serve(pages);
    const result = await mountList(path);
    wrapper = result.wrapper;
    await flushPromises();
    return Object.assign(result.list, { router: result.router });
  }

  it("loads the first page without a filter, a page size or a cursor", async () => {
    const list = await mounted({ "": page(["A", "B"], "c1") });

    expect(mocks.get).toHaveBeenCalledWith(PROMOTIONS, { params: { query: {} }, signal: expect.any(AbortSignal) });
    expect(list.items.value.map((item) => item.name)).toEqual(["A", "B"]);
    expect(list.loaded.value).toBe(true);
    expect(list.hasNext.value).toBe(true);
    expect(list.hasPrevious.value).toBe(false);
    expect(list.pageNumber.value).toBe(1);
    expect(list.range.value).toEqual({ from: 1, to: 2 });
  });

  it("gives the total of the first page and keeps it on the next pages", async () => {
    const list = await mounted({
      "": { ...page(["A"], "c1"), total: { value: 2, capped: false } },
      c1: { ...page(["B"]), total: null },
    });
    expect(list.total.value).toEqual({ value: 2, capped: false });

    await list.goNext();

    expect(list.total.value).toEqual({ value: 2, capped: false });
  });

  it("pages forward and back with the cursors of the API", async () => {
    const list = await mounted({ "": page(["A"], "c1"), c1: page(["B"], "c2"), c2: page(["C"], null) });

    await list.goNext();
    await list.goNext();
    expect(list.items.value.map((item) => item.name)).toEqual(["C"]);
    expect(list.hasNext.value).toBe(false);
    expect(list.pageNumber.value).toBe(3);

    await list.goPrevious();
    expect(list.items.value.map((item) => item.name)).toEqual(["B"]);
    expect(queries()).toEqual([{}, { after: "c1" }, { after: "c2" }, { after: "c1" }]);

    await list.goPrevious();
    expect(queries().at(-1)).toEqual({});
    expect(list.hasPrevious.value).toBe(false);
  });

  it("filters by status and starts again from the first page", async () => {
    const list = await mounted({ "": page(["A"], "c1"), c1: page(["B"], null) });
    await list.goNext();

    list.selectStatus("active");
    await flushPromises();

    expect(queries().at(-1)).toEqual({ status: "active" });
    expect(list.pageNumber.value).toBe(1);
    expect(list.hasPrevious.value).toBe(false);
  });

  it("keeps the status filter while paging and drops it for all statuses", async () => {
    const list = await mounted({ "": page(["A"], "c1"), c1: page(["B"], null) });

    list.selectStatus("ended");
    await flushPromises();
    await list.goNext();
    expect(queries().at(-1)).toEqual({ status: "ended", after: "c1" });

    list.selectStatus(null);
    await flushPromises();
    expect(queries().at(-1)).toEqual({});
  });

  it("does not ask again for the status that is already chosen", async () => {
    const list = await mounted();

    list.selectStatus(null);
    await flushPromises();

    expect(mocks.get).toHaveBeenCalledTimes(1);
  });

  it("sends the page size only when it is not the default of 25, and starts from the first page for a new one", async () => {
    const list = await mounted({ "": page(["A"], "c1"), c1: page(["B"]) });
    await list.goNext();

    list.setPerPage(10);
    await flushPromises();
    expect(queries().at(-1)).toEqual({ perPage: 10 });
    expect(list.pageNumber.value).toBe(1);

    list.setPerPage(25);
    await flushPromises();
    expect(queries().at(-1)).toEqual({});
  });

  it("drops the rows and the cursors of the old filter when the request for the new one fails, and recovers on retry", async () => {
    const list = await mounted({ "": page(["A"], "c1") });
    serve({}, 500);

    list.selectStatus("planned");
    await flushPromises();

    expect(list.items.value).toEqual([]);
    expect(list.loaded.value).toBe(false);
    expect(list.hasNext.value).toBe(false);
    expect(list.error.value).toBe("Nie udało się pobrać promocji (HTTP 500).");

    serve({ "": page(["P"]) });
    await list.retry();
    expect(queries().at(-1)).toEqual({ status: "planned" });
    expect(list.items.value.map((item) => item.name)).toEqual(["P"]);
    expect(list.error.value).toBeNull();
  });

  it("keeps the page that is shown when the next page fails and retries that page", async () => {
    const list = await mounted({ "": page(["A"], "c1") });
    serve({}, 500);

    await list.goNext();
    expect(list.items.value.map((item) => item.name)).toEqual(["A"]);
    expect(list.pageNumber.value).toBe(1);
    expect(list.error.value).not.toBeNull();

    serve({ c1: page(["B"]) });
    await list.retry();
    expect(list.items.value.map((item) => item.name)).toEqual(["B"]);
    expect(list.pageNumber.value).toBe(2);
  });

  it("reports a 403 as such and a request that failed to leave as a connection problem", async () => {
    serve({}, 403);
    const result = await mountList();
    wrapper = result.wrapper;
    await flushPromises();
    expect(result.list.forbidden.value).toBe(true);
    expect(result.list.error.value).toBe("Nie masz dostępu do promocji.");

    mocks.get.mockRejectedValue(new TypeError("network down"));
    await result.list.retry();
    expect(result.list.forbidden.value).toBe(false);
    expect(result.list.error.value).toContain("połączenie");
  });

  it("ignores an answer that arrives after a newer request", async () => {
    let release!: (value: unknown) => void;
    mocks.get.mockImplementationOnce(() => new Promise((resolve) => (release = resolve)));
    const result = await mountList();
    wrapper = result.wrapper;
    serve({ "": page(["Fresh"]) });

    result.list.selectStatus("active");
    await flushPromises();
    release({ data: page(["Stale"]), response: { status: 200 } });
    await flushPromises();

    expect(result.list.items.value.map((item) => item.name)).toEqual(["Fresh"]);
  });

  it("tells a filtered list from an unfiltered one and keeps the text for an empty list that has nothing yet", async () => {
    const list = await mounted({ "": { items: [], next: null } });
    expect(list.filtered.value).toBe(false);
    expect(EMPTY_MESSAGE).toBe("Nie ma jeszcze promocji.");

    list.selectStatus("ended");
    await flushPromises();
    expect(list.filtered.value).toBe(true);

    list.selectStatus(null);
    await flushPromises();
    expect(list.filtered.value).toBe(false);
  });

  describe("the state in the address", () => {
    it("reads the status and the page size of the address with one request", async () => {
      const list = await mounted({ "": page(["A"]) }, "/promotions?status=active&perPage=10");

      expect(queries()).toEqual([{ status: "active", perPage: 10 }]);
      expect(list.query.value).toEqual({ status: "active", perPage: 10 });
      expect(list.filtered.value).toBe(true);
    });

    it.each([
      "/promotions?status=nope",
      "/promotions?status=",
      "/promotions?status=all",
      "/promotions?status=active&status=planned",
      "/promotions?status=ACTIVE",
      "/promotions?perPage=7",
    ])("skips an invalid value of %s, lists everything and corrects the address", async (path) => {
      const list = await mounted({ "": page(["A"]) }, path);

      expect(queries()).toEqual([{}]);
      expect(list.query.value).toEqual({ status: null, perPage: 25 });
      expect(list.router.currentRoute.value.fullPath).toBe("/promotions");
    });

    it("does not take a parameter of another name (status[]) as the status", async () => {
      const list = await mounted({ "": page(["A"]) }, "/promotions?status[]=active");

      expect(queries()).toEqual([{}]);
      expect(list.query.value.status).toBeNull();
    });

    it("writes a chosen status and a page size to the address without a new entry of the history", async () => {
      const list = await mounted();
      const push = vi.spyOn(list.router, "push");

      list.selectStatus("active");
      await flushPromises();
      list.setPerPage(50);
      await flushPromises();

      expect(list.router.currentRoute.value.fullPath).toBe("/promotions?status=active&perPage=50");
      expect(push, "a change replaces the entry of the history").not.toHaveBeenCalled();
    });

    it("switches the open list to the status of a changed address, from the first page and without a reload", async () => {
      const list = await mounted({ "": page(["B"], "c1"), c1: page(["A"]) });
      await list.goNext();
      expect(list.pageNumber.value).toBe(2);
      mocks.get.mockClear();
      serve({ "": page(["Z"]) });

      await list.router.push({ path: "/promotions", query: { status: "active" } });
      await flushPromises();

      expect(queries()).toEqual([{ status: "active" }]);
      expect(list.query.value.status).toBe("active");
      expect(list.pageNumber.value).toBe(1);
      expect(list.items.value.map((item) => item.name)).toEqual(["Z"]);
    });

    it("returns to all when the address loses the filter", async () => {
      const list = await mounted({ "": page(["A"]) }, "/promotions?status=active");
      mocks.get.mockClear();

      await list.router.push("/promotions");
      await flushPromises();

      expect(queries()).toEqual([{}]);
      expect(list.query.value.status).toBeNull();
    });

    it("does not ask again when the address changes without the parameters of the list changing", async () => {
      const list = await mounted({ "": page(["A"]) }, "/promotions?status=active");
      mocks.get.mockClear();

      await list.router.push({ path: "/promotions", query: { status: "active", utm: "x" } });
      await flushPromises();

      expect(mocks.get).not.toHaveBeenCalled();
    });

    it("drops the filter and the page size on clearFilters: the address is the list again", async () => {
      const list = await mounted({ "": page(["A"]) }, "/promotions?status=active&perPage=10");
      mocks.get.mockClear();

      list.clearFilters();
      await flushPromises();

      expect(queries()).toEqual([{}]);
      expect(list.filtered.value).toBe(false);
      expect(list.router.currentRoute.value.fullPath).toBe("/promotions");
    });

    it("does not ask for the list when the staff member leaves the page", async () => {
      const list = await mounted({ "": page(["A"]) }, "/promotions?status=active");
      mocks.get.mockClear();

      await list.router.push("/products");
      await flushPromises();

      expect(mocks.get).not.toHaveBeenCalled();
    });
  });
});

describe("usePromotionList request abort", () => {
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

    const signal = requestSignal(mocks.get, PROMOTIONS);
    expect(signal).toBeInstanceOf(AbortSignal);
    expect(signal!.aborted).toBe(false);

    wrapper.unmount();
    wrapper = undefined;
    expect(signal!.aborted).toBe(true);
  });
});
