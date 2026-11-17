import { DOMWrapper, flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { afterEach, beforeEach, describe, expect, it, type MockInstance, vi } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import { toast } from "vue-sonner";
import type { components } from "@/api/schema";
import { Select } from "@/components/ui/select";
import { type StaffMe, useAuthStore } from "@/stores/auth";
import InventoryView from "@/views/InventoryView.vue";
import type { WithOptionalTotal } from "./listPage";

type StockList = WithOptionalTotal<components["schemas"]["StockListResponse"]>;
type StockListItem = components["schemas"]["StockListItemResponse"];
type ListedStock = components["schemas"]["ListedStockResponse"];
type StockState = components["schemas"]["StockStateResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get, POST: mocks.post }) }));

const STOCK = "/api/v1/admin/stock";
const RECEIPTS = "/api/v1/admin/variants/{variantId}/stock/receipts";
const CORRECTIONS = "/api/v1/admin/variants/{variantId}/stock/corrections";

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

function state(overrides: Partial<StockState> = {}): StockState {
  return {
    variantId: "var-A",
    physical: 15,
    reserved: 4,
    available: 11,
    level: "in_stock",
    version: 4,
    updatedAt: "2026-11-02T13:00:00+00:00",
    ...overrides,
  };
}

/** Answers the stock list; `list(after)` is read on every request, so a test can change what the next reload brings. */
function serve(list: (after: string | undefined) => StockList) {
  mocks.get.mockImplementation(async (path: string, options?: { params?: { query?: { after?: string } } }) =>
    path === STOCK ? { data: list(options?.params?.query?.after), response: { status: 200 } } : { data: [], response: { status: 200 } },
  );
}

function stockQueries(): Record<string, unknown>[] {
  return mocks.get.mock.calls
    .filter(([path]) => path === STOCK)
    .map(([, options]) => (options as { params: { query: Record<string, unknown> } }).params.query);
}

function answer(status: number, body?: unknown) {
  const ok = status < 300;
  mocks.post.mockResolvedValue({ data: ok ? body : undefined, error: ok ? undefined : body, response: { status } });
}

async function settle() {
  await vi.advanceTimersByTimeAsync(0);
  await flushPromises();
}

/** Everything is in the body: the windows are teleported there, so a query goes through the whole document. */
const page = () => new DOMWrapper(document.body);

async function mountView(roles: StaffMe["roles"] = ["warehouse"]) {
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
  await router.push("/inventory");
  const wrapper = mount(InventoryView, { global: { plugins: [pinia, router] }, attachTo: document.body });
  await settle();
  return wrapper;
}

describe("InventoryView: receipts and corrections", () => {
  let wrapper: Awaited<ReturnType<typeof mountView>> | undefined;
  let successToast: MockInstance<typeof toast.success>;

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    mocks.get.mockReset();
    mocks.post.mockReset();
    // The toast store is global; the tests read what was shown from the calls.
    successToast = vi.spyOn(toast, "success").mockImplementation(() => 1);
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    document.body.innerHTML = "";
    vi.restoreAllMocks();
    vi.useRealTimers();
  });

  const one = (): StockList => ({ items: [item("A")], next: null });

  function dialog(id: string) {
    return page().get(`[data-testid=${id}]`);
  }

  async function open(button: "receive-stock" | "correct-stock", row = 0) {
    await page().findAll("[data-testid=stock-row]")[row]!.get(`[data-testid=${button}]`).trigger("click");
    await settle();
  }

  async function fillReceipt(quantity: string, note = "") {
    await page().get("#receive-quantity").setValue(quantity);
    await dialog("receive-note").setValue(note);
  }

  async function submitReceipt() {
    await dialog("receive-submit").trigger("click");
    await settle();
  }

  async function chooseReason(reason: string) {
    wrapper!.findAllComponents(Select).at(-1)!.vm.$emit("update:modelValue", reason);
    await settle();
  }

  async function fillCorrection(physical: string, reason = "inventory_count", note = "") {
    await page().get("#correct-physical").setValue(physical);
    await chooseReason(reason);
    await dialog("correct-note").setValue(note);
  }

  async function submitCorrection() {
    await dialog("correct-submit").trigger("click");
    await settle();
  }

  function physicalOfRow(row = 0) {
    return page().findAll("[data-testid=stock-row]")[row]!.get("[data-testid=physical]").text();
  }

  describe("a receipt", () => {
    it("sends the quantity and note with a UUID as Idempotency-Key made when the dialog opens", async () => {
      serve(one);
      const uuid = vi.spyOn(crypto, "randomUUID").mockReturnValue("11111111-1111-4111-8111-111111111111");
      wrapper = await mountView();
      answer(201, state());

      expect(uuid).not.toHaveBeenCalled();
      await open("receive-stock");
      expect(uuid).toHaveBeenCalledTimes(1);
      await fillReceipt("5", "Dostawa");
      await submitReceipt();

      expect(mocks.post).toHaveBeenCalledWith(RECEIPTS, {
        params: { path: { variantId: "var-A" }, header: { "Idempotency-Key": "11111111-1111-4111-8111-111111111111" } },
        body: { quantity: 5, note: "Dostawa" },
      });
    });

    it("receives into the variant of the row and replaces only that row, naming the variant in the dialog", async () => {
      serve(() => ({
        items: [
          item("Kajak", {
            variants: [
              { variantId: "v-green", label: "zielony", stock: stock({ physical: 0, reserved: 0, available: 0, level: "out_of_stock", version: 1 }), matchesLevel: false },
              { variantId: "v-orange", label: "pomarańczowy", stock: stock({ physical: 3, reserved: 0, available: 3, level: "low_stock", version: 2 }), matchesLevel: false },
            ],
          }),
        ],
        next: null,
      }));
      wrapper = await mountView();
      answer(201, state({ variantId: "v-orange", physical: 8, reserved: 0, available: 8, version: 3 }));

      await open("receive-stock", 1);
      expect(dialog("receive-dialog").text()).toContain("Kajak / pomarańczowy");
      await fillReceipt("5");
      await submitReceipt();

      expect(mocks.post).toHaveBeenCalledWith(RECEIPTS, expect.objectContaining({ params: expect.objectContaining({ path: { variantId: "v-orange" } }) }));
      expect([physicalOfRow(0), physicalOfRow(1)]).toEqual(["0", "8"]);
    });

    it("shows the stock the API answered with in the row and closes", async () => {
      serve(one);
      wrapper = await mountView();
      answer(201, state({ physical: 15, reserved: 4, available: 11 }));

      await open("receive-stock");
      await fillReceipt("5");
      await submitReceipt();

      const row = page().get("[data-testid=stock-row]");
      expect(["physical", "reserved", "available"].map((id) => row.get(`[data-testid=${id}]`).text())).toEqual(["15", "4", "11"]);
      expect(page().find("[data-testid=receive-dialog]").exists()).toBe(false);
    });

    it("sends the same key again when the same receipt is repeated after a network error", async () => {
      serve(one);
      vi.spyOn(crypto, "randomUUID").mockReturnValueOnce("11111111-1111-4111-8111-111111111111").mockReturnValueOnce("22222222-2222-4222-8222-222222222222");
      wrapper = await mountView();
      mocks.post.mockRejectedValueOnce(new TypeError("network down"));

      await open("receive-stock");
      await fillReceipt("5", "Dostawa");
      await submitReceipt();
      expect(dialog("receive-error").text()).toContain("połączenie");

      answer(200, state());
      await submitReceipt();

      const keys = mocks.post.mock.calls.map(([, options]) => options.params.header["Idempotency-Key"]);
      expect(keys).toEqual(["11111111-1111-4111-8111-111111111111", "11111111-1111-4111-8111-111111111111"]);
      expect(page().find("[data-testid=receive-dialog]").exists()).toBe(false);
    });

    it("makes a new key for a new dialog", async () => {
      serve(one);
      vi.spyOn(crypto, "randomUUID").mockReturnValueOnce("11111111-1111-4111-8111-111111111111").mockReturnValueOnce("22222222-2222-4222-8222-222222222222");
      wrapper = await mountView();
      mocks.post.mockRejectedValue(new TypeError("network down"));

      await open("receive-stock");
      await fillReceipt("5");
      await submitReceipt();
      await dialog("receive-cancel").trigger("click");
      await settle();
      await open("receive-stock");
      await fillReceipt("5");
      await submitReceipt();

      const keys = mocks.post.mock.calls.map(([, options]) => options.params.header["Idempotency-Key"]);
      expect(keys).toEqual(["11111111-1111-4111-8111-111111111111", "22222222-2222-4222-8222-222222222222"]);
    });

    it("does not send a missing quantity and says why at the field", async () => {
      serve(one);
      wrapper = await mountView();

      await open("receive-stock");
      await submitReceipt();

      expect(mocks.post).not.toHaveBeenCalled();
      expect(dialog("receive-quantity-error").text()).toContain("ilość");
    });

    it("shows the 422 violation at the field it names", async () => {
      serve(one);
      wrapper = await mountView();
      answer(422, {
        status: 422,
        violations: [
          { propertyPath: "quantity", title: "Ilość musi wynosić od 1 do 100 000." },
          { propertyPath: "note", title: "Notatka jest za długa." },
        ],
      });

      await open("receive-stock");
      await fillReceipt("0");
      await submitReceipt();

      expect(dialog("receive-quantity-error").text()).toBe("Ilość musi wynosić od 1 do 100 000.");
      expect(dialog("receive-note-error").text()).toBe("Notatka jest za długa.");
      expect(physicalOfRow()).toBe("10");
    });

    it("shows the detail of a 422 without violations (over 1 000 000) in the dialog", async () => {
      serve(one);
      wrapper = await mountView();
      answer(422, { status: 422, detail: "Stan fizyczny nie może przekroczyć 1 000 000." });

      await open("receive-stock");
      await fillReceipt("100000");
      await submitReceipt();

      expect(dialog("receive-error").text()).toBe("Stan fizyczny nie może przekroczyć 1 000 000.");
    });

    it("shows the detail of a 409 about the key", async () => {
      serve(one);
      wrapper = await mountView();
      answer(409, { status: 409, detail: "Ten klucz został użyty dla innego przyjęcia." });

      await open("receive-stock");
      await fillReceipt("5");
      await submitReceipt();

      expect(dialog("receive-error").text()).toBe("Ten klucz został użyty dla innego przyjęcia.");
    });

    it("sends one request when Przyjmij is clicked again while saving", async () => {
      serve(one);
      wrapper = await mountView();
      let respond!: (value: unknown) => void;
      mocks.post.mockImplementationOnce(() => new Promise((done) => (respond = done)));

      await open("receive-stock");
      await fillReceipt("5");
      await dialog("receive-submit").trigger("click");
      await dialog("receive-submit").trigger("click");
      await settle();

      expect(mocks.post).toHaveBeenCalledTimes(1);
      respond({ data: state(), error: undefined, response: { status: 201 } });
      await settle();
    });

    it("does not let a late answer of a closed dialog touch the dialog opened for another product", async () => {
      serve(() => ({ items: [item("A"), item("B")], next: null }));
      wrapper = await mountView();
      let respond!: (value: unknown) => void;
      mocks.post.mockImplementationOnce(() => new Promise((done) => (respond = done)));

      await open("receive-stock", 0);
      await fillReceipt("5");
      await dialog("receive-submit").trigger("click");
      await dialog("receive-cancel").trigger("click");
      await settle();
      await open("receive-stock", 1);
      respond({ data: undefined, error: { status: 403 }, response: { status: 403 } });
      await settle();

      expect(dialog("receive-dialog").text()).toContain("B");
      expect(page().find("[data-testid=receive-error]").exists()).toBe(false);
    });

    it("says the staff member may not on a 403", async () => {
      serve(one);
      wrapper = await mountView();
      answer(403, { status: 403 });

      await open("receive-stock");
      await fillReceipt("5");
      await submitReceipt();

      expect(dialog("receive-error").text()).toContain("Nie masz uprawnień");
      expect(page().find("[data-testid=receive-dialog]").exists()).toBe(true);
    });
  });

  describe("a correction", () => {
    it("corrects the variant of the row with the version of that row", async () => {
      serve(() => ({
        items: [
          item("Kajak", {
            variants: [
              { variantId: "v-green", label: "zielony", stock: stock({ version: 7 }), matchesLevel: false },
              { variantId: "v-orange", label: "pomarańczowy", stock: stock({ version: 2 }), matchesLevel: false },
            ],
          }),
        ],
        next: null,
      }));
      wrapper = await mountView();
      answer(200, state({ variantId: "v-green", physical: 6, version: 8 }));

      await open("correct-stock", 0);
      expect(dialog("correct-dialog").text()).toContain("Kajak / zielony");
      await fillCorrection("6");
      await submitCorrection();

      expect(mocks.post).toHaveBeenCalledWith(CORRECTIONS, {
        params: { path: { variantId: "v-green" } },
        body: { physical: 6, reason: "inventory_count", note: null, version: 7 },
      });
      expect([physicalOfRow(0), physicalOfRow(1)]).toEqual(["6", "10"]);
    });

    it("sends the count, the reason and the version of the row", async () => {
      serve(one);
      wrapper = await mountView();
      answer(200, state());

      await open("correct-stock");
      await fillCorrection("15", "damage", "Pęknięte opakowania");
      await submitCorrection();

      expect(mocks.post).toHaveBeenCalledWith(CORRECTIONS, {
        params: { path: { variantId: "var-A" } },
        body: { physical: 15, reason: "damage", note: "Pęknięte opakowania", version: 3 },
      });
    });

    it("chooses the reason with a Select named „Powód”, starting from Inwentaryzacja, and shows the four reasons by name", async () => {
      serve(one);
      wrapper = await mountView();

      await open("correct-stock");

      const trigger = page().get("#correct-reason");
      expect(page().get("label[for=correct-reason]").text()).toBe("Powód");
      expect(trigger.text()).toBe("Inwentaryzacja");
      const labels: string[] = [];
      for (const value of ["damage", "loss", "other", "inventory_count"]) {
        await chooseReason(value);
        labels.push(trigger.text());
      }
      expect(labels).toEqual(["Uszkodzenie", "Zaginięcie", "Inne", "Inwentaryzacja"]);
    });

    it("sends version null for a product without a stock record", async () => {
      serve(() => ({ items: [item("A", { stock: null })], next: null }));
      wrapper = await mountView();
      answer(200, state({ physical: 7, reserved: 0, available: 7, version: 1 }));

      await open("correct-stock");
      await fillCorrection("7");
      await submitCorrection();

      expect(mocks.post.mock.calls[0]![1].body).toMatchObject({ physical: 7, version: null });
      expect(physicalOfRow()).toBe("7");
    });

    it("requires a note for Inne and sends nothing without it", async () => {
      serve(one);
      wrapper = await mountView();
      answer(200, state());

      await open("correct-stock");
      await fillCorrection("15", "other", "  ");
      await submitCorrection();

      expect(mocks.post).not.toHaveBeenCalled();
      expect(dialog("correct-note-error").text()).toContain("notatk");

      await dialog("correct-note").setValue("Inne powody");
      await submitCorrection();
      expect(mocks.post.mock.calls[0]![1].body).toMatchObject({ reason: "other", note: "Inne powody" });
    });

    it("does not send a note for the other reasons when it is blank", async () => {
      serve(one);
      wrapper = await mountView();
      answer(200, state());

      await open("correct-stock");
      await fillCorrection("15", "loss");
      await submitCorrection();

      expect(mocks.post.mock.calls[0]![1].body).toEqual({ physical: 15, reason: "loss", note: null, version: 3 });
    });

    it("shows the stock the API answered with in the row and closes", async () => {
      serve(one);
      wrapper = await mountView();
      answer(200, state({ physical: 8, reserved: 4, available: 4, level: "low_stock", version: 4 }));

      await open("correct-stock");
      await fillCorrection("8");
      await submitCorrection();

      const row = page().get("[data-testid=stock-row]");
      expect(["physical", "reserved", "available", "level"].map((id) => row.get(`[data-testid=${id}]`).text())).toEqual(["8", "4", "4", "Ostatnie sztuki"]);
      expect(page().find("[data-testid=correct-dialog]").exists()).toBe(false);
    });

    it("does not send a missing count and says why at the field", async () => {
      serve(one);
      wrapper = await mountView();

      await open("correct-stock");
      await submitCorrection();

      expect(mocks.post).not.toHaveBeenCalled();
      expect(dialog("correct-physical-error").text()).toContain("stan");
    });

    it("on a 409 of the version says the stock changed, refreshes the row and sends the new version next", async () => {
      let current = stock();
      serve(() => ({ items: [item("A", { stock: current })], next: null }));
      wrapper = await mountView();
      answer(409, { type: "about:blank", status: 409, detail: "The stock was changed." });

      await open("correct-stock");
      await fillCorrection("15");
      current = stock({ physical: 12, available: 8, version: 4 });
      await submitCorrection();

      expect(dialog("correct-conflict").text()).toBe("Stan został w międzyczasie zmieniony – odśwież");
      expect(physicalOfRow()).toBe("12");

      answer(200, state({ version: 5 }));
      await submitCorrection();
      expect(mocks.post.mock.calls.at(-1)![1].body).toMatchObject({ physical: 15, version: 4 });
    });

    it("on a 409 below the reserved units shows the detail of the API and keeps the row", async () => {
      serve(one);
      wrapper = await mountView();
      answer(409, { type: "about:blank", status: 409, detail: "Nie można ustawić stanu poniżej zarezerwowanych: 4 szt." });

      await open("correct-stock");
      await fillCorrection("2");
      await submitCorrection();

      expect(dialog("correct-error").text()).toBe("Nie można ustawić stanu poniżej zarezerwowanych: 4 szt.");
      expect(page().find("[data-testid=correct-conflict]").exists()).toBe(false);
      expect(physicalOfRow()).toBe("10");
    });

    it("shows the 422 violations at their fields", async () => {
      serve(one);
      wrapper = await mountView();
      answer(422, {
        status: 422,
        violations: [
          { propertyPath: "physical", title: "Stan musi wynosić od 0 do 1 000 000." },
          { propertyPath: "note", title: "Podaj notatkę." },
        ],
      });

      await open("correct-stock");
      await fillCorrection("2000000", "loss");
      await submitCorrection();

      expect(dialog("correct-physical-error").text()).toBe("Stan musi wynosić od 0 do 1 000 000.");
      expect(dialog("correct-note-error").text()).toBe("Podaj notatkę.");
    });

    it("says the staff member may not on a 403", async () => {
      serve(one);
      wrapper = await mountView();
      answer(403, { status: 403 });

      await open("correct-stock");
      await fillCorrection("2");
      await submitCorrection();

      expect(dialog("correct-error").text()).toContain("Nie masz uprawnień");
    });

    it("sends one request when Zapisz korektę is clicked again while saving", async () => {
      serve(one);
      wrapper = await mountView();
      let respond!: (value: unknown) => void;
      mocks.post.mockImplementationOnce(() => new Promise((done) => (respond = done)));

      await open("correct-stock");
      await fillCorrection("15");
      await dialog("correct-submit").trigger("click");
      await dialog("correct-submit").trigger("click");
      await settle();

      expect(mocks.post).toHaveBeenCalledTimes(1);
      respond({ data: state(), error: undefined, response: { status: 200 } });
      await settle();
    });

    it("does not let a late answer of a closed dialog touch the dialog opened for another product", async () => {
      serve(() => ({ items: [item("A"), item("B")], next: null }));
      wrapper = await mountView();
      let respond!: (value: unknown) => void;
      mocks.post.mockImplementationOnce(() => new Promise((done) => (respond = done)));

      await open("correct-stock", 0);
      await fillCorrection("15");
      await dialog("correct-submit").trigger("click");
      await dialog("correct-cancel").trigger("click");
      await settle();
      await open("correct-stock", 1);
      respond({ data: undefined, error: { status: 403 }, response: { status: 403 } });
      await settle();

      expect(dialog("correct-dialog").text()).toContain("B");
      expect(page().find("[data-testid=correct-error]").exists()).toBe(false);
    });
  });
  describe("the windows", () => {
    it("are dialogs with the title, the name of the variant, labelled number fields and the width of the standard", async () => {
      serve(() => ({ items: [item("Namiot", { variants: [{ variantId: "v-g", label: "zielony", stock: stock(), matchesLevel: false }] })], next: null }));
      wrapper = await mountView();

      await open("receive-stock");
      let window = page().get("[role=dialog]");
      expect(window.attributes("data-testid")).toBe("receive-dialog");
      expect(page().get("[role=dialog] [data-slot=dialog-title]").text()).toBe("Przyjęcie");
      expect(window.text()).toContain("Namiot / zielony");
      expect(window.classes()).toContain("w-[min(28rem,calc(100vw-2rem))]");
      expect(page().get("label[for=receive-quantity]").text()).toBe("Przyjęta ilość (szt.)");
      expect(page().get("#receive-quantity").attributes("inputmode")).toBe("numeric");
      expect(page().get("label[for=receive-note]").text()).toBe("Notatka (opcjonalnie)");
      expect(page().get("[data-testid=receive-submit]").text()).toBe("Przyjmij");
      expect(page().get("[data-testid=receive-submit]").classes()).toContain("bg-interface");
      expect(page().get("[data-testid=receive-cancel]").text()).toBe("Anuluj");
      await page().get("[data-testid=receive-cancel]").trigger("click");
      await settle();

      await open("correct-stock");
      window = page().get("[role=dialog]");
      expect(window.attributes("data-testid")).toBe("correct-dialog");
      expect(page().get("[role=dialog] [data-slot=dialog-title]").text()).toBe("Korekta");
      expect(window.classes()).toContain("w-[min(28rem,calc(100vw-2rem))]");
      expect(page().get("label[for=correct-physical]").text()).toBe("Stan policzony (szt.)");
      expect(page().get("#correct-physical").attributes("inputmode")).toBe("numeric");
      expect(page().get("label[for=correct-note]").text()).toBe("Notatka (opcjonalnie)");
      expect(page().get("[data-testid=correct-submit]").text()).toBe("Zapisz korektę");
      expect(page().get("[data-testid=correct-submit]").classes()).toContain("bg-interface");
    });

    it("names the physical stock of the row in the window, as 0 for a variant without a stock record", async () => {
      serve(() => ({ items: [item("A", { stock: null })], next: null }));
      wrapper = await mountView();

      await open("receive-stock");

      expect(dialog("receive-dialog").text()).toContain("Stan fizyczny: 0.");
    });

    it("puts the focus in the first field when a window opens", async () => {
      serve(one);
      wrapper = await mountView();

      await open("receive-stock");
      expect(document.activeElement).toBe(page().get("#receive-quantity").element);
      await dialog("receive-cancel").trigger("click");
      await settle();

      await open("correct-stock");
      expect(document.activeElement).toBe(page().get("#correct-physical").element);
    });

    it("saves with the Enter key in the number field", async () => {
      serve(one);
      wrapper = await mountView();
      answer(201, state());

      await open("receive-stock");
      await page().get("#receive-quantity").setValue("5");
      await page().get("#receive-quantity").trigger("keydown", { key: "Enter" });
      await settle();

      expect(mocks.post).toHaveBeenCalledTimes(1);
    });
  });

  describe("the number fields", () => {
    it.each([["abc"], ["1,5"], ["1.5"], ["1e3"], ["١٢"], ["12 3"]])("do not send %j and say at the field that a whole number is needed", async (text) => {
      serve(one);
      wrapper = await mountView();

      await open("receive-stock");
      await fillReceipt(text);
      await submitReceipt();

      expect(mocks.post).not.toHaveBeenCalled();
      expect(dialog("receive-quantity-error").text()).toBe("Podaj liczbę całkowitą.");
      expect(document.activeElement).toBe(page().get("#receive-quantity").element);
    });

    it("read a count with spaces around it, also non-breaking ones, and a count of zero", async () => {
      serve(one);
      wrapper = await mountView();
      answer(200, state({ physical: 0, available: 0, reserved: 0, level: "out_of_stock" }));

      await open("correct-stock");
      await fillCorrection("\u00a0 0 \u00a0");
      await submitCorrection();

      expect(mocks.post.mock.calls[0]![1].body).toMatchObject({ physical: 0 });
    });

    it("do not send a count with only white space and say it is missing", async () => {
      serve(one);
      wrapper = await mountView();

      await open("correct-stock");
      await fillCorrection("\u00a0 ");
      await submitCorrection();

      expect(mocks.post).not.toHaveBeenCalled();
      expect(dialog("correct-physical-error").text()).toBe("Podaj stan policzony.");
    });
  });

  describe("after a save", () => {
    it("says so in a toast with the quantity, the product and the variant, and gives the focus back to „Przyjęcie” of the row", async () => {
      serve(() => ({ items: [item("Namiot", { variants: [{ variantId: "v-g", label: "zielony", stock: stock(), matchesLevel: false }, { variantId: "v-o", label: "pomarańczowy", stock: stock(), matchesLevel: false }] })], next: null }));
      wrapper = await mountView();
      answer(201, state({ variantId: "v-o", physical: 20 }));

      await open("receive-stock", 1);
      await fillReceipt("10");
      await submitReceipt();

      expect(successToast).toHaveBeenCalledWith("Przyjęto 10 szt.: Namiot / pomarańczowy");
      expect(page().find("[data-testid=receive-dialog]").exists()).toBe(false);
      expect(document.activeElement).toBe(page().findAll("[data-testid=receive-stock]")[1]!.element);
    });

    it("says a correction in a toast with the product and the variant, and gives the focus back to „Korekta” of the row", async () => {
      serve(() => ({ items: [item("Namiot", { variants: [{ variantId: "v-g", label: "zielony", stock: stock(), matchesLevel: false }] })], next: null }));
      wrapper = await mountView();
      answer(200, state({ variantId: "v-g", physical: 8 }));

      await open("correct-stock");
      await fillCorrection("8");
      await submitCorrection();

      expect(successToast).toHaveBeenCalledWith("Stan skorygowany: Namiot / zielony");
      expect(document.activeElement).toBe(page().get("[data-testid=correct-stock]").element);
    });

    it("names the product only for the default variant", async () => {
      serve(one);
      wrapper = await mountView();
      answer(201, state());

      await open("receive-stock");
      await fillReceipt("5");
      await submitReceipt();

      expect(successToast).toHaveBeenCalledWith("Przyjęto 5 szt.: A");
    });

    it("keeps the new stock in the row while the list is refreshed afterwards", async () => {
      serve(one);
      wrapper = await mountView();
      answer(201, state({ physical: 15 }));
      await open("receive-stock");
      await fillReceipt("5");
      await submitReceipt();

      let respond: (page: StockList) => void = () => undefined;
      mocks.get.mockImplementation(() => new Promise((resolve) => (respond = (answered) => resolve({ data: answered, response: { status: 200 } }))));
      await page().get("[data-testid=search]").setValue("zz");
      await vi.advanceTimersByTimeAsync(400);
      expect(physicalOfRow()).toBe("15");
      respond(one());
      await settle();

      expect(physicalOfRow()).toBe("15");
    });
  });

  describe("after a failure", () => {
    it("puts the focus in the field that the 422 names, and shows no toast", async () => {
      serve(one);
      wrapper = await mountView();
      answer(422, { status: 422, violations: [{ propertyPath: "quantity", title: "Ilość musi wynosić od 1 do 100 000." }] });

      await open("receive-stock");
      await fillReceipt("0");
      await submitReceipt();

      expect(document.activeElement).toBe(page().get("#receive-quantity").element);
      expect(page().get("#receive-quantity").attributes("aria-describedby")).toContain(page().get("[data-testid=receive-quantity-error]").attributes("id"));
      expect(page().get("#receive-quantity").attributes("aria-invalid")).toBe("true");
      expect(successToast).not.toHaveBeenCalled();
    });

    it("puts the focus on the message of a 403, of the connection and of a 409 about the key", async () => {
      serve(one);
      wrapper = await mountView();
      answer(403, { status: 403 });
      await open("receive-stock");
      await fillReceipt("5");
      await submitReceipt();
      expect(document.activeElement).toBe(dialog("receive-error").element);
      expect(dialog("receive-error").attributes("role")).toBe("alert");

      answer(409, { status: 409, detail: "Ten klucz został użyty dla innego przyjęcia." });
      await submitReceipt();
      expect(document.activeElement).toBe(dialog("receive-error").element);
    });

    it("puts the focus on the conflict message of a correction, and on its error message otherwise", async () => {
      serve(one);
      wrapper = await mountView();
      answer(409, { type: "about:blank", status: 409, detail: "x" });
      await open("correct-stock");
      await fillCorrection("15");
      await submitCorrection();
      // The reload brought the same version: the correction was refused for another reason.
      expect(document.activeElement).toBe(dialog("correct-error").element);

      serve(() => ({ items: [item("A", { stock: stock({ version: 4 }) })], next: null }));
      await submitCorrection();
      expect(document.activeElement).toBe(dialog("correct-conflict").element);
      expect(dialog("correct-conflict").attributes("role")).toBe("alert");
    });

    it("puts the focus in the count field of a correction when the 422 names it", async () => {
      serve(one);
      wrapper = await mountView();
      answer(422, { status: 422, violations: [{ propertyPath: "physical", title: "Stan musi wynosić od 0 do 1 000 000." }] });

      await open("correct-stock");
      await fillCorrection("2000000");
      await submitCorrection();

      expect(document.activeElement).toBe(page().get("#correct-physical").element);
    });
  });

  describe("a 409", () => {
    it("of a receipt reloads the current page, not the first, and shows the detail of the API", async () => {
      serve((after) => (after === "c1" ? { items: [item("B")], next: null } : { items: [item("A")], next: "c1" }));
      wrapper = await mountView();
      await page().get("[data-testid=next-page]").trigger("click");
      await settle();
      answer(409, { status: 409, detail: "Ten klucz został użyty dla innego przyjęcia." });
      const calls = stockQueries().length;

      await open("receive-stock");
      await fillReceipt("5");
      await submitReceipt();

      expect(stockQueries().slice(calls)).toEqual([{ perPage: 10, after: "c1" }]);
      expect(dialog("receive-error").text()).toBe("Ten klucz został użyty dla innego przyjęcia.");
      expect(page().get("[data-testid=page-number]").text()).toBe("Strona 2");
    });

    it("of a correction reloads the current page, not the first, and says the stock was changed", async () => {
      let current = stock();
      serve((after) => (after === "c1" ? { items: [item("B", { stock: current })], next: null } : { items: [item("A")], next: "c1" }));
      wrapper = await mountView();
      await page().get("[data-testid=next-page]").trigger("click");
      await settle();
      answer(409, { type: "about:blank", status: 409, detail: "The stock was changed." });
      const calls = stockQueries().length;

      await open("correct-stock");
      await fillCorrection("15");
      current = stock({ physical: 12, available: 8, version: 4 });
      await submitCorrection();

      expect(stockQueries().slice(calls)).toEqual([{ perPage: 10, after: "c1" }]);
      expect(dialog("correct-conflict").text()).toBe("Stan został w międzyczasie zmieniony – odśwież");
      expect(physicalOfRow()).toBe("12");
      expect(page().get("[data-testid=page-number]").text()).toBe("Strona 2");
    });
  });
});
