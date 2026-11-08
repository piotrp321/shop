import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import PrimeVue from "primevue/config";
import ToggleSwitch from "primevue/toggleswitch";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter, RouterView } from "vue-router";
import type { components } from "@/api/schema";
import { primeVueOptions } from "@/primevue";
import { SEARCH_DEBOUNCE_MS } from "@/prices/usePriceList";
import { type StaffMe, useAuthStore } from "@/stores/auth";
import PromotionFormView from "@/views/PromotionFormView.vue";

type Promotion = components["schemas"]["PromotionResponse"];
type PromotionVariant = components["schemas"]["PromotionVariantResponse"];
type PriceListItem = components["schemas"]["PriceListItemResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }));

vi.mock("@/api/client", () => ({
  getApiClient: () => ({ GET: mocks.get, POST: mocks.post, PUT: mocks.put, DELETE: mocks.delete }),
}));

const PROMOTION = "/api/v1/admin/promotions/{id}";
const PROMOTIONS = "/api/v1/admin/promotions";
const PRICES = "/api/v1/admin/prices";
const PREFIX = "tag:piotrp321.github.io,2026:shop/problems/";

function variant(id: string, overrides: Partial<PromotionVariant> = {}): PromotionVariant {
  return { variantId: id, productId: `p-${id}`, productName: `Produkt ${id}`, label: "", regularGross: 10000, promotionalGross: 8000, ...overrides };
}

function promotion(overrides: Partial<Promotion> = {}): Promotion {
  return {
    id: "promo-1",
    name: "Jesień w górach",
    percentOff: 20,
    startsAt: "2026-11-10T23:00:00+00:00",
    endsAt: "2026-11-20T23:00:00+00:00",
    status: "planned",
    variantCount: 2,
    version: 4,
    updatedAt: "2026-11-07T12:30:00+00:00",
    updatedByName: "ola.marketing",
    variants: [variant("v-1"), variant("v-2", { label: "Zielony" })],
    ...overrides,
  };
}

const price = { gross: 12000, net: 9756, vat: 2244, vatRate: 2300, currency: "PLN", version: 1, updatedAt: "2026-11-01T12:30:00+00:00", vatRateOutdated: false };

const catalog: PriceListItem[] = [
  {
    productId: "prod-a",
    name: "Namiot Tarvik",
    taxClass: "standard",
    applicableVatRate: 2300,
    variants: [
      { variantId: "a-green", label: "Zielony", price },
      { variantId: "a-red", label: "Czerwony", price },
      { variantId: "a-none", label: "Bez ceny", price: null },
    ],
  },
  { productId: "prod-b", name: "Śpiwór", taxClass: "standard", applicableVatRate: 2300, variants: [{ variantId: "b-1", label: "", price }] },
];

function serve(data: Promotion | null = promotion(), prices: PriceListItem[] = catalog) {
  mocks.get.mockImplementation(async (path: string) => {
    if (path === PRICES) {
      return { data: { items: prices, next: null }, response: { status: 200 } };
    }
    return data ? { data, response: { status: 200 } } : { data: undefined, error: {}, response: { status: 404 } };
  });
}

function conflict(kind: string, detail: string, extra: Record<string, unknown> = {}) {
  return {
    data: undefined,
    error: { type: kind === "about:blank" ? kind : `${PREFIX}${kind}`, title: "Conflict", status: 409, detail, ...extra },
    response: { status: 409 },
  };
}

async function settle() {
  await vi.advanceTimersByTimeAsync(0);
  await flushPromises();
}

async function mountForm(path: string, roles: StaffMe["roles"] = ["marketing"]) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().staff = { id: "u1", username: "anna", roles };
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/promotions", name: "promotions", component: empty },
      { path: "/promotions/new", name: "promotion-new", component: PromotionFormView },
      { path: "/promotions/:id", name: "promotion", component: PromotionFormView },
    ],
  });
  await router.push(path);
  const wrapper = mount(
    { components: { RouterView }, template: "<RouterView />" },
    { attachTo: document.body, global: { plugins: [pinia, router, [PrimeVue, primeVueOptions]] as never[] } },
  );
  await settle();
  return { wrapper, router };
}

