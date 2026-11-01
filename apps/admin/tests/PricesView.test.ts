import { flushPromises, mount } from "@vue/test-utils";
import PrimeVue from "primevue/config";
import TreeSelect from "primevue/treeselect";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { components } from "@/api/schema";
import { primeVueOptions } from "@/primevue";
import PricesView from "@/views/PricesView.vue";

type PriceList = components["schemas"]["PriceListResponse"];
type PriceListItem = components["schemas"]["PriceListItemResponse"];
type ListedPrice = components["schemas"]["ListedPriceResponse"];
type CategoryNode = components["schemas"]["CategoryNode"];

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

const PRICES = "/api/v1/admin/prices";
const CATEGORIES = "/api/v1/admin/categories";

function price(overrides: Partial<ListedPrice> = {}): ListedPrice {
  return {
    gross: 5490,
    net: 5229,
    vat: 261,
    vatRate: 500,
    currency: "PLN",
    version: 1,
    updatedAt: "2026-11-01T12:30:00+00:00",
    vatRateOutdated: false,
    ...overrides,
  };
}

function item(name: string, overrides: Partial<PriceListItem> = {}): PriceListItem {
  return {
    productId: `id-${name}`,
    name,
    taxClass: "printed_book",
    applicableVatRate: 500,
    price: price(),
    ...overrides,
  };
}

const tree: CategoryNode[] = [
  { id: "c-sport", name: "Sport", children: [{ id: "c-run", name: "Bieganie", children: [] }] },
  { id: "c-food", name: "Jedzenie", children: [] },
];

interface Backend {
  pages: Record<string, PriceList>;
  status?: number;
  categories?: CategoryNode[] | "error";
}

/** Answers the price list by the `after` cursor of the request ("" for the first page). */
function serve(backend: Backend) {
  mocks.get.mockImplementation(async (path: string, options?: { params?: { query?: { after?: string } } }) => {
    if (path === CATEGORIES) {
      return backend.categories === "error"
        ? { data: undefined, response: { status: 500 } }
        : { data: backend.categories ?? tree, response: { status: 200 } };
    }
    if (backend.status) {
      return { data: undefined, response: { status: backend.status } };
    }
    return { data: backend.pages[options?.params?.query?.after ?? ""], response: { status: 200 } };
  });
}

function priceCalls(): Record<string, unknown>[] {
  return mocks.get.mock.calls
    .filter(([path]) => path === PRICES)
    .map(([, options]) => (options as { params: { query: Record<string, unknown> } }).params.query);
}

async function settle() {
  await vi.advanceTimersByTimeAsync(0);
  await flushPromises();
}

async function mountView() {
  const wrapper = mount(PricesView, { global: { plugins: [[PrimeVue, primeVueOptions]] } });
  await settle();
  return wrapper;
}

