import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import PrimeVue from "primevue/config";
import SelectButton from "primevue/selectbutton";
import TreeSelect from "primevue/treeselect";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import type { components } from "@/api/schema";
import { primeVueOptions } from "@/primevue";
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

function serve(pages: Record<string, ProductList>, status?: number) {
  mocks.get.mockImplementation(async (path: string, options?: { params?: { query?: { after?: string } } }) => {
    if (path === CATEGORIES) {
      return { data: tree, response: { status: 200 } };
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

async function mountView(roles: StaffMe["roles"] = ["admin"]) {
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
    ],
  });
  await router.push("/products");
  const wrapper = mount(ProductsView, { global: { plugins: [pinia, router, [PrimeVue, primeVueOptions]] as never[] } });
  await settle();
  return wrapper;
}

describe("ProductsView", () => {
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

  it("shows a row per product with the thumbnail, the linked name, the main category, the variant count, the status and the last change", async () => {
    const image = {
      alt: "Buty",
      size: "thumbnail",
      aspectRatio: "1:1",
      variants: { webp: [{ width: 96, url: "https://img.test/buty-96.webp" }] },
    };
    serve({
      "": {
        items: [
          item("Buty", { variantCount: 3, image, status: "published", updatedAt: "2026-11-01T12:30:00+00:00" }),
          item("Szkic", { status: "draft" }),
        ],
        next: null,
      },
    });
    wrapper = await mountView();

    const rows = wrapper.findAll("[data-testid=product-row]");
    expect(rows).toHaveLength(2);
    expect(rows[0]!.get("[data-testid=product-image]").attributes("src")).toBe("https://img.test/buty-96.webp");
    expect(rows[0]!.get("[data-testid=product-link]").text()).toBe("Buty");
    expect(rows[0]!.get("[data-testid=product-link]").attributes("href")).toBe("/products/id-Buty");
    expect(rows[0]!.get("[data-testid=product-category]").text()).toBe("Sport");
    expect(rows[0]!.get("[data-testid=variant-count]").text()).toBe("3");
    expect(rows[0]!.get("[data-testid=product-status]").text()).toBe("Opublikowany");
    expect(rows[0]!.get("[data-testid=product-updated]").text()).toBe("01.11.2026, 13:30:00");
    expect(rows[1]!.get("[data-testid=product-status]").text()).toBe("Szkic");
    expect(rows[1]!.find("[data-testid=product-image]").exists()).toBe(false);
  });

  it.each([
    ["admin", true],
    ["marketing", true],
    ["support", false],
    ["warehouse", false],
  ] as const)("%s sees the add button: %s", async (role, visible) => {
    serve({ "": { items: [], next: null } });
    wrapper = await mountView([role]);

    const button = wrapper.find("[data-testid=add-product]");
    expect(button.exists()).toBe(visible);
    if (visible) {
      expect(button.text()).toContain("Dodaj produkt");
      expect(button.attributes("href")).toBe("/products/new");
    }
  });

  it("filters by status, by category and by search, each from the first page", async () => {
    serve({ "": { items: [item("A")], next: null } });
    wrapper = await mountView();

    wrapper.getComponent(SelectButton).vm.$emit("update:modelValue", "draft");
    await settle();
    expect(productQueries().at(-1)).toEqual({ status: "draft" });

    wrapper.getComponent(TreeSelect).vm.$emit("update:modelValue", { "c-sport": true });
    await settle();
    expect(productQueries().at(-1)).toEqual({ category: "c-sport", status: "draft" });

    await wrapper.get("[data-testid=search]").setValue("buty");
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 100);
    await settle();
    expect(productQueries().at(-1)).toEqual({ q: "buty", category: "c-sport", status: "draft" });

    wrapper.getComponent(SelectButton).vm.$emit("update:modelValue", "all");
    await settle();
    expect(productQueries().at(-1)).toEqual({ q: "buty", category: "c-sport" });
  });

  it("offers the three statuses of the filter", async () => {
    serve({ "": { items: [], next: null } });
    wrapper = await mountView();

    const labels = wrapper.get("[data-testid=status-filter]").text();
    expect(labels).toContain("Wszystkie");
    expect(labels).toContain("Szkice");
    expect(labels).toContain("Opublikowane");
  });

  it("hints that a search needs two characters", async () => {
    serve({ "": { items: [], next: null } });
    wrapper = await mountView();

    await wrapper.get("[data-testid=search]").setValue("a");

    expect(wrapper.get("[data-testid=search-hint]").text()).toContain("2 znaki");
  });

  it("pages with the cursor stack", async () => {
    serve({ "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: null } });
    wrapper = await mountView();

    await wrapper.get("[data-testid=next-page]").trigger("click");
    await settle();
    expect(wrapper.get("[data-testid=product-link]").text()).toBe("B");
    expect(wrapper.get("[data-testid=page-number]").text()).toBe("Strona 2");

    await wrapper.get("[data-testid=previous-page]").trigger("click");
    await settle();
    expect(wrapper.get("[data-testid=product-link]").text()).toBe("A");
    expect(productQueries()).toEqual([{}, { after: "c1" }, {}]);
  });

  it("says when nothing matches", async () => {
    serve({ "": { items: [], next: null } });
    wrapper = await mountView();

    expect(wrapper.get("[data-testid=empty]").text()).toContain("Brak produktów");
  });

  it("shows a failure with a retry", async () => {
    serve({}, 500);
    wrapper = await mountView();

    expect(wrapper.get("[data-testid=products-error]").text()).toContain("HTTP 500");
    serve({ "": { items: [item("A")], next: null } });
    await wrapper.get("[data-testid=retry]").trigger("click");
    await settle();
    expect(wrapper.find("[data-testid=products-error]").exists()).toBe(false);
    expect(wrapper.get("[data-testid=product-link]").text()).toBe("A");
  });
});
