import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, ref } from "vue";
import { createMemoryHistory, createRouter, type Router } from "vue-router";
import type { components } from "@/api/schema";
import type { CategoryTreeNode } from "@/composables/useCategoryTree";
import { SEARCH_DEBOUNCE_MS, useProductList } from "@/products/useProductList";

type ProductList = components["schemas"]["ProductListResponse"];
type ProductListItem = components["schemas"]["ProductListItemResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

const PRODUCTS = "/api/v1/admin/products";

function item(name: string, overrides: Partial<ProductListItem> = {}): ProductListItem {
  return {
    id: `id-${name}`,
    name,
    slug: name.toLowerCase(),
    status: "published",
    mainCategory: { id: "c-main", name: "Sport" },
    variantCount: 1,
    image: null,
    updatedAt: "2026-11-01T12:30:00+00:00",
    ...overrides,
  };
}

/** Answers the product list by the `after` cursor of the request ("" for the first page). */
function serve(pages: Record<string, ProductList>, status?: number) {
  mocks.get.mockImplementation(async (_path: string, options?: { params?: { query?: { after?: string } } }) =>
    status ? { data: undefined, response: { status } } : { data: pages[options?.params?.query?.after ?? ""], response: { status: 200 } },
  );
}

function queries(): Record<string, unknown>[] {
  return mocks.get.mock.calls.map(([, options]) => (options as { params: { query: Record<string, unknown> } }).params.query);
}

async function settle() {
  await vi.advanceTimersByTimeAsync(0);
  await flushPromises();
}

/** The tree of the category filter: loaded (with these categories) or still loading (null). */
type Tree = CategoryTreeNode[] | null;

async function mountList(address = "/products", tree?: Tree) {
  let list!: ReturnType<typeof useProductList>;
  const empty = { template: "<div />" };
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: "/products", name: "products", component: empty }] });
  const nodes = ref<CategoryTreeNode[]>(tree ?? []);
  const loaded = ref(tree !== undefined && tree !== null);
  // The router is on its address before the view is mounted, as the guard of the app leaves it.
  await router.push(address);
  const wrapper = mount(
    defineComponent({
      setup() {
        list = useProductList(tree === undefined ? undefined : { nodes, loaded });
        return () => null;
      },
    }),
    { global: { plugins: [router] } },
  );
  return { wrapper, list, router, nodes, loaded };
}

const leaf = (key: string): CategoryTreeNode => ({ key, label: key, children: [] });