describe("PricesView", () => {
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

  it("loads the first page and shows a row with the price formatted from grosze by the API's numbers", async () => {
    serve({ pages: { "": { items: [item("Przewodnik Tatry")], next: null } } });
    wrapper = await mountView();

    expect(priceCalls()).toEqual([{}]);
    const row = wrapper.get("[data-testid=price-row]");
    expect(row.get("[data-testid=name]").text()).toBe("Przewodnik Tatry");
    expect(row.get("[data-testid=tax-class]").text()).toBe("printed_book");
    expect(row.get("[data-testid=gross]").text()).toMatch(/^54,90\s*zł$/);
    expect(row.get("[data-testid=net]").text()).toMatch(/^52,29\s*zł$/);
    expect(row.get("[data-testid=vat]").text()).toMatch(/^2,61\s*zł$/);
    expect(row.get("[data-testid=vat-rate]").text()).toBe("5%");
    expect(row.find("[data-testid=outdated]").exists()).toBe(false);
  });

  it("shows a product without a price as 'Brak ceny' with no amounts", async () => {
    serve({ pages: { "": { items: [item("Nowy żel", { price: null, taxClass: "energy_gel", applicableVatRate: 800 })], next: null } } });
    wrapper = await mountView();

    const row = wrapper.get("[data-testid=price-row]");
    expect(row.get("[data-testid=gross]").text()).toBe("Brak ceny");
    expect(row.get("[data-testid=net]").text()).toBe("—");
    expect(row.get("[data-testid=vat]").text()).toBe("—");
    expect(row.get("[data-testid=vat-rate]").text()).toBe("—");
  });

  it("marks a price at an outdated rate and names the rate that applies now", async () => {
    serve({
      pages: {
        "": { items: [item("Plecak", { taxClass: "standard", applicableVatRate: 2300, price: price({ vatRate: 800, vatRateOutdated: true }) })], next: null },
      },
    });
    wrapper = await mountView();

    const row = wrapper.get("[data-testid=price-row]");
    expect(row.get("[data-testid=vat-rate]").text()).toContain("8%");
    expect(row.get("[data-testid=outdated]").text()).toContain("Nieaktualna");
    expect(row.get("[data-testid=outdated]").text()).toContain("23%");
  });

  it("shows a message and no rows when nothing matches", async () => {
    serve({ pages: { "": { items: [], next: null } } });
    wrapper = await mountView();

    expect(wrapper.findAll("[data-testid=price-row]")).toHaveLength(0);
    expect(wrapper.get("[data-testid=empty]").text()).toContain("Brak produktów");
  });

  it("pages forward and back with the cursors of the API, keeping the cursor stack in the panel", async () => {
    serve({
      pages: {
        "": { items: [item("A")], next: "c1" },
        c1: { items: [item("B")], next: "c2" },
        c2: { items: [item("C")], next: null },
      },
    });
    wrapper = await mountView();
    const names = () => wrapper!.findAll("[data-testid=name]").map((cell) => cell.text());
    const previous = () => wrapper!.get("[data-testid=previous-page]");
    const next = () => wrapper!.get("[data-testid=next-page]");

    expect(names()).toEqual(["A"]);
    expect(previous().attributes("disabled")).toBeDefined();
    expect(next().attributes("disabled")).toBeUndefined();

    await next().trigger("click");
    await settle();
    expect(names()).toEqual(["B"]);
    expect(priceCalls().at(-1)).toEqual({ after: "c1" });

    await next().trigger("click");
    await settle();
    expect(names()).toEqual(["C"]);
    expect(priceCalls().at(-1)).toEqual({ after: "c2" });
    expect(next().attributes("disabled")).toBeDefined();

    await previous().trigger("click");
    await settle();
    expect(names()).toEqual(["B"]);
    expect(priceCalls().at(-1)).toEqual({ after: "c1" });

    await previous().trigger("click");
    await settle();
    expect(names()).toEqual(["A"]);
    expect(priceCalls().at(-1)).toEqual({});
    expect(previous().attributes("disabled")).toBeDefined();
  });

  it("does not send a search shorter than two characters", async () => {
    serve({ pages: { "": { items: [item("A")], next: null } } });
    wrapper = await mountView();
    const calls = priceCalls().length;

    await wrapper.get("[data-testid=search]").setValue("a");
    await vi.advanceTimersByTimeAsync(1000);
    await settle();

    expect(priceCalls()).toHaveLength(calls);
    expect(wrapper.get("[data-testid=search-hint]").text()).toContain("2 znaki");
  });

  it("counts characters, not UTF-16 units: a single emoji is too short to search", async () => {
    serve({ pages: { "": { items: [item("A")], next: null } } });
    wrapper = await mountView();
    const calls = priceCalls().length;

    await wrapper.get("[data-testid=search]").setValue("😀");
    await vi.advanceTimersByTimeAsync(1000);
    await settle();

    expect(priceCalls()).toHaveLength(calls);
    expect(wrapper.get("[data-testid=search-hint]").text()).toContain("2 znaki");
  });

  it("searches for two emoji, which are two characters", async () => {
    serve({ pages: { "": { items: [item("A")], next: null } } });
    wrapper = await mountView();

    await wrapper.get("[data-testid=search]").setValue("😀😀");
    await vi.advanceTimersByTimeAsync(400);
    await settle();

    expect(priceCalls().at(-1)).toEqual({ q: "😀😀" });
  });

  it("limits the search field to the 100 characters the API accepts", async () => {
    serve({ pages: { "": { items: [item("A")], next: null } } });
    wrapper = await mountView();

    expect(wrapper.get("[data-testid=search]").attributes("maxlength")).toBe("100");
  });

  it("searches after the typing pauses, once, with the trimmed term", async () => {
    serve({ pages: { "": { items: [item("A")], next: null } } });
    wrapper = await mountView();
    const search = wrapper.get("[data-testid=search]");

    await search.setValue("bi");
    await vi.advanceTimersByTimeAsync(100);
    await search.setValue("bid");
    await vi.advanceTimersByTimeAsync(100);
    await search.setValue("  bidon ");
    expect(priceCalls()).toHaveLength(1);
    await vi.advanceTimersByTimeAsync(400);
    await settle();

    expect(priceCalls()).toEqual([{}, { q: "bidon" }]);
    expect(wrapper.find("[data-testid=search-hint]").exists()).toBe(false);
  });

  it("starts again from the first page when the search changes, and goes back to the full list when it is cleared", async () => {
    serve({
      pages: {
        "": { items: [item("A")], next: "c1" },
        c1: { items: [item("B")], next: null },
      },
    });
    wrapper = await mountView();
    await wrapper.get("[data-testid=next-page]").trigger("click");
    await settle();
    expect(priceCalls().at(-1)).toEqual({ after: "c1" });

    await wrapper.get("[data-testid=search]").setValue("zz");
    await vi.advanceTimersByTimeAsync(400);
    await settle();
    expect(priceCalls().at(-1)).toEqual({ q: "zz" });
    expect(wrapper.get("[data-testid=previous-page]").attributes("disabled")).toBeDefined();

    await wrapper.get("[data-testid=search]").setValue("");
    await vi.advanceTimersByTimeAsync(400);
    await settle();
    expect(priceCalls().at(-1)).toEqual({});
  });

  it("filters by the chosen category and starts from the first page", async () => {
    serve({
      pages: {
        "": { items: [item("A")], next: "c1" },
        c1: { items: [item("B")], next: null },
      },
    });
    wrapper = await mountView();
    await wrapper.get("[data-testid=next-page]").trigger("click");
    await settle();

    const filter = wrapper.getComponent(TreeSelect);
    expect(filter.props("options")).toEqual([
      { key: "c-sport", label: "Sport", children: [{ key: "c-run", label: "Bieganie", children: [] }] },
      { key: "c-food", label: "Jedzenie", children: [] },
    ]);
    filter.vm.$emit("update:modelValue", { "c-run": true });
    await settle();

    expect(priceCalls().at(-1)).toEqual({ category: "c-run" });
    expect(wrapper.get("[data-testid=previous-page]").attributes("disabled")).toBeDefined();

    filter.vm.$emit("update:modelValue", null);
    await settle();
    expect(priceCalls().at(-1)).toEqual({});
  });

  it("combines the search and the category", async () => {
    serve({ pages: { "": { items: [item("A")], next: null } } });
    wrapper = await mountView();

    wrapper.getComponent(TreeSelect).vm.$emit("update:modelValue", { "c-food": true });
    await settle();
    await wrapper.get("[data-testid=search]").setValue("baton");
    await vi.advanceTimersByTimeAsync(400);
    await settle();

    expect(priceCalls().at(-1)).toEqual({ q: "baton", category: "c-food" });
  });

  it("shows the error, keeps the page it was on, and retries", async () => {
    serve({ pages: { "": { items: [item("A")], next: "c1" } } });
    wrapper = await mountView();

    serve({ pages: {}, status: 500 });
    await wrapper.get("[data-testid=next-page]").trigger("click");
    await settle();

    expect(wrapper.get("[data-testid=prices-error]").text()).toContain("HTTP 500");
    expect(wrapper.findAll("[data-testid=name]").map((cell) => cell.text())).toEqual(["A"]);

    serve({ pages: { c1: { items: [item("B")], next: null } } });
    await wrapper.get("[data-testid=retry]").trigger("click");
    await settle();

    expect(wrapper.find("[data-testid=prices-error]").exists()).toBe(false);
    expect(wrapper.findAll("[data-testid=name]").map((cell) => cell.text())).toEqual(["B"]);
  });

  it("never pairs a new category with a cursor of the old list after a failed filter change", async () => {
    serve({
      pages: {
        "": { items: [item("A")], next: "c1" },
        c1: { items: [item("B")], next: "c2" },
      },
    });
    wrapper = await mountView();
    await wrapper.get("[data-testid=next-page]").trigger("click");
    await settle();
    expect(priceCalls().at(-1)).toEqual({ after: "c1" });

    serve({ pages: {}, status: 500 });
    wrapper.getComponent(TreeSelect).vm.$emit("update:modelValue", { "c-food": true });
    await settle();

    expect(wrapper.get("[data-testid=prices-error]").exists()).toBe(true);
    expect(wrapper.get("[data-testid=next-page]").attributes("disabled")).toBeDefined();
    expect(wrapper.get("[data-testid=previous-page]").attributes("disabled")).toBeDefined();
    await wrapper.get("[data-testid=next-page]").trigger("click");
    await wrapper.get("[data-testid=previous-page]").trigger("click");
    await settle();
    expect(priceCalls().filter((query) => query.category !== undefined && query.after !== undefined)).toEqual([]);

    serve({ pages: { "": { items: [item("F")], next: null } } });
    await wrapper.get("[data-testid=retry]").trigger("click");
    await settle();
    expect(priceCalls().at(-1)).toEqual({ category: "c-food" });
    expect(wrapper.findAll("[data-testid=name]").map((cell) => cell.text())).toEqual(["F"]);
  });

  it("never pairs a new search with a cursor of the old list after a failed search", async () => {
    serve({ pages: { "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: null } } });
    wrapper = await mountView();
    await wrapper.get("[data-testid=next-page]").trigger("click");
    await settle();

    serve({ pages: {}, status: 500 });
    await wrapper.get("[data-testid=search]").setValue("zz");
    await vi.advanceTimersByTimeAsync(400);
    await settle();

    expect(wrapper.get("[data-testid=next-page]").attributes("disabled")).toBeDefined();
    expect(wrapper.get("[data-testid=previous-page]").attributes("disabled")).toBeDefined();
    expect(priceCalls().at(-1)).toEqual({ q: "zz" });
  });

  it("shows an error when the request itself fails", async () => {
    mocks.get.mockImplementation(async (path: string) => {
      if (path === CATEGORIES) {
        return { data: tree, response: { status: 200 } };
      }
      throw new TypeError("network down");
    });
    wrapper = await mountView();

    expect(wrapper.get("[data-testid=prices-error]").text()).toContain("połączenie");
  });

  it("says so when the chosen category no longer exists", async () => {
    serve({ pages: { "": { items: [item("A")], next: null } } });
    wrapper = await mountView();

    serve({ pages: {}, status: 404 });
    wrapper.getComponent(TreeSelect).vm.$emit("update:modelValue", { "c-food": true });
    await settle();

    expect(wrapper.get("[data-testid=prices-error]").text()).toContain("kategoria");
  });

  it("still lists prices when the category tree cannot be loaded, and says the filter is unavailable", async () => {
    serve({ pages: { "": { items: [item("A")], next: null } }, categories: "error" });
    wrapper = await mountView();

    expect(wrapper.findAll("[data-testid=price-row]")).toHaveLength(1);
    expect(wrapper.get("[data-testid=categories-error]").text()).toContain("kategorii");
  });

  it("ignores an answer that arrives after a newer request", async () => {
    let releaseFirst: (value: unknown) => void = () => {};
    const first = new Promise((resolve) => {
      releaseFirst = resolve;
    });
    mocks.get.mockImplementation(async (path: string, options?: { params?: { query?: { q?: string } } }) => {
      if (path === CATEGORIES) {
        return { data: tree, response: { status: 200 } };
      }
      if (options?.params?.query?.q === "ab") {
        return { data: { items: [item("Z ab")], next: null }, response: { status: 200 } };
      }
      await first;
      return { data: { items: [item("Z full list")], next: null }, response: { status: 200 } };
    });
    wrapper = mount(PricesView, { global: { plugins: [[PrimeVue, primeVueOptions]] } });
    await settle();

    await wrapper.get("[data-testid=search]").setValue("ab");
    await vi.advanceTimersByTimeAsync(400);
    await settle();
    releaseFirst(undefined);
    await settle();

    expect(wrapper.findAll("[data-testid=name]").map((cell) => cell.text())).toEqual(["Z ab"]);
  });
});
