import { DOMWrapper, flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import type { components } from "@/api/schema";
import CategoryCombobox from "@/components/CategoryCombobox.vue";
import { Select } from "@/components/ui/select";
import { SEARCH_DEBOUNCE_MS } from "@/inventory/useStockList";
import { type StaffMe, useAuthStore } from "@/stores/auth";
import InventoryView from "@/views/InventoryView.vue";
import type { WithOptionalTotal } from "./listPage";

type StockList = WithOptionalTotal<components["schemas"]["StockListResponse"]>;
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

/** A product with one default variant (empty label) unless `variants` names others; `stock` is that variant's. */
function item(name: string, overrides: { stock?: ListedStock | null; variants?: StockListItem["variants"] } = {}): StockListItem {
  return {
    productId: `id-${name}`,
    name,
    variants: overrides.variants ?? [{ variantId: `var-${name}`, label: "", stock: overrides.stock === undefined ? stock() : overrides.stock, matchesLevel: false }],
  };
}

const tree: CategoryNode[] = [
  { id: "c-sport", name: "Sport", slug: "sport", path: "sport", children: [{ id: "c-run", name: "Bieganie", slug: "bieganie", path: "sport/bieganie", children: [] }] },
  { id: "c-food", name: "Jedzenie", slug: "jedzenie", path: "jedzenie", children: [] },
];

interface Backend {
  pages: Record<string, StockList>;
  status?: number;
  categories?: CategoryNode[] | "error";
}

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

function stockQueries(): Record<string, unknown>[] {
  return mocks.get.mock.calls
    .filter(([path]) => path === STOCK)
    .map(([, options]) => (options as { params: { query: Record<string, unknown> } }).params.query);
}

async function settle() {
  await vi.advanceTimersByTimeAsync(0);
  await flushPromises();
}

/** Everything is in the body: the windows are teleported there, so a query goes through the whole document. */
const page = () => new DOMWrapper(document.body);

async function mountView(roles: StaffMe["roles"] = ["support"], address = "/inventory") {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().staff = { id: "u1", username: "anna", roles };
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/inventory", name: "inventory", component: empty },
      { path: "/", name: "dashboard", component: empty },
    ],
  });
  await router.push(address);
  const wrapper = mount(InventoryView, { global: { plugins: [pinia, router] }, attachTo: document.body });
  await settle();
  return Object.assign(wrapper, { router });
}

type Mounted = Awaited<ReturnType<typeof mountView>>;

