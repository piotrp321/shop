import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import type { components } from "@/api/schema";
import CategoryCombobox from "@/components/CategoryCombobox.vue";
import { Select } from "@/components/ui/select";
import { SEARCH_DEBOUNCE_MS } from "@/products/useProductList";
import { type StaffMe, useAuthStore } from "@/stores/auth";
import ProductsView from "@/views/ProductsView.vue";

type ProductList = components["schemas"]["ProductListResponse"];
type ProductListItem = components["schemas"]["ProductListItemResponse"];
type CategoryNode = components["schemas"]["CategoryNode"];

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

const PRODUCTS = "/api/v1/admin/products";
const CATEGORIES = "/api/v1/admin/categories";

const tree: CategoryNode[] = [{ id: "c-sport", name: "Sport", slug: "sport", path: "sport", children: [] }];

function item(name: string, overrides: Partial<ProductListItem> = {}): ProductListItem {
  return {
    id: `id-${name}`,
    name,
    slug: name.toLowerCase(),
    status: "published",
    mainCategory: { id: "c-sport", name: "Sport" },
    variantCount: 1,
    image: null,
    updatedAt: "2026-11-01T12:30:00+00:00",
    ...overrides,
  };
}

/** A full page of 25 products, so that „Pokazano X–Y” has a range to show. */
function fullPage(prefix: string, next: string | null): ProductList {
  return { items: Array.from({ length: 25 }, (_, index) => item(`${prefix}${index}`)), next };
}

function serve(pages: Record<string, ProductList>, status?: number, categories: CategoryNode[] | number = tree) {
  mocks.get.mockImplementation(async (path: string, options?: { params?: { query?: { after?: string } } }) => {
    if (path === CATEGORIES) {
      return typeof categories === "number" ? { data: undefined, response: { status: categories } } : { data: categories, response: { status: 200 } };
    }
    if (status) {
      return { data: undefined, response: { status } };
    }
    return { data: pages[options?.params?.query?.after ?? ""], response: { status: 200 } };
  });
}

function productQueries(): Record<string, unknown>[] {
  return mocks.get.mock.calls.filter(([path]) => path === PRODUCTS).map(([, options]) => (options as { params: { query: Record<string, unknown> } }).params.query);
}

async function settle() {
  await vi.advanceTimersByTimeAsync(0);
  await flushPromises();
}

async function mountView(roles: StaffMe["roles"] = ["admin"], address = "/products") {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().staff = { id: "u1", username: "anna", roles };
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/products", name: "products", component: empty },
      { path: "/products/new", name: "product-new", component: empty },
      { path: "/products/:id", name: "product", component: empty },
      { path: "/", name: "dashboard", component: empty },
    ],
  });
  await router.push(address);
  const wrapper = mount(ProductsView, { global: { plugins: [pinia, router] }, attachTo: document.body });
  await settle();
  return Object.assign(wrapper, { router });
}

type Mounted = Awaited<ReturnType<typeof mountView>>;

const IMAGE = {
  alt: "Buty",
  size: "thumbnail",
  aspectRatio: "1:1",
  variants: { webp: [{ width: 96, url: "https://img.test/buty-96.webp" }] },
};

