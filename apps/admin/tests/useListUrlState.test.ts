import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { computed, defineComponent, ref } from "vue";
import { createMemoryHistory, createRouter, type Router } from "vue-router";
import { type PageRequest, type PageResult, useCursorPager } from "@/composables/useCursorPager";
import { type ListParam, useListUrlState } from "@/composables/useListUrlState";

// The same shape as the list of products: a search of 2 to 100 characters, a category the tree must confirm, a status
// and a page size with a default.
const STATUSES = ["draft", "published"] as const;
const SIZES = [10, 25, 50];

const schema = (confirm: ListParam<string, null>["confirm"]) => ({
  q: {
    parse: (raw: string) => {
      const term = raw.trim();
      const length = [...term].length;
      return length >= 2 && length <= 100 ? term : null;
    },
    fallback: null,
  } satisfies ListParam<string, null>,
  category: { parse: (raw: string) => raw, fallback: null, confirm } satisfies ListParam<string, null>,
  status: { parse: (raw: string) => STATUSES.find((status) => status === raw) ?? null, fallback: null } satisfies ListParam<(typeof STATUSES)[number], null>,
  perPage: { parse: (raw: string) => SIZES.find((size) => String(size) === raw) ?? null, fallback: 25 } satisfies ListParam<number, number>,
});

type Row = { name: string };

interface Mounted {
  router: Router;
  list: ReturnType<typeof useListUrlState<ReturnType<typeof schema>>>;
  pager: ReturnType<typeof useCursorPager<Row, { q: string | null; category: string | null; status: string | null; perPage: number }>>;
  fetchPage: ReturnType<typeof vi.fn>;
  knownCategories: ReturnType<typeof ref<string[] | null>>;
  unmount: () => void;
}