describe("useProductList", () => {
  let wrapper: Awaited<ReturnType<typeof mountList>>["wrapper"] | undefined;
  let router: Router | undefined;

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    mocks.get.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    vi.useRealTimers();
  });

  async function start(pages: Record<string, ProductList>, status?: number, address?: string, tree?: Tree) {
    serve(pages, status);
    const mounted = await mountList(address, tree);
    wrapper = mounted.wrapper;
    router = mounted.router;
    await settle();
    return mounted.list;
  }

  it("loads the first page of the product list on mount", async () => {
    const list = await start({ "": { items: [item("Bidon")], next: null } });

    expect(mocks.get).toHaveBeenCalledWith(PRODUCTS, { params: { query: {} } });
    expect(list.items.value.map((product) => product.name)).toEqual(["Bidon"]);
    expect(list.loaded.value).toBe(true);
    expect(list.hasNext.value).toBe(false);
    expect(list.hasPrevious.value).toBe(false);
  });

  it("pages forward and back with the cursors of the API, keeping the cursor stack", async () => {
    const list = await start({
      "": { items: [item("A")], next: "c1" },
      c1: { items: [item("B")], next: "c2" },
      c2: { items: [item("C")], next: null },
    });

    await list.goNext();
    await list.goNext();
    expect(list.items.value.map((product) => product.name)).toEqual(["C"]);
    expect(list.pageNumber.value).toBe(3);
    expect(list.hasNext.value).toBe(false);

    await list.goPrevious();
    expect(list.items.value.map((product) => product.name)).toEqual(["B"]);
    await list.goPrevious();
    expect(list.items.value.map((product) => product.name)).toEqual(["A"]);
    expect(queries()).toEqual([{}, { after: "c1" }, { after: "c2" }, { after: "c1" }, {}]);
    expect(list.hasPrevious.value).toBe(false);
  });

  it("does not ask for a search shorter than two characters", async () => {
    const list = await start({ "": { items: [item("A")], next: null } });

    list.search.value = "a";
    await vi.advanceTimersByTimeAsync(1000);
    await settle();

    expect(queries()).toHaveLength(1);
    expect(list.searchTooShort.value).toBe(true);
  });

  it("counts characters, not UTF-16 units", async () => {
    const list = await start({ "": { items: [item("A")], next: null } });

    list.search.value = "😀";
    await vi.advanceTimersByTimeAsync(1000);
    expect(queries()).toHaveLength(1);

    list.search.value = "😀😀";
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 100);
    await settle();
    expect(queries().at(-1)).toEqual({ q: "😀😀" });
  });

  it("searches once after the typing pauses, with the trimmed term, and goes back to the full list when cleared", async () => {
    const list = await start({ "": { items: [item("A")], next: null } });

    list.search.value = "bi";
    await vi.advanceTimersByTimeAsync(100);
    list.search.value = "  bidon ";
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 100);
    await settle();
    expect(queries()).toEqual([{}, { q: "bidon" }]);

    list.search.value = "";
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 100);
    await settle();
    expect(queries().at(-1)).toEqual({});
  });

  it("filters by category and by status, and starts from the first page when a filter or the search changes", async () => {
    const list = await start({
      "": { items: [item("A")], next: "c1" },
      c1: { items: [item("B")], next: null },
    });
    await list.goNext();

    list.selectCategory("c-run");
    await settle();
    expect(queries().at(-1)).toEqual({ category: "c-run" });
    expect(list.hasPrevious.value).toBe(false);

    list.selectStatus("draft");
    await settle();
    expect(queries().at(-1)).toEqual({ category: "c-run", status: "draft" });

    list.search.value = "baton";
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 100);
    await settle();
    expect(queries().at(-1)).toEqual({ q: "baton", category: "c-run", status: "draft" });

    list.selectCategory(null);
    list.selectStatus(null);
    await settle();
    expect(queries().at(-1)).toEqual({ q: "baton" });
  });

  it("does not ask again for a filter that did not change", async () => {
    const list = await start({ "": { items: [item("A")], next: null } });

    list.selectCategory(null);
    list.selectStatus(null);
    await settle();

    expect(queries()).toHaveLength(1);
  });

  it("drops the cursor of the old filter, so a failed filter change cannot be paged with it", async () => {
    const list = await start({ "": { items: [item("A")], next: "c1" } });

    serve({}, 500);
    list.selectStatus("draft");
    await settle();

    expect(list.hasNext.value).toBe(false);
    expect(list.hasPrevious.value).toBe(false);
    expect(list.error.value).toContain("HTTP 500");
  });

  it("keeps the page that is shown and explains a failure, then retries", async () => {
    const list = await start({ "": { items: [item("A")], next: "c1" } });

    serve({}, 500);
    await list.goNext();
    expect(list.error.value).toContain("HTTP 500");
    expect(list.items.value.map((product) => product.name)).toEqual(["A"]);
    expect(list.pageNumber.value).toBe(1);

    serve({ c1: { items: [item("B")], next: null } });
    await list.retry();
    expect(list.error.value).toBeNull();
    expect(list.items.value.map((product) => product.name)).toEqual(["B"]);
  });

  it("tells that the chosen category no longer exists on a 404", async () => {
    const list = await start({ "": { items: [item("A")], next: null } });

    serve({}, 404);
    list.selectCategory("gone");
    await settle();

    expect(list.error.value).toContain("kategoria");
  });

  it("reports a network failure", async () => {
    const list = await start({ "": { items: [item("A")], next: null } });

    mocks.get.mockRejectedValue(new Error("offline"));
    list.selectCategory("c1");
    await settle();

    expect(list.error.value).toContain("połączenie");
  });

  it("ignores an older response that arrives after a newer request", async () => {
    let finishFirst!: (value: unknown) => void;
    mocks.get.mockImplementationOnce(() => new Promise((resolve) => (finishFirst = resolve)));
    mocks.get.mockImplementationOnce(async () => ({ data: { items: [item("Nowy")], next: null }, response: { status: 200 } }));
    const mounted = await mountList();
    wrapper = mounted.wrapper;

    mounted.list.selectCategory("c1");
    await settle();
    finishFirst({ data: { items: [item("Stary")], next: null }, response: { status: 200 } });
    await settle();

    expect(mounted.list.items.value.map((product) => product.name)).toEqual(["Nowy"]);
  });

  describe("the state of the list is in the address", () => {
    const one = { "": { items: [item("A")], next: null } };

    it("starts from the search, the filters and the page size of the address, with one request", async () => {
      const list = await start(one, undefined, "/products?q=buty&category=c-run&status=draft&perPage=10", [leaf("c-run")]);

      expect(queries()).toEqual([{ q: "buty", category: "c-run", status: "draft", perPage: 10 }]);
      expect(list.search.value).toBe("buty");
    });

    it("shows a search that is too short in the field, with the hint, and does not filter by it", async () => {
      const list = await start(one, undefined, "/products?q=a");

      expect(list.search.value).toBe("a");
      expect(list.searchTooShort.value).toBe(true);
      expect(list.searchHint.value).toBe("Wpisz co najmniej 2 znaki, aby wyszukać.");
      expect(queries()).toEqual([{}]);
    });

    it("shows a search that is too long in the field, with a hint, and does not filter by it", async () => {
      const long = "a".repeat(101);
      const list = await start(one, undefined, `/products?q=${long}`);

      expect(list.search.value).toBe(long);
      expect(list.searchHint.value).toBe("Wpisz najwyżej 100 znaków, aby wyszukać.");
      expect(queries()).toEqual([{}]);
    });

    it("skips a category that the tree does not have, before the first request", async () => {
      await start(one, undefined, "/products?category=gone&status=draft", [leaf("c-run")]);

      expect(queries()).toEqual([{ status: "draft" }]);
      expect(router!.currentRoute.value.query).toEqual({ status: "draft" });
    });

    it("waits for the tree before it asks, when the address has a category", async () => {
      serve(one);
      const mounted = await mountList("/products?category=c-run", null);
      wrapper = mounted.wrapper;
      await settle();
      expect(mocks.get).not.toHaveBeenCalled();

      mounted.nodes.value = [leaf("c-run")];
      mounted.loaded.value = true;
      await settle();
      expect(queries()).toEqual([{ category: "c-run" }]);
    });

    it("skips every category when the tree failed to load", async () => {
      serve(one);
      const mounted = await mountList("/products?category=c-run", null);
      wrapper = mounted.wrapper;

      mounted.loaded.value = true;
      await settle();

      expect(queries()).toEqual([{}]);
    });

    it("writes the applied search to the address, not what is typed, and the filters and the page size", async () => {
      const list = await start(one);

      list.search.value = "b";
      await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 100);
      await settle();
      expect(router!.currentRoute.value.query).toEqual({});

      list.search.value = "  bidon ";
      await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 100);
      await settle();
      expect(router!.currentRoute.value.query).toEqual({ q: "bidon" });

      list.selectStatus("published");
      list.selectCategory("c-run");
      list.setPerPage(10);
      await settle();
      expect(router!.currentRoute.value.query).toEqual({ q: "bidon", status: "published", category: "c-run", perPage: "10" });
    });

    it("sends the page size only when it is not the default of the API", async () => {
      const list = await start(one);

      list.setPerPage(50);
      await settle();
      expect(queries().at(-1)).toEqual({ perPage: 50 });

      list.setPerPage(25);
      await settle();
      expect(queries().at(-1)).toEqual({});
    });

    it("goes back to the first page when the page size changes", async () => {
      const list = await start({ "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: null } });
      await list.goNext();
      expect(list.hasPrevious.value).toBe(true);

      list.setPerPage(10);
      await settle();

      expect(queries().at(-1)).toEqual({ perPage: 10 });
      expect(list.hasPrevious.value).toBe(false);
    });

    it("clears the search, the filters and the page size, with one request for the whole list", async () => {
      const list = await start(one, undefined, "/products?q=buty&status=draft&perPage=10");
      mocks.get.mockClear();

      list.clearFilters();
      await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 100);
      await settle();

      expect(list.search.value).toBe("");
      expect(router!.currentRoute.value.query).toEqual({});
      expect(queries()).toEqual([{}]);
    });

    it("is filtered while a search or a filter is set, and the page size is not a filter", async () => {
      const list = await start(one, undefined, "/products?perPage=10");
      expect(list.filtered.value).toBe(false);

      list.selectStatus("draft");
      await settle();
      expect(list.filtered.value).toBe(true);

      list.selectStatus(null);
      list.search.value = "buty";
      await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 100);
      await settle();
      expect(list.filtered.value).toBe(true);
    });

    it("follows a change of the address from outside with one request from the first page, and shows its search", async () => {
      const list = await start({ "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: null } });
      await list.goNext();
      mocks.get.mockClear();

      await router!.push({ path: "/products", query: { q: "buty" } });
      await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 100);
      await settle();

      expect(queries()).toEqual([{ q: "buty" }]);
      expect(list.search.value).toBe("buty");
      expect(list.hasPrevious.value).toBe(false);
    });
  });

  describe("errors", () => {
    it("shows no rows and no pager after a failed change of the query, until a retry succeeds (#251 F10)", async () => {
      const list = await start({ "": { items: [item("A")], next: "c1" } });

      serve({}, 500);
      list.selectStatus("draft");
      await settle();

      expect(list.items.value).toEqual([]);
      expect(list.loaded.value).toBe(false);
      expect(list.hasNext.value).toBe(false);
      expect(list.error.value).toContain("HTTP 500");

      serve({ "": { items: [item("D")], next: null } });
      await list.retry();
      expect(list.error.value).toBeNull();
      expect(list.items.value.map((product) => product.name)).toEqual(["D"]);
      expect(queries().at(-1)).toEqual({ status: "draft" });
    });

    it("reports a 403 as forbidden, without a retry to offer", async () => {
      const list = await start({}, 403);

      expect(list.forbidden.value).toBe(true);
      expect(list.error.value).toBe("Nie masz dostępu do produktów.");
    });
  });

  it("numbers the rows of the page for „Pokazano X–Y”", async () => {
    const page = (from: number, count: number, next: string | null) => ({
      items: Array.from({ length: count }, (_, index) => item(`P${from + index}`)),
      next,
    });
    const list = await start({ "": page(1, 25, "c1"), c1: page(26, 25, null) });
    expect(list.range.value).toEqual({ from: 1, to: 25 });

    await list.goNext();
    expect(list.range.value).toEqual({ from: 26, to: 50 });
  });
});