describe("ProductsView", () => {
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

  const statusSelect = () => wrapper!.findAllComponents(Select)[0]!;
  const perPageSelect = () => wrapper!.findAllComponents(Select)[1]!;

  describe("the rows", () => {
    it("shows a row per product with the thumbnail, the linked name, the slug, the main category, the variant count, the status and the last change", async () => {
      serve({
        "": {
          items: [item("Buty", { variantCount: 3, image: IMAGE, status: "published" }), item("Szkic", { status: "draft" })],
          next: null,
        },
      });
      wrapper = await mountView();

      const rows = wrapper.findAll("[data-testid=product-row]");
      expect(rows).toHaveLength(2);
      expect(rows[0]!.get("[data-testid=product-image]").attributes("src")).toBe("https://img.test/buty-96.webp");
      expect(rows[0]!.get("[data-testid=product-link]").text()).toBe("Buty");
      expect(rows[0]!.get("[data-testid=product-link]").attributes("href")).toBe("/products/id-Buty");
      expect(rows[0]!.get("[data-testid=product-slug]").text()).toBe("buty");
      expect(rows[0]!.get("[data-testid=product-category]").text()).toBe("Sport");
      expect(rows[0]!.get("[data-testid=variant-count]").text()).toBe("3");
      expect(rows[0]!.get("[data-testid=product-status]").text()).toBe("Opublikowany");
      expect(rows[0]!.get("[data-testid=product-updated]").text()).toBe("01.11.2026, 13:30:00");
      expect(rows[1]!.get("[data-testid=product-status]").text()).toBe("Szkic");
    });

    it("shows a placeholder of the same size for a product without a photo", async () => {
      serve({ "": { items: [item("Buty", { image: IMAGE }), item("Bidon")], next: null } });
      wrapper = await mountView();

      const rows = wrapper.findAll("[data-testid=product-row]");
      expect(rows[0]!.find("[data-testid=product-placeholder]").exists()).toBe(false);
      const placeholder = rows[1]!.get("[data-testid=product-placeholder]");
      expect(rows[1]!.find("[data-testid=product-image]").exists()).toBe(false);
      expect(placeholder.classes()).toContain("size-10.5");
      expect(rows[0]!.get("[data-testid=product-image]").classes()).toContain("size-10.5");
    });

    it("shows the date of the last change with the time, seconds included, under it", async () => {
      serve({ "": { items: [item("Buty")], next: null } });
      wrapper = await mountView();

      const spans = wrapper.get("[data-testid=product-updated]").findAll("span");
      expect(spans[0]!.text()).toBe("01.11.2026");
      expect(spans.at(-1)!.text()).toBe("13:30:00");
    });

    it("names the actions with the product, with a tooltip, and previews only a published product in the store", async () => {
      serve({ "": { items: [item("Buty", { slug: "buty-trailowe" }), item("Szkic", { status: "draft" })], next: null } });
      wrapper = await mountView();

      const rows = wrapper.findAll("[data-testid=product-row]");
      const edit = rows[0]!.get("[data-testid=action-edit]");
      expect(edit.attributes("aria-label")).toBe("Edytuj: Buty");
      expect(edit.attributes("href")).toBe("/products/id-Buty");
      const preview = rows[0]!.get("[data-testid=action-preview]");
      expect(preview.attributes("href")).toBe("http://store.test/p/buty-trailowe");
      expect(preview.attributes("target")).toBe("_blank");
      expect(preview.attributes("rel")).toBe("noopener");
      expect(preview.attributes("aria-label")).toContain("Podgląd w sklepie: Buty");
      expect(rows[1]!.get("[data-testid=action-edit]").attributes("aria-label")).toBe("Edytuj: Szkic");
      expect(rows[1]!.find("[data-testid=action-preview]").exists()).toBe(false);
    });

    it("keeps the category and the last change as lines of the main cell for the narrow screens", async () => {
      serve({ "": { items: [item("Buty")], next: null } });
      wrapper = await mountView();

      const main = wrapper.get("[data-testid=product-row]").findAll("td")[0]!;
      expect(main.get("[data-testid=line-category]").text()).toBe("Kategoria: Sport");
      expect(main.get("[data-testid=line-category]").classes()).toContain("lg:hidden");
      expect(main.get("[data-testid=line-updated]").text()).toBe("Ostatnia zmiana: 01.11.2026, 13:30:00");
      expect(main.get("[data-testid=line-updated]").classes()).toContain("min-[900px]:hidden");
      const heads = wrapper.findAll("th");
      expect(heads.map((head) => head.text())).toEqual(["Produkt", "Kategoria", "Warianty", "Status", "Ostatnia zmiana", "Akcje"]);
      expect(heads[1]!.classes()).toContain("lg:table-cell");
      expect(heads[4]!.classes()).toContain("min-[900px]:table-cell");
    });

    it("scrolls in a region named by the list", async () => {
      serve({ "": { items: [item("Buty")], next: null } });
      wrapper = await mountView();

      const region = wrapper.get("[role=region]");
      expect(region.attributes("aria-label")).toBe("Lista produktów");
      expect(region.attributes("tabindex")).toBe("0");
    });
  });

  describe("the add button", () => {
    it.each([
      ["admin", true],
      ["marketing", true],
      ["support", false],
      ["warehouse", false],
    ] as const)("%s sees the add button: %s", async (role, visible) => {
      serve({ "": { items: [item("A")], next: null } });
      wrapper = await mountView([role]);

      const button = wrapper.find("[data-testid=add-product]");
      expect(button.exists()).toBe(visible);
      if (visible) {
        expect(button.text()).toContain("Dodaj produkt");
        expect(button.attributes("href")).toBe("/products/new");
      }
    });
  });

  describe("the toolbar", () => {
    it("filters by status, by category and by search, each from the first page", async () => {
      serve({ "": { items: [item("A")], next: null } });
      wrapper = await mountView();

      statusSelect().vm.$emit("update:modelValue", "draft");
      await settle();
      expect(productQueries().at(-1)).toEqual({ status: "draft" });

      wrapper.getComponent(CategoryCombobox).vm.$emit("update:modelValue", "c-sport");
      await settle();
      expect(productQueries().at(-1)).toEqual({ category: "c-sport", status: "draft" });

      await wrapper.get("[data-testid=search]").setValue("buty");
      await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 100);
      await settle();
      expect(productQueries().at(-1)).toEqual({ q: "buty", category: "c-sport", status: "draft" });

      statusSelect().vm.$emit("update:modelValue", "all");
      await settle();
      expect(productQueries().at(-1)).toEqual({ q: "buty", category: "c-sport" });
    });

    it("has a category field with a visible label, and an accessible status field with the three statuses", async () => {
      serve({ "": { items: [], next: null } });
      wrapper = await mountView();

      const category = wrapper.getComponent(CategoryCombobox);
      expect(category.props("label")).toBe("Kategoria");
      const status = wrapper.get<HTMLButtonElement>("[data-testid=status-filter]");
      expect(status.element.labels?.[0]?.textContent?.trim()).toBe("Status");
      expect(status.text()).toBe("Wszystkie");

      status.element.dispatchEvent(new PointerEvent("pointerdown", { bubbles: true, cancelable: true, button: 0, ctrlKey: false }));
      await settle();
      const options = [...document.body.querySelectorAll("[role=option]")].map((option) => option.textContent?.trim());
      expect(options).toEqual(["Wszystkie", "Szkice", "Opublikowane"]);
    });

    it("hints that a search needs two characters, in a status region", async () => {
      serve({ "": { items: [], next: null } });
      wrapper = await mountView();

      await wrapper.get("[data-testid=search]").setValue("a");

      const hint = wrapper.get("[data-testid=search-hint]");
      expect(hint.text()).toContain("2 znaki");
      expect(hint.attributes("role")).toBe("status");
    });

    it("tells that the filter of categories is not available when the tree did not load, and keeps the list", async () => {
      serve({ "": { items: [item("A")], next: null } }, undefined, 500);
      wrapper = await mountView();

      expect(wrapper.get("[data-testid=categories-error]").text()).toContain("Filtr kategorii jest niedostępny");
      expect(wrapper.findAll("[data-testid=product-row]")).toHaveLength(1);
    });

    it("shows „Wyczyść filtry” only while a filter or a search is set", async () => {
      serve({ "": { items: [item("A")], next: null } });
      wrapper = await mountView();
      expect(wrapper.find("[data-testid=clear-filters]").exists()).toBe(false);

      statusSelect().vm.$emit("update:modelValue", "draft");
      await settle();
      expect(wrapper.find("[data-testid=clear-filters]").exists()).toBe(true);
    });

    it("clears the filters, the search and the page size with „Wyczyść filtry” and puts the focus in the search", async () => {
      serve({ "": { items: [item("A")], next: null } });
      wrapper = await mountView(["admin"], "/products?q=buty&status=draft&perPage=10");
      expect(productQueries()).toEqual([{ q: "buty", status: "draft", perPage: 10 }]);

      await wrapper.get("[data-testid=clear-filters]").trigger("click");
      await settle();

      expect(wrapper.router.currentRoute.value.fullPath).toBe("/products");
      expect(productQueries().at(-1)).toEqual({});
      expect(wrapper.get<HTMLInputElement>("[data-testid=search]").element.value).toBe("");
      expect(document.activeElement).toBe(wrapper.get("[data-testid=search]").element);
    });
  });

  describe("the address", () => {
    it("shows the search, the filters and the page size of the address, with one request", async () => {
      serve({ "": { items: [item("A")], next: null } });
      wrapper = await mountView(["admin"], "/products?q=buty&category=c-sport&status=published&perPage=10");

      expect(productQueries()).toEqual([{ q: "buty", category: "c-sport", status: "published", perPage: 10 }]);
      expect(wrapper.get<HTMLInputElement>("[data-testid=search]").element.value).toBe("buty");
      expect(statusSelect().text()).toBe("Opublikowane");
      expect(perPageSelect().text()).toBe("10");
      expect(wrapper.get<HTMLInputElement>("[role=combobox]").element.value).toBe("Sport");
    });

    it("writes a change of the filter to the address without a new history entry", async () => {
      serve({ "": { items: [item("A")], next: null } });
      wrapper = await mountView();
      const push = vi.spyOn(wrapper.router, "push");
      const replace = vi.spyOn(wrapper.router, "replace");

      statusSelect().vm.$emit("update:modelValue", "draft");
      await settle();

      expect(replace).toHaveBeenCalledTimes(1);
      expect(push).not.toHaveBeenCalled();
      expect(wrapper.router.currentRoute.value.query).toEqual({ status: "draft" });
    });

    it("skips a category that does not exist", async () => {
      serve({ "": { items: [item("A")], next: null } });
      wrapper = await mountView(["admin"], "/products?category=gone");

      expect(productQueries()).toEqual([{}]);
      expect(wrapper.router.currentRoute.value.query).toEqual({});
    });

    it("follows the search of the topbar while the list is open, from the first page, with one request", async () => {
      serve({ "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: null } });
      wrapper = await mountView();
      await wrapper.get("[data-testid=next-page]").trigger("click");
      await settle();
      mocks.get.mockClear();

      await wrapper.router.push({ path: "/products", query: { q: "buty" } });
      await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 100);
      await settle();

      expect(productQueries()).toEqual([{ q: "buty" }]);
      expect(wrapper.get<HTMLInputElement>("[data-testid=search]").element.value).toBe("buty");
      expect(wrapper.get("[data-testid=page-number]").text()).toBe("Strona 1");
    });

    it("changes the page size from the first page", async () => {
      serve({ "": { items: [item("A")], next: null } });
      wrapper = await mountView();

      perPageSelect().vm.$emit("update:perPage", 10);
      perPageSelect().vm.$emit("update:modelValue", "10");
      await settle();

      expect(productQueries().at(-1)).toEqual({ perPage: 10 });
      expect(wrapper.router.currentRoute.value.query).toEqual({ perPage: "10" });
    });
  });

  describe("the footer", () => {
    it("pages with the cursor stack and numbers the rows: „Pokazano 26–50” on the second page of 25", async () => {
      serve({ "": fullPage("A", "c1"), c1: fullPage("B", null) });
      wrapper = await mountView();
      expect(wrapper.get("[role=status][data-testid=range]").text()).toBe("Pokazano 1–25");

      await wrapper.get("[data-testid=next-page]").trigger("click");
      await settle();
      expect(wrapper.get("[data-testid=product-link]").text()).toBe("B0");
      expect(wrapper.get("[data-testid=page-number]").text()).toBe("Strona 2");
      expect(wrapper.get("[data-testid=range]").text()).toBe("Pokazano 26–50");

      await wrapper.get("[data-testid=previous-page]").trigger("click");
      await settle();
      expect(wrapper.get("[data-testid=product-link]").text()).toBe("A0");
      expect(productQueries()).toEqual([{}, { after: "c1" }, {}]);
    });

    it("keeps the focus on „Następna strona” after the move, and moves it to „Poprzednia strona” on the last page", async () => {
      serve({ "": fullPage("A", "c1"), c1: fullPage("B", "c2"), c2: fullPage("C", null) });
      wrapper = await mountView();
      const next = wrapper.get<HTMLButtonElement>("[data-testid=next-page]");
      next.element.focus();

      await next.trigger("click");
      await settle();
      expect(document.activeElement).toBe(next.element);

      await next.trigger("click");
      await settle();
      expect(next.element.disabled).toBe(true);
      expect(document.activeElement).toBe(wrapper.get("[data-testid=previous-page]").element);
    });

    it("has no pager for a list without rows", async () => {
      serve({ "": { items: [], next: null } });
      wrapper = await mountView();

      expect(wrapper.find("[data-testid=next-page]").exists()).toBe(false);
    });
  });

  describe("the empty states", () => {
    it.each([
      ["admin", true],
      ["marketing", true],
      ["support", false],
    ] as const)("says there are no products yet, with the add button for %s: %s", async (role, visible) => {
      serve({ "": { items: [], next: null } });
      wrapper = await mountView([role]);

      expect(wrapper.get("[data-testid=empty]").text()).toContain("Nie ma jeszcze produktów.");
      expect(wrapper.get("[data-testid=empty]").find("[data-testid=add-product]").exists()).toBe(visible);
    });

    it("says there are no results for the filters, with „Wyczyść filtry”", async () => {
      serve({ "": { items: [], next: null } });
      wrapper = await mountView(["admin"], "/products?status=draft");

      const empty = wrapper.get("[data-testid=empty]");
      expect(empty.text()).toContain("Brak wyników dla wybranych filtrów.");
      expect(empty.text()).not.toContain("Nie ma jeszcze produktów.");
      expect(empty.find("[data-testid=add-product]").exists()).toBe(false);

      await empty.get("[data-testid=clear-filters]").trigger("click");
      await settle();
      expect(wrapper.router.currentRoute.value.fullPath).toBe("/products");
      expect(document.activeElement).not.toBe(document.body);
    });
  });

  describe("the errors", () => {
    it("shows only the alert, without rows and pager, when the list fails, and a retry brings the rows (#251 F10)", async () => {
      serve({}, 500);
      wrapper = await mountView();

      const alert = wrapper.get("[data-testid=list-error]");
      expect(alert.attributes("role")).toBe("alert");
      expect(alert.text()).toContain("HTTP 500");
      expect(wrapper.find("table").exists()).toBe(false);
      expect(wrapper.find("[data-testid=next-page]").exists()).toBe(false);

      serve({ "": { items: [item("A")], next: null } });
      await wrapper.get("[data-testid=retry]").trigger("click");
      await settle();
      expect(wrapper.find("[data-testid=list-error]").exists()).toBe(false);
      expect(wrapper.get("[data-testid=product-link]").text()).toBe("A");
    });

    it("hides the rows of the old query when a change of the filter fails (#251 F10)", async () => {
      serve({ "": { items: [item("A")], next: "c1" } });
      wrapper = await mountView();
      expect(wrapper.findAll("[data-testid=product-row]")).toHaveLength(1);

      serve({}, 500);
      statusSelect().vm.$emit("update:modelValue", "draft");
      await settle();

      expect(wrapper.find("[data-testid=list-error]").exists()).toBe(true);
      expect(wrapper.find("[data-testid=product-row]").exists()).toBe(false);
      expect(wrapper.find("[data-testid=next-page]").exists()).toBe(false);
    });

    it("keeps the page that is shown when a neighbouring page fails", async () => {
      serve({ "": { items: [item("A")], next: "c1" } });
      wrapper = await mountView();

      serve({}, 500);
      await wrapper.get("[data-testid=next-page]").trigger("click");
      await settle();

      expect(wrapper.get("[data-testid=list-error]").text()).toContain("HTTP 500");
      expect(wrapper.get("[data-testid=product-link]").text()).toBe("A");
      expect(wrapper.get("[data-testid=page-number]").text()).toBe("Strona 1");
    });

    it("moves the focus to the message when the retry ends in a 403, never to the body (#337)", async () => {
      serve({}, 500);
      wrapper = await mountView();
      const retry = wrapper.get<HTMLButtonElement>("[data-testid=retry]");
      retry.element.focus();

      serve({}, 403);
      await retry.trigger("click");
      await settle();

      expect(wrapper.find("[data-testid=retry]").exists()).toBe(false);
      const alert = wrapper.get("[data-testid=list-error]");
      expect(alert.text()).toContain("Nie masz dostępu do produktów.");
      expect(document.activeElement).toBe(alert.element);
    });
  });

  it("shows a skeleton of rows while the first page loads", async () => {
    mocks.get.mockImplementation((path: string) => (path === CATEGORIES ? Promise.resolve({ data: tree, response: { status: 200 } }) : new Promise(() => {})));
    wrapper = await mountView();

    expect(wrapper.find("[data-testid=list-loading]").exists()).toBe(true);
    expect(wrapper.find("table").exists()).toBe(false);
  });
});
