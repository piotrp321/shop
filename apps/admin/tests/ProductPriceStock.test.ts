import { DOMWrapper, flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { type Mock, afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { RouterView, createMemoryHistory, createRouter } from "vue-router";
import { toast } from "vue-sonner";
import type { components } from "@/api/schema";
import { type StaffMe, useAuthStore } from "@/stores/auth";
import ProductFormView from "@/views/ProductFormView.vue";

type ProductEditView = components["schemas"]["ProductEditViewResponse"];
type PriceListItem = components["schemas"]["PriceListItemResponse"];
type StockListItem = components["schemas"]["StockListItemResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get, POST: mocks.post, PUT: mocks.put }) }));

const PRICES = "/api/v1/admin/prices";
const STOCK = "/api/v1/admin/stock";
const PRICE_OF = "/api/v1/admin/variants/{variantId}/price";
const PRICE_HISTORY = "/api/v1/admin/variants/{variantId}/price-history";
const TAX_CLASSES = "/api/v1/admin/tax-classes";
const RECEIPTS = "/api/v1/admin/variants/{variantId}/stock/receipts";

function product(overrides: Partial<ProductEditView> = {}): ProductEditView {
  return {
    id: "p-1",
    version: 3,
    status: "draft",
    firstPublishedAt: null,
    name: "Buty biegowe",
    slug: "buty-biegowe",
    slugLocked: false,
    shortDescription: "Lekkie buty.",
    mainCategoryId: "c-main",
    additionalCategoryIds: [],
    taxClass: "standard",
    images: [],
    variantAxes: ["Kolor"],
    variants: [
      { variantId: "v-1", values: ["czarne"] },
      { variantId: "v-2", values: ["białe"] },
    ],
    updatedAt: "2026-11-01T12:30:00+00:00",
    ...overrides,
  };
}

const price = (gross: number, version = 1) => ({ gross, net: Math.round(gross / 1.23), vat: gross - Math.round(gross / 1.23), vatRate: 2300, currency: "PLN", version, updatedAt: "2026-11-01T12:00:00+00:00", vatRateOutdated: false });
const stock = (physical: number, reserved: number, version = 1) => ({ physical, reserved, available: physical - reserved, level: "in_stock" as const, version, updatedAt: "2026-11-01T12:00:00+00:00" });

// v-1 has a price and a stock, v-2 has neither.
function priceItem(overrides: Partial<PriceListItem> = {}): PriceListItem {
  return {
    productId: "p-1",
    name: "Buty biegowe",
    taxClass: "standard",
    applicableVatRate: 2300,
    variants: [
      { variantId: "v-1", label: "czarne", price: price(12300) },
      { variantId: "v-2", label: "białe", price: null },
    ],
    ...overrides,
  };
}

function stockItem(): StockListItem {
  return {
    productId: "p-1",
    name: "Buty biegowe",
    variants: [
      { variantId: "v-1", label: "czarne", stock: stock(10, 4), matchesLevel: false },
      { variantId: "v-2", label: "białe", stock: null, matchesLevel: false },
    ],
  };
}

const page = (items: unknown[]) => ({ data: { items, next: null, total: { value: items.length, capped: false } }, response: { status: 200 } });

interface Served {
  product?: ProductEditView;
  prices?: PriceListItem[] | number;
  stocks?: StockListItem[] | number;
  taxClasses?: { code: string; name: string }[];
  historyTaxClass?: string;
}

function serve({ product: shown = product(), prices = [priceItem()], stocks = [stockItem()], taxClasses = [{ code: "standard", name: "Standardowa" }], historyTaxClass = "standard" }: Served = {}) {
  mocks.get.mockImplementation(async (path: string) => {
    if (path === "/api/v1/admin/categories") {
      return { data: [], response: { status: 200 } };
    }
    if (path === "/api/v1/admin/tax-classes") {
      return { data: taxClasses, response: { status: 200 } };
    }
    if (path === PRICE_HISTORY) {
      return {
        data: { items: [{ changedAt: "2026-11-01T12:30:00+00:00", currency: "PLN", gross: 12300, net: 10000, vat: 2300, vatRate: 2300, taxClass: historyTaxClass, source: "staff", actorName: "Anna Admin" }], next: null },
        response: { status: 200 },
      };
    }
    if (path === PRICES) {
      return typeof prices === "number" ? { data: undefined, response: { status: prices } } : page(prices);
    }
    if (path === STOCK) {
      return typeof stocks === "number" ? { data: undefined, response: { status: stocks } } : page(stocks);
    }
    return { data: shown, response: { status: 200 } };
  });
}

async function mountForm(path: string, roles: StaffMe["roles"] = ["admin"]) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().staff = { id: "u1", username: "anna", roles };
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/", name: "dashboard", component: empty },
      { path: "/products", name: "products", component: empty },
      { path: "/products/new", name: "product-new", component: ProductFormView },
      { path: "/products/:id", name: "product", component: ProductFormView },
    ],
  });
  await router.push(path);
  const wrapper = mount({ components: { RouterView }, template: "<RouterView />" }, { attachTo: document.body, global: { plugins: [pinia, router], stubs: { transition: false } } });
  await flushPromises();
  return { wrapper, router };
}

