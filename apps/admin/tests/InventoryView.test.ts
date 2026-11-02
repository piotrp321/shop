import { flushPromises, mount } from "@vue/test-utils";
import PrimeVue from "primevue/config";
import TreeSelect from "primevue/treeselect";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { components } from "@/api/schema";
import { primeVueOptions } from "@/primevue";
import InventoryView from "@/views/InventoryView.vue";

type StockList = components["schemas"]["StockListResponse"];
type StockListItem = components["schemas"]["StockListItemResponse"];
type ListedStock = components["schemas"]["ListedStockResponse"];
type CategoryNode = components["schemas"]["CategoryNode"];

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

const STOCK = "/api/v1/admin/stock";
const CATEGORIES = "/api/v1/admin/categories";

function stock(overrides: Partial<ListedStock> = {}): ListedStock {
  return { physical: 10, reserved: 4, available: 6, level: "in_stock", version: 3, updatedAt: "2026-11-02T12:30:00+00:00", ...overrides };
}

function item(name: string, overrides: Partial<StockListItem> = {}): StockListItem {
  return { productId: `id-${name}`, name, stock: stock(), ...overrides };
}

const tree: CategoryNode[] = [{ id: "c-sport", name: "Sport", children: [{ id: "c-run", name: "Bieganie", children: [] }] }];

function serve(pages: Record<string, StockList>, status?: number) {
  mocks.get.mockImplementation(async (path: string, options?: { params?: { query?: { after?: string } } }) => {
    if (path === CATEGORIES) {
      return { data: tree, response: { status: 200 } };
    }
    return status ? { data: undefined, response: { status } } : { data: pages[options?.params?.query?.after ?? ""], response: { status: 200 } };
  });
}

function stockQueries(): Record<string, unknown>[] {
  return mocks.get.mock.calls
    .filter(([path]) => path === STOCK)
    .map(([, options]) => (options as { params: { query: Record<string, unknown> } }).params.query);
}

async function settle() {
  await vi.advanceTimersByTimeAsync(0);
  await flushPromises();
}

async function mountView() {
  const wrapper = mount(InventoryView, { global: { plugins: [[PrimeVue, primeVueOptions]] as never[] } });
  await settle();
  return wrapper;
}

describe("InventoryView", () => {
  let wrapper: Awaited<ReturnType<typeof mountView>> | undefined;

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    mocks.get.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    vi.useRealTimers();
  });

  it("shows the name, physical, reserved, available and the level the API gave", async () => {
    serve({ "": { items: [item("Bidon"), item("Żel", { stock: stock({ physical: 3, reserved: 3, available: 0, level: "out_of_stock" }) }), item("Baton", { stock: stock({ physical: 9, reserved: 4, available: 5, level: "low_stock" }) })], next: null } });
    wrapper = await mountView();

    const rows = wrapper.findAll("[data-testid=stock-row]");
    const cells = (row: (typeof rows)[number]) =>
      ["name", "physical", "reserved", "available", "level"].map((id) => row.get(`[data-testid=${id}]`).text());
    expect(cells(rows[0]!)).toEqual(["Bidon", "10", "4", "6", "Dostępny"]);
    expect(cells(rows[1]!)).toEqual(["Żel", "3", "3", "0", "Brak"]);
    expect(cells(rows[2]!)).toEqual(["Baton", "9", "4", "5", "Mało"]);
  });

  it("shows zeros for a product without a stock record", async () => {
    serve({ "": { items: [item("Nowy", { stock: null })], next: null } });
    wrapper = await mountView();

    const row = wrapper.get("[data-testid=stock-row]");
    expect(["physical", "reserved", "available", "level"].map((id) => row.get(`[data-testid=${id}]`).text())).toEqual(["0", "0", "0", "Brak"]);
  });

  it("shows a message and no rows when nothing matches", async () => {
    serve({ "": { items: [], next: null } });
    wrapper = await mountView();

    expect(wrapper.findAll("[data-testid=stock-row]")).toHaveLength(0);
    expect(wrapper.get("[data-testid=empty]").text()).toContain("Brak produktów");
  });

  it("pages with the Next and Previous buttons", async () => {
    serve({ "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: null } });
    wrapper = await mountView();
    const names = () => wrapper!.findAll("[data-testid=name]").map((cell) => cell.text());

    expect(wrapper.get("[data-testid=previous-page]").attributes("disabled")).toBeDefined();
    await wrapper.get("[data-testid=next-page]").trigger("click");
    await settle();
    expect(names()).toEqual(["B"]);
    expect(stockQueries().at(-1)).toEqual({ after: "c1" });
    expect(wrapper.get("[data-testid=next-page]").attributes("disabled")).toBeDefined();

    await wrapper.get("[data-testid=previous-page]").trigger("click");
    await settle();
    expect(names()).toEqual(["A"]);
    expect(wrapper.get("[data-testid=page-number]").text()).toBe("Strona 1");
  });

  it("searches from two characters after a pause and hints for a shorter term", async () => {
    serve({ "": { items: [item("A")], next: null } });
    wrapper = await mountView();
    const search = wrapper.get("[data-testid=search]");

    await search.setValue("b");
    await vi.advanceTimersByTimeAsync(1000);
    expect(stockQueries()).toHaveLength(1);
    expect(wrapper.get("[data-testid=search-hint]").text()).toContain("2 znaki");

    await search.setValue("bidon");
    await vi.advanceTimersByTimeAsync(400);
    await settle();
    expect(stockQueries().at(-1)).toEqual({ q: "bidon" });
    expect(wrapper.find("[data-testid=search-hint]").exists()).toBe(false);
  });

  it("filters by the category chosen from the tree", async () => {
    serve({ "": { items: [item("A")], next: null } });
    wrapper = await mountView();

    const filter = wrapper.getComponent(TreeSelect);
    expect(filter.props("options")).toEqual([{ key: "c-sport", label: "Sport", children: [{ key: "c-run", label: "Bieganie", children: [] }] }]);
    filter.vm.$emit("update:modelValue", { "c-run": true });
    await settle();
    expect(stockQueries().at(-1)).toEqual({ category: "c-run" });

    filter.vm.$emit("update:modelValue", null);
    await settle();
    expect(stockQueries().at(-1)).toEqual({});
  });

  it("shows the error and retries", async () => {
    serve({}, 500);
    wrapper = await mountView();
    expect(wrapper.get("[data-testid=stock-error]").text()).toContain("HTTP 500");

    serve({ "": { items: [item("A")], next: null } });
    await wrapper.get("[data-testid=retry]").trigger("click");
    await settle();

    expect(wrapper.find("[data-testid=stock-error]").exists()).toBe(false);
    expect(wrapper.findAll("[data-testid=stock-row]")).toHaveLength(1);
  });
});
