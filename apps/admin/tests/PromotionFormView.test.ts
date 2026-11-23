import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { type Mock, afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter, RouterView } from "vue-router";
import { toast } from "vue-sonner";
import type { components } from "@/api/schema";
import { NAME_SEARCH_DEBOUNCE_MS } from "@/composables/useNameSearch";
import { formatDateTime } from "@/prices/format";
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
      { path: "/", name: "dashboard", component: empty },
      { path: "/promotions", name: "promotions", component: empty },
      { path: "/promotions/new", name: "promotion-new", component: PromotionFormView },
      { path: "/promotions/:id", name: "promotion", component: PromotionFormView },
    ],
  });
  await router.push(path);
  const wrapper = mount(
    { components: { RouterView }, template: "<RouterView />" },
    { attachTo: document.body, global: { plugins: [pinia, router] } },
  );
  await settle();
  return { wrapper, router };
}

describe("PromotionFormView", () => {
  let mounted: Awaited<ReturnType<typeof mountForm>> | undefined;
  let successToast: Mock<typeof toast.success>;
  let scrollIntoView: Mock<Element["scrollIntoView"]>;

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout", "Date"] });
    vi.setSystemTime(new Date("2026-11-15T10:20:30.000Z"));
    for (const mock of Object.values(mocks)) {
      mock.mockReset();
    }
    // The toast store is global; the tests read what was shown from the calls.
    successToast = vi.spyOn(toast, "success").mockImplementation(() => 1);
    scrollIntoView = vi.spyOn(Element.prototype, "scrollIntoView").mockImplementation(() => {});
    serve();
  });

  afterEach(() => {
    mounted?.wrapper.unmount();
    mounted = undefined;
    document.body.innerHTML = "";
    vi.restoreAllMocks();
    vi.useRealTimers();
  });

  async function open(path = "/promotions/promo-1", roles?: StaffMe["roles"], data?: Promotion | null) {
    if (data !== undefined) {
      serve(data);
    }
    mounted = await mountForm(path, roles);
    return mounted.wrapper;
  }

  type Wrapper = Awaited<ReturnType<typeof open>>;

  const click = async (wrapper: Wrapper, testid: string) => {
    await wrapper.get(`[data-testid=${testid}]`).trigger("click");
    await settle();
  };
  const input = (wrapper: Wrapper, testid: string) => wrapper.get(`[data-testid=${testid}]`).element as HTMLInputElement;
  const disabled = (wrapper: Wrapper, testid: string) => wrapper.get(`[data-testid=${testid}]`).attributes("disabled") !== undefined;
  const isOn = (wrapper: Wrapper) => wrapper.get("[data-testid=start-now]").attributes("aria-checked") === "true";
  const saveBar = () => document.body.querySelector("section[aria-label='Zapis formularza']");
  const barButton = (name: string) => [...(saveBar()?.querySelectorAll("button") ?? [])].find((button) => button.textContent?.trim() === name) as HTMLButtonElement | undefined;
  const dialog = () => document.body.querySelector<HTMLElement>("[role=alertdialog]");
  const dialogButton = (name: string) => [...(dialog()?.querySelectorAll("button") ?? [])].find((button) => button.textContent?.trim() === name);
  const heading = (level: "h1" | "h2", text?: string) => [...document.body.querySelectorAll<HTMLElement>(level)].find((candidate) => text === undefined || candidate.textContent?.trim() === text);
  const card = (title: string) => heading("h2", title)!.closest("section")!;
  const pressEscape = () => (document.activeElement ?? document.body).dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }));
  const text = (element: Element | null) => element?.textContent?.replace(/\s+/g, " ").trim();
  /** The control a visible label points at. */
  const labelled = (label: string) => {
    const element = [...document.body.querySelectorAll("label")].find((candidate) => candidate.textContent?.trim() === label);
    expect(element, `a label „${label}”`).toBeDefined();
    return element!.htmlFor ? document.getElementById(element!.htmlFor) : null;
  };
  const variantRows = (wrapper: Wrapper) => wrapper.findAll("[data-testid=variant-row]");
  const selectedIds = (wrapper: Wrapper) => variantRows(wrapper).map((row) => row.attributes("data-variant-id"));
  const removeButton = (wrapper: Wrapper, variantId: string) => wrapper.get<HTMLButtonElement>(`[data-variant-id=${variantId}] [data-testid=remove-variant]`);
  const pickerRows = (wrapper: Wrapper) => wrapper.findAll("[data-testid=picker-row]");
  const searchField = (wrapper: Wrapper) => wrapper.get("[data-testid=picker-search]").element;

  describe("the layout", () => {
    it("has the cards „Status”, „Dane promocji” and „Warianty” in that order in the document, and the crumbs of the list", async () => {
      const wrapper = await open();

      expect([...document.body.querySelectorAll("h2")].map((title) => title.textContent?.trim())).toEqual(["Status", "Dane promocji", "Warianty"]);
      const crumbs = wrapper.get("nav[aria-label=Okruszki]");
      expect(crumbs.text()).toMatch(/^Pulpit.*Marketing.*Promocje.*Jesień w górach$/);
      expect(crumbs.get("a[href='/promotions']").text()).toBe("Promocje");
      expect(wrapper.find("[data-testid=back]").exists()).toBe(false);
    });

    it("has no card „Status” for a new promotion", async () => {
      await open("/promotions/new");

      expect([...document.body.querySelectorAll("h2")].map((title) => title.textContent?.trim())).toEqual(["Dane promocji", "Warianty"]);
    });

    it("puts the status label in the card „Status”", async () => {
      await open();

      expect(text(card("Status").querySelector("[data-testid=promotion-status]"))).toBe("Zaplanowana");
    });
  });

  describe("a promotion", () => {
    it("shows its fields with labels, its times in Polish time and its variants with the prices from the API", async () => {
      const wrapper = await open();

      expect(wrapper.get("h1").text()).toBe("Jesień w górach");
      expect(input(wrapper, "name-input").value).toBe("Jesień w górach");
      expect(input(wrapper, "percent-input").value).toBe("20");
      expect(input(wrapper, "starts-input").value).toBe("2026-11-11T00:00");
      expect(input(wrapper, "ends-input").value).toBe("2026-11-21T00:00");
      expect(wrapper.get("[data-testid=promotion-status]").text()).toBe("Zaplanowana");
      expect(wrapper.text()).toContain("Czas w strefie Europe/Warsaw");
      for (const [label, testid] of [
        ["Nazwa", "name-input"],
        ["Obniżka (%)", "percent-input"],
        ["Początek", "starts-input"],
        ["Koniec", "ends-input"],
        ["Od teraz", "start-now"],
      ] as const) {
        expect(labelled(label), label).toBe(wrapper.get(`[data-testid=${testid}]`).element);
      }
      const first = variantRows(wrapper)[0]!;
      expect(first.get("[data-testid=variant-name]").text()).toBe("Produkt v-1");
      expect(first.get("[data-testid=variant-regular]").text()).toMatch(/^100,00\s*zł$/);
      expect(first.get("[data-testid=variant-promotional]").text()).toMatch(/^80,00\s*zł$/);
      expect(variantRows(wrapper)[1]!.get("[data-testid=variant-label]").text()).toBe("Zielony");
      expect(wrapper.findAll("th[scope=col]").map((header) => header.text())).toEqual(["Produkt", "Wariant", "Cena katalogowa", "Cena promocyjna", "Akcje"]);
    });

    it("marks a variant deleted from the catalog and shows no price it does not have", async () => {
      const wrapper = await open(
        "/promotions/promo-1",
        undefined,
        promotion({
          variants: [variant("v-1"), variant("v-gone", { productId: null, productName: null, label: null, regularGross: null, promotionalGross: null })],
        }),
      );

      const gone = variantRows(wrapper)[1]!;
      expect(gone.get("[data-testid=variant-deleted]").text()).toBe("Wariant usunięty");
      expect(gone.find("[data-testid=variant-name]").exists()).toBe(false);
      expect(gone.get("[data-testid=variant-regular]").text()).toBe("—");
      expect(gone.get("[data-testid=variant-promotional]").text()).toBe("—");
      expect(gone.get("[data-testid=remove-variant]").attributes("aria-label")).toBe("Usuń: wariant usunięty");
    });

    it("shows no promotional price for an ended promotion", async () => {
      const wrapper = await open("/promotions/promo-1", undefined, promotion({ status: "ended", variants: [variant("v-1", { promotionalGross: null })] }));

      expect(variantRows(wrapper)[0]!.get("[data-testid=variant-promotional]").text()).toBe("—");
    });

    it("has no save bar until something changes, then saves a changed name and percent with the version", async () => {
      const wrapper = await open();
      expect(saveBar()).toBeNull();

      await wrapper.get("[data-testid=name-input]").setValue("Zimowa wyprzedaż");
      await wrapper.get("[data-testid=percent-input]").setValue("30");
      expect(saveBar()?.textContent).toContain("Niezapisane zmiany");
      mocks.put.mockResolvedValue({ data: promotion({ name: "Zimowa wyprzedaż", percentOff: 30, version: 5 }), response: { status: 200 } });

      await click(wrapper, "save");

      expect(mocks.put).toHaveBeenCalledWith(PROMOTION, {
        params: { path: { id: "promo-1" } },
        body: expect.objectContaining({ name: "Zimowa wyprzedaż", percentOff: 30, version: 4 }),
      });
      expect(successToast).toHaveBeenCalledTimes(1);
      expect(successToast).toHaveBeenCalledWith("Promocja zapisana.");
      expect(wrapper.find("[data-testid=success-message]").exists()).toBe(false);
      expect(wrapper.get("h1").text()).toBe("Zimowa wyprzedaż");
      expect(saveBar()).toBeNull();
    });

    it("moves the focus to the H1 after a save, as the save bar and its button go away", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=name-input]").setValue("Inna");
      mocks.put.mockResolvedValue({ data: promotion({ name: "Inna", version: 5 }), response: { status: 200 } });

      (wrapper.get("[data-testid=save]").element as HTMLElement).focus();
      await click(wrapper, "save");

      expect(document.activeElement).toBe(heading("h1"));
      expect(heading("h1")?.getAttribute("tabindex")).toBe("-1");
    });

    it("#580: the focus cannot move to a field while the save runs, as the fields are off, so it goes to the H1 after the save", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=name-input]").setValue("Inna");
      let release!: (value: unknown) => void;
      mocks.put.mockReturnValue(new Promise((resolve) => (release = resolve)));

      await wrapper.get("[data-testid=save]").trigger("click");
      const ends = wrapper.get("[data-testid=ends-input]").element as HTMLInputElement;
      expect(ends.matches(":disabled")).toBe(true);
      ends.focus();
      expect(document.activeElement).not.toBe(ends);
      release({ data: promotion({ name: "Inna", version: 5 }), response: { status: 200 } });
      await settle();

      expect(successToast).toHaveBeenCalledWith("Promocja zapisana.");
      expect(document.activeElement).toBe(heading("h1"));
    });

    it("#250 F16: leaves the focus in a field the staff member moved to while „Zakończ teraz” ran, as that action does not lock the fields", async () => {
      const wrapper = await open("/promotions/promo-1", undefined, promotion({ status: "active" }));
      let release!: (value: unknown) => void;
      mocks.put.mockReturnValue(new Promise((resolve) => (release = resolve)));
      await click(wrapper, "end-now");
      dialogButton("Zakończ promocję teraz")!.click();
      await settle();

      const name = wrapper.get("[data-testid=name-input]").element as HTMLInputElement;
      expect(name.matches(":disabled")).toBe(false);
      name.focus();
      release({ data: promotion({ status: "active", endsAt: "2026-11-15T10:20:31+00:00" }), response: { status: 200 } });
      await settle();

      expect(document.activeElement).toBe(name);
    });

    it("sends the end typed in Polish time as UTC", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=ends-input]").setValue("2026-11-22T08:30");
      mocks.put.mockResolvedValue({ data: promotion(), response: { status: 200 } });

      await click(wrapper, "save");

      expect(mocks.put.mock.calls[0]![1].body.endsAt).toBe("2026-11-22T07:30:00Z");
    });

    it("starts a planned promotion now with the switch „Od teraz”, which disables the start field", async () => {
      const wrapper = await open();
      expect(isOn(wrapper)).toBe(false);
      await click(wrapper, "start-now");
      expect(isOn(wrapper)).toBe(true);
      expect(disabled(wrapper, "starts-input")).toBe(true);
      mocks.put.mockResolvedValue({ data: promotion({ status: "active" }), response: { status: 200 } });

      await click(wrapper, "save");

      expect(mocks.put.mock.calls[0]![1].body.startsAt).toBeNull();
    });

    it("throws away the changes with „Odrzuć” without a request, and puts the focus on the H1", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=name-input]").setValue("Inna");
      await removeButton(wrapper, "v-1").trigger("click");
      mocks.get.mockClear();

      barButton("Odrzuć")!.click();
      await settle();

      expect(input(wrapper, "name-input").value).toBe("Jesień w górach");
      expect(selectedIds(wrapper)).toEqual(["v-1", "v-2"]);
      expect(saveBar()).toBeNull();
      expect(mocks.get).not.toHaveBeenCalled();
      expect(mocks.put).not.toHaveBeenCalled();
      expect(document.activeElement).toBe(heading("h1"));
    });
  });

  describe("a failed save (#304 F3)", () => {
    it("#304: moves the focus to the message of the form and brings it into view for a 409", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=name-input]").setValue("Inna");
      mocks.put.mockResolvedValue(
        conflict("promotion-overlap", "Część wariantów jest już w innej promocji w tym okresie.", { conflictingVariantIds: ["v-2"] }),
      );
      scrollIntoView.mockClear();

      await click(wrapper, "save");

      const alert = wrapper.get("[data-testid=form-error]");
      expect(alert.text()).toContain("Część wariantów jest już w innej promocji w tym okresie.");
      expect(alert.attributes("role")).toBe("alert");
      expect(document.activeElement).toBe(alert.element);
      expect(scrollIntoView).toHaveBeenCalled();
      expect(scrollIntoView.mock.contexts.at(-1)).toBe(alert.element);
      expect(successToast).not.toHaveBeenCalled();
    });

    it("#304: moves the focus to the first field with an error, in the order of the document, and brings it into view", async () => {
      const wrapper = await open("/promotions/new");
      await wrapper.get("[data-testid=name-input]").setValue("N");
      await wrapper.get("[data-testid=percent-input]").setValue("95");
      await click(wrapper, "start-now");
      await wrapper.get("[data-testid=ends-input]").setValue("2026-11-30T00:00");
      mocks.post.mockResolvedValue({
        data: undefined,
        error: {
          status: 422,
          violations: [
            { propertyPath: "endsAt", title: "Koniec musi być po początku." },
            { propertyPath: "percentOff", title: "Procent musi być od 1 do 90." },
            { propertyPath: "variantIds", title: "Wybierz co najmniej jeden wariant." },
          ],
        },
        response: { status: 422 },
      });
      scrollIntoView.mockClear();

      await click(wrapper, "save");

      const percent = wrapper.get("[data-testid=percent-input]");
      expect(wrapper.get("[data-testid=percent-error]").text()).toBe("Procent musi być od 1 do 90.");
      expect(percent.attributes("aria-invalid")).toBe("true");
      expect(percent.attributes("aria-describedby")).toContain(wrapper.get("[data-testid=percent-error]").attributes("id"));
      expect(document.activeElement).toBe(percent.element);
      expect(scrollIntoView.mock.contexts.at(-1)).toBe(percent.element);
      expect(wrapper.get("[data-testid=variants-error]").text()).toBe("Wybierz co najmniej jeden wariant.");
      expect(wrapper.get("[data-testid=ends-error]").text()).toBe("Koniec musi być po początku.");
    });

    it("focuses the message of the variants when it is the only error, and makes it a target for the script only", async () => {
      const wrapper = await open("/promotions/new");
      await wrapper.get("[data-testid=name-input]").setValue("Jesień");
      await wrapper.get("[data-testid=percent-input]").setValue("20");
      await wrapper.get("[data-testid=starts-input]").setValue("2026-11-20T00:00");
      await wrapper.get("[data-testid=ends-input]").setValue("2026-11-30T00:00");
      mocks.post.mockResolvedValue({
        data: undefined,
        error: { status: 422, violations: [{ propertyPath: "variantIds", title: "Wybierz co najmniej jeden wariant." }] },
        response: { status: 422 },
      });

      await click(wrapper, "save");

      const message = wrapper.get("[data-testid=variants-error]");
      expect(document.activeElement).toBe(message.element);
      expect(message.attributes("tabindex")).toBe("-1");
    });

    it("shows what cannot be read at the field, without sending, and focuses the first such field", async () => {
      const wrapper = await open("/promotions/new");
      await wrapper.get("[data-testid=percent-input]").setValue("2x");

      await click(wrapper, "save");

      expect(mocks.post).not.toHaveBeenCalled();
      expect(wrapper.get("[data-testid=percent-error]").text()).toBe("Podaj procent jako liczbę całkowitą.");
      expect(wrapper.get("[data-testid=starts-error]").text()).toContain("Podaj początek");
      expect(wrapper.get("[data-testid=ends-error]").text()).toBe("Podaj koniec promocji.");
      expect(document.activeElement).toBe(wrapper.get("[data-testid=percent-input]").element);
    });
  });

  describe("an active promotion", () => {
    const active = () => promotion({ status: "active" });

    it("locks the percent, the start and the variants but not the name and the end, and says so in the card „Dane promocji”", async () => {
      const wrapper = await open("/promotions/promo-1", undefined, active());

      expect(disabled(wrapper, "percent-input")).toBe(true);
      expect(disabled(wrapper, "starts-input")).toBe(true);
      expect(disabled(wrapper, "start-now")).toBe(true);
      expect(wrapper.find("[data-testid=open-picker]").exists()).toBe(false);
      expect(wrapper.find("[data-testid=remove-variant]").exists()).toBe(false);
      expect(disabled(wrapper, "name-input")).toBe(false);
      expect(disabled(wrapper, "ends-input")).toBe(false);
      const note = wrapper.get("[data-testid=schedule-locked-note]");
      expect(note.text()).toContain("nazwę i koniec");
      expect(card("Dane promocji").contains(note.element)).toBe(true);
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
  });

  describe("ending now", () => {
    const active = () => promotion({ status: "active" });
    const endButton = (wrapper: Wrapper) => wrapper.get<HTMLButtonElement>("[data-testid=end-now]");

    it("has „Zakończ teraz” in the card „Status” of an active promotion, and nothing for a promotion that is not", async () => {
      const wrapper = await open("/promotions/promo-1", undefined, active());
      expect(card("Status").contains(endButton(wrapper).element)).toBe(true);
      expect(endButton(wrapper).text()).toBe("Zakończ teraz");
      mounted!.wrapper.unmount();

      const planned = await open("/promotions/promo-1", undefined, promotion());
      expect(planned.find("[data-testid=end-now]").exists()).toBe(false);
    });

    it("#304: asks in an alert dialog named by the promotion, with „Wróć” in the focus and a red button with the effect", async () => {
      const wrapper = await open("/promotions/promo-1", undefined, active());

      endButton(wrapper).element.focus();
      await click(wrapper, "end-now");

      const root = dialog();
      expect(root).not.toBeNull();
      expect(document.getElementById(root!.getAttribute("aria-labelledby")!)?.textContent).toBe("Zakończyć promocję Jesień w górach teraz?");
      expect(dialogButton("Zakończ promocję teraz")?.className).toContain("bg-destructive");
      expect(document.activeElement).toBe(dialogButton("Wróć"));
      expect(mocks.put).not.toHaveBeenCalled();
    });

    it("#304: closes with Escape, and gives the focus back to the button that opened it, without ending", async () => {
      const wrapper = await open("/promotions/promo-1", undefined, active());
      endButton(wrapper).element.focus();
      await click(wrapper, "end-now");

      pressEscape();
      await settle();

      expect(dialog()).toBeNull();
      expect(document.activeElement).toBe(endButton(wrapper).element);
      expect(mocks.put).not.toHaveBeenCalled();
    });

    it("#304: closes with „Wróć” without ending", async () => {
      const wrapper = await open("/promotions/promo-1", undefined, active());
      await click(wrapper, "end-now");

      dialogButton("Wróć")!.click();
      await settle();

      expect(dialog()).toBeNull();
      expect(mocks.put).not.toHaveBeenCalled();
      expect(wrapper.find("[data-testid=confirm-box]").exists()).toBe(false);
    });

    it("ends after the confirmation with the end set to the time of the browser, and an ended answer says so at once, read-only, with the focus on „Status”", async () => {
      const wrapper = await open("/promotions/promo-1", undefined, active());
      mocks.put.mockResolvedValue({ data: promotion({ status: "ended" }), response: { status: 200 } });
      await click(wrapper, "end-now");

      dialogButton("Zakończ promocję teraz")!.click();
      await settle();

      expect(mocks.put).toHaveBeenCalledTimes(1);
      expect(mocks.put.mock.calls[0]![1].body).toMatchObject({
        name: "Jesień w górach",
        percentOff: 20,
        startsAt: "2026-11-10T23:00:00+00:00",
        endsAt: "2026-11-15T10:20:30.000Z",
        version: 4,
      });
      expect(successToast).toHaveBeenCalledTimes(1);
      expect(successToast).toHaveBeenCalledWith("Promocja zakończona.");
      expect(text(card("Status").querySelector("[data-testid=promotion-status]"))).toBe("Zakończona");
      expect(wrapper.find("[data-testid=end-now]").exists()).toBe(false);
      expect(wrapper.get("[data-testid=read-only-note]").text()).toContain("tylko do odczytu");
      expect(disabled(wrapper, "name-input")).toBe(true);
      expect(saveBar()).toBeNull();
      expect(dialog()).toBeNull();
      expect(document.activeElement).toBe(heading("h2", "Status"));
    });

    it("does not offer ending while the form has unsaved changes, and says why when it is asked about it", async () => {
      const wrapper = await open("/promotions/promo-1", undefined, active());
      await wrapper.get("[data-testid=name-input]").setValue("Inna nazwa");

      expect(endButton(wrapper).attributes("disabled")).toBeDefined();
      expect(mocks.put).not.toHaveBeenCalled();
    });

    describe("when the API leaves it active (#304 F7)", () => {
      /** The PUT answers with the promotion still active, its end a second ahead of the clock. */
      async function endAndWait() {
        const wrapper = await open("/promotions/promo-1", undefined, active());
        mocks.put.mockResolvedValue({ data: promotion({ status: "active", endsAt: "2026-11-15T10:20:31+00:00" }), response: { status: 200 } });
        await click(wrapper, "end-now");
        dialogButton("Zakończ promocję teraz")!.click();
        await settle();
        mocks.get.mockClear();
        return wrapper;
      }

      it("#304: disables „Zapisz” and „Zakończ teraz” with the description „Promocja kończy się”, and puts the focus on the heading „Status”", async () => {
        const wrapper = await endAndWait();

        expect(endButton(wrapper).attributes("disabled")).toBeDefined();
        const reason = document.getElementById(endButton(wrapper).attributes("aria-describedby")!);
        expect(reason?.textContent?.trim()).toBe("Promocja kończy się.");
        expect(card("Status").contains(reason)).toBe(true);
        expect(reason?.closest("[role=status]"), "in a status region that was there before the text").not.toBeNull();
        expect(document.activeElement).toBe(heading("h2", "Status"));
        expect(successToast).not.toHaveBeenCalled();
        // „Zapisz” shows with the first change typed meanwhile, off and with the same description.
        await wrapper.get("[data-testid=name-input]").setValue("Inna nazwa");
        expect(barButton("Zapisz")?.disabled).toBe(true);
        expect(barButton("Zapisz")?.getAttribute("aria-describedby")).toBe(reason?.id);
      });

      it("#304: reads again after the end and one second, and an ended answer gives the toast and the read-only form with the card „Status”", async () => {
        const wrapper = await endAndWait();
        mocks.get.mockImplementation(async () => ({ data: promotion({ status: "ended", endsAt: "2026-11-15T10:20:31+00:00" }), response: { status: 200 } }));

        await vi.advanceTimersByTimeAsync(1999);
        expect(mocks.get).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(1);
        await settle();

        expect(mocks.get).toHaveBeenCalledTimes(1);
        expect(successToast).toHaveBeenCalledTimes(1);
        expect(successToast).toHaveBeenCalledWith("Promocja zakończona.");
        expect(text(card("Status").querySelector("[data-testid=promotion-status]"))).toBe("Zakończona");
        expect(wrapper.get("[data-testid=read-only-note]").text()).toContain("tylko do odczytu");
        expect(card("Status").contains(wrapper.get("[data-testid=read-only-note]").element)).toBe(true);
        expect(disabled(wrapper, "name-input")).toBe(true);
        expect(wrapper.find("[data-testid=end-now]").exists()).toBe(false);
        expect(wrapper.find("[data-testid=ending-note]").exists()).toBe(false);
        expect(saveBar()).toBeNull();
        expect(document.activeElement).toBe(heading("h2", "Status"));
      });

      it("#304: reads again every second at most 5 times, then says when the end is set, in Polish time", async () => {
        const wrapper = await endAndWait();
        mocks.get.mockImplementation(async () => ({ data: promotion({ status: "active", endsAt: "2026-11-15T10:20:45+00:00", version: 9 }), response: { status: 200 } }));

        await vi.advanceTimersByTimeAsync(2000);
        expect(mocks.get).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(5000);
        await settle();
        await vi.advanceTimersByTimeAsync(10_000);

        expect(mocks.get).toHaveBeenCalledTimes(6);
        const notice = wrapper.get("[data-testid=end-notice]");
        expect(notice.text()).toBe(`Koniec promocji ustawiony na ${formatDateTime("2026-11-15T10:20:45+00:00")}.`);
        expect(notice.text()).toContain("11:20:45");
        expect(notice.element.closest("[role=status]")).not.toBeNull();
        expect(card("Status").contains(notice.element)).toBe(true);
        expect(successToast).not.toHaveBeenCalled();
        expect(text(card("Status").querySelector("[data-testid=promotion-status]"))).toBe("Aktywna");
        expect(wrapper.find("[data-testid=ending-note]").exists()).toBe(false);
        expect(endButton(wrapper).attributes("disabled")).toBeUndefined();
        // The state is the one of the last answer: the next save carries its version, not the one of the PUT.
        await wrapper.get("[data-testid=name-input]").setValue("Inna nazwa");
        mocks.put.mockResolvedValue({ data: promotion({ status: "active", version: 10 }), response: { status: 200 } });
        await click(wrapper, "save");
        expect(mocks.put.mock.calls.at(-1)![1].body.version).toBe(9);
      });

      it("#304: ends the wait with an error and „Odśwież” when the read fails, and „Odśwież” shows the promotion as it is", async () => {
        const wrapper = await endAndWait();
        mocks.get.mockResolvedValue({ data: undefined, error: {}, response: { status: 500 } });

        await vi.advanceTimersByTimeAsync(2000);
        await settle();

        const alert = wrapper.get("[data-testid=end-error]");
        expect(alert.text()).toContain("Nie udało się odczytać promocji");
        expect(alert.attributes("role")).toBe("alert");
        expect(card("Status").contains(alert.element)).toBe(true);
        expect(wrapper.find("[data-testid=ending-note]").exists()).toBe(false);
        mocks.get.mockImplementation(async () => ({ data: promotion({ status: "ended" }), response: { status: 200 } }));

        await alert.get("button").trigger("click");
        await settle();

        expect(wrapper.find("[data-testid=end-error]").exists()).toBe(false);
        expect(text(card("Status").querySelector("[data-testid=promotion-status]"))).toBe("Zakończona");
        expect(document.activeElement).toBe(heading("h2", "Status"));
      });

      it("#304: keeps the form and puts the focus on the message to try again when „Odśwież” fails as well, as its button gives way to it (B3)", async () => {
        const wrapper = await endAndWait();
        mocks.get.mockResolvedValue({ data: undefined, error: {}, response: { status: 500 } });
        await vi.advanceTimersByTimeAsync(2000);
        await settle();
        const refresh = wrapper.get("[data-testid=end-error] button");
        (refresh.element as HTMLButtonElement).focus();

        await refresh.trigger("click");
        await settle();

        expect(wrapper.find("[data-testid=load-error]").exists()).toBe(false);
        expect(wrapper.find("[data-testid=promotion-fields]").exists()).toBe(true);
        const alert = wrapper.get("[data-testid=read-failed]");
        expect(alert.text()).toContain("Nie udało się odczytać stanu. Spróbuj ponownie.");
        expect(alert.attributes("role")).toBe("alert");
        expect(wrapper.find("[data-testid=end-error]").exists()).toBe(false);
        expect(document.activeElement).not.toBe(document.body);
        expect(document.activeElement).toBe(alert.element);
      });

      it("#304: makes no request after the form is left", async () => {
        await endAndWait();
        mounted!.wrapper.unmount();
        mounted = undefined;

        await vi.advanceTimersByTimeAsync(30_000);
        await flushPromises();

        expect(mocks.get).not.toHaveBeenCalled();
        expect(successToast).not.toHaveBeenCalled();
      });
    });
  });

  describe("the lock while a save runs (#580)", () => {
    const FIELDS = ["name-input", "percent-input", "starts-input", "start-now", "ends-input"];
    const VARIANT_CONTROLS = [
      "open-picker",
      "remove-variant",
      "picker-search",
      "close-picker",
      "add-variant",
      "add-product",
      "picker-previous",
      "picker-next",
    ];
    // Whether the browser would refuse input in every element of the test id now (its own `disabled` or a disabled fieldset).
    const off = (wrapper: Wrapper, testid: string) => {
      const elements = wrapper.findAll(`[data-testid=${testid}]`);
      expect(elements.length, `an element ${testid}`).toBeGreaterThan(0);
      return elements.every((element) => element.element.matches(":disabled"));
    };
    const lockedState = (wrapper: Wrapper) => Object.fromEntries([...FIELDS, ...VARIANT_CONTROLS].map((testid) => [testid, off(wrapper, testid)]));

    /** A planned promotion with a changed name and the choice of variants open, and a save whose answer waits for the test. */
    async function saveHeld(failure?: unknown) {
      const wrapper = await open();
      await wrapper.get("[data-testid=name-input]").setValue("Wysłana nazwa");
      await wrapper.get("[data-testid=percent-input]").setValue("35");
      await click(wrapper, "open-picker");
      let release!: (value: unknown) => void;
      let reject!: (reason: unknown) => void;
      mocks.put.mockImplementation(() => new Promise((resolve, fail) => ((release = resolve), (reject = fail))));
      await wrapper.get("[data-testid=save]").trigger("click");
      await settle();
      return { wrapper, release: (value: unknown = failure) => release(value), reject: (reason: unknown) => reject(reason) };
    }

    it("disables the fields and the controls of the variants, keeps the open picker open, and a second „Zapisz” sends nothing", async () => {
      const { wrapper, release } = await saveHeld();

      expect(lockedState(wrapper)).toEqual(Object.fromEntries([...FIELDS, ...VARIANT_CONTROLS].map((testid) => [testid, true])));
      expect(wrapper.find("[data-testid=picker]").exists()).toBe(true);
      expect(pickerRows(wrapper).length).toBeGreaterThan(0);
      expect(barButton("Zapisz")?.disabled).toBe(true);
      barButton("Zapisz")!.click();
      await wrapper.get("[data-testid=save]").trigger("click");
      await settle();
      expect(mocks.put).toHaveBeenCalledTimes(1);

      release({ data: promotion({ name: "Wysłana nazwa", percentOff: 35, version: 5 }), response: { status: 200 } });
      await settle();

      expect(successToast).toHaveBeenCalledWith("Promocja zapisana.");
      expect(input(wrapper, "name-input").value).toBe("Wysłana nazwa");
      expect(off(wrapper, "name-input")).toBe(false);
      expect(off(wrapper, "open-picker")).toBe(false);
    });

    it("unlocks the fields with the values that were sent after a 422, with the focus on the field with the error", async () => {
      const { wrapper, release } = await saveHeld();

      release({ data: undefined, error: { status: 422, violations: [{ propertyPath: "percentOff", title: "Procent musi być od 1 do 90." }] }, response: { status: 422 } });
      await settle();

      expect(lockedState(wrapper)).toEqual({ ...Object.fromEntries([...FIELDS, ...VARIANT_CONTROLS].map((testid) => [testid, false])), "picker-previous": true, "picker-next": true });
      expect(input(wrapper, "name-input").value).toBe("Wysłana nazwa");
      expect(input(wrapper, "percent-input").value).toBe("35");
      expect(document.activeElement).toBe(wrapper.get("[data-testid=percent-input]").element);
    });

    it("unlocks the fields with the values that were sent after a save that does not reach the API, and says the result is unknown", async () => {
      const { wrapper, reject } = await saveHeld();

      reject(new TypeError("network down"));
      await settle();

      expect(off(wrapper, "name-input")).toBe(false);
      expect(off(wrapper, "percent-input")).toBe(false);
      expect(off(wrapper, "add-variant")).toBe(false);
      expect(input(wrapper, "name-input").value).toBe("Wysłana nazwa");
      expect(input(wrapper, "percent-input").value).toBe("35");
      expect(wrapper.find("[data-testid=form-error]").exists()).toBe(false);
      const alert = wrapper.get("[data-testid=unknown-result]");
      expect(alert.text()).toContain("Nie wiemy, czy zapis się powiódł. Twoje zmiany zostają w formularzu.");
      expect(alert.attributes("role")).toBe("alert");
      expect(document.activeElement).toBe(alert.element);
    });

    it("ignores the answer of a save that comes after another promotion was opened: no change, no toast, no move and no wait", async () => {
      const wrapper = await open();
      await click(wrapper, "start-now");
      let release!: (value: unknown) => void;
      mocks.put.mockImplementation(() => new Promise((resolve) => (release = resolve)));
      await wrapper.get("[data-testid=save]").trigger("click");
      await settle();

      serve(promotion({ id: "promo-2", name: "Druga promocja", version: 7 }));
      const navigation = mounted!.router.push("/promotions/promo-2");
      await settle();
      dialogButton("Opuść bez zapisu")!.click();
      await navigation;
      await settle();
      expect(input(wrapper, "name-input").value).toBe("Druga promocja");
      mocks.get.mockClear();
      release({ data: promotion({ status: "planned", startsAt: "2026-11-15T10:20:31+00:00", version: 5 }), response: { status: 200 } });
      await settle();
      await vi.advanceTimersByTimeAsync(15_000);

      expect(mounted!.router.currentRoute.value.path).toBe("/promotions/promo-2");
      expect(input(wrapper, "name-input").value).toBe("Druga promocja");
      expect(heading("h1")?.textContent?.trim()).toBe("Druga promocja");
      expect(successToast).not.toHaveBeenCalled();
      expect(mocks.get.mock.calls.filter(([path]) => path === PROMOTION)).toHaveLength(0);
      expect(wrapper.text()).not.toContain("Promocja rozpoczyna się.");
      expect(off(wrapper, "name-input")).toBe(false);
    });
  });

  describe("after a save „od teraz” that answers planned (#580)", () => {
    const READ_FAILED = "Nie udało się odczytać statusu promocji. Odśwież, żeby zobaczyć jej stan.";
    const statusRegion = () => card("Status").querySelector<HTMLElement>("[role=status]");
    const badge = () => text(card("Status").querySelector("[data-testid=promotion-status]"));
    const reads = () => mocks.get.mock.calls.filter(([path]) => path === PROMOTION).length;
    const off = (wrapper: Wrapper, testid: string) => wrapper.findAll(`[data-testid=${testid}]`).every((element) => element.element.matches(":disabled"));

    /** Answers the reads of the promotion (the picker keeps its price list). */
    function serveRead(answer: () => Promise<unknown>) {
      mocks.get.mockImplementation(async (path: string) => (path === PRICES ? { data: { items: catalog, next: null }, response: { status: 200 } } : answer()));
    }
    const readAs = (overrides: Partial<Promotion>) => serveRead(async () => ({ data: promotion(overrides), response: { status: 200 } }));

    /** Saves the planned promotion „od teraz”; the PUT answers it planned with the start 1 s ahead of the clock. */
    async function saveNow(startsAt = "2026-11-15T10:20:31+00:00") {
      const wrapper = await open();
      await click(wrapper, "start-now");
      mocks.put.mockResolvedValue({ data: promotion({ status: "planned", startsAt, version: 5 }), response: { status: 200 } });
      (wrapper.get("[data-testid=save]").element as HTMLElement).focus();
      await click(wrapper, "save");
      mocks.get.mockClear();
      return wrapper;
    }

    it("locks the form and the actions of „Status” while it waits, says so, and an active answer 2 s later unlocks it with „Zakończ teraz”", async () => {
      const wrapper = await saveNow();

      expect(successToast).toHaveBeenCalledWith("Promocja zapisana.");
      expect(text(statusRegion())).toBe("Promocja rozpoczyna się.");
      expect(badge()).toBe("Zaplanowana");
      for (const testid of ["name-input", "percent-input", "start-now", "ends-input", "open-picker", "remove-variant"]) {
        expect(off(wrapper, testid), testid).toBe(true);
      }
      const remove = wrapper.get<HTMLButtonElement>("[data-testid=delete]");
      expect(remove.element.disabled).toBe(true);
      expect(document.getElementById(remove.attributes("aria-describedby")!)?.textContent?.trim()).toBe("Promocja rozpoczyna się.");
      expect(document.activeElement).toBe(heading("h1"));
      readAs({ status: "active", startsAt: "2026-11-15T10:20:31+00:00", version: 6 });

      await vi.advanceTimersByTimeAsync(1999);
      expect(reads()).toBe(0);
      await vi.advanceTimersByTimeAsync(1);
      await settle();

      expect(reads()).toBe(1);
      expect(badge()).toBe("Aktywna");
      expect(wrapper.get("[data-testid=end-now]").text()).toBe("Zakończ teraz");
      expect(wrapper.get<HTMLButtonElement>("[data-testid=end-now]").element.disabled).toBe(false);
      expect(wrapper.find("[data-testid=delete]").exists()).toBe(false);
      expect(text(statusRegion())).toBe("Promocja jest aktywna.");
      expect(off(wrapper, "name-input")).toBe(false);
      expect(off(wrapper, "ends-input")).toBe(false);
      expect(document.activeElement).toBe(heading("h1"));
    });

    it("reads first 3 s after the answer when the start is far ahead of the clock of the browser", async () => {
      await saveNow("2026-11-15T10:21:30+00:00");
      readAs({ status: "active" });

      await vi.advanceTimersByTimeAsync(2999);
      expect(reads()).toBe(0);
      await vi.advanceTimersByTimeAsync(1);
      await settle();

      expect(reads()).toBe(1);
      expect(badge()).toBe("Aktywna");
    });

    it("reads again every second at most 5 times, then unlocks the form, still planned, and says when it starts, in Polish time", async () => {
      const wrapper = await saveNow();
      readAs({ status: "planned", startsAt: "2026-11-15T10:20:41+00:00", version: 8 });

      await vi.advanceTimersByTimeAsync(2000);
      expect(reads()).toBe(1);
      await vi.advanceTimersByTimeAsync(5000);
      await settle();
      await vi.advanceTimersByTimeAsync(20_000);

      expect(reads()).toBe(6);
      expect(badge()).toBe("Zaplanowana");
      expect(text(statusRegion())).toBe(`Promocja jeszcze się nie rozpoczęła. Początek: ${formatDateTime("2026-11-15T10:20:41+00:00")}.`);
      expect(text(statusRegion())).toContain("11:20:41");
      expect(off(wrapper, "name-input")).toBe(false);
      expect(wrapper.get<HTMLButtonElement>("[data-testid=delete]").element.disabled).toBe(false);
    });

    it("ends the wait with an error and „Odśwież” when the read fails, and „Odśwież” shows the promotion with the focus on „Status”", async () => {
      const wrapper = await saveNow();
      serveRead(async () => ({ data: undefined, error: {}, response: { status: 500 } }));

      await vi.advanceTimersByTimeAsync(2000);
      await settle();

      const alert = [...card("Status").querySelectorAll<HTMLElement>("[role=alert]")].find((element) => text(element)?.includes(READ_FAILED));
      expect(alert, "the error of the read in the card „Status”").toBeDefined();
      const refresh = [...alert!.querySelectorAll("button")].find((button) => button.textContent?.trim() === "Odśwież");
      expect(refresh).toBeDefined();
      expect(text(statusRegion()) ?? "").toBe("");
      expect(badge()).toBe("Zaplanowana");
      expect(off(wrapper, "name-input")).toBe(false);
      readAs({ status: "active", version: 6 });

      refresh!.click();
      await settle();

      expect(card("Status").textContent).not.toContain(READ_FAILED);
      expect(badge()).toBe("Aktywna");
      expect(document.activeElement).toBe(heading("h2", "Status"));
    });

    it("locks the fields while „Odśwież” waits for its read, so a name typed meanwhile is not lost, and unlocks them with the answer", async () => {
      const wrapper = await saveNow();
      serveRead(async () => ({ data: undefined, error: {}, response: { status: 500 } }));
      await vi.advanceTimersByTimeAsync(2000);
      await settle();
      const refresh = [...card("Status").querySelectorAll<HTMLButtonElement>("[role=alert] button")].find((button) => button.textContent?.trim() === "Odśwież");
      expect(refresh).toBeDefined();
      let release!: (value: unknown) => void;
      serveRead(() => new Promise((resolve) => (release = resolve)));
      await wrapper.get("[data-testid=name-input]").setValue("Wpisana przed odświeżeniem");
      const fields = () => wrapper.get<HTMLFieldSetElement>("[data-testid=promotion-fields]").element;

      refresh!.click();
      await settle();

      expect(fields().disabled).toBe(true);
      expect(off(wrapper, "name-input")).toBe(true);
      expect(off(wrapper, "remove-variant")).toBe(true);
      expect(wrapper.get<HTMLButtonElement>("[data-testid=save]").element.disabled).toBe(true);
      await wrapper.get("[data-testid=name-input]").setValue("Wpisana w trakcie odczytu");
      expect(wrapper.get<HTMLButtonElement>("[data-testid=save]").element.disabled).toBe(true);

      release({ data: promotion({ status: "active", name: "Z odczytu", version: 6 }), response: { status: 200 } });
      await settle();

      expect(fields().disabled).toBe(false);
      expect(off(wrapper, "name-input")).toBe(false);
      expect(input(wrapper, "name-input").value).toBe("Z odczytu");
      expect(badge()).toBe("Aktywna");
    });

    it("keeps the form and gives the focus to the message to try again when „Odśwież” fails as well", async () => {
      const wrapper = await saveNow();
      serveRead(async () => {
        throw new TypeError("network down");
      });
      await vi.advanceTimersByTimeAsync(2000);
      await settle();
      const refresh = [...card("Status").querySelectorAll<HTMLButtonElement>("[role=alert] button")].find((button) => button.textContent?.trim() === "Odśwież");
      expect(refresh).toBeDefined();
      refresh!.focus();
      serveRead(async () => ({ data: undefined, error: {}, response: { status: 500 } }));

      refresh!.click();
      await settle();

      expect(wrapper.find("[data-testid=load-error]").exists()).toBe(false);
      expect(wrapper.get("[data-testid=read-failed]").text()).toContain("Nie udało się odczytać stanu. Spróbuj ponownie.");
      expect(document.activeElement).toBe(wrapper.get("[data-testid=read-failed]").element);
    });

    it("B3: says „Odświeżamy…” in a status region and locks the fields while „Odśwież” reads, and 10 s without an answer unlocks them with a message to try again", async () => {
      const wrapper = await saveNow();
      serveRead(async () => {
        throw new TypeError("network down");
      });
      await vi.advanceTimersByTimeAsync(2000);
      await settle();
      let release!: (value: unknown) => void;
      serveRead(() => new Promise((resolve) => (release = resolve)));
      const refresh = [...card("Status").querySelectorAll<HTMLButtonElement>("[role=alert] button")].find((button) => button.textContent?.trim() === "Odśwież");

      refresh!.click();
      await settle();

      const region = wrapper.get("[data-testid=safety-status]");
      expect(region.attributes("role")).toBe("status");
      expect(region.text()).toBe("Odświeżamy…");
      expect(off(wrapper, "name-input")).toBe(true);
      await wrapper.get("[data-testid=name-input]").setValue("Wpisana w trakcie");
      await vi.advanceTimersByTimeAsync(10_000);
      await settle();

      expect(region.text()).toBe("");
      expect(off(wrapper, "name-input")).toBe(false);
      const alert = wrapper.get("[data-testid=read-failed]");
      expect(alert.attributes("role")).toBe("alert");
      expect(alert.text()).toContain("Nie udało się odczytać stanu. Spróbuj ponownie.");
      expect(alert.get("[data-testid=retry-read]").text()).toBe("Spróbuj ponownie");
      await wrapper.get("[data-testid=name-input]").setValue("Zmieniona po limicie");
      release({ data: promotion({ name: "Spóźniona", status: "active", version: 9 }), response: { status: 200 } });
      await settle();
      expect(input(wrapper, "name-input").value).toBe("Zmieniona po limicie");
      expect(badge()).toBe("Zaplanowana");

      readAs({ name: "Z serwera", status: "active", version: 10 });
      await alert.get("[data-testid=retry-read]").trigger("click");
      await settle();

      expect(wrapper.find("[data-testid=read-failed]").exists()).toBe(false);
      expect(input(wrapper, "name-input").value).toBe("Z serwera");
      expect(badge()).toBe("Aktywna");
    });

    it("gives up 10 s after the answer of the save when a read has no answer, and the late active answer changes nothing", async () => {
      const wrapper = await saveNow();
      let release!: (value: unknown) => void;
      serveRead(() => new Promise((resolve) => (release = resolve)));

      await vi.advanceTimersByTimeAsync(9999);
      expect(reads()).toBe(1);
      expect(off(wrapper, "name-input")).toBe(true);
      await vi.advanceTimersByTimeAsync(1);
      await settle();

      expect(off(wrapper, "name-input")).toBe(false);
      expect(card("Status").textContent).toContain(READ_FAILED);
      expect(text(statusRegion()) ?? "").toBe("");
      await wrapper.get("[data-testid=name-input]").setValue("Zmieniona po limicie");

      release({ data: promotion({ status: "active", name: "Z odczytu", version: 9 }), response: { status: 200 } });
      await settle();

      expect(input(wrapper, "name-input").value).toBe("Zmieniona po limicie");
      expect(badge()).toBe("Zaplanowana");
      expect(card("Status").textContent).toContain(READ_FAILED);
      expect(text(statusRegion()) ?? "").toBe("");
      // The next save carries the version of the save, not the one of the late read.
      mocks.put.mockResolvedValue({ data: promotion({ name: "Zmieniona po limicie", version: 6 }), response: { status: 200 } });
      await click(wrapper, "save");
      expect(mocks.put.mock.calls.at(-1)![1].body.version).toBe(5);
    });

    it("waits on after a new promotion created „od teraz” moved to its page, and says in the card „Status” that it is active", async () => {
      const wrapper = await open("/promotions/new");
      await wrapper.get("[data-testid=name-input]").setValue("Jesień w górach");
      await wrapper.get("[data-testid=percent-input]").setValue("20");
      await click(wrapper, "start-now");
      await wrapper.get("[data-testid=ends-input]").setValue("2026-11-30T00:00");
      await click(wrapper, "open-picker");
      await pickerRows(wrapper)[0]!.get("[data-testid=add-variant]").trigger("click");
      mocks.post.mockResolvedValue({ data: promotion({ status: "planned", startsAt: "2026-11-15T10:20:31+00:00" }), response: { status: 201 } });
      await click(wrapper, "save");
      mocks.get.mockClear();

      expect(mounted!.router.currentRoute.value.path).toBe("/promotions/promo-1");
      expect(text(statusRegion())).toBe("Promocja rozpoczyna się.");
      expect(off(wrapper, "name-input")).toBe(true);
      readAs({ status: "active" });

      await vi.advanceTimersByTimeAsync(2000);
      await settle();

      expect(reads()).toBe(1);
      expect(badge()).toBe("Aktywna");
      expect(text(statusRegion())).toBe("Promocja jest aktywna.");
      expect(off(wrapper, "name-input")).toBe(false);
    });

    it("makes no read after the form is left", async () => {
      await saveNow();
      readAs({ status: "active" });
      mounted!.wrapper.unmount();
      mounted = undefined;

      await vi.advanceTimersByTimeAsync(30_000);
      await flushPromises();

      expect(reads()).toBe(0);
    });

    it("does not read again after a save with a start in the future", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=starts-input]").setValue("2026-11-18T10:00");
      mocks.put.mockResolvedValue({ data: promotion({ status: "planned", startsAt: "2026-11-18T09:00:00+00:00", version: 5 }), response: { status: 200 } });
      await click(wrapper, "save");
      mocks.get.mockClear();

      await vi.advanceTimersByTimeAsync(30_000);

      expect(reads()).toBe(0);
      expect(text(statusRegion()) ?? "").toBe("");
      expect(off(wrapper, "name-input")).toBe(false);
    });
  });

  describe("an ended promotion", () => {
    it("is read-only, with the note in the card „Status”, without any action or save bar", async () => {
      const wrapper = await open("/promotions/promo-1", undefined, promotion({ status: "ended" }));

      for (const field of ["name-input", "percent-input", "starts-input", "ends-input", "start-now"]) {
        expect(disabled(wrapper, field), field).toBe(true);
      }
      for (const action of ["save", "end-now", "delete", "open-picker", "remove-variant"]) {
        expect(wrapper.find(`[data-testid=${action}]`).exists(), action).toBe(false);
      }
      expect(saveBar()).toBeNull();
      expect(card("Status").contains(wrapper.get("[data-testid=read-only-note]").element)).toBe(true);
      expect(wrapper.get("[data-testid=read-only-note]").text()).toBe("Zakończonej promocji nie można zmienić. Widok jest tylko do odczytu.");
      expect(wrapper.find("[data-testid=schedule-locked-note]").exists()).toBe(false);
    });
  });

  describe("deleting", () => {
    const deleteButton = (wrapper: Wrapper) => wrapper.get<HTMLButtonElement>("[data-testid=delete]");

    it("has „Usuń” in the card „Status” of a planned promotion", async () => {
      const wrapper = await open();

      expect(card("Status").contains(deleteButton(wrapper).element)).toBe(true);
      expect(deleteButton(wrapper).text()).toBe("Usuń");
    });

    it("#304: asks in an alert dialog named by the promotion with „Wróć” in the focus, and closes with Escape giving the focus back", async () => {
      const wrapper = await open();
      deleteButton(wrapper).element.focus();
      await click(wrapper, "delete");

      const root = dialog();
      expect(document.getElementById(root!.getAttribute("aria-labelledby")!)?.textContent).toBe("Usunąć promocję Jesień w górach?");
      expect(dialogButton("Usuń promocję")?.className).toContain("bg-destructive");
      expect(document.activeElement).toBe(dialogButton("Wróć"));

      pressEscape();
      await settle();

      expect(dialog()).toBeNull();
      expect(document.activeElement).toBe(deleteButton(wrapper).element);
      expect(mocks.delete).not.toHaveBeenCalled();
    });

    it("deletes after the confirmation, with the version, says so in a toast and goes to the list", async () => {
      const wrapper = await open();
      mocks.delete.mockResolvedValue({ data: undefined, response: { status: 204 } });
      await click(wrapper, "delete");
      expect(mocks.delete).not.toHaveBeenCalled();

      dialogButton("Usuń promocję")!.click();
      await settle();

      expect(mocks.delete).toHaveBeenCalledWith(PROMOTION, { params: { path: { id: "promo-1" }, query: { version: 4 } } });
      expect(mounted!.router.currentRoute.value.name).toBe("promotions");
      expect(successToast).toHaveBeenCalledTimes(1);
      expect(successToast).toHaveBeenCalledWith("Promocja usunięta.");
    });

    it("does not offer deleting while the form has unsaved changes", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=name-input]").setValue("Inna nazwa");

      expect(deleteButton(wrapper).attributes("disabled")).toBeDefined();
    });

    it("shows the detail of a 409, the promotion as it is now, and the message with the focus", async () => {
      const wrapper = await open();
      mocks.delete.mockResolvedValue(conflict("promotion-not-editable", "Tylko zaplanowaną promocję można usunąć."));
      serve(promotion({ status: "active" }));
      await click(wrapper, "delete");

      dialogButton("Usuń promocję")!.click();
      await settle();

      expect(wrapper.get("[data-testid=form-error]").text()).toContain("Tylko zaplanowaną promocję można usunąć.");
      expect(text(card("Status").querySelector("[data-testid=promotion-status]"))).toBe("Aktywna");
      expect(wrapper.find("[data-testid=delete]").exists()).toBe(false);
      expect(mounted!.router.currentRoute.value.name).toBe("promotion");
      expect(successToast).not.toHaveBeenCalled();
      expect(document.activeElement).toBe(wrapper.get("[data-testid=form-error]").element);
    });
  });

  describe("a new promotion", () => {
    it("starts empty, with the picker closed, and with the save bar „Nowa promocja” and „Anuluj”, which goes back to the list", async () => {
      const wrapper = await open("/promotions/new");

      expect(wrapper.get("h1").text()).toBe("Nowa promocja");
      expect(mocks.get).not.toHaveBeenCalled();
      expect(wrapper.find("[data-testid=promotion-status]").exists()).toBe(false);
      expect(wrapper.find("[data-testid=delete]").exists()).toBe(false);
      expect(wrapper.find("[data-testid=end-now]").exists()).toBe(false);
      expect(wrapper.find("[data-testid=picker]").exists()).toBe(false);
      expect(wrapper.get("[data-testid=variants-empty]").text()).toBe("Nie wybrano wariantów.");
      expect(saveBar()?.textContent).toContain("Nowa promocja");
      expect(barButton("Odrzuć")).toBeUndefined();

      barButton("Anuluj")!.click();
      await settle();

      expect(mounted!.router.currentRoute.value.name).toBe("promotions");
    });

    it("creates the promotion, moves to its page, says so in a toast and moves the focus to the H1", async () => {
      const wrapper = await open("/promotions/new");
      await wrapper.get("[data-testid=name-input]").setValue("Jesień w górach");
      await wrapper.get("[data-testid=percent-input]").setValue("20");
      await wrapper.get("[data-testid=starts-input]").setValue("2026-11-20T00:00");
      await wrapper.get("[data-testid=ends-input]").setValue("2026-11-30T00:00");
      await click(wrapper, "open-picker");
      await pickerRows(wrapper)[0]!.get("[data-testid=add-variant]").trigger("click");
      mocks.post.mockResolvedValue({ data: promotion(), response: { status: 201 } });

      (wrapper.get("[data-testid=save]").element as HTMLElement).focus();
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
      expect(mounted!.router.currentRoute.value.name).toBe("promotion");
      expect(mounted!.router.currentRoute.value.path).toBe("/promotions/promo-1");
      expect(successToast).toHaveBeenCalledWith("Promocja zapisana.");
      expect(wrapper.find("[data-testid=success-message]").exists()).toBe(false);
      expect(saveBar()).toBeNull();
      expect(document.activeElement).toBe(heading("h1"));
      expect(heading("h1")?.textContent?.trim()).toBe("Jesień w górach");
      // The page of the created promotion is the same instance, not loaded again.
      expect(mocks.get.mock.calls.filter(([path]) => path === PROMOTION)).toHaveLength(0);
    });

    it("puts the violations of a 422 at their fields", async () => {
      const wrapper = await open("/promotions/new");
      await wrapper.get("[data-testid=name-input]").setValue("N");
      await wrapper.get("[data-testid=percent-input]").setValue("95");
      await click(wrapper, "start-now");
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

  describe("leaving with unsaved changes", () => {
    it("asks in a dialog and stays, with the form as it was, when the staff member stays", async () => {
      const wrapper = await open();
      const confirm = vi.spyOn(window, "confirm");

      await wrapper.get("[data-testid=name-input]").setValue("Inna");
      const navigation = mounted!.router.push("/promotions");
      await settle();

      expect(dialog()?.textContent).toContain("Masz niezapisane zmiany");
      expect(document.activeElement).toBe(dialogButton("Zostań"));
      dialogButton("Zostań")!.click();
      await navigation;
      await settle();

      expect(confirm).not.toHaveBeenCalled();
      expect(dialog()).toBeNull();
      expect(mounted!.router.currentRoute.value.name).toBe("promotion");
      expect(input(wrapper, "name-input").value).toBe("Inna");
      expect(saveBar()).not.toBeNull();
    });

    it("leaves with „Opuść bez zapisu”", async () => {
      await open();
      const wrapper = mounted!.wrapper;
      await wrapper.get("[data-testid=percent-input]").setValue("25");

      const navigation = mounted!.router.push("/promotions");
      await settle();
      dialogButton("Opuść bez zapisu")!.click();
      await navigation;
      await settle();

      expect(mounted!.router.currentRoute.value.name).toBe("promotions");
      expect(mocks.put).not.toHaveBeenCalled();
    });

    it("does not ask when nothing changed", async () => {
      await open();

      await mounted!.router.push("/promotions");
      await settle();

      expect(dialog()).toBeNull();
      expect(mounted!.router.currentRoute.value.name).toBe("promotions");
    });
  });

  describe("choosing variants", () => {
    async function openPicker() {
      const wrapper = await open("/promotions/new");
      await click(wrapper, "open-picker");
      return wrapper;
    }

    it("opens from the button, which says it is expanded, puts the focus in the search and closes with the focus back on the button", async () => {
      const wrapper = await open("/promotions/new");
      const opener = wrapper.get("[data-testid=open-picker]");
      expect(opener.attributes("aria-expanded")).toBe("false");

      await opener.trigger("click");
      await settle();

      expect(opener.attributes("aria-expanded")).toBe("true");
      expect(document.activeElement).toBe(searchField(wrapper));
      expect(wrapper.get("[data-testid=picker]").attributes("aria-label")).toBe("Wybór wariantów");
      await click(wrapper, "close-picker");
      expect(wrapper.find("[data-testid=picker]").exists()).toBe(false);
      expect(opener.attributes("aria-expanded")).toBe("false");
      expect(document.activeElement).toBe(opener.element);
    });

    it("lists the products of the price list with a row per variant, 25 products per page", async () => {
      const wrapper = await openPicker();

      expect(mocks.get).toHaveBeenCalledWith(PRICES, { params: { query: { perPage: 25 } } });
      expect(pickerRows(wrapper).map((row) => row.text())).toEqual([
        expect.stringContaining("Namiot Tarvik"),
        expect.stringContaining("Czerwony"),
        expect.stringContaining("Bez ceny"),
        expect.stringContaining("Śpiwór"),
      ]);
      expect(pickerRows(wrapper)[2]!.text()).toContain("Brak ceny");
    });

    it("#304: names the buttons with the name of the variant and the product", async () => {
      const wrapper = await openPicker();

      expect(pickerRows(wrapper).map((row) => row.get("[data-testid=add-variant]").attributes("aria-label"))).toEqual([
        "Dodaj: Namiot Tarvik / Zielony",
        "Dodaj: Namiot Tarvik / Czerwony",
        "Dodaj: Namiot Tarvik / Bez ceny",
        "Dodaj: Śpiwór",
      ]);
      expect(wrapper.findAll("[data-testid=add-product]").map((button) => button.attributes("aria-label"))).toEqual([
        "Dodaj cały produkt: Namiot Tarvik",
        "Dodaj cały produkt: Śpiwór",
      ]);
      await pickerRows(wrapper)[1]!.get("[data-testid=add-variant]").trigger("click");
      await settle();
      expect(wrapper.get("[data-variant-id=a-red] [data-testid=remove-variant]").attributes("aria-label")).toBe("Usuń: Namiot Tarvik / Czerwony");
      expect(pickerRows(wrapper)[1]!.get("[data-testid=add-variant]").attributes("aria-label")).toBe("Dodano: Namiot Tarvik / Czerwony");
    });

    it("adds a single variant, and shows it as added", async () => {
      const wrapper = await openPicker();

      await pickerRows(wrapper)[1]!.get("[data-testid=add-variant]").trigger("click");
      await settle();

      expect(selectedIds(wrapper)).toEqual(["a-red"]);
      const row = variantRows(wrapper)[0]!;
      expect(row.get("[data-testid=variant-name]").text()).toBe("Namiot Tarvik");
      expect(row.get("[data-testid=variant-label]").text()).toBe("Czerwony");
      expect(row.get("[data-testid=variant-regular]").text()).toMatch(/^120,00\s*zł$/);
      expect(row.get("[data-testid=variant-promotional]").text()).toBe("—");
      expect(pickerRows(wrapper)[1]!.get("[data-testid=add-variant]").attributes("disabled")).toBeDefined();
      expect(pickerRows(wrapper)[1]!.get("[data-testid=add-variant]").text()).toBe("Dodano");
    });

    it("adds a whole product: all its variants that have a price", async () => {
      const wrapper = await openPicker();

      await wrapper.get("[data-testid=add-product]").trigger("click");
      await settle();

      expect(selectedIds(wrapper)).toEqual(["a-green", "a-red"]);
    });

    it("does not add a variant twice, whether single or with its product", async () => {
      const wrapper = await openPicker();
      await pickerRows(wrapper)[1]!.get("[data-testid=add-variant]").trigger("click");

      await wrapper.get("[data-testid=add-product]").trigger("click");
      await settle();

      expect(selectedIds(wrapper)).toEqual(["a-red", "a-green"]);
    });

    it("offers to add a whole product only while it has a variant that can be added", async () => {
      const wrapper = await openPicker();
      const button = (index: number) => wrapper.findAll("[data-testid=add-product]")[index]!;
      expect(button(0).attributes("disabled")).toBeUndefined();

      await button(0).trigger("click");
      await settle();

      // The variant without a price is never added, so nothing is left to add for this product.
      expect(button(0).attributes("disabled")).toBeDefined();
      expect(selectedIds(wrapper)).toEqual(["a-green", "a-red"]);
      expect(button(1).attributes("disabled")).toBeUndefined();

      await pickerRows(wrapper)[3]!.get("[data-testid=add-variant]").trigger("click");
      await settle();

      expect(button(1).attributes("disabled")).toBeDefined();
    });

    it("cannot add a variant without a price", async () => {
      const wrapper = await openPicker();

      expect(pickerRows(wrapper)[2]!.get("[data-testid=add-variant]").attributes("disabled")).toBeDefined();
    });

    it("#304: puts the focus back in the search of the picker after adding a variant, as the button that had it goes off", async () => {
      const wrapper = await openPicker();
      const add = pickerRows(wrapper)[1]!.get<HTMLButtonElement>("[data-testid=add-variant]");
      add.element.focus();

      await add.trigger("click");
      await settle();

      expect(document.activeElement).toBe(searchField(wrapper));
    });

    it("#304: puts the focus back in the search of the picker after adding a whole product", async () => {
      const wrapper = await openPicker();
      const add = wrapper.get<HTMLButtonElement>("[data-testid=add-product]");
      add.element.focus();

      await add.trigger("click");
      await settle();

      expect(document.activeElement).toBe(searchField(wrapper));
    });

    it("removes a chosen variant", async () => {
      const wrapper = await openPicker();
      await wrapper.get("[data-testid=add-product]").trigger("click");
      await settle();

      await removeButton(wrapper, "a-green").trigger("click");
      await settle();

      expect(selectedIds(wrapper)).toEqual(["a-red"]);
      expect(pickerRows(wrapper)[0]!.text()).toContain("Namiot Tarvik");
      expect(pickerRows(wrapper)[0]!.get("[data-testid=add-variant]").attributes("disabled")).toBeUndefined();
    });

    async function openPickerWithThree() {
      const wrapper = await openPicker();
      await wrapper.get("[data-testid=add-product]").trigger("click");
      await pickerRows(wrapper)[3]!.get("[data-testid=add-variant]").trigger("click");
      await settle();
      expect(selectedIds(wrapper)).toEqual(["a-green", "a-red", "b-1"]);
      return wrapper;
    }

    it("#304: puts the focus on the button that removes the next row after removing a chosen variant", async () => {
      const wrapper = await openPickerWithThree();
      removeButton(wrapper, "a-green").element.focus();

      await removeButton(wrapper, "a-green").trigger("click");
      await settle();

      expect(document.activeElement).toBe(removeButton(wrapper, "a-red").element);
    });

    it("#304: puts the focus on the button of the next row also from the middle of the list", async () => {
      const wrapper = await openPickerWithThree();

      await removeButton(wrapper, "a-red").trigger("click");
      await settle();

      expect(document.activeElement).toBe(removeButton(wrapper, "b-1").element);
    });

    it("#304: puts the focus in the search of the open picker after removing the last row", async () => {
      const wrapper = await openPickerWithThree();

      await removeButton(wrapper, "b-1").trigger("click");
      await settle();

      expect(document.activeElement).toBe(searchField(wrapper));
    });

    it("#304: puts the focus on the button that opens the picker after removing the last row when the picker is closed", async () => {
      const wrapper = await openPickerWithThree();
      await click(wrapper, "close-picker");

      await removeButton(wrapper, "b-1").trigger("click");
      await settle();

      expect(document.activeElement).toBe(wrapper.get("[data-testid=open-picker]").element);
    });

    it("searches the price list by name once the typing pauses, and says that a phrase that is too short is not sent", async () => {
      const wrapper = await openPicker();

      await wrapper.get("[data-testid=picker-search]").setValue("ś");
      await vi.advanceTimersByTimeAsync(NAME_SEARCH_DEBOUNCE_MS);
      await flushPromises();
      expect(wrapper.get("[data-testid=picker]").text()).toContain("Wpisz co najmniej 2 znaki, aby wyszukać.");
      expect(mocks.get).toHaveBeenCalledTimes(1);

      await wrapper.get("[data-testid=picker-search]").setValue("śpi");
      await vi.advanceTimersByTimeAsync(NAME_SEARCH_DEBOUNCE_MS);
      await flushPromises();

      expect(mocks.get).toHaveBeenLastCalledWith(PRICES, { params: { query: { q: "śpi", perPage: 25 } } });
      expect(wrapper.get("[data-testid=picker]").text()).not.toContain("Wpisz co najmniej");
    });

    it("has a labelled search field", async () => {
      const wrapper = await openPicker();

      expect(wrapper.get("[data-testid=picker-search]").attributes("aria-label")).toBe("Szukaj produktu po nazwie");
    });

    it("says that nothing matches", async () => {
      const wrapper = await open("/promotions/new");
      serve(promotion(), []);
      await click(wrapper, "open-picker");

      expect(wrapper.get("[data-testid=picker-empty]").text()).toBe("Brak produktów spełniających kryteria.");
    });

    it("shows an error with a retry in the picker, and the retry loads the list", async () => {
      const wrapper = await open("/promotions/new");
      mocks.get.mockResolvedValue({ data: undefined, response: { status: 500 } });
      await click(wrapper, "open-picker");

      expect(wrapper.get("[data-testid=picker-error]").text()).toContain("Nie udało się pobrać cen (HTTP 500).");
      serve();
      await click(wrapper, "picker-retry");

      expect(wrapper.find("[data-testid=picker-error]").exists()).toBe(false);
      expect(pickerRows(wrapper)).toHaveLength(4);
    });

    it("pages the price list with the buttons of the picker", async () => {
      const wrapper = await open("/promotions/new");
      mocks.get.mockImplementation(async (_path: string, options?: { params?: { query?: { after?: string } } }) =>
        options?.params?.query?.after === "c1"
          ? { data: { items: [catalog[1]], next: null }, response: { status: 200 } }
          : { data: { items: [catalog[0]], next: "c1" }, response: { status: 200 } },
      );
      await click(wrapper, "open-picker");
      expect(wrapper.get("[data-testid=picker-previous]").attributes("disabled")).toBeDefined();

      await click(wrapper, "picker-next");

      expect(wrapper.get("[data-testid=picker]").text()).toContain("Strona 2");
      expect(pickerRows(wrapper)[0]!.text()).toContain("Śpiwór");
      expect(wrapper.get("[data-testid=picker-next]").attributes("disabled")).toBeDefined();
      await click(wrapper, "picker-previous");
      expect(pickerRows(wrapper)[0]!.text()).toContain("Namiot Tarvik");
    });

    it("closes the picker and keeps the chosen variants", async () => {
      const wrapper = await openPicker();
      await wrapper.get("[data-testid=add-product]").trigger("click");

      await click(wrapper, "close-picker");

      expect(wrapper.find("[data-testid=picker]").exists()).toBe(false);
      expect(variantRows(wrapper)).toHaveLength(2);
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
      expect(variantRows(wrapper)[0]!.find("[data-testid=variant-conflict]").exists()).toBe(false);
      expect(variantRows(wrapper)[1]!.get("[data-testid=variant-conflict]").text()).toBe("Koliduje z inną promocją");
      expect(input(wrapper, "name-input").value).toBe("Inna");
    });

    it("keeps what was typed and does not read the promotion when the version changed, and offers the server state", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=name-input]").setValue("Inna");
      mocks.put.mockResolvedValue(conflict("about:blank", "Ktoś zmienił."));
      serve(promotion({ name: "Zmieniona przez kogoś", version: 9 }));
      mocks.get.mockClear();

      await click(wrapper, "save");
      await vi.advanceTimersByTimeAsync(30_000);

      expect(wrapper.get("[data-testid=form-error]").text()).toContain("Promocja została w międzyczasie zmieniona");
      expect(input(wrapper, "name-input").value).toBe("Inna");
      expect(mocks.get).not.toHaveBeenCalled();
      expect(wrapper.get("[data-testid=restore-state]").text()).toBe("Odrzuć moje zmiany i pokaż stan z serwera");
    });

    it("shows the state of the server only after „Odrzuć moje zmiany i pokaż stan z serwera”, with the focus on the H1", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=name-input]").setValue("Inna");
      mocks.put.mockResolvedValue(conflict("about:blank", "Ktoś zmienił."));
      await click(wrapper, "save");
      serve(promotion({ name: "Zmieniona przez kogoś", version: 9 }));
      (wrapper.get("[data-testid=restore-state]").element as HTMLElement).focus();

      await click(wrapper, "restore-state");

      expect(input(wrapper, "name-input").value).toBe("Zmieniona przez kogoś");
      expect(wrapper.find("[data-testid=form-error]").exists()).toBe(false);
      expect(wrapper.find("[data-testid=restore-state]").exists()).toBe(false);
      expect(document.activeElement).toBe(heading("h1"));
    });

    it("keeps the way to the server state with its context after „Odrzuć”, the label as it was, and the focus on the message", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=name-input]").setValue("Inna");
      mocks.put.mockResolvedValue(conflict("promotion-not-editable", "Rozpoczętej promocji nie można zmienić."));
      await click(wrapper, "save");

      barButton("Odrzuć")!.click();
      await settle();

      expect(input(wrapper, "name-input").value).toBe("Jesień w górach");
      expect(wrapper.find("[data-testid=form-error]").exists()).toBe(false);
      const note = wrapper.get("[data-testid=restore-note]");
      expect(note.text()).toContain("Rozpoczętej promocji nie można zmienić.");
      expect(wrapper.find("[data-testid=check-state]").exists()).toBe(false);
      expect(wrapper.get("[data-testid=restore-state]").text()).toBe("Odrzuć moje zmiany i pokaż stan z serwera");
      expect(document.activeElement).toBe(note.element);
      await wrapper.get("[data-testid=name-input]").setValue("Nowa zmiana");
      expect(wrapper.get("[data-testid=restore-state]").text()).toBe("Odrzuć moje zmiany i pokaż stan z serwera");
    });

    it("puts the focus on the context of the 409 every time „Odrzuć” is used, also the second time with the same text", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=name-input]").setValue("Inna");
      mocks.put.mockResolvedValue(conflict("promotion-not-editable", "Rozpoczętej promocji nie można zmienić."));
      await click(wrapper, "save");
      barButton("Odrzuć")!.focus();
      barButton("Odrzuć")!.click();
      await settle();
      expect(document.activeElement).toBe(wrapper.get("[data-testid=restore-note]").element);

      await wrapper.get("[data-testid=name-input]").setValue("Jeszcze inna");
      barButton("Odrzuć")!.focus();
      barButton("Odrzuć")!.click();
      await settle();

      expect(document.activeElement).not.toBe(document.body);
      expect(document.activeElement).toBe(wrapper.get("[data-testid=restore-note]").element);
    });

    it("shows the detail of another 409 and does not read the promotion", async () => {
      const wrapper = await open();
      await wrapper.get("[data-testid=name-input]").setValue("Inna");
      mocks.put.mockResolvedValue(conflict("promotion-not-editable", "Rozpoczętej promocji nie można zmienić."));
      serve(promotion({ status: "active" }));
      mocks.get.mockClear();

      await click(wrapper, "save");

      expect(wrapper.get("[data-testid=form-error]").text()).toContain("Rozpoczętej promocji nie można zmienić.");
      expect(text(card("Status").querySelector("[data-testid=promotion-status]"))).toBe("Zaplanowana");
      expect(input(wrapper, "name-input").value).toBe("Inna");
      expect(mocks.get).not.toHaveBeenCalled();
    });
  });

  describe("loading", () => {
    it("says so when the promotion does not exist: the heading, the message with the colour of an error, and a link to the list (#446)", async () => {
      const wrapper = await open("/promotions/nope", undefined, null);

      const alert = wrapper.get("[data-testid=load-error]");
      expect(alert.text()).toContain("Ta promocja nie istnieje.");
      expect(alert.attributes("role")).toBe("alert");
      expect(alert.classes().join(" ")).toContain("text-status-danger");
      expect(alert.get("a").attributes("href")).toBe("/promotions");
      expect(wrapper.find("[data-testid=name-input]").exists()).toBe(false);
      expect(wrapper.get("h1").text()).toBe("Nie udało się wczytać promocji");
      expect(saveBar()).toBeNull();
    });

    it("keeps that heading for an existing promotion that fails to load", async () => {
      mocks.get.mockResolvedValue({ data: undefined, error: {}, response: { status: 500 } });
      const wrapper = await open("/promotions/promo-1");

      expect(wrapper.find("[data-testid=load-error]").exists()).toBe(true);
      expect(wrapper.get("h1").text()).toBe("Nie udało się wczytać promocji");
    });

    it("keeps the heading of an existing promotion until it is loaded", async () => {
      mocks.get.mockReturnValue(new Promise(() => {}));
      const wrapper = await open("/promotions/promo-1");

      expect(wrapper.get("h1").text()).toBe("Promocja");
    });
  });

  describe("who can change promotions", () => {
    it.each([[["admin"]], [["marketing"]], [["support", "marketing"]]] as StaffMe["roles"][][])("offers the actions to the roles %j", async (roles) => {
      const wrapper = await open("/promotions/promo-1", roles);
      await wrapper.get("[data-testid=name-input]").setValue("Inna");

      for (const action of ["save", "delete", "open-picker", "remove-variant"]) {
        expect(wrapper.find(`[data-testid=${action}]`).exists(), action).toBe(true);
      }
      expect(wrapper.find("[data-testid=read-only-note]").exists()).toBe(false);
      expect(disabled(wrapper, "name-input")).toBe(false);
    });

    it.each([[["support"]], [["warehouse"]], [["support", "warehouse"]], [[]]] as StaffMe["roles"][][])(
      "shows the roles %j the promotion read-only, with its variants and prices and the note in the card „Status”",
      async (roles) => {
        const wrapper = await open("/promotions/promo-1", roles, promotion({ status: "active" }));

        for (const action of ["save", "delete", "end-now", "open-picker", "remove-variant"]) {
          expect(wrapper.find(`[data-testid=${action}]`).exists(), action).toBe(false);
        }
        for (const field of ["name-input", "percent-input", "starts-input", "ends-input"]) {
          expect(disabled(wrapper, field), field).toBe(true);
        }
        expect(saveBar()).toBeNull();
        expect(card("Status").contains(wrapper.get("[data-testid=read-only-note]").element)).toBe(true);
        expect(wrapper.get("[data-testid=read-only-note]").text()).toContain("tylko do odczytu");
        expect(variantRows(wrapper)).toHaveLength(2);
        expect(variantRows(wrapper)[0]!.get("[data-testid=variant-promotional]").text()).toMatch(/80,00/);
      },
    );
  });

  describe("the unknown result of a save (stage 2, B4)", () => {
    const UNKNOWN = "Nie wiemy, czy zapis się powiódł. Twoje zmiany zostają w formularzu.";
    const alert = (wrapper: Wrapper) => wrapper.get("[data-testid=unknown-result]");

    /** A planned promotion with a changed name and a save whose answer waits for the test; `calls[n]` answers the n-th. */
    async function saveHeld(path = "/promotions/promo-1") {
      const wrapper = await open(path);
      if (path.endsWith("/new")) {
        await wrapper.get("[data-testid=name-input]").setValue("Nowa");
        await wrapper.get("[data-testid=percent-input]").setValue("10");
        await wrapper.get("[data-testid=starts-input]").setValue("2026-11-20T00:00");
        await wrapper.get("[data-testid=ends-input]").setValue("2026-11-30T00:00");
        await click(wrapper, "open-picker");
        await pickerRows(wrapper)[0]!.get("[data-testid=add-variant]").trigger("click");
        await settle();
      } else {
        await wrapper.get("[data-testid=name-input]").setValue("Pierwsza");
      }
      const calls: { resolve: (value: unknown) => void; reject: (reason: unknown) => void }[] = [];
      const hold = () =>
        new Promise((resolve, reject) => {
          calls.push({ resolve, reject });
        });
      mocks.put.mockImplementation(hold);
      mocks.post.mockImplementation(hold);
      await wrapper.get("[data-testid=save]").trigger("click");
      await settle();
      return { wrapper, calls };
    }

    it("says after 10 s that the result is unknown, with „Sprawdź stan” and the focus on the message, and keeps the fields locked", async () => {
      const { wrapper } = await saveHeld();

      await vi.advanceTimersByTimeAsync(9_999);
      expect(wrapper.find("[data-testid=unknown-result]").exists()).toBe(false);
      await vi.advanceTimersByTimeAsync(1);
      await settle();

      expect(alert(wrapper).text()).toContain(UNKNOWN);
      expect(alert(wrapper).attributes("role")).toBe("alert");
      expect(alert(wrapper).get("[data-testid=check-state]").text()).toBe("Sprawdź stan");
      expect(wrapper.find("[data-testid=unblock-form]").exists()).toBe(false);
      expect(document.activeElement).toBe(alert(wrapper).element);
      expect(input(wrapper, "name-input").matches(":disabled")).toBe(true);
      expect(wrapper.get<HTMLButtonElement>("[data-testid=save]").element.disabled).toBe(true);
    });

    it("„Sprawdź stan” takes the promotion from the server when it equals the form, says so in a status region and sends no write", async () => {
      const { wrapper } = await saveHeld();
      await vi.advanceTimersByTimeAsync(10_000);
      await settle();
      serve(promotion({ name: "Pierwsza", version: 5 }));

      await click(wrapper, "check-state");

      expect(wrapper.find("[data-testid=unknown-result]").exists()).toBe(false);
      expect(wrapper.get("[data-testid=safety-status]").text()).toBe("Stan na serwerze odpowiada formularzowi.");
      expect(wrapper.get("[data-testid=safety-status]").attributes("role")).toBe("status");
      expect(input(wrapper, "name-input").matches(":disabled")).toBe(false);
      expect(input(wrapper, "name-input").value).toBe("Pierwsza");
      expect(saveBar()).toBeNull();
      expect(mocks.put).toHaveBeenCalledTimes(1);
    });

    it("„Sprawdź stan” says the write is not on the server when the version is the same and the values are not", async () => {
      const { wrapper } = await saveHeld();
      await vi.advanceTimersByTimeAsync(10_000);
      await settle();

      await click(wrapper, "check-state");

      expect(alert(wrapper).text()).toContain("Zapisu nie widać na serwerze. Może jeszcze dotrzeć.");
      expect(document.activeElement).toBe(alert(wrapper).element);
      expect(input(wrapper, "name-input").value).toBe("Pierwsza");
      expect(input(wrapper, "name-input").matches(":disabled")).toBe(false);
    });

    it("„Sprawdź stan” offers „Odrzuć moje zmiany i pokaż stan z serwera” when the version on the server is another", async () => {
      const { wrapper } = await saveHeld();
      await vi.advanceTimersByTimeAsync(10_000);
      await settle();
      serve(promotion({ name: "Cudza", version: 8 }));

      await click(wrapper, "check-state");

      expect(alert(wrapper).text()).toContain("Na serwerze jest inna wersja niż ta, którą edytujesz.");
      expect(input(wrapper, "name-input").value).toBe("Pierwsza");
      await click(wrapper, "restore-state");
      expect(input(wrapper, "name-input").value).toBe("Cudza");
      expect(wrapper.find("[data-testid=unknown-result]").exists()).toBe(false);
    });

    it("says in a toast that the earlier save was done when its answer comes after „Sprawdź stan”, and changes nothing in the form", async () => {
      const { wrapper, calls } = await saveHeld();
      await vi.advanceTimersByTimeAsync(10_000);
      await settle();
      await click(wrapper, "check-state");
      await wrapper.get("[data-testid=name-input]").setValue("Druga");

      calls[0]!.resolve({ data: promotion({ name: "Pierwsza", version: 5 }), response: { status: 200 } });
      await settle();

      expect(successToast).toHaveBeenCalledWith("Wcześniejszy zapis został wykonany.");
      expect(successToast).not.toHaveBeenCalledWith("Promocja zapisana.");
      expect(input(wrapper, "name-input").value).toBe("Druga");
      expect(input(wrapper, "name-input").matches(":disabled")).toBe(false);
    });

    it("gives an ordinary result, with the toast, when the answer comes after the limit but before „Sprawdź stan”", async () => {
      const { wrapper, calls } = await saveHeld();
      await vi.advanceTimersByTimeAsync(10_000);
      await settle();

      calls[0]!.resolve({ data: promotion({ name: "Pierwsza", version: 5 }), response: { status: 200 } });
      await settle();

      expect(successToast).toHaveBeenCalledWith("Promocja zapisana.");
      expect(wrapper.find("[data-testid=unknown-result]").exists()).toBe(false);
      expect(input(wrapper, "name-input").matches(":disabled")).toBe(false);
    });

    it("moves the focus to the message after an error that does not reach the API, with the fields unlocked", async () => {
      const { wrapper, calls } = await saveHeld();

      calls[0]!.reject(new TypeError("network down"));
      await settle();

      expect(alert(wrapper).text()).toContain(UNKNOWN);
      expect(document.activeElement).toBe(alert(wrapper).element);
      expect(input(wrapper, "name-input").matches(":disabled")).toBe(false);
      expect(input(wrapper, "name-input").value).toBe("Pierwsza");
    });

    describe("a new promotion", () => {
      it("says after 10 s that the result is unknown, with „Odblokuj formularz” instead of „Sprawdź stan”", async () => {
        const { wrapper } = await saveHeld("/promotions/new");
        await vi.advanceTimersByTimeAsync(10_000);
        await settle();

        expect(alert(wrapper).text()).toContain(UNKNOWN);
        expect(wrapper.find("[data-testid=check-state]").exists()).toBe(false);
        expect(alert(wrapper).get("[data-testid=unblock-form]").text()).toBe("Odblokuj formularz");
        expect(input(wrapper, "name-input").matches(":disabled")).toBe(true);
      });

      it("unlocks the form with the message about the list and a link to it in a new tab, and a late success only gives a toast", async () => {
        const { wrapper, calls } = await saveHeld("/promotions/new");
        await vi.advanceTimersByTimeAsync(10_000);
        await settle();

        await click(wrapper, "unblock-form");

        expect(alert(wrapper).text()).toContain("Nie wiemy, czy promocja została utworzona. Wcześniejszy zapis może się jeszcze wykonać. Sprawdź listę przed ponownym zapisem.");
        const link = alert(wrapper).get("[data-testid=list-link]");
        expect(link.attributes("href")).toBe("/promotions");
        expect(link.attributes("target")).toBe("_blank");
        expect(link.attributes("rel")).toContain("noopener");
        expect(link.text()).toContain("Lista promocji");
        expect(input(wrapper, "name-input").matches(":disabled")).toBe(false);
        await wrapper.get("[data-testid=name-input]").setValue("Inna nazwa");

        calls[0]!.resolve({ data: promotion({ id: "created" }), response: { status: 201 } });
        await settle();

        expect(successToast).toHaveBeenCalledWith("Wcześniejszy zapis został wykonany.");
        expect(mounted!.router.currentRoute.value.name).toBe("promotion-new");
        expect(input(wrapper, "name-input").value).toBe("Inna nazwa");
      });
    });
  });
});
