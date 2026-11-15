import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent } from "vue";
import { createMemoryHistory, createRouter } from "vue-router";
import type { components } from "@/api/schema";
import { SEARCH_DEBOUNCE_MS, useDiscountCodeList } from "@/discount-codes/useDiscountCodeList";
import type { WithOptionalTotal } from "./listPage";

type CodeList = WithOptionalTotal<components["schemas"]["DiscountCodeListResponse"]>;
type Code = components["schemas"]["DiscountCodeResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

const CODES = "/api/v1/admin/discount-codes";

function code(text: string, overrides: Partial<Code> = {}): Code {
  return {
    id: `id-${text}`,
    code: text,
    kind: "percent",
    value: 10,
    minimumOrderGross: null,
    startsAt: "2026-11-10T23:00:00+00:00",
    endsAt: null,
    usageLimit: null,
    activeUses: 0,
    enabled: true,
    status: "planned",
    version: 1,
    ...overrides,
  };
}

function page(codes: string[], next: string | null = null): CodeList {
  return { items: codes.map((text) => code(text)), next };
}

/** Answers the list by the `after` cursor of the request ("" for the first page). */
function serve(pages: Record<string, CodeList>, status?: number) {
  mocks.get.mockImplementation(async (_path: string, options?: { params?: { query?: { after?: string } } }) =>
    status ? { data: undefined, response: { status } } : { data: pages[options?.params?.query?.after ?? ""], response: { status: 200 } },
  );
}

function queries(): Record<string, unknown>[] {
  return mocks.get.mock.calls.map(([, options]) => (options as { params: { query: Record<string, unknown> } }).params.query);
}

/** Mounts the composable on a router at `path` (the filter of the list is read from its query). */
async function mountList(path = "/discount-codes") {
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/discount-codes", name: "discount-codes", component: empty },
      { path: "/products", name: "products", component: empty },
    ],
  });
  await router.push(path);
  let list!: ReturnType<typeof useDiscountCodeList>;
  const wrapper = mount(
    defineComponent({
      setup() {
        list = useDiscountCodeList();
        return () => null;
      },
    }),
    { global: { plugins: [router] } },
  );
  return { wrapper, list, router };
}