describe("InventoryView", () => {
  let wrapper: Mounted | undefined;

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    mocks.get.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    document.body.innerHTML = "";
    vi.useRealTimers();
  });

  it("loads the first page of 10 products and shows physical, reserved, available and the level the API gave", async () => {
    serve({
      pages: {
        "": {
          items: [
            item("Bidon"),
            item("Żel", { stock: stock({ physical: 3, reserved: 3, available: 0, level: "out_of_stock" }) }),
            item("Baton", { stock: stock({ physical: 9, reserved: 4, available: 5, level: "low_stock" }) }),
          ],
          next: null,
        },
      },
    });
    wrapper = await mountView();

    expect(stockQueries()).toEqual([{ perPage: 10 }]);
    const rows = page().findAll("[data-testid=stock-row]");
    const cells = (row: (typeof rows)[number]) => ["physical", "reserved", "available", "level"].map((id) => row.get(`[data-testid=${id}]`).text());
    expect(cells(rows[0]!)).toEqual(["10", "4", "6", "Dostępny"]);
    expect(cells(rows[1]!)).toEqual(["3", "3", "0", "Niedostępny"]);
    expect(cells(rows[2]!)).toEqual(["9", "4", "5", "Ostatnie sztuki"]);
  });

  it("shows the level as a Badge in the colour of the level", async () => {
    serve({
      pages: {
        "": {
          items: [
            item("A"),
            item("B", { stock: stock({ physical: 0, available: 0, level: "out_of_stock" }) }),
            item("C", { stock: stock({ level: "low_stock" }) }),
          ],
          next: null,
        },
      },
    });
    wrapper = await mountView();

    const levels = page().findAll("[data-testid=level]");
    expect(levels.map((level) => level.attributes("data-slot"))).toEqual(["badge", "badge", "badge"]);
    expect(levels[0]!.classes()).toContain("bg-status-success-soft");
    expect(levels[1]!.classes()).toContain("bg-status-danger-soft");
    expect(levels[2]!.classes()).toContain("bg-status-warning-soft");
  });

  it("shows a row per variant under the row of its product, with the label in the 'Wariant' column", async () => {
    serve({
      pages: {
        "": {
          items: [
            item("Kajak", {
              variants: [
                { variantId: "v-green", label: "zielony", stock: stock({ physical: 0, reserved: 0, available: 0, level: "out_of_stock" }), matchesLevel: false },
                { variantId: "v-orange", label: "pomarańczowy", stock: null, matchesLevel: false },
              ],
            }),
            item("Bidon"),
          ],
          next: null,
        },
      },
    });
    wrapper = await mountView();

    const rows = page().findAll("[data-testid=stock-row]");
    expect(rows).toHaveLength(3);
    expect(page().findAll("[data-testid=product-row] [data-testid=name]").map((cell) => cell.text())).toEqual(["Kajak", "Bidon"]);
    expect(page().get("[data-testid=product-row] th").attributes("scope")).toBe("rowgroup");
    expect(page().findAll("tbody tr").map((row) => row.attributes("data-testid"))).toEqual(["product-row", "stock-row", "stock-row", "product-row", "stock-row"]);
    expect(rows.every((row) => !row.find("[data-testid=name]").exists())).toBe(true);
    expect(rows.map((row) => row.get("[data-testid=variant-label]").text())).toEqual(["zielony", "pomarańczowy", "Wariant domyślny"]);
    expect(rows.map((row) => row.get("[data-testid=physical]").text())).toEqual(["0", "—", "10"]);
    expect(page().get("thead").text()).toContain("Wariant");
  });

  it("has no actions in the row of a product: the buttons are in the rows of the variants", async () => {
    serve({ pages: { "": { items: [item("Buty", { variants: [{ variantId: "v-40", label: "40", stock: stock(), matchesLevel: false }, { variantId: "v-41", label: "41", stock: stock(), matchesLevel: false }] })], next: null } } });
    wrapper = await mountView(["admin"]);

    expect(page().get("[data-testid=product-row]").findAll("button, a")).toHaveLength(0);
    expect(page().findAll("[data-testid=stock-row]").map((row) => row.findAll("button").length)).toEqual([3, 3]);
  });

  it("shows a dash in the numbers and no level label for a variant without a stock record", async () => {
    serve({ pages: { "": { items: [item("Nowy", { stock: null })], next: null } } });
    wrapper = await mountView();

    const row = page().get("[data-testid=stock-row]");
    expect(["physical", "reserved", "available", "level"].map((id) => row.get(`[data-testid=${id}]`).text())).toEqual(["—", "—", "—", "—"]);
    expect(row.get("[data-testid=level]").attributes("data-slot")).toBeUndefined();
    expect(row.get("[data-testid=line-reserved]").text()).toBe("Zarezerwowane: —");
  });

  it("gives Zarezerwowane as a line of the cell of the variant, for a narrow screen, with its label", async () => {
    serve({ pages: { "": { items: [item("Buty")], next: null } } });
    wrapper = await mountView();

    const row = page().get("[data-testid=stock-row]");
    expect(row.get("[data-testid=line-reserved]").text()).toBe("Zarezerwowane: 4");
    expect(row.get("[data-testid=line-reserved]").classes()).toContain("lg:hidden");
    const heads = page().findAll("thead th");
    expect(heads.map((head) => head.text())).toEqual(["Wariant", "Fizycznie", "Zarezerwowane", "Dostępne", "Poziom", "Akcje"]);
    expect(heads[2]!.classes()).toEqual(expect.arrayContaining(["hidden", "lg:table-cell"]));
  });

  it("shows the empty state of an empty list and the way to clear the filters of a filtered one", async () => {
    serve({ pages: { "": { items: [], next: null } } });
    wrapper = await mountView();

    expect(page().findAll("[data-testid=stock-row]")).toHaveLength(0);
    expect(page().get("[data-testid=empty]").text()).toContain("Nie ma jeszcze produktów z wariantami.");
    wrapper.unmount();

    wrapper = await mountView(["support"], "/inventory?q=nic");
    expect(page().get("[data-testid=empty]").text()).toContain("Brak wyników dla wybranych filtrów.");
    expect(page().find("[data-testid=empty-clear-filters]").exists()).toBe(true);
  });

  it("pages with the Next and Previous buttons, keeping the cursor stack", async () => {
    serve({ pages: { "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: null } } });
    wrapper = await mountView();
    const names = () => page().findAll("[data-testid=product-row] [data-testid=name]").map((cell) => cell.text());

    expect(page().get("[data-testid=previous-page]").attributes("disabled")).toBeDefined();
    await page().get("[data-testid=next-page]").trigger("click");
    await settle();
    expect(names()).toEqual(["B"]);
    expect(stockQueries().at(-1)).toEqual({ perPage: 10, after: "c1" });
    expect(page().get("[data-testid=next-page]").attributes("disabled")).toBeDefined();

    await page().get("[data-testid=previous-page]").trigger("click");
    await settle();
    expect(names()).toEqual(["A"]);
    expect(page().get("[data-testid=page-number]").text()).toBe("Strona 1");
  });

  describe("„Pokazano produkty X–Y”", () => {
    const productsPage = (prefix: string, next: string | null): StockList => ({
      items: Array.from({ length: 10 }, (_, index) =>
        item(`${prefix}${index}`, { variants: [{ variantId: `${prefix}${index}-a`, label: "a", stock: null, matchesLevel: false }, { variantId: `${prefix}${index}-b`, label: "b", stock: null, matchesLevel: false }] }),
      ),
      next,
    });

    it("counts products, not variants: the second page of 10 products is 11–20", async () => {
      serve({ pages: { "": productsPage("A", "c1"), c1: productsPage("B", null) } });
      wrapper = await mountView();
      expect(page().get("[data-testid=range]").text()).toBe("Pokazano produkty 1–10");
      expect(page().findAll("[data-testid=stock-row]")).toHaveLength(20);

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

  describe("the state of the list is in the address", () => {
    it("shows the search, the category, the page size and the results of an address", async () => {
      serve({ pages: { "": { items: [item("Bidon")], next: null } } });
      wrapper = await mountView(["support"], "/inventory?q=bidon&category=c-run&perPage=25");

      expect(stockQueries()).toEqual([{ q: "bidon", category: "c-run", perPage: 25 }]);
      expect(page().get<HTMLInputElement>("[data-testid=search]").element.value).toBe("bidon");
      expect(page().get("[data-testid=per-page]").text()).toBe("25");
      expect(wrapper.getComponent(CategoryCombobox).props("modelValue")).toBe("c-run");
      expect(page().findAll("[data-testid=stock-row]")).toHaveLength(1);
      expect(page().find("[data-testid=clear-filters]").exists()).toBe(true);
    });

    it("shows the category of the address and its results (/inventory?category=<id>)", async () => {
      serve({ pages: { "": { items: [item("Baton")], next: null } } });
      wrapper = await mountView(["support"], "/inventory?category=c-food");

      expect(stockQueries()).toEqual([{ category: "c-food", perPage: 10 }]);
      expect(wrapper.getComponent(CategoryCombobox).props("modelValue")).toBe("c-food");
      expect(page().findAll("input").map((input) => (input.element as HTMLInputElement).value)).toContain("Jedzenie");
    });

    it("filters by the category chosen from the tree, writing it to the address without a new entry of the history", async () => {
      serve({ pages: { "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: null } } });
      wrapper = await mountView();
      await page().get("[data-testid=next-page]").trigger("click");
      await settle();
      const position = wrapper.router.options.history.state.position;

      const filter = wrapper.getComponent(CategoryCombobox);
      expect(filter.props("nodes")).toEqual([
        { key: "c-sport", label: "Sport", children: [{ key: "c-run", label: "Bieganie", children: [] }] },
        { key: "c-food", label: "Jedzenie", children: [] },
      ]);
      expect(filter.props("label")).toBe("Kategoria");
      filter.vm.$emit("update:modelValue", "c-run");
      await settle();

      expect(stockQueries().at(-1)).toEqual({ category: "c-run", perPage: 10 });
      expect(wrapper.router.currentRoute.value.query).toEqual({ category: "c-run" });
      expect(wrapper.router.options.history.state.position, "replace, not push").toBe(position);
      expect(page().get("[data-testid=previous-page]").attributes("disabled")).toBeDefined();

      filter.vm.$emit("update:modelValue", null);
      await settle();
      expect(stockQueries().at(-1)).toEqual({ perPage: 10 });
    });

    it("searches from two characters after a pause and hints for a shorter term", async () => {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView();
      const search = page().get("[data-testid=search]");

      await search.setValue("b");
      await vi.advanceTimersByTimeAsync(1000);
      expect(stockQueries()).toHaveLength(1);
      expect(page().get("[data-testid=search-hint]").text()).toContain("2 znaki");

      await search.setValue("bidon");
      await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 100);
      await settle();
      expect(stockQueries().at(-1)).toEqual({ q: "bidon", perPage: 10 });
      expect(page().get("[data-testid=search-hint]").text()).toBe("");
    });

    it("clears the search, the filters and the page size with „Wyczyść filtry” and puts the focus in the search field", async () => {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView(["support"], "/inventory?q=bidon&perPage=50");

      await page().get("[data-testid=clear-filters]").trigger("click");
      await settle();

      expect(wrapper.router.currentRoute.value.fullPath).toBe("/inventory");
      expect(stockQueries().at(-1)).toEqual({ perPage: 10 });
      expect(page().get<HTMLInputElement>("[data-testid=search]").element.value).toBe("");
      expect(document.activeElement).toBe(page().get("[data-testid=search]").element);
    });

    it("has a Select „Poziom” with a visible label, „Wszystkie”, „Bez stanu” and „Niski stan”", async () => {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView();

      const trigger = page().get<HTMLButtonElement>("[data-testid=level-filter]");
      expect(trigger.element.labels?.[0]?.textContent?.trim()).toBe("Poziom");
      expect(trigger.text()).toBe("Wszystkie");
      trigger.element.dispatchEvent(new PointerEvent("pointerdown", { bubbles: true, cancelable: true, button: 0, ctrlKey: false }));
      await settle();
      const options = [...document.body.querySelectorAll("[role=option]")].map((option) => option.textContent?.trim());
      expect(options).toEqual(["Wszystkie", "Bez stanu", "Niski stan"]);
    });

    it("clears and disables the search and the category when a level is chosen, says why, and asks for the level alone", async () => {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView(["support"], "/inventory?q=buty&category=c-run&sort=-name");
      expect(page().get<HTMLInputElement>("[data-testid=search]").element.value).toBe("buty");
      expect(page().get("[data-testid=level-hint]").text()).toBe("");

      wrapper.findAllComponents(Select)[0]!.vm.$emit("update:modelValue", "out_of_stock");
      await settle();

      expect(stockQueries().at(-1)).toEqual({ perPage: 10, level: "out_of_stock" });
      expect(wrapper.router.currentRoute.value.fullPath).toBe("/inventory?level=out_of_stock");
      const search = page().get<HTMLInputElement>("[data-testid=search]");
      expect(search.element.value).toBe("");
      expect(search.element.disabled).toBe(true);
      const category = page().get<HTMLInputElement>("[data-testid=category-filter] input");
      expect(category.element.disabled).toBe(true);
      const description = "Filtr poziomu nie łączy się z wyszukiwaniem ani kategorią.";
      const hint = page().get("[data-testid=level-hint]");
      expect(hint.text()).toBe(description);
      expect(hint.attributes("role")).toBe("status");
      expect(page().get("[data-testid=search-hint]").text()).toBe(description);
      expect(search.attributes("aria-describedby")).toBe(page().get("[data-testid=search-hint]").attributes("id"));
      expect(category.attributes("aria-describedby")?.split(" ")).toContain(hint.attributes("id"));
      expect(page().get("[data-testid=level-filter]").text()).toBe("Bez stanu");
    });

    it("enables the search and the category again when the level is dropped", async () => {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView(["support"], "/inventory?level=low_stock");
      expect(page().get<HTMLInputElement>("[data-testid=search]").element.disabled).toBe(true);
      expect(page().get("[data-testid=level-filter]").text()).toBe("Niski stan");

      wrapper.findAllComponents(Select)[0]!.vm.$emit("update:modelValue", "all");
      await settle();

      expect(stockQueries().at(-1)).toEqual({ perPage: 10 });
      expect(wrapper.router.currentRoute.value.fullPath).toBe("/inventory");
      expect(page().get<HTMLInputElement>("[data-testid=search]").element.disabled).toBe(false);
      expect(page().get<HTMLInputElement>("[data-testid=category-filter] input").element.disabled).toBe(false);
      expect(page().get("[data-testid=level-hint]").text()).toBe("");
    });

    it("shows „Wyczyść filtry” for a level and clears it", async () => {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView(["support"], "/inventory?level=out_of_stock");

      await page().get("[data-testid=clear-filters]").trigger("click");
      await settle();

      expect(wrapper.router.currentRoute.value.fullPath).toBe("/inventory");
      expect(page().find("[data-testid=clear-filters]").exists()).toBe(false);
    });

    it("disables the orders of the sort while a level is on, with its reason, and shows the order of the level", async () => {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView(["support"], "/inventory?level=low_stock");

      expect(page().get("[data-testid=sort]").text()).toBe("Wg produktu");
      expect(page().get("[data-testid=sort-hint]").text()).toBe("Filtr poziomu ma własną kolejność.");
    });

    it("marks the variants at the level of the filter", async () => {
      serve({
        pages: {
          "": {
            items: [
              item("Kajak", {
                variants: [
                  { variantId: "v-green", label: "zielony", stock: stock({ physical: 2, reserved: 2, available: 0, level: "out_of_stock" }), matchesLevel: true },
                  { variantId: "v-orange", label: "pomarańczowy", stock: stock(), matchesLevel: false },
                ],
              }),
            ],
            next: null,
          },
        },
      });
      wrapper = await mountView(["support"], "/inventory?level=out_of_stock");

      const rows = page().findAll("[data-testid=stock-row]");
      expect(rows.map((row) => row.get("[data-testid=level]").attributes("data-matches-level"))).toEqual(["true", "false"]);
      expect(rows[0]!.text()).toContain("pasuje do filtra");
      expect(rows[1]!.text()).not.toContain("pasuje do filtra");
    });

    it("has a Select „Sortuj” by the name or the last change, which sets the address and asks once from the first page", async () => {
      serve({ pages: { "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: null } } });
      wrapper = await mountView();
      const trigger = page().get<HTMLButtonElement>("[data-testid=sort]");
      expect(trigger.element.labels?.[0]?.textContent?.trim()).toBe("Sortuj");
      expect(trigger.text()).toBe("Nazwa A–Z");
      await page().get("[data-testid=next-page]").trigger("click");
      await settle();

      wrapper.findAllComponents(Select)[1]!.vm.$emit("update:modelValue", "-updatedAt");
      await settle();

      expect(stockQueries().at(-1)).toEqual({ perPage: 10, sort: "-updatedAt" });
      expect(stockQueries().filter((query) => query.sort === "-updatedAt")).toHaveLength(1);
      expect(page().get("[data-testid=page-number]").text()).toBe("Strona 1");
      expect(wrapper.router.currentRoute.value.fullPath).toBe("/inventory?sort=-updatedAt");
      expect(page().get("[data-testid=sort]").text()).toBe("Ostatnio zmienione");
      expect(page().findAll("th[aria-sort]"), "the name is in the row of the group, not in a column").toHaveLength(0);
    });

    it("applies a sort that the address brings from outside with one request, over the rows of the groups", async () => {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView();
      mocks.get.mockClear();

      await wrapper.router.push("/inventory?sort=-name");
      await settle();

      expect(stockQueries()).toEqual([{ perPage: 10, sort: "-name" }]);
      expect(page().get("[data-testid=sort]").text()).toBe("Nazwa Z–A");
    });

    it("goes back to the first page when the page size changes, and asks for the size always", async () => {
      serve({ pages: { "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: null } } });
      wrapper = await mountView();
      await page().get("[data-testid=next-page]").trigger("click");
      await settle();

      wrapper.findAllComponents(Select)[2]!.vm.$emit("update:modelValue", "25");
      await settle();

      expect(stockQueries().at(-1)).toEqual({ perPage: 25 });
      expect(page().get("[data-testid=page-number]").text()).toBe("Strona 1");
      expect(wrapper.router.currentRoute.value.query).toEqual({ perPage: "25" });
    });
  });

  describe("the actions of a row", () => {
    it("names the buttons with the product and the variant, and the default variant with the product only", async () => {
      serve({ pages: { "": { items: [item("Namiot", { variants: [{ variantId: "v-g", label: "zielony", stock: stock(), matchesLevel: false }] }), item("Bidon")], next: null } } });
      wrapper = await mountView(["warehouse"]);

      const names = (testid: string) => page().findAll(`[data-testid=${testid}]`).map((button) => button.attributes("aria-label"));
      expect(names("receive-stock")).toEqual(["Przyjęcie: Namiot / zielony", "Przyjęcie: Bidon"]);
      expect(names("correct-stock")).toEqual(["Korekta: Namiot / zielony", "Korekta: Bidon"]);
      expect(names("show-history")).toEqual(["Historia: Namiot / zielony", "Historia: Bidon"]);
    });

    it.each([["admin"], ["warehouse"]] as const)("offers Przyjęcie and Korekta to %s", async (role) => {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView([role]);

      expect(page().find("[data-testid=receive-stock]").exists()).toBe(true);
      expect(page().find("[data-testid=correct-stock]").exists()).toBe(true);
      expect(page().find("[data-testid=show-history]").exists()).toBe(true);
    });

    it.each([[["support"]], [["marketing"]], [[]]] as StaffMe["roles"][][])("offers only Historia to the roles %j", async (roles) => {
      serve({ pages: { "": { items: [item("A")], next: null } } });
      wrapper = await mountView(roles);

      expect(page().find("[data-testid=receive-stock]").exists()).toBe(false);
      expect(page().find("[data-testid=correct-stock]").exists()).toBe(false);
      expect(page().find("[data-testid=show-history]").exists()).toBe(true);
    });
  });

  describe("errors of the list", () => {
    it("shows the error, keeps the page it was on, and retries", async () => {
      serve({ pages: { "": { items: [item("A")], next: "c1" } } });
      wrapper = await mountView();

      serve({ pages: {}, status: 500 });
      await page().get("[data-testid=next-page]").trigger("click");
      await settle();
      expect(page().get("[data-testid=stock-error]").text()).toContain("HTTP 500");
      expect(page().findAll("[data-testid=product-row] [data-testid=name]").map((cell) => cell.text())).toEqual(["A"]);

      serve({ pages: { c1: { items: [item("B")], next: null } } });
      await page().get("[data-testid=retry]").trigger("click");
      await settle();

      expect(page().find("[data-testid=stock-error]").exists()).toBe(false);
      expect(page().findAll("[data-testid=product-row] [data-testid=name]").map((cell) => cell.text())).toEqual(["B"]);
    });

    it("shows the error of a failed search with no rows and no pager of the old list (#272)", async () => {
      serve({ pages: { "": { items: [item("A")], next: "c1" } } });
      wrapper = await mountView();
      expect(page().findAll("[data-testid=stock-row]")).toHaveLength(1);

      serve({ pages: {}, status: 500 });
      await page().get("[data-testid=search]").setValue("bidon");
      await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 50);
      await settle();

      expect(page().get("[data-testid=stock-error]").text()).toContain("Nie udało się pobrać stanów magazynowych");
      expect(page().findAll("[data-testid=stock-row]")).toHaveLength(0);
      expect(page().find("[data-testid=next-page]").exists()).toBe(false);
    });

    it("answers a 403 without a retry to offer", async () => {
      serve({ pages: {}, status: 403 });
      wrapper = await mountView();

      expect(page().get("[data-testid=stock-error]").text()).toContain("Nie masz dostępu do stanów magazynowych.");
      expect(page().find("[data-testid=retry]").exists()).toBe(false);
    });

    it("still lists the stock when the category tree cannot be loaded, and says the filter is unavailable", async () => {
      serve({ pages: { "": { items: [item("A")], next: null } }, categories: "error" });
      wrapper = await mountView();

      expect(page().findAll("[data-testid=stock-row]")).toHaveLength(1);
      expect(page().get("[data-testid=categories-error]").text()).toContain("kategorii");
    });
  });
});
