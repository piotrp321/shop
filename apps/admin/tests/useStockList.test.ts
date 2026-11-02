import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent } from "vue";
import type { components } from "@/api/schema";
import { SEARCH_DEBOUNCE_MS, useStockList } from "@/inventory/useStockList";

type StockList = components["schemas"]["StockListResponse"];
type StockListItem = components["schemas"]["StockListItemResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

const STOCK = "/api/v1/admin/stock";

function item(name: string, overrides: Partial<StockListItem> = {}): StockListItem {
  return {
    productId: `id-${name}`,
    name,
    stock: { physical: 10, reserved: 4, available: 6, level: "in_stock", version: 3, updatedAt: "2026-11-02T12:30:00+00:00" },
    ...overrides,
  };
}

/** Answers the stock list by the `after` cursor of the request ("" for the first page). */
function serve(pages: Record<string, StockList>, status?: number) {
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

function mountList() {
  let list!: ReturnType<typeof useStockList>;
  const wrapper = mount(
    defineComponent({
      setup() {
        list = useStockList();
        return () => null;
      },
    }),
  );
  return { wrapper, list };
}

describe("useStockList", () => {
  let wrapper: ReturnType<typeof mountList>["wrapper"] | undefined;

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    mocks.get.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    vi.useRealTimers();
  });

  async function start(pages: Record<string, StockList>, status?: number) {
    serve(pages, status);
    const mounted = mountList();
    wrapper = mounted.wrapper;
    await settle();
    return mounted.list;
  }

  it("loads the first page of the stock list on mount", async () => {
    const list = await start({ "": { items: [item("Bidon")], next: null } });

    expect(mocks.get).toHaveBeenCalledWith(STOCK, { params: { query: {} } });
    expect(list.items.value.map((row) => row.name)).toEqual(["Bidon"]);
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
    expect(list.items.value.map((row) => row.name)).toEqual(["C"]);
    expect(list.pageNumber.value).toBe(3);
    expect(list.hasNext.value).toBe(false);

    await list.goPrevious();
    expect(list.items.value.map((row) => row.name)).toEqual(["B"]);
    await list.goPrevious();
    expect(list.items.value.map((row) => row.name)).toEqual(["A"]);
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

  it("starts from the first page when the search or the category changes", async () => {
    const list = await start({
      "": { items: [item("A")], next: "c1" },
      c1: { items: [item("B")], next: null },
    });
    await list.goNext();

    list.selectCategory("c-run");
    await settle();
    expect(queries().at(-1)).toEqual({ category: "c-run" });
    expect(list.hasPrevious.value).toBe(false);

    list.search.value = "baton";
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 100);
    await settle();
    expect(queries().at(-1)).toEqual({ q: "baton", category: "c-run" });

    list.selectCategory(null);
    await settle();
    expect(queries().at(-1)).toEqual({ q: "baton" });
  });

  it("keeps the page that is shown and explains a failure, then retries", async () => {
    const list = await start({ "": { items: [item("A")], next: "c1" } });

    serve({}, 500);
    await list.goNext();
    expect(list.error.value).toContain("HTTP 500");
    expect(list.items.value.map((row) => row.name)).toEqual(["A"]);
    expect(list.pageNumber.value).toBe(1);

    serve({ c1: { items: [item("B")], next: null } });
    await list.retry();
    expect(list.error.value).toBeNull();
    expect(list.items.value.map((row) => row.name)).toEqual(["B"]);
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
    await list.goNext().catch(() => undefined);
    list.selectCategory("c1");
    await settle();

    expect(list.error.value).toContain("połączenie");
  });

  it("ignores an older response that arrives after a newer request", async () => {
    let finishFirst!: (value: unknown) => void;
    mocks.get.mockImplementationOnce(() => new Promise((resolve) => (finishFirst = resolve)));
    mocks.get.mockImplementationOnce(async () => ({ data: { items: [item("Nowy")], next: null }, response: { status: 200 } }));
    const mounted = mountList();
    wrapper = mounted.wrapper;

    mounted.list.selectCategory("c1");
    await settle();
    finishFirst({ data: { items: [item("Stary")], next: null }, response: { status: 200 } });
    await settle();

    expect(mounted.list.items.value.map((row) => row.name)).toEqual(["Nowy"]);
  });
});
