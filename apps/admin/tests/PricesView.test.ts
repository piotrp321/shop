import { DOMWrapper, flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { afterEach, beforeEach, describe, expect, it, type MockInstance, vi } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import { toast } from "vue-sonner";
import type { components } from "@/api/schema";
import CategoryCombobox from "@/components/CategoryCombobox.vue";
import { Select } from "@/components/ui/select";
import { useAuthStore, type StaffMe } from "@/stores/auth";
import { SEARCH_DEBOUNCE_MS } from "@/prices/usePriceList";
import PricesView from "@/views/PricesView.vue";
import type { WithOptionalTotal } from "./listPage";

type PriceList = WithOptionalTotal<components["schemas"]["PriceListResponse"]>;
type PriceListItem = components["schemas"]["PriceListItemResponse"];
type PriceListVariant = components["schemas"]["PriceListVariantResponse"];
type ListedPrice = components["schemas"]["ListedPriceResponse"];
type CategoryNode = components["schemas"]["CategoryNode"];

const mocks = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get, PUT: mocks.put }) }));

const PRICES = "/api/v1/admin/prices";
const CATEGORIES = "/api/v1/admin/categories";
const TAX_CLASSES = "/api/v1/admin/tax-classes";

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

interface ItemOverrides extends Partial<PriceListItem> {
  /** The id of the one (default) variant of the product. */
  variantId?: string;
  /** The price of the one (default) variant of the product. */
  price?: ListedPrice | null;
}

/** A product of the list; without `variants`, with one default variant (empty label) that has the given price. */
function item(name: string, overrides: ItemOverrides = {}): PriceListItem {
  const { variantId = `variant-${name}`, price: variantPrice = price(), ...rest } = overrides;
  return {
    productId: `id-${name}`,
    name,
    taxClass: "printed_book",
    applicableVatRate: 500,
    variants: [{ variantId, label: "", price: variantPrice }],
    ...rest,
  };
}

function variant(variantId: string, label: string, variantPrice: ListedPrice | null = price()): PriceListVariant {
  return { variantId, label, price: variantPrice };
}

const TAX_CLASS_LIST = [
  { code: "printed_book", name: "Książki drukowane" },
  { code: "standard", name: "Standardowa" },
];

const tree: CategoryNode[] = [
  { id: "c-sport", name: "Sport", slug: "sport", path: "sport", children: [{ id: "c-run", name: "Bieganie", slug: "bieganie", path: "sport/bieganie", children: [] }] },
  { id: "c-food", name: "Jedzenie", slug: "jedzenie", path: "jedzenie", children: [] },
];

interface Backend {
  pages: Record<string, PriceList>;
  status?: number;
  categories?: CategoryNode[] | "error";
  /** The tax classes of the API; "error" is a failed request. */
  taxClasses?: { code: string; name: string }[] | "error";
}