describe("useListUrlState", () => {
  let mounted: Mounted | undefined;

  beforeEach(() => {
    vi.restoreAllMocks();
  });

  afterEach(() => {
    mounted?.unmount();
    mounted = undefined;
  });

  /** Opens `address` with a list on it. `known` is the category ids of the tree; null is a tree that is still loading. */
  async function open(address: string, known: string[] | null = ["c-run", "c-tents"], prepare: (router: Router) => void = () => {}): Promise<Mounted> {
    const fetchPage = vi.fn(async ({ after }: PageRequest<unknown>): Promise<PageResult<Row>> => ({ items: [{ name: after ?? "first" }], next: "c1" }));
    const knownCategories = ref<string[] | null>(known);
    const empty = { template: "<div />" };
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: "/products", name: "products", component: empty },
        { path: "/other", name: "other", component: empty },
      ],
    });
    await router.push(address);
    prepare(router);
    const result = {} as Mounted;
    const wrapper = mount(
      defineComponent({
        setup() {
          const list = useListUrlState(
            schema((id) => (knownCategories.value === null ? undefined : knownCategories.value.includes(id))),
          );
          const pager = useCursorPager<Row, { q: string | null; category: string | null; status: string | null; perPage: number }>(
            fetchPage,
            list.query,
            { ready: list.ready },
          );
          Object.assign(result, { list, pager });
          return () => computed(() => pager.items.value.length).value;
        },
      }),
      { global: { plugins: [router] } },
    );
    Object.assign(result, { router, fetchPage, knownCategories, unmount: () => wrapper.unmount() });
    mounted = result;
    await flushPromises();
    return result;
  }

  const queries = (m: Mounted) => m.fetchPage.mock.calls.map(([request]) => (request as PageRequest<unknown>).query);
  const address = (m: Mounted) => m.router.currentRoute.value.query;

  it("reads the search, the filters and the page size from the address before the first request", async () => {
    const m = await open("/products?q=buty&category=c-run&status=published&perPage=10");

    expect(m.list.query.value).toEqual({ q: "buty", category: "c-run", status: "published", perPage: 10 });
    expect(m.fetchPage).toHaveBeenCalledTimes(1);
    expect(queries(m)).toEqual([{ q: "buty", category: "c-run", status: "published", perPage: 10 }]);
  });

  it("uses the defaults for an address without parameters and leaves the address alone", async () => {
    let replace!: ReturnType<typeof vi.spyOn>;
    const m = await open("/products", undefined, (router) => (replace = vi.spyOn(router, "replace")));

    expect(m.list.query.value).toEqual({ q: null, category: null, status: null, perPage: 25 });
    expect(address(m)).toEqual({});
    expect(replace).not.toHaveBeenCalled();
  });

  it("skips an unknown status and an unknown page size and corrects the address without a new history entry", async () => {
    let replace!: ReturnType<typeof vi.spyOn>;
    let push!: ReturnType<typeof vi.spyOn>;
    const m = await open("/products?status=bogus&perPage=7&q=buty", undefined, (router) => {
      replace = vi.spyOn(router, "replace");
      push = vi.spyOn(router, "push");
    });

    expect(m.list.query.value).toEqual({ q: "buty", category: null, status: null, perPage: 25 });
    expect(address(m)).toEqual({ q: "buty" });
    expect(replace).toHaveBeenCalledTimes(1);
    expect(push).not.toHaveBeenCalled();
    expect(m.fetchPage).toHaveBeenCalledTimes(1);
  });

  it.each([
    ["an empty value", "/products?status="],
    ["a repeated parameter", "/products?status=draft&status=published"],
    ["a page size that is not a number", "/products?perPage=abc"],
    ["a page size over the maximum", "/products?perPage=1000"],
  ])("skips %s", async (_name, path) => {
    const m = await open(path);

    expect(m.list.query.value).toEqual({ q: null, category: null, status: null, perPage: 25 });
    expect(address(m)).toEqual({});
  });

  it("skips a category that is not in the tree, before the first request, and corrects the address", async () => {
    const m = await open("/products?category=gone&status=draft");

    expect(m.list.query.value.category).toBeNull();
    expect(queries(m)).toEqual([{ q: null, category: null, status: "draft", perPage: 25 }]);
    expect(address(m)).toEqual({ status: "draft" });
  });

  it("waits for the tree before it asks for a list with a category, then asks once", async () => {
    const m = await open("/products?category=c-run", null);
    expect(m.list.ready.value).toBe(false);
    expect(m.fetchPage).not.toHaveBeenCalled();

    m.knownCategories.value = ["c-run"];
    await flushPromises();
    expect(m.list.ready.value).toBe(true);
    expect(queries(m)).toEqual([{ q: null, category: "c-run", status: null, perPage: 25 }]);
  });

  it("does not wait for the tree when the address has no category", async () => {
    const m = await open("/products?status=draft", null);

    expect(m.list.ready.value).toBe(true);
    expect(m.fetchPage).toHaveBeenCalledTimes(1);
  });

  it("shows a search that is too short in the field but does not filter by it", async () => {
    const m = await open("/products?q=a");

    expect(m.list.address.value.q).toBe("a");
    expect(m.list.query.value.q).toBeNull();
    expect(queries(m)).toEqual([{ q: null, category: null, status: null, perPage: 25 }]);
    expect(address(m)).toEqual({});
  });

  it("shows a search that is too long in the field but does not filter by it", async () => {
    const long = "a".repeat(101);
    const m = await open(`/products?q=${long}`);

    expect(m.list.address.value.q).toBe(long);
    expect(m.list.query.value.q).toBeNull();
  });

  it("writes a change with router.replace, once, and asks once for it", async () => {
    const m = await open("/products");
    const replace = vi.spyOn(m.router, "replace");
    const push = vi.spyOn(m.router, "push");

    m.list.set({ status: "draft" });
    await flushPromises();

    expect(replace).toHaveBeenCalledTimes(1);
    expect(push).not.toHaveBeenCalled();
    expect(address(m)).toEqual({ status: "draft" });
    expect(m.fetchPage).toHaveBeenCalledTimes(2);
    expect(queries(m).at(-1)).toEqual({ q: null, category: null, status: "draft", perPage: 25 });
  });

  it("writes the page size only when it is not the default", async () => {
    const m = await open("/products");

    m.list.set({ perPage: 10 });
    await flushPromises();
    expect(address(m)).toEqual({ perPage: "10" });
    expect(queries(m).at(-1)).toMatchObject({ perPage: 10 });

    m.list.set({ perPage: 25 });
    await flushPromises();
    expect(address(m)).toEqual({});
    expect(m.fetchPage).toHaveBeenCalledTimes(3);
  });

  it("keeps the parameters of the address that are not its own", async () => {
    const m = await open("/products?utm=1");

    m.list.set({ status: "draft" });
    await flushPromises();

    expect(address(m)).toEqual({ utm: "1", status: "draft" });
  });

  it("clears every parameter, the page size too, and asks once for the unfiltered list", async () => {
    const m = await open("/products?q=buty&category=c-run&status=draft&perPage=10");

    m.list.clear();
    await flushPromises();

    expect(address(m)).toEqual({});
    expect(m.list.query.value).toEqual({ q: null, category: null, status: null, perPage: 25 });
    expect(m.fetchPage).toHaveBeenCalledTimes(2);
  });

  it("is not confused by two changes in a row: the last one wins and each is asked for once", async () => {
    const m = await open("/products");

    m.list.set({ status: "draft" });
    m.list.set({ status: "published" });
    await flushPromises();

    expect(m.list.query.value.status).toBe("published");
    expect(address(m)).toEqual({ status: "published" });
    expect(m.fetchPage.mock.calls.length).toBeLessThanOrEqual(3);
    expect(queries(m).at(-1)).toMatchObject({ status: "published" });
  });

  describe("a change of route.query from outside while the list is open", () => {
    it("sets the filters, starts from the first page and asks once", async () => {
      const m = await open("/products?status=draft");
      await m.pager.goNext();
      expect(m.pager.hasPrevious.value).toBe(true);
      m.fetchPage.mockClear();

      await m.router.push({ path: "/products", query: { q: "buty", category: "c-tents", perPage: "50" } });
      await flushPromises();

      expect(m.list.query.value).toEqual({ q: "buty", category: "c-tents", status: null, perPage: 50 });
      expect(m.fetchPage).toHaveBeenCalledTimes(1);
      expect(m.fetchPage).toHaveBeenCalledWith({ query: { q: "buty", category: "c-tents", status: null, perPage: 50 }, after: null });
      expect(m.pager.hasPrevious.value).toBe(false);
    });

    it("shows the search of the address in the field and skips an unknown value of it", async () => {
      const m = await open("/products");

      await m.router.push({ path: "/products", query: { q: "x", status: "bogus", category: "gone" } });
      await flushPromises();

      expect(m.list.address.value.q).toBe("x");
      expect(m.list.query.value).toEqual({ q: null, category: null, status: null, perPage: 25 });
      expect(address(m)).toEqual({});
    });

    it("does not ask again for an address that is the same list", async () => {
      const m = await open("/products?status=draft");
      m.fetchPage.mockClear();

      await m.router.push({ path: "/products", query: { status: "draft", utm: "1" } });
      await flushPromises();

      expect(m.fetchPage).not.toHaveBeenCalled();
    });

    it("does not ask for a list when the route has left the page", async () => {
      const m = await open("/products");
      m.fetchPage.mockClear();

      await m.router.push({ path: "/other", query: { status: "draft" } });
      await flushPromises();

      expect(m.fetchPage).not.toHaveBeenCalled();
    });

    it("does not take its own write for a change from outside", async () => {
      const m = await open("/products");
      await m.pager.goNext();
      m.fetchPage.mockClear();

      m.list.set({ status: "draft" });
      await flushPromises();

      // One request, from the first page: the page change before it is not asked for again by the echo of the address.
      expect(m.fetchPage).toHaveBeenCalledTimes(1);
      expect(m.fetchPage).toHaveBeenCalledWith({ query: { q: null, category: null, status: "draft", perPage: 25 }, after: null });
    });
  });
});