const card = () => [...document.body.querySelectorAll("section")].find((section) => section.querySelector("h2")?.textContent?.trim() === "Ceny i stany") ?? null;
const rowsOf = () => [...(card()?.querySelectorAll<HTMLElement>("[data-testid=price-stock-row]") ?? [])];
const text = (row: HTMLElement, testid: string) => row.querySelector(`[data-testid=${testid}]`)?.textContent?.replace(/\s+/g, " ").trim();
const actionsOf = (row: HTMLElement) => [...row.querySelectorAll<HTMLButtonElement>("[data-testid=change-price], [data-testid=receive-stock], [data-testid=correct-stock], [data-testid=show-price-history], [data-testid=show-stock-history]")].map((button) => button.getAttribute("aria-label"));
const byTestid = (testid: string) => document.body.querySelector<HTMLElement>(`[data-testid=${testid}]`);
const typeInto = (selector: string, value: string) => new DOMWrapper(document.body.querySelector<HTMLInputElement>(selector)!).setValue(value);
const h2Titles = () => [...document.body.querySelectorAll("h2")].map((heading) => heading.textContent?.trim());

describe("the card „Ceny i stany” of the product form", () => {
  let mounted: Awaited<ReturnType<typeof mountForm>> | undefined;
  let successToast: Mock<typeof toast.success>;

  beforeEach(() => {
    mocks.get.mockReset();
    mocks.post.mockReset();
    mocks.put.mockReset();
    Element.prototype.scrollIntoView = vi.fn();
    successToast = vi.spyOn(toast, "success").mockImplementation(() => 1);
    serve();
  });

  afterEach(() => {
    mounted?.wrapper.unmount();
    mounted = undefined;
    document.body.innerHTML = "";
    delete (Element.prototype as Partial<Element>).scrollIntoView;
    vi.restoreAllMocks();
  });

  it("reads the prices and the stocks of the product by its id and shows a row per saved variant under „Warianty”", async () => {
    mounted = await mountForm("/products/p-1");

    expect(mocks.get).toHaveBeenCalledWith(PRICES, { params: { query: { productId: "p-1" } } });
    expect(mocks.get).toHaveBeenCalledWith(STOCK, { params: { query: { productId: "p-1" } } });
    const titles = h2Titles();
    expect(titles.indexOf("Ceny i stany")).toBe(titles.indexOf("Warianty") + 1);
    const [black, white] = rowsOf();
    expect(text(black!, "variant-label")).toBe("czarne");
    expect(text(black!, "gross")).toContain("123,00");
    expect(text(black!, "net")).toContain("100,00");
    expect(text(black!, "vat")).toContain("23,00");
    expect(text(black!, "physical")).toBe("10");
    expect(text(black!, "reserved")).toBe("4");
    expect(text(black!, "available")).toBe("6");
    expect(text(black!, "level")).toBe("Dostępny");
    expect(text(white!, "variant-label")).toBe("białe");
    expect(text(white!, "physical")).toBe("—");
    expect(text(white!, "level")).toBe("—");
  });

  it("has no card on the page of a new product and shows it after the first save", async () => {
    mounted = await mountForm("/products/new");
    expect(card()).toBeNull();
    expect(mocks.get).not.toHaveBeenCalledWith(PRICES, expect.anything());
    mocks.post.mockResolvedValue({ data: product({ variantAxes: [], variants: [{ variantId: "v-1", values: [] }] }), response: { status: 201 } });
    serve({ prices: [priceItem({ variants: [{ variantId: "v-1", label: "", price: null }] })], stocks: [] });

    await mounted.wrapper.get("[data-testid=name]").setValue("Buty biegowe");
    await mounted.wrapper.get("[data-testid=save]").trigger("click");
    await flushPromises();

    expect(card()).not.toBeNull();
    expect(mocks.get).toHaveBeenCalledWith(PRICES, { params: { query: { productId: "p-1" } } });
    expect(rowsOf()).toHaveLength(1);
  });

  it("marks a variant without a price with „Bez ceny” and warns that such variants are unavailable in the shop", async () => {
    mounted = await mountForm("/products/p-1");

    const [black, white] = rowsOf();
    expect(black!.querySelector("[data-testid=no-price]")).toBeNull();
    expect(text(white!, "no-price")).toBe("Bez ceny");
    expect(byTestid("no-price-warning")?.textContent?.trim()).toBe("Warianty bez ceny są w sklepie niedostępne.");
  });

  it("has no warning when every variant has a price", async () => {
    serve({ prices: [priceItem({ variants: [{ variantId: "v-1", label: "czarne", price: price(12300) }, { variantId: "v-2", label: "białe", price: price(9900) }] })] });
    mounted = await mountForm("/products/p-1");

    expect(rowsOf()).toHaveLength(2);
    expect(byTestid("no-price-warning")).toBeNull();
    expect(byTestid("no-price")).toBeNull();
  });

  describe("the actions by role", () => {
    it.each([
      [["admin"], ["Zmień cenę: Buty biegowe / czarne", "Przyjęcie: Buty biegowe / czarne", "Korekta: Buty biegowe / czarne", "Historia ceny: Buty biegowe / czarne", "Historia stanu: Buty biegowe / czarne"]],
      [["warehouse"], ["Przyjęcie: Buty biegowe / czarne", "Korekta: Buty biegowe / czarne", "Historia ceny: Buty biegowe / czarne", "Historia stanu: Buty biegowe / czarne"]],
      [["marketing"], ["Historia ceny: Buty biegowe / czarne", "Historia stanu: Buty biegowe / czarne"]],
      [["support"], ["Historia ceny: Buty biegowe / czarne", "Historia stanu: Buty biegowe / czarne"]],
    ] as [StaffMe["roles"], string[]][])("offers %s only what the spec allows", async (roles, expected) => {
      mounted = await mountForm("/products/p-1", roles);

      expect(actionsOf(rowsOf()[0]!)).toEqual(expected);
    });
  });

  describe("a save in a window", () => {
    async function openPriceChange() {
      mounted = await mountForm("/products/p-1");
      await mounted.wrapper.get("[data-testid=name]").setValue("Inna nazwa");
      byTestid("change-price")!.click();
      await flushPromises();
    }

    it("changes the price in the row and leaves the typed fields and the unsaved state of the form as they were", async () => {
      await openPriceChange();
      mocks.put.mockResolvedValue({ data: price(15000, 2), response: { status: 200 } });
      await typeInto("[data-testid=gross-input]", "150,00");
      byTestid("save-price")!.click();
      await flushPromises();

      expect(mocks.put).toHaveBeenCalledWith(PRICE_OF, { params: { path: { variantId: "v-1" } }, body: { gross: 15000, version: 1 } });
      expect(successToast).toHaveBeenCalledWith("Cena zapisana: Buty biegowe / czarne");
      expect(text(rowsOf()[0]!, "gross")).toContain("150,00");
      expect((mounted!.wrapper.get("[data-testid=name]").element as HTMLInputElement).value).toBe("Inna nazwa");
      expect(document.body.querySelector("section[aria-label='Zapis formularza']")).not.toBeNull();
      expect(mounted!.wrapper.get("[data-testid=publish]").attributes("disabled")).toBeDefined();
      expect(byTestid("publish-reason")?.textContent).toContain("Najpierw zapisz zmiany.");
      expect(mocks.put).toHaveBeenCalledTimes(1);
    });

    it("does not make a clean form dirty", async () => {
      mounted = await mountForm("/products/p-1");
      byTestid("change-price")!.click();
      await flushPromises();
      mocks.put.mockResolvedValue({ data: price(15000, 2), response: { status: 200 } });
      await typeInto("[data-testid=gross-input]", "150");
      byTestid("save-price")!.click();
      await flushPromises();

      expect(text(rowsOf()[0]!, "gross")).toContain("150,00");
      expect(document.body.querySelector("section[aria-label='Zapis formularza']")).toBeNull();
      expect(byTestid("publish-reason")).toBeNull();
    });

    it("receives stock with an Idempotency-Key and shows the new stock in the row", async () => {
      mounted = await mountForm("/products/p-1", ["warehouse"]);
      byTestid("receive-stock")!.click();
      await flushPromises();
      mocks.post.mockResolvedValue({ data: { variantId: "v-1", ...stock(15, 4, 2) }, response: { status: 201 } });
      await typeInto("#receive-quantity", "5");
      byTestid("receive-submit")!.click();
      await flushPromises();

      expect(mocks.post).toHaveBeenCalledWith(RECEIPTS, expect.objectContaining({ params: { path: { variantId: "v-1" }, header: { "Idempotency-Key": expect.any(String) } }, body: { quantity: 5, note: null } }));
      expect(text(rowsOf()[0]!, "physical")).toBe("15");
      expect(text(rowsOf()[0]!, "available")).toBe("11");
      expect(successToast).toHaveBeenCalledWith("Przyjęto 5 szt.: Buty biegowe / czarne");
    });

    it("reads the card again after a price conflict and says so in the window", async () => {
      mounted = await mountForm("/products/p-1");
      byTestid("change-price")!.click();
      await flushPromises();
      mocks.put.mockResolvedValue({ data: undefined, error: { type: "about:blank" }, response: { status: 409 } });
      serve({ prices: [priceItem({ variants: [{ variantId: "v-1", label: "czarne", price: price(13000, 2) }, { variantId: "v-2", label: "białe", price: null }] })] });
      await typeInto("[data-testid=gross-input]", "150");
      byTestid("save-price")!.click();
      await flushPromises();

      expect(byTestid("change-conflict")?.textContent).toContain("Cena została w międzyczasie zmieniona");
      expect(text(rowsOf()[0]!, "gross")).toContain("130,00");
    });
  });

  it("says that a variant row of the editor that is not saved has no price and stock to set, and does not list it", async () => {
    mounted = await mountForm("/products/p-1");
    expect(byTestid("unsaved-variants-note")).toBeNull();

    await mounted.wrapper.get("[data-testid=add-variant]").trigger("click");
    await flushPromises();

    expect(byTestid("unsaved-variants-note")?.textContent?.trim()).toBe("Zapisz produkt, żeby ustawić cenę i stan.");
    expect(rowsOf()).toHaveLength(2);
  });

  it("reads the prices and stocks again after the product is saved", async () => {
    mounted = await mountForm("/products/p-1");
    const reads = () => mocks.get.mock.calls.filter(([path]) => path === PRICES).length;
    expect(reads()).toBe(1);
    mocks.put.mockResolvedValue({ data: product({ version: 4, name: "Inna nazwa" }), response: { status: 200 } });
    serve({ product: product({ version: 4 }), prices: [priceItem({ variants: [{ variantId: "v-1", label: "czarne", price: price(11100, 2) }, { variantId: "v-2", label: "białe", price: null }] })] });

    await mounted.wrapper.get("[data-testid=name]").setValue("Inna nazwa");
    await mounted.wrapper.get("[data-testid=save]").trigger("click");
    await flushPromises();

    expect(reads()).toBe(2);
    expect(mocks.get.mock.calls.filter(([path]) => path === STOCK)).toHaveLength(2);
    expect(text(rowsOf()[0]!, "gross")).toContain("111,00");
  });

  it("shows an error with a retry when the prices cannot be read, and the rows after the retry", async () => {
    serve({ prices: 500 });
    mounted = await mountForm("/products/p-1");

    expect(rowsOf()).toHaveLength(0);
    expect(byTestid("price-stock-error")?.textContent).toContain("Nie udało się pobrać cen i stanów (HTTP 500).");
    serve();
    (byTestid("price-stock-error")!.querySelector("button") as HTMLButtonElement).click();
    await flushPromises();

    expect(byTestid("price-stock-error")).toBeNull();
    expect(rowsOf()).toHaveLength(2);
  });

  it("shows a message without a retry for a 403", async () => {
    serve({ stocks: 403 });
    mounted = await mountForm("/products/p-1");

    expect(byTestid("price-stock-error")?.textContent).toContain("Nie masz dostępu do cen i stanów magazynowych.");
    expect(byTestid("price-stock-error")?.querySelector("button")).toBeNull();
  });

  it("says a product without saved variants has none", async () => {
    serve({ prices: [], stocks: [] });
    mounted = await mountForm("/products/p-1");

    expect(byTestid("price-stock-empty")?.textContent).toContain("Produkt nie ma jeszcze zapisanych wariantów.");
  });

  describe("the tax class in the price history", () => {
    async function openHistory(served: Served = {}) {
      serve(served);
      mounted = await mountForm("/products/p-1");
      byTestid("show-price-history")!.click();
      await flushPromises();
      return document.body.querySelector<HTMLElement>("[data-testid=history-row]")!;
    }

    it("shows the Polish name from the dictionary the form loaded, in the column and in the line of the date cell, without a second request", async () => {
      const row = await openHistory();

      expect(text(row, "history-tax-class")).toBe("Standardowa");
      expect(text(row, "line-taxClass")).toBe("Klasa: Standardowa");
      expect(mocks.get.mock.calls.filter(([path]) => path === TAX_CLASSES)).toHaveLength(1);
    });

    it("shows the code when the dictionary of the form is empty", async () => {
      const row = await openHistory({ taxClasses: [] });

      expect(text(row, "history-tax-class")).toBe("standard");
      expect(text(row, "line-taxClass")).toBe("Klasa: standard");
    });

    it("shows the code of a tax class the dictionary does not have", async () => {
      const row = await openHistory({ historyTaxClass: "energy_bar" });

      expect(text(row, "history-tax-class")).toBe("energy_bar");
      expect(text(row, "line-taxClass")).toBe("Klasa: energy_bar");
    });
  });
});
