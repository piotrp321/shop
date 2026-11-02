import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import PrimeVue from "primevue/config";
import TreeSelect from "primevue/treeselect";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { components } from "@/api/schema";
import { primeVueOptions } from "@/primevue";
import { useAuthStore, type StaffMe } from "@/stores/auth";
import { SEARCH_DEBOUNCE_MS } from "@/prices/usePriceList";
import PricesView from "@/views/PricesView.vue";

type PriceList = components["schemas"]["PriceListResponse"];
type PriceListItem = components["schemas"]["PriceListItemResponse"];
type ListedPrice = components["schemas"]["ListedPriceResponse"];
type CategoryNode = components["schemas"]["CategoryNode"];

const mocks = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get, PUT: mocks.put }) }));

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

function mountOptions(roles: StaffMe["roles"]) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().staff = { id: "u1", username: "anna", roles };
  // The dialog is teleported to <body>; rendered in place it can be found in the wrapper.
  return { global: { plugins: [pinia, [PrimeVue, primeVueOptions]] as never[], stubs: { teleport: true } } };
}

async function mountView(roles: StaffMe["roles"] = ["admin"]) {
  const wrapper = mount(PricesView, mountOptions(roles));
  await settle();
  return wrapper;
}

describe("PricesView", () => {
  let wrapper: Awaited<ReturnType<typeof mountView>> | undefined;

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    mocks.get.mockReset();
    mocks.put.mockReset();
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

    expect(wrapper.find("[data-testid=prices-error]").exists()).toBe(true);
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
    wrapper = mount(PricesView, mountOptions(["admin"]));
    await settle();

    await wrapper.get("[data-testid=search]").setValue("ab");
    await vi.advanceTimersByTimeAsync(400);
    await settle();
    releaseFirst(undefined);
    await settle();

    expect(wrapper.findAll("[data-testid=name]").map((cell) => cell.text())).toEqual(["Z ab"]);
  });
});