describe("PromotionFormView", () => {
  let mounted: Awaited<ReturnType<typeof mountForm>> | undefined;

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout", "Date"] });
    vi.setSystemTime(new Date("2026-11-15T10:20:30.000Z"));
    for (const mock of Object.values(mocks)) {
      mock.mockReset();
    }
    serve();
  });

  afterEach(() => {
    mounted?.wrapper.unmount();
    mounted = undefined;
    vi.useRealTimers();
  });

  async function open(path = "/promotions/promo-1", roles?: StaffMe["roles"], data?: Promotion | null) {
    if (data !== undefined) {
      serve(data);
    }
    mounted = await mountForm(path, roles);
    return mounted.wrapper;
  }

  const click = async (wrapper: Awaited<ReturnType<typeof open>>, testid: string) => {
    await wrapper.get(`[data-testid=${testid}]`).trigger("click");
    await settle();
  };

  function rows(wrapper: Awaited<ReturnType<typeof open>>) {
    return wrapper.findAll("[data-testid=variant-row]");
  }

  describe("a promotion", () => {
    it("shows its fields, its times in Polish time and its variants with the prices from the API", async () => {
      const wrapper = await open();

      expect(wrapper.get("h1").text()).toBe("Jesień w górach");
      expect((wrapper.get("[data-testid=name-input]").element as HTMLInputElement).value).toBe("Jesień w górach");
      expect((wrapper.get("[data-testid=percent-input]").element as HTMLInputElement).value).toBe("20");
      expect((wrapper.get("[data-testid=starts-input]").element as HTMLInputElement).value).toBe("2026-11-11T00:00");
      expect((wrapper.get("[data-testid=ends-input]").element as HTMLInputElement).value).toBe("2026-11-21T00:00");
      expect(wrapper.get("[data-testid=promotion-status]").text()).toBe("Zaplanowana");
      expect(wrapper.text()).toContain("Czas w strefie Europe/Warsaw");
      const first = rows(wrapper)[0]!;
      expect(first.get("[data-testid=variant-name]").text()).toBe("Produkt v-1");
      expect(first.get("[data-testid=variant-regular]").text()).toMatch(/^100,00\s*zł$/);
      expect(first.get("[data-testid=variant-promotional]").text()).toMatch(/^80,00\s*zł$/);
      expect(rows(wrapper)[1]!.get("[data-testid=variant-label]").text()).toBe("Zielony");
    });

    it("marks a variant deleted from the catalog and shows no price it does not have", async () => {
      const wrapper = await open(
        "/promotions/promo-1",
        undefined,
        promotion({
          variants: [variant("v-1"), variant("v-gone", { productId: null, productName: null, label: null, regularGross: null, promotionalGross: null })],
        }),
      );

      const gone = rows(wrapper)[1]!;
      expect(gone.get("[data-testid=variant-deleted]").text()).toBe("Wariant usunięty");
      expect(gone.find("[data-testid=variant-name]").exists()).toBe(false);
      expect(gone.get("[data-testid=variant-regular]").text()).toBe("—");
      expect(gone.get("[data-testid=variant-promotional]").text()).toBe("—");
    });

    it("shows no promotional price for an ended promotion", async () => {
      const wrapper = await open(
        "/promotions/promo-1",
        undefined,
        promotion({ status: "ended", variants: [variant("v-1", { promotionalGross: null })] }),
      );

      expect(rows(wrapper)[0]!.get("[data-testid=variant-promotional]").text()).toBe("—");
    });

    it("says so when the promotion does not exist", async () => {
      const wrapper = await open("/promotions/nope", undefined, null);

      expect(wrapper.get("[data-testid=load-error]").text()).toBe("Ta promocja nie istnieje.");
      expect(wrapper.find("[data-testid=name-input]").exists()).toBe(false);
    });

    it("saves a changed name and percent with the version and shows the saved promotion", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=name-input]").setValue("Zimowa wyprzedaż");
      await wrapper.get("[data-testid=percent-input]").setValue("30");
      mocks.put.mockResolvedValue({ data: promotion({ name: "Zimowa wyprzedaż", percentOff: 30, version: 5 }), response: { status: 200 } });

      await click(wrapper, "save");

      expect(mocks.put).toHaveBeenCalledWith(PROMOTION, {
        params: { path: { id: "promo-1" } },
        body: expect.objectContaining({ name: "Zimowa wyprzedaż", percentOff: 30, version: 4 }),
      });
      expect(wrapper.get("[data-testid=success-message]").text()).toBe("Promocja zapisana.");
      expect(wrapper.get("h1").text()).toBe("Zimowa wyprzedaż");
    });

    it("sends the end typed in Polish time as UTC", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=ends-input]").setValue("2026-11-22T08:30");
      mocks.put.mockResolvedValue({ data: promotion(), response: { status: 200 } });

      await click(wrapper, "save");

      expect(mocks.put.mock.calls[0]![1].body.endsAt).toBe("2026-11-22T07:30:00Z");
    });

    it("starts a planned promotion now and disables the start field while „od teraz” is on", async () => {
      const wrapper = await open();
      wrapper.getComponent(ToggleSwitch).vm.$emit("update:modelValue", true);
      await settle();
      expect(wrapper.get("[data-testid=starts-input]").attributes("disabled")).toBeDefined();
      mocks.put.mockResolvedValue({ data: promotion({ status: "active" }), response: { status: 200 } });

      await click(wrapper, "save");

      expect(mocks.put.mock.calls[0]![1].body.startsAt).toBeNull();
    });

    it("keeps the save button off until something changes", async () => {
      const wrapper = await open();
      expect(wrapper.get("[data-testid=save]").attributes("disabled")).toBeDefined();

      await wrapper.get("[data-testid=name-input]").setValue("Inna");

      expect(wrapper.get("[data-testid=save]").attributes("disabled")).toBeUndefined();
    });
  });

  describe("an active promotion", () => {
    const active = () => promotion({ status: "active" });

    it("locks the percent, the start and the variants but not the name and the end", async () => {
      const wrapper = await open("/promotions/promo-1", undefined, active());

      expect(wrapper.get("[data-testid=percent-input]").attributes("disabled")).toBeDefined();
      expect(wrapper.get("[data-testid=starts-input]").attributes("disabled")).toBeDefined();
      expect(wrapper.get("[data-testid=start-now] input").attributes("disabled")).toBeDefined();
      expect(wrapper.find("[data-testid=open-picker]").exists()).toBe(false);
      expect(wrapper.find("[data-testid=remove-variant]").exists()).toBe(false);
      expect(wrapper.get("[data-testid=name-input]").attributes("disabled")).toBeUndefined();
      expect(wrapper.get("[data-testid=ends-input]").attributes("disabled")).toBeUndefined();
      expect(wrapper.get("[data-testid=schedule-locked-note]").text()).toContain("nazwę i koniec");
      expect(wrapper.find("[data-testid=delete]").exists()).toBe(false);
    });

    it("changes the name and the end", async () => {
      const wrapper = await open("/promotions/promo-1", undefined, active());
      await wrapper.get("[data-testid=name-input]").setValue("Nowa nazwa");
      await wrapper.get("[data-testid=ends-input]").setValue("2026-11-25T12:00");
      mocks.put.mockResolvedValue({ data: active(), response: { status: 200 } });

      await click(wrapper, "save");

      expect(mocks.put.mock.calls[0]![1].body).toEqual({
        name: "Nowa nazwa",
        percentOff: 20,
        startsAt: "2026-11-10T23:00:00+00:00",
        endsAt: "2026-11-25T11:00:00Z",
        variantIds: ["v-1", "v-2"],
        version: 4,
      });
    });

    it("ends now after a confirmation, with the end set to the time of the browser", async () => {
      const wrapper = await open("/promotions/promo-1", undefined, active());
      mocks.put.mockResolvedValue({ data: promotion({ status: "ended" }), response: { status: 200 } });

      await click(wrapper, "end-now");
      expect(mocks.put).not.toHaveBeenCalled();
      expect(wrapper.get("[data-testid=confirm-box]").text()).toContain("Zakończyć promocję teraz?");

      await click(wrapper, "confirm-action");

      expect(mocks.put).toHaveBeenCalledTimes(1);
      expect(mocks.put.mock.calls[0]![1].body).toMatchObject({
        name: "Jesień w górach",
        percentOff: 20,
        startsAt: "2026-11-10T23:00:00+00:00",
        endsAt: "2026-11-15T10:20:30.000Z",
        version: 4,
      });
      expect(wrapper.get("[data-testid=success-message]").text()).toBe("Promocja zakończona.");
      expect(wrapper.get("[data-testid=promotion-status]").text()).toBe("Zakończona");
      expect(wrapper.find("[data-testid=end-now]").exists()).toBe(false);
      expect(wrapper.find("[data-testid=confirm-box]").exists()).toBe(false);
    });

    it("does not end when the confirmation is cancelled", async () => {
      const wrapper = await open("/promotions/promo-1", undefined, active());

      await click(wrapper, "end-now");
      await click(wrapper, "cancel-confirm");

      expect(wrapper.find("[data-testid=confirm-box]").exists()).toBe(false);
      expect(mocks.put).not.toHaveBeenCalled();
    });
  });

  describe("an ended promotion", () => {
    it("is read-only, without any action", async () => {
      const wrapper = await open("/promotions/promo-1", undefined, promotion({ status: "ended" }));

      for (const field of ["name-input", "percent-input", "starts-input", "ends-input"]) {
        expect(wrapper.get(`[data-testid=${field}]`).attributes("disabled")).toBeDefined();
      }
      for (const action of ["save", "end-now", "delete", "open-picker", "remove-variant"]) {
        expect(wrapper.find(`[data-testid=${action}]`).exists()).toBe(false);
      }
    });
  });

  describe("deleting", () => {
    it("deletes a planned promotion after a confirmation, with the version, and goes to the list", async () => {
      const wrapper = await open();
      mocks.delete.mockResolvedValue({ data: undefined, response: { status: 204 } });

      await click(wrapper, "delete");
      expect(mocks.delete).not.toHaveBeenCalled();
      expect(wrapper.get("[data-testid=confirm-box]").text()).toContain("Usunąć promocję?");
      await click(wrapper, "confirm-action");

      expect(mocks.delete).toHaveBeenCalledWith(PROMOTION, { params: { path: { id: "promo-1" }, query: { version: 4 } } });
      expect(mounted!.router.currentRoute.value.name).toBe("promotions");
    });

    it("shows the detail of a 409 and the promotion as it is now", async () => {
      const wrapper = await open();
      mocks.delete.mockResolvedValue(conflict("promotion-not-editable", "Tylko zaplanowaną promocję można usunąć."));
      serve(promotion({ status: "active" }));

      await click(wrapper, "delete");
      await click(wrapper, "confirm-action");

      expect(wrapper.get("[data-testid=form-error]").text()).toContain("Tylko zaplanowaną promocję można usunąć.");
      expect(wrapper.get("[data-testid=promotion-status]").text()).toBe("Aktywna");
      expect(wrapper.find("[data-testid=delete]").exists()).toBe(false);
      expect(mounted!.router.currentRoute.value.name).toBe("promotion");
    });
  });

  describe("a new promotion", () => {
    it("starts empty, with the picker closed, and creates the promotion", async () => {
      const wrapper = await open("/promotions/new");
      expect(wrapper.get("h1").text()).toBe("Nowa promocja");
      expect(mocks.get).not.toHaveBeenCalled();
      expect(wrapper.find("[data-testid=promotion-status]").exists()).toBe(false);
      expect(wrapper.find("[data-testid=delete]").exists()).toBe(false);
      expect(wrapper.find("[data-testid=end-now]").exists()).toBe(false);

      await wrapper.get("[data-testid=name-input]").setValue("Jesień w górach");
      await wrapper.get("[data-testid=percent-input]").setValue("20");
      await wrapper.get("[data-testid=starts-input]").setValue("2026-11-20T00:00");
      await wrapper.get("[data-testid=ends-input]").setValue("2026-11-30T00:00");
      await click(wrapper, "open-picker");
      await wrapper.get("[data-testid=picker-row] [data-testid=add-variant]").trigger("click");
      mocks.post.mockResolvedValue({ data: promotion(), response: { status: 201 } });

      await click(wrapper, "save");

      expect(mocks.post).toHaveBeenCalledWith(PROMOTIONS, {
        body: {
          name: "Jesień w górach",
          percentOff: 20,
          startsAt: "2026-11-19T23:00:00Z",
          endsAt: "2026-11-29T23:00:00Z",
          variantIds: ["a-green"],
        },
      });
      expect(mounted!.router.currentRoute.value.path).toBe("/promotions/promo-1");
      expect(wrapper.get("[data-testid=success-message]").text()).toBe("Promocja zapisana.");
      // The page of the created promotion is the same instance, not loaded again.
      expect(mocks.get.mock.calls.filter(([path]) => path === PROMOTION)).toHaveLength(0);
    });

    it("shows the errors of a typed value that cannot be sent, at its field, without a request", async () => {
      const wrapper = await open("/promotions/new");
      await wrapper.get("[data-testid=percent-input]").setValue("2x");

      await click(wrapper, "save");

      expect(mocks.post).not.toHaveBeenCalled();
      expect(wrapper.get("[data-testid=percent-error]").text()).toBe("Podaj procent jako liczbę całkowitą.");
      expect(wrapper.get("[data-testid=starts-error]").text()).toContain("Podaj początek");
      expect(wrapper.get("[data-testid=ends-error]").text()).toBe("Podaj koniec promocji.");
    });

    it("puts the violations of a 422 at their fields", async () => {
      const wrapper = await open("/promotions/new");
      await wrapper.get("[data-testid=name-input]").setValue("N");
      await wrapper.get("[data-testid=percent-input]").setValue("95");
      wrapper.getComponent(ToggleSwitch).vm.$emit("update:modelValue", true);
      await wrapper.get("[data-testid=ends-input]").setValue("2026-11-30T00:00");
      mocks.post.mockResolvedValue({
        data: undefined,
        error: {
          status: 422,
          violations: [
            { propertyPath: "percentOff", title: "Procent musi być od 1 do 90." },
            { propertyPath: "variantIds", title: "Wybierz co najmniej jeden wariant." },
            { propertyPath: "endsAt", title: "Koniec musi być po początku." },
          ],
        },
        response: { status: 422 },
      });

      await click(wrapper, "save");

      expect(wrapper.get("[data-testid=percent-error]").text()).toBe("Procent musi być od 1 do 90.");
      expect(wrapper.get("[data-testid=variants-error]").text()).toBe("Wybierz co najmniej jeden wariant.");
      expect(wrapper.get("[data-testid=ends-error]").text()).toBe("Koniec musi być po początku.");
    });
  });

  describe("choosing variants", () => {
    async function openPicker() {
      const wrapper = await open("/promotions/new");
      await click(wrapper, "open-picker");
      return wrapper;
    }

    const selectedIds = (wrapper: Awaited<ReturnType<typeof open>>) => rows(wrapper).map((row) => row.attributes("data-variant-id"));

    it("lists the products of the price list with a row per variant", async () => {
      const wrapper = await openPicker();

      expect(mocks.get).toHaveBeenCalledWith(PRICES, { params: { query: {} } });
      const pickerRows = wrapper.findAll("[data-testid=picker-row]");
      expect(pickerRows.map((row) => row.text())).toEqual([
        expect.stringContaining("Namiot Tarvik"),
        expect.stringContaining("Czerwony"),
        expect.stringContaining("Bez ceny"),
        expect.stringContaining("Śpiwór"),
      ]);
    });

    it("adds a single variant, and shows it as added", async () => {
      const wrapper = await openPicker();

      await wrapper.findAll("[data-testid=picker-row]")[1]!.get("[data-testid=add-variant]").trigger("click");
      await settle();

      expect(selectedIds(wrapper)).toEqual(["a-red"]);
      const row = rows(wrapper)[0]!;
      expect(row.get("[data-testid=variant-name]").text()).toBe("Namiot Tarvik");
      expect(row.get("[data-testid=variant-label]").text()).toBe("Czerwony");
      expect(row.get("[data-testid=variant-regular]").text()).toMatch(/^120,00\s*zł$/);
      expect(row.get("[data-testid=variant-promotional]").text()).toBe("—");
      expect(wrapper.findAll("[data-testid=picker-row]")[1]!.get("[data-testid=add-variant]").attributes("disabled")).toBeDefined();
    });

    it("adds a whole product: all its variants that have a price", async () => {
      const wrapper = await openPicker();

      await wrapper.get("[data-testid=add-product]").trigger("click");
      await settle();

      expect(selectedIds(wrapper)).toEqual(["a-green", "a-red"]);
    });

    it("does not add a variant twice, whether single or with its product", async () => {
      const wrapper = await openPicker();
      await wrapper.findAll("[data-testid=picker-row]")[2 - 1]!.get("[data-testid=add-variant]").trigger("click");

      await wrapper.get("[data-testid=add-product]").trigger("click");
      await settle();

      expect(selectedIds(wrapper)).toEqual(["a-red", "a-green"]);
    });

    it("cannot add a variant without a price", async () => {
      const wrapper = await openPicker();

      const row = wrapper.findAll("[data-testid=picker-row]")[2]!;
      expect(row.get("[data-testid=add-variant]").attributes("disabled")).toBeDefined();
    });

    it("removes a chosen variant", async () => {
      const wrapper = await openPicker();
      await wrapper.get("[data-testid=add-product]").trigger("click");
      await settle();

      await rows(wrapper)[0]!.get("[data-testid=remove-variant]").trigger("click");
      await settle();

      expect(selectedIds(wrapper)).toEqual(["a-red"]);
      expect(wrapper.findAll("[data-testid=picker-row]")[0]!.text()).toContain("Namiot Tarvik");
      expect(wrapper.findAll("[data-testid=picker-row] [data-testid=add-variant]")[0]!.attributes("disabled")).toBeUndefined();
    });

    it("searches the price list by name once the typing pauses", async () => {
      const wrapper = await openPicker();

      await wrapper.get("[data-testid=picker-search]").setValue("śpi");
      await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS);
      await flushPromises();

      expect(mocks.get).toHaveBeenLastCalledWith(PRICES, { params: { query: { q: "śpi" } } });
    });

    it("closes the picker and keeps the chosen variants", async () => {
      const wrapper = await openPicker();
      await wrapper.get("[data-testid=add-product]").trigger("click");

      await click(wrapper, "close-picker");

      expect(wrapper.find("[data-testid=picker]").exists()).toBe(false);
      expect(rows(wrapper)).toHaveLength(2);
    });
  });

  describe("a conflict of promotions", () => {
    it("marks the colliding variants and keeps the form", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=name-input]").setValue("Inna");
      mocks.put.mockResolvedValue(
        conflict("promotion-overlap", "Część wariantów jest już w innej promocji w tym okresie.", { conflictingVariantIds: ["v-2"] }),
      );

      await click(wrapper, "save");

      expect(wrapper.get("[data-testid=form-error]").text()).toContain("Część wariantów jest już w innej promocji w tym okresie.");
      expect(rows(wrapper)[0]!.find("[data-testid=variant-conflict]").exists()).toBe(false);
      expect(rows(wrapper)[1]!.get("[data-testid=variant-conflict]").text()).toBe("Koliduje z inną promocją");
      expect((wrapper.get("[data-testid=name-input]").element as HTMLInputElement).value).toBe("Inna");
    });

    it("reloads the promotion and offers no refresh button when the version changed", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=name-input]").setValue("Inna");
      mocks.put.mockResolvedValue(conflict("about:blank", "Ktoś zmienił."));
      serve(promotion({ name: "Zmieniona przez kogoś", version: 9 }));

      await click(wrapper, "save");

      expect(wrapper.get("[data-testid=form-error]").text()).toContain("Promocja została w międzyczasie zmieniona");
      expect((wrapper.get("[data-testid=name-input]").element as HTMLInputElement).value).toBe("Zmieniona przez kogoś");
    });

    it("shows the detail of another 409 and reloads", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=name-input]").setValue("Inna");
      mocks.put.mockResolvedValue(conflict("promotion-not-editable", "Rozpoczętej promocji nie można zmienić."));
      serve(promotion({ status: "active" }));

      await click(wrapper, "save");

      expect(wrapper.get("[data-testid=form-error]").text()).toContain("Rozpoczętej promocji nie można zmienić.");
      expect(wrapper.get("[data-testid=promotion-status]").text()).toBe("Aktywna");
    });
  });

  describe("who can change promotions", () => {
    it.each([[["admin"]], [["marketing"]], [["support", "marketing"]]] as StaffMe["roles"][][])("offers the actions to the roles %j", async (roles) => {
      const wrapper = await open("/promotions/promo-1", roles);

      for (const action of ["save", "delete", "open-picker", "remove-variant"]) {
        expect(wrapper.find(`[data-testid=${action}]`).exists()).toBe(true);
      }
      expect(wrapper.find("[data-testid=read-only-note]").exists()).toBe(false);
      expect(wrapper.get("[data-testid=name-input]").attributes("disabled")).toBeUndefined();
    });

    it.each([[["support"]], [["warehouse"]], [["support", "warehouse"]], [[]]] as StaffMe["roles"][][])(
      "shows the roles %j the promotion read-only, with its variants and prices",
      async (roles) => {
        const wrapper = await open("/promotions/promo-1", roles, promotion({ status: "active" }));

        for (const action of ["save", "delete", "end-now", "open-picker", "remove-variant"]) {
          expect(wrapper.find(`[data-testid=${action}]`).exists()).toBe(false);
        }
        for (const field of ["name-input", "percent-input", "starts-input", "ends-input"]) {
          expect(wrapper.get(`[data-testid=${field}]`).attributes("disabled")).toBeDefined();
        }
        expect(wrapper.get("[data-testid=read-only-note]").text()).toContain("tylko do odczytu");
        expect(rows(wrapper)).toHaveLength(2);
        expect(rows(wrapper)[0]!.get("[data-testid=variant-promotional]").text()).toMatch(/80,00/);
      },
    );
  });
});