/** Answers the price list by the `after` cursor of the request ("" for the first page). */
function serve(backend: Backend) {
  mocks.get.mockImplementation(async (path: string, options?: { params?: { query?: { after?: string } } }) => {
    if (path === CATEGORIES) {
      return backend.categories === "error"
        ? { data: undefined, response: { status: 500 } }
        : { data: backend.categories ?? tree, response: { status: 200 } };
    }
    if (path === TAX_CLASSES) {
      return backend.taxClasses === "error"
        ? { data: undefined, response: { status: 500 } }
        : { data: backend.taxClasses ?? TAX_CLASS_LIST, response: { status: 200 } };
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

/** Everything is in the body: the windows are teleported there, so a query goes through the whole document. */
const page = () => new DOMWrapper(document.body);

async function mountView(roles: StaffMe["roles"] = ["admin"], address = "/prices") {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().staff = { id: "u1", username: "anna", roles };
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/prices", name: "prices", component: empty },
      { path: "/", name: "dashboard", component: empty },
    ],
  });
  await router.push(address);
  const wrapper = mount(PricesView, { global: { plugins: [pinia, router] }, attachTo: document.body });
  await settle();
  return Object.assign(wrapper, { router });
}

type Mounted = Awaited<ReturnType<typeof mountView>>;

describe("PricesView", () => {
  let wrapper: Mounted | undefined;

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    mocks.get.mockReset();
    mocks.put.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    document.body.innerHTML = "";
    vi.useRealTimers();
  });

  it("loads the first page and shows a row with the price formatted from grosze by the API's numbers", async () => {
    serve({ pages: { "": { items: [item("Przewodnik Tatry")], next: null } } });
    wrapper = await mountView();

    expect(priceCalls()).toEqual([{ perPage: 10 }]);
    const group = page().get("[data-testid=product-row]");
    expect(group.get("[data-testid=name]").text()).toBe("Przewodnik Tatry");
    expect(group.get("th").attributes("scope")).toBe("rowgroup");
    const row = page().get("[data-testid=price-row]");
    expect(row.find("[data-testid=name]").exists()).toBe(false);
    expect(row.get("[data-testid=gross]").text()).toMatch(/^54,90\s*zł$/);
    expect(row.get("[data-testid=net]").text()).toMatch(/^52,29\s*zł$/);
    expect(row.get("[data-testid=vat]").text()).toMatch(/^2,61\s*zł$/);
    expect(row.get("[data-testid=vat-rate]").text()).toBe("5%");
    expect(row.find("[data-testid=outdated]").exists()).toBe(false);
  });

  it("shows a row per variant under the name of its product, with the label in the 'Wariant' column", async () => {
    serve({
      pages: {
        "": {
          items: [
            item("Buty Asfalt", {
              taxClass: "standard",
              applicableVatRate: 2300,
              variants: [variant("v-40", "czarne, 40", price({ gross: 34900 })), variant("v-41", "czarne, 41", null)],
            }),
            item("Hantle"),
          ],
          next: null,
        },
      },
    });
    wrapper = await mountView();

    const rows = page().findAll("[data-testid=price-row]");
    expect(rows).toHaveLength(3);
    expect(page().get("thead").text()).toContain("Wariant");
    expect(page().findAll("[data-testid=product-row] [data-testid=name]").map((cell) => cell.text())).toEqual(["Buty Asfalt", "Hantle"]);
    // The group row of a product stands right before the rows of its variants.
    expect(page().findAll("tbody tr").map((row) => row.attributes("data-testid"))).toEqual([
      "product-row",
      "price-row",
      "price-row",
      "product-row",
      "price-row",
    ]);
    expect(rows.map((row) => row.get("[data-testid=variant-label]").text())).toEqual(["czarne, 40", "czarne, 41", "Wariant domyślny"]);
    expect(rows[0]!.get("[data-testid=gross]").text()).toMatch(/^349,00\s*zł$/);
    expect(rows[1]!.get("[data-testid=gross]").text()).toBe("Brak ceny");
  });

  it("changes the price of the variant of the row and replaces only that row", async () => {
    serve({
      pages: {
        "": {
          items: [item("Buty", { variants: [variant("v-40", "40", price({ version: 2 })), variant("v-41", "41", price({ version: 5 }))] })],
          next: null,
        },
      },
    });
    mocks.put.mockResolvedValue({ data: price({ gross: 5990, version: 6 }), response: { status: 200 } });
    wrapper = await mountView();

    await page().findAll("[data-testid=change-price]")[1]!.trigger("click");
    await settle();
    expect(page().get("[data-testid=change-dialog]").text()).toContain("Buty / 41");
    await page().get("[data-testid=gross-input]").setValue("59,90");
    await page().get("[data-testid=save-price]").trigger("click");
    await settle();

    expect(mocks.put).toHaveBeenCalledWith("/api/v1/admin/variants/{variantId}/price", {
      params: { path: { variantId: "v-41" } },
      body: { gross: 5990, version: 5 },
    });
    const rows = page().findAll("[data-testid=price-row]");
    expect(rows[0]!.get("[data-testid=gross]").text()).toMatch(/^54,90\s*zł$/);
    expect(rows[1]!.get("[data-testid=gross]").text()).toMatch(/^59,90\s*zł$/);
  });

  it("shows a product without a price as 'Brak ceny' with no amounts", async () => {
    serve({ pages: { "": { items: [item("Nowy żel", { price: null, taxClass: "energy_gel", applicableVatRate: 800 })], next: null } } });
    wrapper = await mountView();

    const row = page().get("[data-testid=price-row]");
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

    const row = page().get("[data-testid=price-row]");
    expect(row.get("[data-testid=vat-rate]").text()).toContain("8%");
    expect(row.get("[data-testid=outdated]").text()).toContain("Nieaktualna");
    expect(row.get("[data-testid=outdated]").text()).toContain("23%");
  });

  it("shows a message and no rows when nothing matches", async () => {
    serve({ pages: { "": { items: [], next: null } } });
    wrapper = await mountView();

    expect(page().findAll("[data-testid=price-row]")).toHaveLength(0);
    expect(page().get("[data-testid=empty]").text()).toContain("Nie ma jeszcze produktów z wariantami.");
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
    const names = () => page().findAll("[data-testid=name]").map((cell) => cell.text());
    const previous = () => page().get("[data-testid=previous-page]");
    const next = () => page().get("[data-testid=next-page]");

    expect(names()).toEqual(["A"]);
    expect(previous().attributes("disabled")).toBeDefined();
    expect(next().attributes("disabled")).toBeUndefined();

    await next().trigger("click");
    await settle();
    expect(names()).toEqual(["B"]);
    expect(priceCalls().at(-1)).toEqual({ perPage: 10, after: "c1" });

    await next().trigger("click");
    await settle();
    expect(names()).toEqual(["C"]);
    expect(priceCalls().at(-1)).toEqual({ perPage: 10, after: "c2" });
    expect(next().attributes("disabled")).toBeDefined();

    await previous().trigger("click");
    await settle();
    expect(names()).toEqual(["B"]);
    expect(priceCalls().at(-1)).toEqual({ perPage: 10, after: "c1" });

    await previous().trigger("click");
    await settle();
    expect(names()).toEqual(["A"]);
    expect(priceCalls().at(-1)).toEqual({ perPage: 10 });
    expect(previous().attributes("disabled")).toBeDefined();
  });

  it("does not send a search shorter than two characters", async () => {
    serve({ pages: { "": { items: [item("A")], next: null } } });
    wrapper = await mountView();
    const calls = priceCalls().length;

    await page().get("[data-testid=search]").setValue("a");
    await vi.advanceTimersByTimeAsync(1000);
    await settle();

    expect(priceCalls()).toHaveLength(calls);
    expect(page().get("[data-testid=search-hint]").text()).toContain("2 znaki");
  });

  it("counts characters, not UTF-16 units: a single emoji is too short to search", async () => {
    serve({ pages: { "": { items: [item("A")], next: null } } });
    wrapper = await mountView();
    const calls = priceCalls().length;

    await page().get("[data-testid=search]").setValue("😀");
    await vi.advanceTimersByTimeAsync(1000);
    await settle();

    expect(priceCalls()).toHaveLength(calls);
    expect(page().get("[data-testid=search-hint]").text()).toContain("2 znaki");
  });

  it("searches for two emoji, which are two characters", async () => {
    serve({ pages: { "": { items: [item("A")], next: null } } });
    wrapper = await mountView();

    await page().get("[data-testid=search]").setValue("😀😀");
    await vi.advanceTimersByTimeAsync(400);
    await settle();

    expect(priceCalls().at(-1)).toEqual({ perPage: 10, q: "😀😀" });
  });

  it("limits the search field to the 100 characters the API accepts", async () => {
    serve({ pages: { "": { items: [item("A")], next: null } } });
    wrapper = await mountView();

    expect(page().get("[data-testid=search]").attributes("maxlength")).toBe("100");
  });

  it("searches after the typing pauses, once, with the trimmed term", async () => {
    serve({ pages: { "": { items: [item("A")], next: null } } });
    wrapper = await mountView();
    const search = page().get("[data-testid=search]");

    await search.setValue("bi");
    await vi.advanceTimersByTimeAsync(100);
    await search.setValue("bid");
    await vi.advanceTimersByTimeAsync(100);
    await search.setValue("  bidon ");
    expect(priceCalls()).toHaveLength(1);
    await vi.advanceTimersByTimeAsync(400);
    await settle();

    expect(priceCalls()).toEqual([{ perPage: 10 }, { perPage: 10, q: "bidon" }]);
    expect(page().get("[data-testid=search-hint]").text()).toBe("");
  });

  it("starts again from the first page when the search changes, and goes back to the full list when it is cleared", async () => {
    serve({
      pages: {
        "": { items: [item("A")], next: "c1" },
        c1: { items: [item("B")], next: null },
      },
    });
    wrapper = await mountView();
    await page().get("[data-testid=next-page]").trigger("click");
    await settle();
    expect(priceCalls().at(-1)).toEqual({ perPage: 10, after: "c1" });

    await page().get("[data-testid=search]").setValue("zz");
    await vi.advanceTimersByTimeAsync(400);
    await settle();
    expect(priceCalls().at(-1)).toEqual({ perPage: 10, q: "zz" });
    expect(page().get("[data-testid=previous-page]").attributes("disabled")).toBeDefined();

    await page().get("[data-testid=search]").setValue("");
    await vi.advanceTimersByTimeAsync(400);
    await settle();
    expect(priceCalls().at(-1)).toEqual({ perPage: 10 });
  });

  it("filters by the chosen category and starts from the first page", async () => {
    serve({
      pages: {
        "": { items: [item("A")], next: "c1" },
        c1: { items: [item("B")], next: null },
      },
    });
    wrapper = await mountView();
    await page().get("[data-testid=next-page]").trigger("click");
    await settle();

    const filter = wrapper.getComponent(CategoryCombobox);
    expect(filter.props("nodes")).toEqual([
      { key: "c-sport", label: "Sport", children: [{ key: "c-run", label: "Bieganie", children: [] }] },
      { key: "c-food", label: "Jedzenie", children: [] },
    ]);
    expect(filter.props("label")).toBe("Kategoria");
    filter.vm.$emit("update:modelValue", "c-run");
    await settle();

    expect(priceCalls().at(-1)).toEqual({ perPage: 10, category: "c-run" });
    expect(page().get("[data-testid=previous-page]").attributes("disabled")).toBeDefined();

    filter.vm.$emit("update:modelValue", null);
    await settle();
    expect(priceCalls().at(-1)).toEqual({ perPage: 10 });
  });

  it("combines the search and the category", async () => {
    serve({ pages: { "": { items: [item("A")], next: null } } });
    wrapper = await mountView();

    wrapper.getComponent(CategoryCombobox).vm.$emit("update:modelValue", "c-food");
    await settle();
    await page().get("[data-testid=search]").setValue("baton");
    await vi.advanceTimersByTimeAsync(400);
    await settle();

    expect(priceCalls().at(-1)).toEqual({ perPage: 10, q: "baton", category: "c-food" });
  });

  it("shows the error, keeps the page it was on, and retries", async () => {
    serve({ pages: { "": { items: [item("A")], next: "c1" } } });
    wrapper = await mountView();

    serve({ pages: {}, status: 500 });
    await page().get("[data-testid=next-page]").trigger("click");
    await settle();

    expect(page().get("[data-testid=prices-error]").text()).toContain("HTTP 500");
    expect(page().findAll("[data-testid=name]").map((cell) => cell.text())).toEqual(["A"]);

    serve({ pages: { c1: { items: [item("B")], next: null } } });
    await page().get("[data-testid=retry]").trigger("click");
    await settle();

    expect(page().find("[data-testid=prices-error]").exists()).toBe(false);
    expect(page().findAll("[data-testid=name]").map((cell) => cell.text())).toEqual(["B"]);
  });

  it("never pairs a new category with a cursor of the old list after a failed filter change", async () => {
    serve({
      pages: {
        "": { items: [item("A")], next: "c1" },
        c1: { items: [item("B")], next: "c2" },
      },
    });
    wrapper = await mountView();
    await page().get("[data-testid=next-page]").trigger("click");
    await settle();
    expect(priceCalls().at(-1)).toEqual({ perPage: 10, after: "c1" });

    serve({ pages: {}, status: 500 });
    wrapper.getComponent(CategoryCombobox).vm.$emit("update:modelValue", "c-food");
    await settle();

    // The table and the pager are gone until a retry succeeds (#272): there is nothing to page with the old cursor.
    expect(page().find("[data-testid=prices-error]").exists()).toBe(true);
    expect(page().find("[data-testid=price-row]").exists()).toBe(false);
    expect(page().find("[data-testid=next-page]").exists()).toBe(false);
    expect(page().find("[data-testid=previous-page]").exists()).toBe(false);
    expect(priceCalls().filter((query) => query.category !== undefined && query.after !== undefined)).toEqual([]);

    serve({ pages: { "": { items: [item("F")], next: null } } });
    await page().get("[data-testid=retry]").trigger("click");
    await settle();
    expect(priceCalls().at(-1)).toEqual({ perPage: 10, category: "c-food" });
    expect(page().findAll("[data-testid=name]").map((cell) => cell.text())).toEqual(["F"]);
  });

  it("never pairs a new search with a cursor of the old list after a failed search", async () => {
    serve({ pages: { "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: null } } });
    wrapper = await mountView();
    await page().get("[data-testid=next-page]").trigger("click");
    await settle();

    serve({ pages: {}, status: 500 });
    await page().get("[data-testid=search]").setValue("zz");
    await vi.advanceTimersByTimeAsync(400);
    await settle();

    expect(page().find("[data-testid=next-page]").exists()).toBe(false);
    expect(page().find("[data-testid=previous-page]").exists()).toBe(false);
    expect(priceCalls().at(-1)).toEqual({ perPage: 10, q: "zz" });
  });

  it("shows an error when the request itself fails", async () => {
    mocks.get.mockImplementation(async (path: string) => {
      if (path === CATEGORIES) {
        return { data: tree, response: { status: 200 } };
      }
      if (path === TAX_CLASSES) {
        return { data: TAX_CLASS_LIST, response: { status: 200 } };
      }
      throw new TypeError("network down");
    });
    wrapper = await mountView();

    expect(page().get("[data-testid=prices-error]").text()).toContain("połączenie");
  });

  it("says so when the chosen category no longer exists", async () => {
    serve({ pages: { "": { items: [item("A")], next: null } } });
    wrapper = await mountView();

    serve({ pages: {}, status: 404 });
    wrapper.getComponent(CategoryCombobox).vm.$emit("update:modelValue", "c-food");
    await settle();

    expect(page().get("[data-testid=prices-error]").text()).toContain("kategoria");
  });

  it("still lists prices when the category tree cannot be loaded, and says the filter is unavailable", async () => {
    serve({ pages: { "": { items: [item("A")], next: null } }, categories: "error" });
    wrapper = await mountView();

    expect(page().findAll("[data-testid=price-row]")).toHaveLength(1);
    expect(page().get("[data-testid=categories-error]").text()).toContain("kategorii");
  });

  describe("the row of a product", () => {
    it("names the tax class in Polish, and gives the code for a class that the list does not have", async () => {
      serve({
        pages: { "": { items: [item("Książka"), item("Żel", { taxClass: "energy_gel" })], next: null } },
        taxClasses: [{ code: "printed_book", name: "Książki drukowane" }],
      });
      wrapper = await mountView();

      expect(page().findAll("[data-testid=tax-class]").map((cell) => cell.text())).toEqual(["Książki drukowane", "energy_gel"]);
      expect(page().find("[data-testid=price-row] [data-testid=tax-class]").exists()).toBe(false);
    });

    it("gives the code, without a message, when the tax classes cannot be loaded", async () => {
      serve({ pages: { "": { items: [item("Książka")], next: null } }, taxClasses: "error" });
      wrapper = await mountView();

      expect(page().get("[data-testid=tax-class]").text()).toBe("printed_book");
      expect(page().findAll("[role=alert]")).toHaveLength(0);
    });

    it("has no actions of its own: the buttons are in the rows of the variants", async () => {
      serve({ pages: { "": { items: [item("Buty", { variants: [variant("v-40", "40"), variant("v-41", "41")] })], next: null } } });
      wrapper = await mountView();

      expect(page().get("[data-testid=product-row]").findAll("button, a")).toHaveLength(0);
      expect(page().findAll("[data-testid=price-row]").map((row) => row.findAll("button").length)).toEqual([2, 2]);
    });
  });

  describe("the columns and the actions", () => {
    it("names the actions of a row with the product and the variant, and the default variant with the product only", async () => {
      serve({ pages: { "": { items: [item("Buty", { variants: [variant("v-40", "czarne / 40")] }), item("Hantle")], next: null } } });
      wrapper = await mountView();

      expect(page().findAll("[data-testid=change-price]").map((button) => button.attributes("aria-label"))).toEqual([
        "Zmień cenę: Buty / czarne / 40",
        "Zmień cenę: Hantle",
      ]);
      expect(page().findAll("[data-testid=show-history]").map((button) => button.attributes("aria-label"))).toEqual([
        "Historia: Buty / czarne / 40",
        "Historia: Hantle",
      ]);
    });

    it("gives Netto and VAT as lines of the cell of the variant, for a narrow screen, with their labels", async () => {
      serve({ pages: { "": { items: [item("Buty")], next: null } } });
      wrapper = await mountView();

      const row = page().get("[data-testid=price-row]");
      expect(row.get("[data-testid=line-net]").text()).toMatch(/^Netto: 52,29\s*zł$/);
      expect(row.get("[data-testid=line-vat]").text()).toMatch(/^VAT: 2,61\s*zł$/);
      expect(row.get("[data-testid=line-net]").classes()).toContain("lg:hidden");
      const heads = page().findAll("thead th");
      expect(heads.map((head) => head.text())).toEqual(["Wariant", "Brutto", "Netto", "VAT", "Stawka", "Akcje"]);
      expect(heads[2]!.classes()).toEqual(expect.arrayContaining(["hidden", "lg:table-cell"]));
    });

    it("shows the gross in bold", async () => {
      serve({ pages: { "": { items: [item("Buty")], next: null } } });
      wrapper = await mountView();

      expect(page().get("[data-testid=gross]").classes()).toContain("font-bold");
    });
  });

  describe("the state of the list is in the address", () => {
    it("shows the search, the page size and the results of an address", async () => {
      serve({ pages: { "": { items: [item("Hantle")], next: null } } });
      wrapper = await mountView(["admin"], "/prices?q=hantle&perPage=25");

      expect(priceCalls()).toEqual([{ q: "hantle", perPage: 25 }]);
      expect(page().get<HTMLInputElement>("[data-testid=search]").element.value).toBe("hantle");
      expect(page().get("[data-testid=per-page]").text()).toBe("25");
      expect(page().findAll("[data-testid=price-row]")).toHaveLength(1);
      expect(page().find("[data-testid=clear-filters]").exists()).toBe(true);
    });

    it("writes a change of the filter to the address without a new entry of the history", async () => {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView();
      const position = wrapper.router.options.history.state.position;

      wrapper.getComponent(CategoryCombobox).vm.$emit("update:modelValue", "c-food");
      await settle();

      expect(wrapper.router.currentRoute.value.query).toEqual({ category: "c-food" });
      expect(wrapper.router.options.history.state.position, "replace, not push").toBe(position);
    });

    it("clears the search, the filters and the page size with „Wyczyść filtry” and puts the focus in the search field", async () => {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView(["admin"], "/prices?q=hantle&perPage=50");

      await page().get("[data-testid=clear-filters]").trigger("click");
      await settle();

      expect(wrapper.router.currentRoute.value.fullPath).toBe("/prices");
      expect(priceCalls().at(-1)).toEqual({ perPage: 10 });
      expect(page().get<HTMLInputElement>("[data-testid=search]").element.value).toBe("");
      expect(document.activeElement).toBe(page().get("[data-testid=search]").element);
      expect(page().find("[data-testid=clear-filters]").exists()).toBe(false);
    });

    it("goes back to the first page when the page size changes, and asks for the size always", async () => {
      serve({ pages: { "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: null } } });
      wrapper = await mountView();
      await page().get("[data-testid=next-page]").trigger("click");
      await settle();

      wrapper.findAllComponents(Select)[1]!.vm.$emit("update:modelValue", "25");
      await settle();

      expect(priceCalls().at(-1)).toEqual({ perPage: 25 });
      expect(page().get("[data-testid=page-number]").text()).toBe("Strona 1");
      expect(wrapper.router.currentRoute.value.query).toEqual({ perPage: "25" });
    });


    it("has a Select „Sortuj” by the name or the last change, which sets the address and asks once from the first page", async () => {
      serve({ pages: { "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: null } } });
      wrapper = await mountView();
      const trigger = page().get<HTMLButtonElement>("[data-testid=sort]");
      expect(trigger.element.labels?.[0]?.textContent?.trim()).toBe("Sortuj");
      expect(trigger.text()).toBe("Nazwa A–Z");
      await page().get("[data-testid=next-page]").trigger("click");
      await settle();

      wrapper.findAllComponents(Select)[0]!.vm.$emit("update:modelValue", "-updatedAt");
      await settle();

      expect(priceCalls().at(-1)).toEqual({ perPage: 10, sort: "-updatedAt" });
      expect(priceCalls().filter((query) => query.sort === "-updatedAt")).toHaveLength(1);
      expect(page().get("[data-testid=page-number]").text()).toBe("Strona 1");
      expect(wrapper.router.currentRoute.value.fullPath).toBe("/prices?sort=-updatedAt");
      expect(page().get("[data-testid=sort]").text()).toBe("Ostatnio zmienione");
      expect(page().findAll("th[aria-sort]"), "the name is in the row of the group, not in a column").toHaveLength(0);
    });

    it("applies a sort that the address brings from outside with one request, over the rows of the groups", async () => {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView();
      mocks.get.mockClear();

      await wrapper.router.push("/prices?sort=-name");
      await settle();

      expect(priceCalls()).toEqual([{ perPage: 10, sort: "-name" }]);
      expect(page().get("[data-testid=sort]").text()).toBe("Nazwa Z–A");
    });

    it("says in the empty state that nothing matches the filters, with the way to clear them", async () => {
      serve({ pages: { "": { items: [], next: null } } });
      wrapper = await mountView(["admin"], "/prices?q=nic");

      expect(page().get("[data-testid=empty]").text()).toContain("Brak wyników dla wybranych filtrów.");
      expect(page().find("[data-testid=empty-clear-filters]").exists()).toBe(true);
    });
  });

  describe("„Pokazano produkty X–Y”", () => {
    const productsPage = (prefix: string, next: string | null): PriceList => ({
      items: Array.from({ length: 10 }, (_, index) => item(`${prefix}${index}`, { variants: [variant(`${prefix}${index}-a`, "a"), variant(`${prefix}${index}-b`, "b")] })),
      next,
    });

    it("counts products, not variants: the second page of 10 products is 11–20", async () => {
      serve({ pages: { "": productsPage("A", "c1"), c1: productsPage("B", null) } });
      wrapper = await mountView();
      expect(page().get("[data-testid=range]").text()).toBe("Pokazano produkty 1–10");
      expect(page().findAll("[data-testid=price-row]")).toHaveLength(20);

      await page().get("[data-testid=next-page]").trigger("click");
      await settle();

      expect(page().get("[data-testid=range]").text()).toBe("Pokazano produkty 11–20");
    });

    it("says „z N” with the number of products of the filters, not of their variants", async () => {
      serve({ pages: { "": { ...productsPage("A", "c1"), total: { value: 1234, capped: false } }, c1: productsPage("B", null) } });
      wrapper = await mountView();
      expect(page().get("[data-testid=range]").text()).toBe("Pokazano produkty 1–10 z 1\u00a0234");

      await page().get("[data-testid=next-page]").trigger("click");
      await settle();

      expect(page().get("[data-testid=range]").text()).toBe("Pokazano produkty 11–20 z 1\u00a0234");
    });
  });

  describe("errors of the list", () => {
    it("shows the error of a failed search with no rows of the old list (#272)", async () => {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView();
      expect(page().findAll("[data-testid=price-row]")).toHaveLength(1);

      serve({ pages: {}, status: 500 });
      await page().get("[data-testid=search]").setValue("hantle");
      await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 50);
      await settle();

      expect(page().get("[data-testid=prices-error]").text()).toContain("Nie udało się pobrać cen");
      expect(page().findAll("[data-testid=price-row]")).toHaveLength(0);
    });

    it("answers a 403 without a retry to offer", async () => {
      serve({ pages: {}, status: 403 });
      wrapper = await mountView();

      expect(page().get("[data-testid=prices-error]").text()).toContain("Nie masz dostępu do cen.");
      expect(page().find("[data-testid=retry]").exists()).toBe(false);
    });
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
      if (path === TAX_CLASSES) {
        return { data: TAX_CLASS_LIST, response: { status: 200 } };
      }
      if (options?.params?.query?.q === "ab") {
        return { data: { items: [item("Z ab")], next: null }, response: { status: 200 } };
      }
      await first;
      return { data: { items: [item("Z full list")], next: null }, response: { status: 200 } };
    });
    wrapper = await mountView();

    await page().get("[data-testid=search]").setValue("ab");
    await vi.advanceTimersByTimeAsync(400);
    await settle();
    releaseFirst(undefined);
    await settle();

    expect(page().findAll("[data-testid=name]").map((cell) => cell.text())).toEqual(["Z ab"]);
  });
});

describe("PricesView: changing a price", () => {
  let wrapper: Mounted | undefined;
  let successToast: MockInstance<typeof toast.success>;
  const PRICE = "/api/v1/admin/variants/{variantId}/price";

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    mocks.get.mockReset();
    mocks.put.mockReset();
    // The toast store is global; the tests read what was shown from the calls.
    successToast = vi.spyOn(toast, "success").mockImplementation(() => 1);
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    document.body.innerHTML = "";
    vi.useRealTimers();
  });

  async function openDialog(row = 0) {
    await page().findAll("[data-testid=change-price]")[row]!.trigger("click");
    await settle();
  }

  async function submit(text: string) {
    await page().get("[data-testid=gross-input]").setValue(text);
    await page().get("[data-testid=save-price]").trigger("click");
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

      expect(page().findAll("[data-testid=price-row]")).toHaveLength(1);
      expect(page().find("[data-testid=change-price]").exists()).toBe(false);
    },
  );

  it("offers an administrator the change on every row, with the rate that applies from the API", async () => {
    serve({ pages: { "": { items: [item("A"), item("B", { price: null, applicableVatRate: 2300 })], next: null } } });
    wrapper = await mountView(["admin"]);

    expect(page().findAll("[data-testid=change-price]")).toHaveLength(2);
    await openDialog(1);

    const dialog = page().get("[data-testid=change-dialog]");
    expect(dialog.text()).toContain("B");
    expect(dialog.get("[data-testid=rate-preview]").text()).toContain("23%");
    expect(dialog.get("[data-testid=current-gross]").text()).toContain("Brak ceny");
    // Only the rate from the API: the panel computes no net or VAT amount for the new gross.
    expect(dialog.text()).not.toMatch(/\d,\d{2}\s*zł/);
  });

  describe("the window", () => {
    it("is a dialog titled „Zmień cenę” with the name of the variant, in the width of the standard", async () => {
      serve({ pages: { "": { items: [item("Buty", { variants: [variant("v-41", "41")] })], next: null } } });
      wrapper = await mountView();

      await openDialog();

      const dialog = page().get("[role=dialog]");
      expect(page().get("[role=dialog] [data-slot=dialog-title]").text()).toBe("Zmień cenę");
      expect(dialog.attributes("aria-labelledby")).toBeDefined();
      expect(dialog.attributes("data-testid")).toBe("change-dialog");
      expect(dialog.text()).toContain("Buty / 41");
      expect(dialog.classes()).toContain("w-[min(28rem,calc(100vw-2rem))]");
      expect(page().get("label[for=gross-input]").text()).toBe("Nowa cena brutto (zł)");
    });

    it("puts the focus in the field of the price when it opens", async () => {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView();

      await openDialog();

      expect(document.activeElement).toBe(page().get("[data-testid=gross-input]").element);
    });

    it("puts the focus in the field also when it is opened with the Enter key (#273)", async () => {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView();
      const trigger = page().get<HTMLButtonElement>("[data-testid=change-price]");
      trigger.element.focus();

      // A button is pressed by Enter on keydown: the browser sends a click, the dialog opens, the key goes up in the field.
      await trigger.trigger("keydown", { key: "Enter" });
      await trigger.trigger("click");
      await settle();
      await page().get("[data-testid=gross-input]").trigger("keyup", { key: "Enter" });

      expect(document.activeElement).toBe(page().get("[data-testid=gross-input]").element);
      expect(mocks.put).not.toHaveBeenCalled();
    });

    it("saves with the Enter key in the field", async () => {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView();
      answer(200, price({ gross: 5990, version: 2 }));

      await openDialog();
      await page().get("[data-testid=gross-input]").setValue("59,90");
      await page().get("[data-testid=gross-input]").trigger("keydown", { key: "Enter" });
      await settle();

      expect(mocks.put).toHaveBeenCalledTimes(1);
    });

    it("has the button „Zapisz cenę” in the blue of the interface, and „Anuluj”", async () => {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView();

      await openDialog();

      const save = page().get("[data-testid=save-price]");
      expect(save.text()).toBe("Zapisz cenę");
      expect(save.classes()).toContain("bg-interface");
      expect(page().get("[data-testid=cancel-change]").text()).toBe("Anuluj");
    });
  });

  describe("after a save", () => {
    it("says so in a toast with the product and the variant, and gives the focus back to „Zmień cenę” of the row", async () => {
      serve({ pages: { "": { items: [item("Buty", { variants: [variant("v-40", "40"), variant("v-41", "41")] })], next: null } } });
      wrapper = await mountView();
      answer(200, price({ gross: 5990, version: 2 }));

      await openDialog(1);
      await submit("59,90");

      expect(successToast).toHaveBeenCalledWith("Cena zapisana: Buty / 41");
      expect(page().find("[data-testid=change-dialog]").exists()).toBe(false);
      expect(document.activeElement).toBe(page().findAll("[data-testid=change-price]")[1]!.element);
    });

    it("names the product only for the default variant", async () => {
      serve({ pages: { "": { items: [item("Hantle")], next: null } } });
      wrapper = await mountView();
      answer(200, price({ gross: 5990, version: 2 }));

      await openDialog();
      await submit("59,90");

      expect(successToast).toHaveBeenCalledWith("Cena zapisana: Hantle");
    });

    it("keeps the new price in the row while the list is refreshed afterwards", async () => {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView();
      answer(200, price({ gross: 5990, version: 2 }));
      await openDialog();
      await submit("59,90");

      // The list answers late with the price it had before the save.
      let respond: (page: PriceList) => void = () => undefined;
      mocks.get.mockImplementation(() => new Promise((resolve) => (respond = (answered) => resolve({ data: answered, response: { status: 200 } }))));
      await page().get("[data-testid=search]").setValue("zz");
      await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 50);
      expect(page().get("[data-testid=gross]").text()).toMatch(/^59,90\s*zł$/);
      respond({ items: [item("A")], next: null });
      await settle();

      expect(page().get("[data-testid=gross]").text()).toMatch(/^59,90\s*zł$/);
    });
  });

  describe("after a failure", () => {
    it("puts the focus in the field when the price is rejected (422)", async () => {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView();
      mocks.put.mockResolvedValue({ data: undefined, error: { violations: [{ propertyPath: "gross", title: "Za mało." }] }, response: { status: 422 } });

      await openDialog();
      await submit("0");

      expect(document.activeElement).toBe(page().get("[data-testid=gross-input]").element);
      expect(page().get("[data-testid=gross-input]").attributes("aria-describedby")).toContain(page().get("[data-testid=gross-error]").attributes("id"));
    });

    it("puts the focus on the message of a 403, of the connection and of a conflict", async () => {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView();
      answer(403);
      await openDialog();
      await submit("59,90");
      expect(document.activeElement).toBe(page().get("[data-testid=change-error]").element);
      expect(page().get("[data-testid=change-error]").attributes("role")).toBe("alert");

      mocks.put.mockResolvedValue({ data: undefined, error: { status: 409 }, response: { status: 409 } });
      await submit("59,90");
      expect(document.activeElement).toBe(page().get("[data-testid=change-conflict]").element);
      expect(page().find("[data-testid=change-error]").exists()).toBe(false);
    });

    it("shows no toast and keeps the window open", async () => {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView();
      answer(500);

      await openDialog();
      await submit("59,90");

      expect(successToast).not.toHaveBeenCalled();
      expect(page().find("[data-testid=change-dialog]").exists()).toBe(true);
    });
  });

  it("sends the gross in grosze with the version of the row and shows the saved price", async () => {
    serve({ pages: { "": { items: [item("A", { variantId: "v-1", price: price({ version: 3 }) })], next: null } } });
    wrapper = await mountView();
    answer(200, price({ gross: 5990, net: 5705, vat: 285, version: 4 }));

    await openDialog();
    expect(page().get("[data-testid=current-gross]").text()).toMatch(/54,90\s*zł/);
    await submit("59,90");

    expect(mocks.put).toHaveBeenCalledTimes(1);
    expect(mocks.put).toHaveBeenCalledWith(PRICE, { params: { path: { variantId: "v-1" } }, body: { gross: 5990, version: 3 } });
    expect(page().find("[data-testid=change-dialog]").exists()).toBe(false);
    const row = page().get("[data-testid=price-row]");
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
    await page().get("[data-testid=search]").setValue("Ab");
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS);
    answer(200, price({ gross: 5990, net: 5705, vat: 285, version: 2 }));

    await submit("59,90");
    respondList({ items: [item("A"), item("B")], next: null });
    await settle();

    const rows = page().findAll("[data-testid=price-row]");
    expect(rows).toHaveLength(2);
    expect(rows[0]!.get("[data-testid=gross]").text()).toMatch(/^59,90\s*zł$/);
    expect(rows[1]!.get("[data-testid=gross]").text()).toMatch(/^54,90\s*zł$/);
    answer(200, price({ gross: 6100, version: 3 }));
    await openDialog();
    await submit("61");
    expect(mocks.put).toHaveBeenLastCalledWith(PRICE, { params: { path: { variantId: "variant-A" } }, body: { gross: 6100, version: 2 } });
  });

  it("sends no version for a product without a price", async () => {
    serve({ pages: { "": { items: [item("A", { variantId: "v-2", price: null })], next: null } } });
    wrapper = await mountView();
    answer(200, price({ gross: 1200 }));

    await openDialog();
    await submit("12");

    expect(mocks.put).toHaveBeenCalledWith(PRICE, { params: { path: { variantId: "v-2" } }, body: { gross: 1200, version: null } });
  });

  it("does not send an amount it cannot read and says why at the field", async () => {
    serve({ pages: { "": { items: [item("A")], next: null } } });
    wrapper = await mountView();

    await openDialog();
    await submit("12,345");

    expect(mocks.put).not.toHaveBeenCalled();
    expect(page().get("[data-testid=gross-error]").text()).toContain("2 miejsca po przecinku");
    expect(page().get("[data-testid=gross-input]").attributes("aria-invalid")).toBe("true");
  });

  describe("a 422 of the API", () => {
    function rejectWith(error: unknown) {
      mocks.put.mockResolvedValue({ data: undefined, error, response: { status: 422 } });
    }

    async function submitRejected(error: unknown) {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView();
      rejectWith(error);

      await openDialog();
      await submit("0");

      expect(mocks.put).toHaveBeenCalledWith(PRICE, { params: { path: { variantId: "variant-A" } }, body: { gross: 0, version: 1 } });
      expect(page().find("[data-testid=change-dialog]").exists()).toBe(true);
      expect(page().get("[data-testid=price-row]").get("[data-testid=gross]").text()).toMatch(/^54,90\s*zł$/);
      return page().get("[data-testid=gross-error]").text();
    }

    it("shows the violation of the API at the field as it is, with the range in złoty from the API", async () => {
      const text = await submitRejected({
        type: "about:blank",
        title: "Unprocessable Entity",
        status: 422,
        detail: "The request is invalid.",
        violations: [{ propertyPath: "gross", title: "Cena brutto musi wynosić od 0,01 zł do 999 999,99 zł." }],
      });

      expect(text).toBe("Cena brutto musi wynosić od 0,01 zł do 999 999,99 zł.");
    });

    it("shows every violation", async () => {
      const text = await submitRejected({
        title: "Unprocessable Entity",
        status: 422,
        violations: [
          { propertyPath: "gross", title: "First problem." },
          { propertyPath: "version", title: "Second problem." },
        ],
      });

      expect(text).toBe("First problem. Second problem.");
    });

    it("shows the detail when there are no violations", async () => {
      const text = await submitRejected({ title: "Unprocessable Entity", status: 422, detail: "The price cannot be set." });

      expect(text).toBe("The price cannot be set.");
    });

    it.each([
      ["an empty problem", { title: "Unprocessable Entity", status: 422 }],
      ["empty violations and no detail", { title: "Unprocessable Entity", status: 422, violations: [] }],
      ["a body that is not a problem", "oops"],
      ["no body", undefined],
    ])("says the API rejected the price when the answer has %s", async (_name, error) => {
      const text = await submitRejected(error);

      expect(text).toBe("API odrzuciło cenę (HTTP 422).");
      expect(text).not.toContain("999 999,99");
    });
  });

  it("on a 409 says the price was changed meanwhile and refreshes the row", async () => {
    serve({ pages: { "": { items: [item("A")], next: "c1" }, c1: { items: [item("B", { variantId: "v-b" })], next: null } } });
    wrapper = await mountView();
    await page().get("[data-testid=next-page]").trigger("click");
    await settle();
    answer(409);
    const callsBefore = priceCalls().length;

    await openDialog();
    serve({ pages: { c1: { items: [item("B", { variantId: "v-b", price: price({ gross: 7000, version: 2 }) })], next: null } } });
    await submit("59,90");

    expect(page().get("[data-testid=change-conflict]").text()).toBe("Cena została w międzyczasie zmieniona – odśwież");
    expect(priceCalls()).toHaveLength(callsBefore + 1);
    expect(priceCalls().at(-1)).toEqual({ perPage: 10, after: "c1" });
    expect(page().get("[data-testid=price-row]").get("[data-testid=gross]").text()).toMatch(/^70,00\s*zł$/);
    expect(page().get("[data-testid=current-gross]").text()).toMatch(/70,00\s*zł/);

    answer(200, price({ gross: 5990, version: 3 }));
    await submit("59,90");
    expect(mocks.put).toHaveBeenLastCalledWith(PRICE, { params: { path: { variantId: "v-b" } }, body: { gross: 5990, version: 2 } });
  });

  describe("a 409 by its type", () => {
    const LOCKED = "tag:piotrp321.github.io,2026:shop/problems/price-locked-by-promotion";
    const DETAIL = "Cena wariantu w aktywnej promocji nie może się zmienić. Zakończ promocję, żeby zmienić cenę.";

    function conflictWith(body: unknown) {
      mocks.put.mockResolvedValue({ data: undefined, error: body, response: { status: 409 } });
    }

    it("shows the detail at the price field of a price locked by a promotion, without the concurrent-change message", async () => {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView();
      conflictWith({ type: LOCKED, title: "Conflict", status: 409, detail: DETAIL });

      await openDialog();
      await submit("59,90");

      expect(page().get("[data-testid=gross-error]").text()).toBe(DETAIL);
      expect(page().get("[data-testid=gross-input]").attributes("aria-invalid")).toBe("true");
      expect(page().find("[data-testid=change-conflict]").exists()).toBe(false);
      expect(page().find("[data-testid=change-dialog]").exists()).toBe(true);
    });

    it("does not reload the page after a price locked by a promotion", async () => {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView();
      conflictWith({ type: LOCKED, title: "Conflict", status: 409, detail: DETAIL });
      await openDialog();
      const callsBefore = priceCalls().length;

      await submit("59,90");

      expect(priceCalls()).toHaveLength(callsBefore);
      expect(page().get("[data-testid=price-row]").get("[data-testid=gross]").text()).toMatch(/^54,90\s*zł$/);
    });

    it.each([
      ["about:blank", { type: "about:blank", title: "Conflict", status: 409, detail: "Cena zmieniona." }],
      ["a problem of another type", { type: "tag:piotrp321.github.io,2026:shop/problems/promotion-overlap", title: "Conflict", status: 409, detail: "x" }],
      ["a body without a type", { status: 409 }],
    ])("keeps the concurrent-change message and the reload for %s", async (_name, body) => {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView();
      conflictWith(body);
      await openDialog();
      const callsBefore = priceCalls().length;

      await submit("59,90");

      expect(page().get("[data-testid=change-conflict]").text()).toBe("Cena została w międzyczasie zmieniona – odśwież");
      expect(page().find("[data-testid=gross-error]").exists()).toBe(false);
      expect(priceCalls()).toHaveLength(callsBefore + 1);
    });

    it("clears the lock message when the staff member sends the price again", async () => {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView();
      conflictWith({ type: LOCKED, title: "Conflict", status: 409, detail: DETAIL });
      await openDialog();
      await submit("59,90");

      answer(200, price({ gross: 5990, version: 2 }));
      await submit("59,90");

      expect(page().find("[data-testid=change-dialog]").exists()).toBe(false);
    });
  });

  it("on a 403 says the staff member may not change prices", async () => {
    serve({ pages: { "": { items: [item("A")], next: null } } });
    wrapper = await mountView();
    answer(403);

    await openDialog();
    await submit("59,90");

    expect(page().get("[data-testid=change-error]").text()).toContain("Nie masz uprawnień");
    expect(page().get("[data-testid=price-row]").get("[data-testid=gross]").text()).toMatch(/^54,90\s*zł$/);
  });

  it("says so when the request itself fails", async () => {
    serve({ pages: { "": { items: [item("A")], next: null } } });
    wrapper = await mountView();
    mocks.put.mockRejectedValue(new TypeError("network down"));

    await openDialog();
    await submit("59,90");

    expect(page().get("[data-testid=change-error]").text()).toContain("połączenie");
  });

  it("closes without sending anything when cancelled", async () => {
    serve({ pages: { "": { items: [item("A")], next: null } } });
    wrapper = await mountView();

    await openDialog();
    await page().get("[data-testid=gross-input]").setValue("59,90");
    await page().get("[data-testid=cancel-change]").trigger("click");
    await settle();

    expect(page().find("[data-testid=change-dialog]").exists()).toBe(false);
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
    await page().get("[data-testid=cancel-change]").trigger("click");
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

    const dialog = page().get("[data-testid=change-dialog]");
    expect(dialog.text()).toContain("B");
    // The list still takes the saved price of A.
    expect(page().findAll("[data-testid=price-row]")[0]!.get("[data-testid=gross]").text()).toMatch(/^59,90\s*zł$/);
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

    expect(page().get("[data-testid=change-dialog]").text()).toContain("B");
    expect(page().find("[data-testid=change-conflict]").exists()).toBe(false);
    expect(page().find("[data-testid=change-error]").exists()).toBe(false);
    expect(page().find("[data-testid=gross-error]").exists()).toBe(false);
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
    expect(mocks.put).toHaveBeenLastCalledWith(PRICE, { params: { path: { variantId: "variant-B" } }, body: { gross: 6990, version: 1 } });
    expect(page().find("[data-testid=change-dialog]").exists()).toBe(false);
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

    expect(page().find("[data-testid=change-dialog]").exists()).toBe(true);
    expect(page().find("[data-testid=gross-error]").exists()).toBe(false);
  });
});