describe("PricesView: changing a price", () => {
  let wrapper: Awaited<ReturnType<typeof mountView>> | undefined;
  const PRICE = "/api/v1/admin/products/{productId}/price";

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    mocks.get.mockReset();
    mocks.put.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    vi.useRealTimers();
  });

  async function openDialog(row = 0) {
    await wrapper!.findAll("[data-testid=change-price]")[row]!.trigger("click");
    await settle();
  }

  async function submit(text: string) {
    await wrapper!.get("[data-testid=gross-input]").setValue(text);
    await wrapper!.get("[data-testid=save-price]").trigger("click");
    await settle();
  }

  function answer(status: number, data?: ListedPrice) {
    mocks.put.mockResolvedValue(
      data ? { data, error: undefined, response: { status } } : { data: undefined, error: { status }, response: { status } },
    );
  }

  it.each([[["marketing"]], [["support"]], [["warehouse"]], [["support", "warehouse", "marketing"]], [[]]] as StaffMe["roles"][][])(
    "offers no price change to a staff member with the roles %j",
    async (roles) => {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView(roles);

      expect(wrapper.findAll("[data-testid=price-row]")).toHaveLength(1);
      expect(wrapper.find("[data-testid=change-price]").exists()).toBe(false);
    },
  );

  it("offers an administrator the change on every row, with the rate that applies from the API", async () => {
    serve({ pages: { "": { items: [item("A"), item("B", { price: null, applicableVatRate: 2300 })], next: null } } });
    wrapper = await mountView(["admin"]);

    expect(wrapper.findAll("[data-testid=change-price]")).toHaveLength(2);
    await openDialog(1);

    const dialog = wrapper.get("[data-testid=change-dialog]");
    expect(dialog.text()).toContain("B");
    expect(dialog.get("[data-testid=rate-preview]").text()).toContain("23%");
    expect(dialog.get("[data-testid=current-gross]").text()).toContain("Brak ceny");
    // Only the rate from the API: the panel computes no net or VAT amount for the new gross.
    expect(dialog.text()).not.toMatch(/\d,\d{2}\s*zł/);
  });

  it("sends the gross in grosze with the version of the row and shows the saved price", async () => {
    serve({ pages: { "": { items: [item("A", { productId: "p-1", price: price({ version: 3 }) })], next: null } } });
    wrapper = await mountView();
    answer(200, price({ gross: 5990, net: 5705, vat: 285, version: 4 }));

    await openDialog();
    expect(wrapper.get("[data-testid=current-gross]").text()).toMatch(/54,90\s*zł/);
    await submit("59,90");

    expect(mocks.put).toHaveBeenCalledTimes(1);
    expect(mocks.put).toHaveBeenCalledWith(PRICE, { params: { path: { productId: "p-1" } }, body: { gross: 5990, version: 3 } });
    expect(wrapper.find("[data-testid=change-dialog]").exists()).toBe(false);
    const row = wrapper.get("[data-testid=price-row]");
    expect(row.get("[data-testid=gross]").text()).toMatch(/^59,90\s*zł$/);
    expect(row.get("[data-testid=net]").text()).toMatch(/^57,05\s*zł$/);
    expect(row.get("[data-testid=vat]").text()).toMatch(/^2,85\s*zł$/);
  });

  it("keeps the saved price when a list request that started before the save answers with the old one", async () => {
    serve({ pages: { "": { items: [item("A")], next: null } } });
    wrapper = await mountView();
    await openDialog();
    // A search starts a list request that is still open while the change is saved.
    let respondList: (page: PriceList) => void = () => undefined;
    mocks.get.mockImplementation(
      (path: string) =>
        new Promise((resolve) => {
          respondList = (page) => resolve({ data: path === PRICES ? page : tree, response: { status: 200 } });
        }),
    );
    await wrapper.get("[data-testid=search]").setValue("Ab");
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS);
    answer(200, price({ gross: 5990, net: 5705, vat: 285, version: 2 }));

    await submit("59,90");
    respondList({ items: [item("A"), item("B")], next: null });
    await settle();

    const rows = wrapper.findAll("[data-testid=price-row]");
    expect(rows).toHaveLength(2);
    expect(rows[0]!.get("[data-testid=gross]").text()).toMatch(/^59,90\s*zł$/);
    expect(rows[1]!.get("[data-testid=gross]").text()).toMatch(/^54,90\s*zł$/);
    answer(200, price({ gross: 6100, version: 3 }));
    await openDialog();
    await submit("61");
    expect(mocks.put).toHaveBeenLastCalledWith(PRICE, { params: { path: { productId: "id-A" } }, body: { gross: 6100, version: 2 } });
  });

  it("sends no version for a product without a price", async () => {
    serve({ pages: { "": { items: [item("A", { productId: "p-2", price: null })], next: null } } });
    wrapper = await mountView();
    answer(200, price({ gross: 1200 }));

    await openDialog();
    await submit("12");

    expect(mocks.put).toHaveBeenCalledWith(PRICE, { params: { path: { productId: "p-2" } }, body: { gross: 1200, version: null } });
  });

  it("does not send an amount it cannot read and says why at the field", async () => {
    serve({ pages: { "": { items: [item("A")], next: null } } });
    wrapper = await mountView();

    await openDialog();
    await submit("12,345");

    expect(mocks.put).not.toHaveBeenCalled();
    expect(wrapper.get("[data-testid=gross-error]").text()).toContain("2 miejsca po przecinku");
    expect(wrapper.get("[data-testid=gross-input]").attributes("aria-invalid")).toBe("true");
  });

  it("shows a 422 of the API at the field and changes nothing", async () => {
    serve({ pages: { "": { items: [item("A")], next: null } } });
    wrapper = await mountView();
    answer(422);

    await openDialog();
    await submit("0");

    expect(mocks.put).toHaveBeenCalledWith(PRICE, { params: { path: { productId: "id-A" } }, body: { gross: 0, version: 1 } });
    expect(wrapper.get("[data-testid=gross-error]").text()).toContain("od 0,01 zł do 999 999,99 zł");
    expect(wrapper.find("[data-testid=change-dialog]").exists()).toBe(true);
    expect(wrapper.get("[data-testid=price-row]").get("[data-testid=gross]").text()).toMatch(/^54,90\s*zł$/);
  });

  it("on a 409 says the price was changed meanwhile and refreshes the row", async () => {
    serve({ pages: { "": { items: [item("A")], next: "c1" }, c1: { items: [item("B", { productId: "p-b" })], next: null } } });
    wrapper = await mountView();
    await wrapper.get("[data-testid=next-page]").trigger("click");
    await settle();
    answer(409);
    const callsBefore = priceCalls().length;

    await openDialog();
    serve({ pages: { c1: { items: [item("B", { productId: "p-b", price: price({ gross: 7000, version: 2 }) })], next: null } } });
    await submit("59,90");

    expect(wrapper.get("[data-testid=change-conflict]").text()).toBe("Cena została w międzyczasie zmieniona – odśwież");
    expect(priceCalls()).toHaveLength(callsBefore + 1);
    expect(priceCalls().at(-1)).toEqual({ after: "c1" });
    expect(wrapper.get("[data-testid=price-row]").get("[data-testid=gross]").text()).toMatch(/^70,00\s*zł$/);
    expect(wrapper.get("[data-testid=current-gross]").text()).toMatch(/70,00\s*zł/);

    answer(200, price({ gross: 5990, version: 3 }));
    await submit("59,90");
    expect(mocks.put).toHaveBeenLastCalledWith(PRICE, { params: { path: { productId: "p-b" } }, body: { gross: 5990, version: 2 } });
  });

  it("on a 403 says the staff member may not change prices", async () => {
    serve({ pages: { "": { items: [item("A")], next: null } } });
    wrapper = await mountView();
    answer(403);

    await openDialog();
    await submit("59,90");

    expect(wrapper.get("[data-testid=change-error]").text()).toContain("Nie masz uprawnień");
    expect(wrapper.get("[data-testid=price-row]").get("[data-testid=gross]").text()).toMatch(/^54,90\s*zł$/);
  });

  it("says so when the request itself fails", async () => {
    serve({ pages: { "": { items: [item("A")], next: null } } });
    wrapper = await mountView();
    mocks.put.mockRejectedValue(new TypeError("network down"));

    await openDialog();
    await submit("59,90");

    expect(wrapper.get("[data-testid=change-error]").text()).toContain("połączenie");
  });

  it("closes without sending anything when cancelled", async () => {
    serve({ pages: { "": { items: [item("A")], next: null } } });
    wrapper = await mountView();

    await openDialog();
    await wrapper.get("[data-testid=gross-input]").setValue("59,90");
    await wrapper.get("[data-testid=cancel-change]").trigger("click");
    await settle();

    expect(wrapper.find("[data-testid=change-dialog]").exists()).toBe(false);
    expect(mocks.put).not.toHaveBeenCalled();
  });
  /** A PUT that answers only when the test says so. */
  function deferredPut() {
    let resolve!: (value: unknown) => void;
    mocks.put.mockImplementationOnce(() => new Promise((done) => (resolve = done)));
    return (status: number, data?: ListedPrice) =>
      resolve(data ? { data, error: undefined, response: { status } } : { data: undefined, error: { status }, response: { status } });
  }

  async function closeDialog() {
    await wrapper!.get("[data-testid=cancel-change]").trigger("click");
    await settle();
  }

  it("does not let the answer of a closed dialog close or mark the dialog opened for another product", async () => {
    serve({ pages: { "": { items: [item("A"), item("B")], next: null } } });
    wrapper = await mountView();
    const respond = deferredPut();

    await openDialog(0);
    await submit("59,90");
    await closeDialog();
    await openDialog(1);
    respond(200, price({ gross: 5990, version: 2 }));
    await settle();

    const dialog = wrapper.get("[data-testid=change-dialog]");
    expect(dialog.text()).toContain("B");
    // The list still takes the saved price of A.
    expect(wrapper.findAll("[data-testid=price-row]")[0]!.get("[data-testid=gross]").text()).toMatch(/^59,90\s*zł$/);
  });

  it("does not show the conflict or the error of a closed dialog in the dialog opened for another product", async () => {
    serve({ pages: { "": { items: [item("A"), item("B")], next: null } } });
    wrapper = await mountView();
    const respond = deferredPut();

    await openDialog(0);
    await submit("59,90");
    await closeDialog();
    await openDialog(1);
    respond(409);
    await settle();

    expect(wrapper.get("[data-testid=change-dialog]").text()).toContain("B");
    expect(wrapper.find("[data-testid=change-conflict]").exists()).toBe(false);
    expect(wrapper.find("[data-testid=change-error]").exists()).toBe(false);
    expect(wrapper.find("[data-testid=gross-error]").exists()).toBe(false);
  });

  it("does not carry the saving state of a closed dialog into the next one", async () => {
    serve({ pages: { "": { items: [item("A"), item("B")], next: null } } });
    wrapper = await mountView();
    const respond = deferredPut();

    await openDialog(0);
    await submit("59,90");
    await closeDialog();
    await openDialog(1);
    answer(200, price({ gross: 6990, version: 2 }));
    await submit("69,90");

    expect(mocks.put).toHaveBeenCalledTimes(2);
    expect(mocks.put).toHaveBeenLastCalledWith(PRICE, { params: { path: { productId: "id-B" } }, body: { gross: 6990, version: 1 } });
    expect(wrapper.find("[data-testid=change-dialog]").exists()).toBe(false);
    respond(200, price({ gross: 5990, version: 2 }));
    await settle();
  });

  it("does not reuse a late answer for the same product opened again", async () => {
    serve({ pages: { "": { items: [item("A")], next: null } } });
    wrapper = await mountView();
    const respond = deferredPut();

    await openDialog(0);
    await submit("59,90");
    await closeDialog();
    await openDialog(0);
    respond(422);
    await settle();

    expect(wrapper.find("[data-testid=change-dialog]").exists()).toBe(true);
    expect(wrapper.find("[data-testid=gross-error]").exists()).toBe(false);
  });
});