describe("useDiscountCodeList", () => {
  let wrapper: Awaited<ReturnType<typeof mountList>>["wrapper"] | undefined;

  beforeEach(() => {
    mocks.get.mockReset();
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    vi.useRealTimers();
  });

  async function mounted(pages: Record<string, CodeList> = { "": page(["A"]) }, path?: string) {
    serve(pages);
    const result = await mountList(path);
    wrapper = result.wrapper;
    await flushPromises();
    return Object.assign(result.list, { router: result.router });
  }

  async function type(list: Awaited<ReturnType<typeof mounted>>, text: string) {
    list.search.value = text;
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS);
    await flushPromises();
  }

  it("loads the first page without a filter, a search, a page size or a cursor", async () => {
    const list = await mounted({ "": page(["A", "B"], "c1") });

    expect(mocks.get).toHaveBeenCalledWith(CODES, { params: { query: {} } });
    expect(list.items.value.map((item) => item.code)).toEqual(["A", "B"]);
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
    expect(list.items.value.map((item) => item.code)).toEqual(["C"]);
    expect(list.hasNext.value).toBe(false);
    expect(list.pageNumber.value).toBe(3);

    await list.goPrevious();
    expect(list.items.value.map((item) => item.code)).toEqual(["B"]);
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

    list.selectStatus("expired");
    await flushPromises();
    await list.goNext();
    expect(queries().at(-1)).toEqual({ status: "expired", after: "c1" });

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

  it("searches by the beginning of the code after a pause in typing, from one character", async () => {
    const list = await mounted({ "": page(["A"], "c1"), c1: page(["B"]) });
    await list.goNext();

    list.search.value = "w";
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS - 1);
    expect(mocks.get).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(1);
    await flushPromises();

    expect(queries().at(-1)).toEqual({ q: "w" });
    expect(list.pageNumber.value).toBe(1);
  });

  it("asks once for a burst of typing and keeps the status with the search", async () => {
    const list = await mounted();
    list.selectStatus("active");
    await flushPromises();

    list.search.value = "W";
    await vi.advanceTimersByTimeAsync(100);
    list.search.value = "WI";
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS);
    await flushPromises();

    expect(queries().slice(2)).toEqual([{ status: "active", q: "WI" }]);
  });

  it("trims the search, treats white space only as no search, and does not ask twice for the same term", async () => {
    const list = await mounted();

    await type(list, "  ab  ");
    expect(queries().at(-1)).toEqual({ q: "ab" });
    const calls = mocks.get.mock.calls.length;

    await type(list, "ab ");
    expect(mocks.get).toHaveBeenCalledTimes(calls);

    await type(list, "   ");
    expect(queries().at(-1)).toEqual({});
  });

  it("trims a no-break space the way the API does", async () => {
    const list = await mounted();

    await type(list, " ab ");

    expect(queries().at(-1)).toEqual({ q: "ab" });
  });

  it.each([
    ["U+200B (zero width space)", "​"],
    ["U+00AD (soft hyphen)", "­"],
    ["U+2060 (word joiner)", "⁠"],
    ["a space, U+200B and a space", " ​ "],
  ])("#336: a search of only %s is an empty search: the whole list, and no request with q", async (_name, text) => {
    const list = await mounted();
    await type(list, "ab");
    mocks.get.mockClear();

    await type(list, text);

    expect(queries()).toEqual([{}]);
    expect(list.filtered.value).toBe(false);
  });

  it("#336: a search of only an invisible character on an empty field asks for nothing", async () => {
    const list = await mounted();
    mocks.get.mockClear();

    await type(list, "​");

    expect(mocks.get).not.toHaveBeenCalled();
  });

  it("#336: trims invisible characters around a term and sends the term alone", async () => {
    const list = await mounted();

    await type(list, "​LATO⁠");

    expect(queries().at(-1)).toEqual({ q: "LATO" });
  });

  it("does not search for a term longer than a code can be, and says so", async () => {
    const list = await mounted();

    await type(list, "A".repeat(33));

    expect(mocks.get).toHaveBeenCalledTimes(1);
    expect(list.searchHint.value).toBe("Kod ma najwyżej 32 znaki.");
    await type(list, "A".repeat(32));
    expect(list.searchHint.value).toBeNull();
    expect(queries().at(-1)).toEqual({ q: "A".repeat(32) });
  });

  it("drops the rows and the cursors of the old filter when the request for the new one fails, and recovers on retry", async () => {
    const list = await mounted({ "": page(["A"], "c1") });
    serve({}, 500);

    list.selectStatus("planned");
    await flushPromises();

    expect(list.items.value).toEqual([]);
    expect(list.loaded.value).toBe(false);
    expect(list.hasNext.value).toBe(false);
    expect(list.error.value).toBe("Nie udało się pobrać kodów rabatowych (HTTP 500).");

    serve({ "": page(["P"]) });
    await list.retry();
    expect(queries().at(-1)).toEqual({ status: "planned" });
    expect(list.items.value.map((item) => item.code)).toEqual(["P"]);
    expect(list.error.value).toBeNull();
  });

  it("drops the rows of the old search when the request for the new one fails", async () => {
    const list = await mounted({ "": page(["A"], "c1") });
    serve({}, 500);

    await type(list, "x");

    expect(list.items.value).toEqual([]);
    expect(list.loaded.value).toBe(false);
    expect(list.hasNext.value).toBe(false);
    expect(list.error.value).not.toBeNull();
  });

  it("keeps the page that is shown when the next page fails and retries that page", async () => {
    const list = await mounted({ "": page(["A"], "c1") });
    serve({}, 500);

    await list.goNext();
    expect(list.items.value.map((item) => item.code)).toEqual(["A"]);
    expect(list.pageNumber.value).toBe(1);
    expect(list.error.value).not.toBeNull();

    serve({ c1: page(["B"]) });
    await list.retry();
    expect(list.items.value.map((item) => item.code)).toEqual(["B"]);
    expect(list.pageNumber.value).toBe(2);
  });

  it("reports a 403 as such and a request that failed to leave as a connection problem", async () => {
    serve({}, 403);
    const result = await mountList();
    wrapper = result.wrapper;
    await flushPromises();
    expect(result.list.forbidden.value).toBe(true);
    expect(result.list.error.value).toBe("Nie masz dostępu do kodów rabatowych.");

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

    expect(result.list.items.value.map((item) => item.code)).toEqual(["Fresh"]);
  });

  it("tells a filtered list from an unfiltered one", async () => {
    const list = await mounted({ "": { items: [], next: null } });
    expect(list.filtered.value).toBe(false);

    list.selectStatus("expired");
    await flushPromises();
    expect(list.filtered.value).toBe(true);

    list.selectStatus(null);
    await flushPromises();
    expect(list.filtered.value).toBe(false);
    await type(list, "ab");
    expect(list.filtered.value).toBe(true);
  });

  describe("the state in the address", () => {
    it("reads the status, the search and the page size of the address with one request, and puts the search in the field", async () => {
      const list = await mounted({ "": page(["LATO"]) }, "/discount-codes?status=active&q=LATO&perPage=10");

      expect(queries()).toEqual([{ status: "active", q: "LATO", perPage: 10 }]);
      expect(list.query.value).toEqual({ q: "LATO", status: "active", perPage: 10 });
      expect(list.search.value).toBe("LATO");
      expect(list.filtered.value).toBe(true);
    });

    it.each([
      "/discount-codes?status=nope",
      "/discount-codes?status=",
      "/discount-codes?status=all",
      "/discount-codes?status=active&status=planned",
      "/discount-codes?status=ACTIVE",
      "/discount-codes?perPage=7",
      `/discount-codes?q=${"A".repeat(33)}`,
      "/discount-codes?q=%E2%80%8B",
    ])("skips an invalid value of %s, lists everything and corrects the address", async (path) => {
      const list = await mounted({ "": page(["A"]) }, path);

      expect(queries()).toEqual([{}]);
      expect(list.query.value).toEqual({ q: null, status: null, perPage: 25 });
      expect(list.router.currentRoute.value.fullPath).toBe("/discount-codes");
    });

    it("does not take a parameter of another name (status[]) as the status", async () => {
      const list = await mounted({ "": page(["A"]) }, "/discount-codes?status[]=active");

      expect(queries()).toEqual([{}]);
      expect(list.query.value.status).toBeNull();
    });

    it("writes a chosen status, a search and a page size to the address without a new entry of the history", async () => {
      const list = await mounted();
      const push = vi.spyOn(list.router, "push");

      list.selectStatus("active");
      await flushPromises();
      await type(list, "lato");
      list.setPerPage(50);
      await flushPromises();

      expect(list.router.currentRoute.value.fullPath).toBe("/discount-codes?q=lato&status=active&perPage=50");
      expect(push, "a change replaces the entry of the history").not.toHaveBeenCalled();
    });

    it("switches the open list to the filters of a changed address, from the first page", async () => {
      const list = await mounted({ "": page(["B"], "c1"), c1: page(["A"]) });
      await list.goNext();
      expect(list.pageNumber.value).toBe(2);
      mocks.get.mockClear();
      serve({ "": page(["Z"]) });

      await list.router.push({ path: "/discount-codes", query: { status: "active", q: "ZI" } });
      await flushPromises();

      expect(queries()).toEqual([{ status: "active", q: "ZI" }]);
      expect(list.query.value.status).toBe("active");
      expect(list.search.value, "the field follows the address").toBe("ZI");
      expect(list.pageNumber.value).toBe(1);
      expect(list.items.value.map((item) => item.code)).toEqual(["Z"]);
    });

    it("returns to all when the address loses the filters", async () => {
      const list = await mounted({ "": page(["A"]) }, "/discount-codes?status=active");
      mocks.get.mockClear();

      await list.router.push("/discount-codes");
      await flushPromises();

      expect(queries()).toEqual([{}]);
      expect(list.query.value.status).toBeNull();
    });

    it("does not ask again when the address changes without the parameters of the list changing", async () => {
      const list = await mounted({ "": page(["A"]) }, "/discount-codes?status=active");
      mocks.get.mockClear();

      await list.router.push({ path: "/discount-codes", query: { status: "active", utm: "x" } });
      await flushPromises();

      expect(mocks.get).not.toHaveBeenCalled();
    });

    it("drops the search, the filters and the page size on clearFilters: the address is the list again", async () => {
      const list = await mounted({ "": page(["A"]) }, "/discount-codes?status=active&q=LATO&perPage=10");
      mocks.get.mockClear();

      list.clearFilters();
      await flushPromises();

      expect(queries()).toEqual([{}]);
      expect(list.search.value).toBe("");
      expect(list.filtered.value).toBe(false);
      expect(list.router.currentRoute.value.fullPath).toBe("/discount-codes");
    });

    it("does not ask for the list when the staff member leaves the page", async () => {
      const list = await mounted({ "": page(["A"]) }, "/discount-codes?status=active");
      mocks.get.mockClear();

      await list.router.push("/products");
      await flushPromises();

      expect(mocks.get).not.toHaveBeenCalled();
    });
  });
});
