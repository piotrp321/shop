import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent } from "vue";
import { createMemoryHistory, createRouter } from "vue-router";
import type { components } from "@/api/schema";
import { EMPTY_MESSAGE, FILTERED_EMPTY_MESSAGE, usePromotionList } from "@/promotions/usePromotionList";

type PromotionList = components["schemas"]["PromotionListResponse"];
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

/** Mounts the composable on a router at `path` (the filter of the list is read from its query). */
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

  it("loads the first page without a filter or a cursor", async () => {
    serve({ "": page(["A", "B"], "c1") });
    const mounted = await mountList();
    wrapper = mounted.wrapper;
    await flushPromises();

    expect(mocks.get).toHaveBeenCalledWith(PROMOTIONS, { params: { query: {} } });
    expect(mounted.list.items.value.map((item) => item.name)).toEqual(["A", "B"]);
    expect(mounted.list.loaded.value).toBe(true);
    expect(mounted.list.hasNext.value).toBe(true);
    expect(mounted.list.hasPrevious.value).toBe(false);
    expect(mounted.list.pageNumber.value).toBe(1);
  });

  it("pages forward and back with the cursors of the API", async () => {
    serve({ "": page(["A"], "c1"), c1: page(["B"], "c2"), c2: page(["C"], null) });
    const { wrapper: w, list } = await mountList();
    wrapper = w;
    await flushPromises();

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
    serve({ "": page(["A"], "c1"), c1: page(["B"], null) });
    const { wrapper: w, list } = await mountList();
    wrapper = w;
    await flushPromises();
    await list.goNext();

    await list.selectStatus("active");

    expect(queries().at(-1)).toEqual({ status: "active" });
    expect(list.pageNumber.value).toBe(1);
    expect(list.hasPrevious.value).toBe(false);
  });

  it("keeps the status filter while paging and drops it for all statuses", async () => {
    serve({ "": page(["A"], "c1"), c1: page(["B"], null) });
    const { wrapper: w, list } = await mountList();
    wrapper = w;
    await flushPromises();

    await list.selectStatus("ended");
    await list.goNext();
    expect(queries().at(-1)).toEqual({ status: "ended", after: "c1" });

    await list.selectStatus(null);
    expect(queries().at(-1)).toEqual({});
  });

  it("does not ask again for the status that is already chosen", async () => {
    serve({ "": page(["A"]) });
    const { wrapper: w, list } = await mountList();
    wrapper = w;
    await flushPromises();

    await list.selectStatus(null);

    expect(mocks.get).toHaveBeenCalledTimes(1);
  });

  it("drops the rows and the cursors of the old filter when the request for the new one fails, and recovers on retry", async () => {
    serve({ "": page(["A"], "c1") });
    const { wrapper: w, list } = await mountList();
    wrapper = w;
    await flushPromises();
    serve({}, 500);

    await list.selectStatus("planned");

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
    serve({ "": page(["A"], "c1") });
    const { wrapper: w, list } = await mountList();
    wrapper = w;
    await flushPromises();
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
    const { wrapper: w, list } = await mountList();
    wrapper = w;
    await flushPromises();
    expect(list.forbidden.value).toBe(true);
    expect(list.error.value).toBe("Nie masz dostępu do promocji.");

    mocks.get.mockRejectedValue(new TypeError("network down"));
    await list.retry();
    expect(list.forbidden.value).toBe(false);
    expect(list.error.value).toContain("połączenie");
  });

  it("ignores an answer that arrives after a newer request", async () => {
    let release!: (value: unknown) => void;
    mocks.get.mockImplementationOnce(() => new Promise((resolve) => (release = resolve)));
    const { wrapper: w, list } = await mountList();
    wrapper = w;
    serve({ "": page(["Fresh"]) });

    await list.selectStatus("active");
    release({ data: page(["Stale"]), response: { status: 200 } });
    await flushPromises();

    expect(list.items.value.map((item) => item.name)).toEqual(["Fresh"]);
  });

  it("says why the list is empty: nothing yet, or nothing for the filter", async () => {
    serve({ "": { items: [], next: null } });
    const { wrapper: w, list } = await mountList();
    wrapper = w;
    await flushPromises();
    expect(list.emptyText.value).toBe(EMPTY_MESSAGE);

    await list.selectStatus("ended");
    expect(list.emptyText.value).toBe(FILTERED_EMPTY_MESSAGE);
  });

  describe("status from the address", () => {
    /** Starts the list at `path` and keeps the router, to change the address of the open list. */
    async function startAt(path: string) {
      serve({ "": page(["A"]) });
      const mounted = await mountList(path);
      wrapper = mounted.wrapper;
      await flushPromises();
      return mounted;
    }

    it("reads the status from the address with one request", async () => {
      const { list } = await startAt("/promotions?status=active");

      expect(queries()).toEqual([{ status: "active" }]);
      expect(list.status.value).toBe("active");
    });

    it.each(["/promotions?status=nope", "/promotions?status=", "/promotions?status=active&status=planned", "/promotions?status[]=active", "/promotions?status=ACTIVE"])("skips an unknown value of %s and lists everything", async (path) => {
      const { list } = await startAt(path);

      expect(queries()).toEqual([{}]);
      expect(list.status.value).toBeNull();
    });

    it("switches the open list to the status of a changed address, from the first page and without a reload", async () => {
      serve({ "": page(["B"], "c1"), c1: page(["A"]) });
      const mounted = await mountList("/promotions");
      wrapper = mounted.wrapper;
      await flushPromises();
      await mounted.list.goNext();
      expect(mounted.list.pageNumber.value).toBe(2);
      mocks.get.mockClear();
      serve({ "": page(["Z"]) });

      await mounted.router.push({ path: "/promotions", query: { status: "active" } });
      await flushPromises();

      expect(queries()).toEqual([{ status: "active" }]);
      expect(mounted.list.status.value).toBe("active");
      expect(mounted.list.pageNumber.value).toBe(1);
      expect(mounted.list.items.value.map((item) => item.name)).toEqual(["Z"]);
    });

    it("takes the filter from the address again when the same location is navigated to after a change made in the list", async () => {
      const { list, router } = await startAt("/promotions?status=active");
      await list.selectStatus("planned");
      expect(router.currentRoute.value.fullPath, "the list does not write its filter to the address").toBe("/promotions?status=active");
      mocks.get.mockClear();
      serve({ "": page(["Z"]) });

      await router.push({ path: "/promotions", query: { status: "active" }, force: true, replace: true });
      await flushPromises();

      expect(queries()).toEqual([{ status: "active" }]);
      expect(list.status.value).toBe("active");
    });

    it("returns to all when the address loses the filter", async () => {
      const { list, router } = await startAt("/promotions?status=active");
      mocks.get.mockClear();

      await router.push("/promotions");
      await flushPromises();

      expect(queries()).toEqual([{}]);
      expect(list.status.value).toBeNull();
    });

    it("does not ask again when the address changes without the known filter changing", async () => {
      const { router } = await startAt("/promotions?status=active");
      mocks.get.mockClear();

      await router.push({ path: "/promotions", query: { status: "active", utm: "x" } });
      await flushPromises();

      expect(mocks.get).not.toHaveBeenCalled();
    });

    it("does not write the filter it is given back to the address", async () => {
      const { list, router } = await startAt("/promotions");

      await list.selectStatus("active");

      expect(router.currentRoute.value.fullPath).toBe("/promotions");
    });

    it("does not ask for the list when the staff member leaves the page", async () => {
      const { router } = await startAt("/promotions?status=active");
      mocks.get.mockClear();

      await router.push("/products");
      await flushPromises();

      expect(mocks.get).not.toHaveBeenCalled();
    });
  });
});