describe("PricesView: price history", () => {
  let wrapper: Awaited<ReturnType<typeof mountView>> | undefined;
  const HISTORY = "/api/v1/admin/products/{productId}/price-history";

  type HistoryEntry = components["schemas"]["PriceHistoryItemResponse"];
  type HistoryPage = components["schemas"]["PriceHistoryResponse"];

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    mocks.get.mockReset();
    mocks.put.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    vi.useRealTimers();
  });

  function entry(gross: number, overrides: Partial<HistoryEntry> = {}): HistoryEntry {
    return {
      changedAt: "2026-11-01T12:30:00+00:00",
      currency: "PLN",
      gross,
      net: gross - 261,
      vat: 261,
      vatRate: 500,
      taxClass: "printed_book",
      source: "staff",
      actorName: "Anna Admin",
      ...overrides,
    };
  }

  /** Answers the price list with one page and the history by the `after` cursor of the request ("" for the first page). */
  function serveHistory(histories: Record<string, HistoryPage> | number, items: PriceListItem[] = [item("A", { productId: "p-1" })]) {
    mocks.get.mockImplementation(
      async (path: string, options?: { params?: { path?: { productId: string }; query?: { after?: string } } }) => {
        if (path === CATEGORIES) {
          return { data: tree, response: { status: 200 } };
        }
        if (path === HISTORY) {
          if (typeof histories === "number") {
            return { data: undefined, response: { status: histories } };
          }
          return { data: histories[options?.params?.query?.after ?? ""], response: { status: 200 } };
        }
        return { data: { items, next: null }, response: { status: 200 } };
      },
    );
  }

  function historyCalls() {
    return mocks.get.mock.calls.filter(([path]) => path === HISTORY).map(([, options]) => options.params);
  }

  async function openHistory(row = 0) {
    await wrapper!.findAll("[data-testid=show-history]")[row]!.trigger("click");
    await settle();
  }

  it.each([[["admin"]], [["marketing"]], [["support"]], [["warehouse"]], [[]]] as StaffMe["roles"][][])(
    "offers the history on every row to a staff member with the roles %j",
    async (roles) => {
      serveHistory({}, [item("A"), item("B")]);
      wrapper = await mountView(roles);

      expect(wrapper.findAll("[data-testid=show-history]")).toHaveLength(2);
      expect(historyCalls()).toEqual([]);
    },
  );

  it("shows the entries newest first with the amounts and the rate from the API and the source of each change", async () => {
    serveHistory({
      "": {
        items: [
          entry(5990, { changedAt: "2026-11-03T09:00:00+00:00", source: "tax_reconciliation", actorName: null, net: 5705, vat: 285 }),
          entry(5790, { changedAt: "2026-11-02T09:00:00+00:00", source: "staff", actorName: "Jan Kowalski", net: 5514, vat: 276 }),
          entry(5490, { changedAt: "2026-11-01T09:00:00+00:00", source: "seed", actorName: null, net: 5229, vat: 261 }),
        ],
        next: null,
      },
    });
    wrapper = await mountView();

    await openHistory();

    expect(historyCalls()).toEqual([{ path: { productId: "p-1" }, query: {} }]);
    const dialog = wrapper.get("[data-testid=history-dialog]");
    expect(dialog.text()).toContain("A");
    const rows = dialog.findAll("[data-testid=history-row]");
    expect(rows).toHaveLength(3);
    expect(rows.map((row) => row.get("[data-testid=history-source]").text())).toEqual(["Uzgodnienie podatkowe", "Pracownik", "Dane demo"]);
    expect(rows.map((row) => row.get("[data-testid=history-actor]").text())).toEqual(["—", "Jan Kowalski", "—"]);
    expect(rows[0]!.get("[data-testid=history-changed-at]").text()).toMatch(/^03\.11\.2026.*10:00:00$/);
    expect(rows[0]!.get("[data-testid=history-gross]").text()).toMatch(/^59,90\s*zł$/);
    expect(rows[0]!.get("[data-testid=history-net]").text()).toMatch(/^57,05\s*zł$/);
    expect(rows[0]!.get("[data-testid=history-vat]").text()).toMatch(/^2,85\s*zł$/);
    expect(rows[0]!.get("[data-testid=history-rate]").text()).toBe("5%");
    expect(rows[2]!.get("[data-testid=history-gross]").text()).toMatch(/^54,90\s*zł$/);
    expect(dialog.find("[data-testid=history-more]").exists()).toBe(false);
  });

  it("formats the amounts of an entry in the currency the API sends with it", async () => {
    serveHistory({ "": { items: [entry(5990, { currency: "EUR", net: 5705, vat: 285 })], next: null } });
    wrapper = await mountView();

    await openHistory();

    const row = wrapper.get("[data-testid=history-row]");
    for (const cell of ["history-gross", "history-net", "history-vat"]) {
      expect(row.get(`[data-testid=${cell}]`).text()).toContain("€");
      expect(row.get(`[data-testid=${cell}]`).text()).not.toContain("zł");
    }
  });

  it("loads further entries with 'Pokaż więcej' using the cursor of the API, until the last page", async () => {
    serveHistory({
      "": { items: [entry(3000), entry(2000)], next: "c1" },
      c1: { items: [entry(1000)], next: null },
    });
    wrapper = await mountView();
    await openHistory();
    const grosses = () => wrapper!.findAll("[data-testid=history-gross]").map((cell) => cell.text().replace(/\s/g, " "));

    expect(grosses()).toHaveLength(2);
    expect(wrapper.get("[data-testid=history-more]").text()).toBe("Pokaż więcej");

    await wrapper.get("[data-testid=history-more]").trigger("click");
    await settle();

    expect(historyCalls().at(-1)).toEqual({ path: { productId: "p-1" }, query: { after: "c1" } });
    expect(grosses()).toHaveLength(3);
    expect(wrapper.find("[data-testid=history-more]").exists()).toBe(false);
  });

  it("says so when the product has no recorded price change", async () => {
    serveHistory({ "": { items: [], next: null } });
    wrapper = await mountView();

    await openHistory();

    expect(wrapper.findAll("[data-testid=history-row]")).toHaveLength(0);
    expect(wrapper.get("[data-testid=history-empty]").text()).toBe("Produkt nie ma jeszcze ceny.");
  });

  it("shows the error of the API and loads again on retry", async () => {
    serveHistory(500);
    wrapper = await mountView();

    await openHistory();

    expect(wrapper.get("[data-testid=history-error]").text()).toContain("Nie udało się pobrać historii cen (HTTP 500).");
    expect(wrapper.find("[data-testid=history-empty]").exists()).toBe(false);

    serveHistory({ "": { items: [entry(5490)], next: null } });
    await wrapper.get("[data-testid=history-retry]").trigger("click");
    await settle();

    expect(wrapper.find("[data-testid=history-error]").exists()).toBe(false);
    expect(wrapper.findAll("[data-testid=history-row]")).toHaveLength(1);
  });

  it("keeps the entries and offers a retry when a further page fails", async () => {
    let calls = 0;
    mocks.get.mockImplementation(async (path: string, options?: { params?: { query?: { after?: string } } }) => {
      if (path === CATEGORIES) {
        return { data: tree, response: { status: 200 } };
      }
      if (path === HISTORY) {
        calls++;
        return calls === 2
          ? { data: undefined, response: { status: 503 } }
          : { data: options?.params?.query?.after ? { items: [entry(1000)], next: null } : { items: [entry(2000)], next: "c1" }, response: { status: 200 } };
      }
      return { data: { items: [item("A", { productId: "p-1" })], next: null }, response: { status: 200 } };
    });
    wrapper = await mountView();
    await openHistory();

    await wrapper.get("[data-testid=history-more]").trigger("click");
    await settle();
    expect(wrapper.findAll("[data-testid=history-row]")).toHaveLength(1);
    expect(wrapper.get("[data-testid=history-error]").text()).toContain("HTTP 503");

    await wrapper.get("[data-testid=history-retry]").trigger("click");
    await settle();
    expect(wrapper.findAll("[data-testid=history-row]")).toHaveLength(2);
    expect(wrapper.find("[data-testid=history-error]").exists()).toBe(false);
  });

  it("closes without asking again, and the next product starts from its own first page", async () => {
    serveHistory(
      { "": { items: [entry(5490)], next: "c1" }, c1: { items: [entry(1)], next: null } },
      [item("A", { productId: "p-1" }), item("B", { productId: "p-2" })],
    );
    wrapper = await mountView();
    await openHistory(0);
    await wrapper.get("[data-testid=history-more]").trigger("click");
    await settle();
    expect(wrapper.findAll("[data-testid=history-row]")).toHaveLength(2);

    await wrapper.get("[data-testid=close-history]").trigger("click");
    await settle();
    expect(wrapper.find("[data-testid=history-dialog]").exists()).toBe(false);

    await openHistory(1);
    expect(historyCalls().at(-1)).toEqual({ path: { productId: "p-2" }, query: {} });
    expect(wrapper.findAll("[data-testid=history-row]")).toHaveLength(1);
    expect(wrapper.get("[data-testid=history-dialog]").text()).toContain("B");
  });

  it("leaves the history to every role but opens the price change only for an administrator", async () => {
    serveHistory({ "": { items: [entry(5490)], next: null } });
    wrapper = await mountView(["marketing"]);

    await openHistory();

    expect(wrapper.find("[data-testid=change-price]").exists()).toBe(false);
    expect(wrapper.findAll("[data-testid=history-row]")).toHaveLength(1);
  });
});