describe("PricesView: price history", () => {
  let wrapper: Mounted | undefined;
  const HISTORY = "/api/v1/admin/variants/{variantId}/price-history";

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
    document.body.innerHTML = "";
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
  function serveHistory(histories: Record<string, HistoryPage> | number, items: PriceListItem[] = [item("A", { variantId: "v-1" })]) {
    mocks.get.mockImplementation(
      async (path: string, options?: { params?: { path?: { variantId: string }; query?: { after?: string } } }) => {
        if (path === CATEGORIES) {
          return { data: tree, response: { status: 200 } };
        }
        if (path === TAX_CLASSES) {
          return { data: TAX_CLASS_LIST, response: { status: 200 } };
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
    await page().findAll("[data-testid=show-history]")[row]!.trigger("click");
    await settle();
  }

  it.each([[["admin"]], [["marketing"]], [["support"]], [["warehouse"]], [[]]] as StaffMe["roles"][][])(
    "offers the history on every row to a staff member with the roles %j",
    async (roles) => {
      serveHistory({}, [item("A"), item("B")]);
      wrapper = await mountView(roles);

      expect(page().findAll("[data-testid=show-history]")).toHaveLength(2);
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

    expect(historyCalls()).toEqual([{ path: { variantId: "v-1" }, query: {} }]);
    const dialog = page().get("[data-testid=history-dialog]");
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

    const row = page().get("[data-testid=history-row]");
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
    const grosses = () => page().findAll("[data-testid=history-gross]").map((cell) => cell.text().replace(/\s/g, " "));

    expect(grosses()).toHaveLength(2);
    expect(page().get("[data-testid=history-more]").text()).toBe("Pokaż więcej");

    await page().get("[data-testid=history-more]").trigger("click");
    await settle();

    expect(historyCalls().at(-1)).toEqual({ path: { variantId: "v-1" }, query: { after: "c1" } });
    expect(grosses()).toHaveLength(3);
    expect(page().find("[data-testid=history-more]").exists()).toBe(false);
  });

  it("opens the history of the variant of the row, named by its product and label", async () => {
    serveHistory({ "": { items: [], next: null } }, [item("Buty", { variants: [variant("v-40", "40"), variant("v-41", "41")] })]);
    wrapper = await mountView();

    await openHistory(1);

    expect(historyCalls()).toEqual([{ path: { variantId: "v-41" }, query: {} }]);
    expect(page().get("[data-testid=history-dialog]").text()).toContain("Buty / 41");
  });

  it("says so when the product has no recorded price change", async () => {
    serveHistory({ "": { items: [], next: null } });
    wrapper = await mountView();

    await openHistory();

    expect(page().findAll("[data-testid=history-row]")).toHaveLength(0);
    expect(page().get("[data-testid=history-empty]").text()).toBe("Produkt nie ma jeszcze ceny.");
  });

  it("shows the error of the API and loads again on retry", async () => {
    serveHistory(500);
    wrapper = await mountView();

    await openHistory();

    expect(page().get("[data-testid=history-error]").text()).toContain("Nie udało się pobrać historii cen (HTTP 500).");
    expect(page().find("[data-testid=history-empty]").exists()).toBe(false);

    serveHistory({ "": { items: [entry(5490)], next: null } });
    await page().get("[data-testid=history-retry]").trigger("click");
    await settle();

    expect(page().find("[data-testid=history-error]").exists()).toBe(false);
    expect(page().findAll("[data-testid=history-row]")).toHaveLength(1);
  });

  it("keeps the entries and offers a retry when a further page fails", async () => {
    let calls = 0;
    mocks.get.mockImplementation(async (path: string, options?: { params?: { query?: { after?: string } } }) => {
      if (path === CATEGORIES) {
        return { data: tree, response: { status: 200 } };
      }
      if (path === TAX_CLASSES) {
        return { data: TAX_CLASS_LIST, response: { status: 200 } };
      }
      if (path === HISTORY) {
        calls++;
        return calls === 2
          ? { data: undefined, response: { status: 503 } }
          : { data: options?.params?.query?.after ? { items: [entry(1000)], next: null } : { items: [entry(2000)], next: "c1" }, response: { status: 200 } };
      }
      return { data: { items: [item("A", { variantId: "v-1" })], next: null }, response: { status: 200 } };
    });
    wrapper = await mountView();
    await openHistory();

    await page().get("[data-testid=history-more]").trigger("click");
    await settle();
    expect(page().findAll("[data-testid=history-row]")).toHaveLength(1);
    expect(page().get("[data-testid=history-error]").text()).toContain("HTTP 503");

    await page().get("[data-testid=history-retry]").trigger("click");
    await settle();
    expect(page().findAll("[data-testid=history-row]")).toHaveLength(2);
    expect(page().find("[data-testid=history-error]").exists()).toBe(false);
  });

  it("closes without asking again, and the next product starts from its own first page", async () => {
    serveHistory(
      { "": { items: [entry(5490)], next: "c1" }, c1: { items: [entry(1)], next: null } },
      [item("A", { variantId: "v-1" }), item("B", { variantId: "v-2" })],
    );
    wrapper = await mountView();
    await openHistory(0);
    await page().get("[data-testid=history-more]").trigger("click");
    await settle();
    expect(page().findAll("[data-testid=history-row]")).toHaveLength(2);

    await page().get("[data-testid=close-history]").trigger("click");
    await settle();
    expect(page().find("[data-testid=history-dialog]").exists()).toBe(false);

    await openHistory(1);
    expect(historyCalls().at(-1)).toEqual({ path: { variantId: "v-2" }, query: {} });
    expect(page().findAll("[data-testid=history-row]")).toHaveLength(1);
    expect(page().get("[data-testid=history-dialog]").text()).toContain("B");
  });

  it("leaves the history to every role but opens the price change only for an administrator", async () => {
    serveHistory({ "": { items: [entry(5490)], next: null } });
    wrapper = await mountView(["marketing"]);

    await openHistory();

    expect(page().find("[data-testid=change-price]").exists()).toBe(false);
    expect(page().findAll("[data-testid=history-row]")).toHaveLength(1);
  });

  it("is a dialog titled „Historia ceny” in the wide width, with the table in a named region", async () => {
    serveHistory({ "": { items: [entry(5490)], next: null } });
    wrapper = await mountView();

    await openHistory();

    const dialog = page().get("[role=dialog]");
    expect(dialog.attributes("data-testid")).toBe("history-dialog");
    expect(page().get("[role=dialog] [data-slot=dialog-title]").text()).toBe("Historia ceny");
    expect(dialog.classes()).toContain("w-[min(56rem,calc(100vw-2rem))]");
    expect(dialog.get("[role=region]").attributes("aria-label")).toBe("Historia ceny");
    expect(dialog.findAll("thead th").map((head) => head.text())).toEqual(["Data zmiany", "Brutto", "Netto", "VAT", "Stawka", "Klasa", "Źródło", "Pracownik"]);
  });

  it("keeps Data zmiany, Brutto and Źródło on a narrow screen and gives the rest as lines of the date cell", async () => {
    serveHistory({ "": { items: [entry(5490, { actorName: "Jan Kowalski" })], next: null } });
    wrapper = await mountView();

    await openHistory();

    const row = page().get("[data-testid=history-row]");
    const heads = page().findAll("[role=dialog] thead th");
    expect(heads.map((head) => head.classes().includes("hidden"))).toEqual([false, false, true, true, true, true, false, true]);
    expect(row.findAll("[data-testid^=line-]").map((line) => line.text().replace(/\s+/g, " "))).toEqual([
      "Netto: 52,29 zł",
      "VAT: 2,61 zł",
      "Stawka: 5%",
      "Klasa: printed_book",
      "Pracownik: Jan Kowalski",
    ]);
  });

  it("gives the focus back to „Historia” of the row when it closes", async () => {
    serveHistory({ "": { items: [entry(5490)], next: null } }, [item("A"), item("B")]);
    wrapper = await mountView();

    await openHistory(1);
    await page().get("[data-testid=close-history]").trigger("click");
    await settle();

    expect(document.activeElement).toBe(page().findAll("[data-testid=show-history]")[1]!.element);
  });
});
