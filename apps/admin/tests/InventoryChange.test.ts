import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import PrimeVue from "primevue/config";
import InputNumber from "primevue/inputnumber";
import Select from "primevue/select";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { components } from "@/api/schema";
import { primeVueOptions } from "@/primevue";
import { useAuthStore, type StaffMe } from "@/stores/auth";
import InventoryView from "@/views/InventoryView.vue";

type StockList = components["schemas"]["StockListResponse"];
type StockListItem = components["schemas"]["StockListItemResponse"];
type ListedStock = components["schemas"]["ListedStockResponse"];
type StockState = components["schemas"]["StockStateResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get, POST: mocks.post }) }));

const STOCK = "/api/v1/admin/stock";
const RECEIPTS = "/api/v1/admin/products/{productId}/stock/receipts";
const CORRECTIONS = "/api/v1/admin/products/{productId}/stock/corrections";

function stock(overrides: Partial<ListedStock> = {}): ListedStock {
  return { physical: 10, reserved: 4, available: 6, level: "in_stock", version: 3, updatedAt: "2026-11-02T12:30:00+00:00", ...overrides };
}

function item(name: string, overrides: Partial<StockListItem> = {}): StockListItem {
  return { productId: `id-${name}`, name, stock: stock(), ...overrides };
}

function state(overrides: Partial<StockState> = {}): StockState {
  return {
    productId: "id-A",
    physical: 15,
    reserved: 4,
    available: 11,
    level: "in_stock",
    version: 4,
    updatedAt: "2026-11-02T13:00:00+00:00",
    ...overrides,
  };
}

/** Answers the stock list; `page()` is read on every request, so a test can change what the next reload brings. */
function serve(page: () => StockList) {
  mocks.get.mockImplementation(async (path: string) =>
    path === STOCK ? { data: page(), response: { status: 200 } } : { data: [], response: { status: 200 } },
  );
}

function answer(status: number, body?: unknown) {
  const ok = status < 300;
  mocks.post.mockResolvedValue({ data: ok ? body : undefined, error: ok ? undefined : body, response: { status } });
}

async function settle() {
  await vi.advanceTimersByTimeAsync(0);
  await flushPromises();
}

async function mountView(roles: StaffMe["roles"] = ["warehouse"]) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().staff = { id: "u1", username: "anna", roles };
  // The dialogs are teleported to <body>; rendered in place they can be found in the wrapper.
  const wrapper = mount(InventoryView, { global: { plugins: [pinia, [PrimeVue, primeVueOptions]] as never[], stubs: { teleport: true } } });
  await settle();
  return wrapper;
}

describe("InventoryView: receipts and corrections", () => {
  let wrapper: Awaited<ReturnType<typeof mountView>> | undefined;

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    mocks.get.mockReset();
    mocks.post.mockReset();
    // jsdom has no matchMedia, which the Select of PrimeVue asks for when it is mounted.
    vi.stubGlobal("matchMedia", () => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }));
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    vi.useRealTimers();
  });

  const one = (): StockList => ({ items: [item("A")], next: null });

  function dialog(id: string) {
    return wrapper!.get(`[data-testid=${id}]`);
  }

  async function open(button: "receive-stock" | "correct-stock", row = 0) {
    await wrapper!.findAll("[data-testid=stock-row]")[row]!.get(`[data-testid=${button}]`).trigger("click");
    await settle();
  }

  async function setNumber(inputId: string, value: number | null) {
    const field = wrapper!.findAllComponents(InputNumber).find((component) => component.props("inputId") === inputId)!;
    field.vm.$emit("update:modelValue", value);
    await settle();
  }

  async function fillReceipt(quantity: number | null, note = "") {
    await setNumber("receive-quantity", quantity);
    await dialog("receive-note").setValue(note);
  }

  async function submitReceipt() {
    await dialog("receive-submit").trigger("click");
    await settle();
  }

  async function chooseReason(reason: string) {
    wrapper!.findComponent(Select).vm.$emit("update:modelValue", reason);
    await settle();
  }

  async function fillCorrection(physical: number | null, reason = "inventory_count", note = "") {
    await setNumber("correct-physical", physical);
    await chooseReason(reason);
    await dialog("correct-note").setValue(note);
  }

  async function submitCorrection() {
    await dialog("correct-submit").trigger("click");
    await settle();
  }

  function physicalOfRow(row = 0) {
    return wrapper!.findAll("[data-testid=stock-row]")[row]!.get("[data-testid=physical]").text();
  }

  describe("who sees the buttons", () => {
    it.each([["admin"], ["warehouse"]] as const)("shows Przyjęcie and Korekta to %s", async (role) => {
      serve(one);
      wrapper = await mountView([role]);

      const row = wrapper.get("[data-testid=stock-row]");
      expect(row.get("[data-testid=receive-stock]").text()).toBe("Przyjęcie");
      expect(row.get("[data-testid=correct-stock]").text()).toBe("Korekta");
    });

    it.each([["support"], ["marketing"]] as const)("hides them from %s", async (role) => {
      serve(one);
      wrapper = await mountView([role]);

      expect(wrapper.find("[data-testid=receive-stock]").exists()).toBe(false);
      expect(wrapper.find("[data-testid=correct-stock]").exists()).toBe(false);
    });

    it("hides them until the roles are known", async () => {
      serve(one);
      wrapper = await mountView([]);

      expect(wrapper.find("[data-testid=receive-stock]").exists()).toBe(false);
    });
  });

  describe("a receipt", () => {
    it("sends the quantity and note with a UUID as Idempotency-Key made when the dialog opens", async () => {
      serve(one);
      const uuid = vi.spyOn(crypto, "randomUUID").mockReturnValue("11111111-1111-4111-8111-111111111111");
      wrapper = await mountView();
      answer(201, state());

      expect(uuid).not.toHaveBeenCalled();
      await open("receive-stock");
      expect(uuid).toHaveBeenCalledTimes(1);
      await fillReceipt(5, "Dostawa");
      await submitReceipt();

      expect(mocks.post).toHaveBeenCalledWith(RECEIPTS, {
        params: { path: { productId: "id-A" }, header: { "Idempotency-Key": "11111111-1111-4111-8111-111111111111" } },
        body: { quantity: 5, note: "Dostawa" },
      });
    });

    it("shows the stock the API answered with in the row and closes", async () => {
      serve(one);
      wrapper = await mountView();
      answer(201, state({ physical: 15, reserved: 4, available: 11 }));

      await open("receive-stock");
      await fillReceipt(5);
      await submitReceipt();

      const row = wrapper.get("[data-testid=stock-row]");
      expect(["physical", "reserved", "available"].map((id) => row.get(`[data-testid=${id}]`).text())).toEqual(["15", "4", "11"]);
      expect(wrapper.find("[data-testid=receive-dialog]").exists()).toBe(false);
    });

    it("sends the same key again when the same receipt is repeated after a network error", async () => {
      serve(one);
      vi.spyOn(crypto, "randomUUID").mockReturnValueOnce("11111111-1111-4111-8111-111111111111").mockReturnValueOnce("22222222-2222-4222-8222-222222222222");
      wrapper = await mountView();
      mocks.post.mockRejectedValueOnce(new TypeError("network down"));

      await open("receive-stock");
      await fillReceipt(5, "Dostawa");
      await submitReceipt();
      expect(dialog("receive-error").text()).toContain("połączenie");

      answer(200, state());
      await submitReceipt();

      const keys = mocks.post.mock.calls.map(([, options]) => options.params.header["Idempotency-Key"]);
      expect(keys).toEqual(["11111111-1111-4111-8111-111111111111", "11111111-1111-4111-8111-111111111111"]);
      expect(wrapper.find("[data-testid=receive-dialog]").exists()).toBe(false);
    });

    it("makes a new key for a new dialog", async () => {
      serve(one);
      vi.spyOn(crypto, "randomUUID").mockReturnValueOnce("11111111-1111-4111-8111-111111111111").mockReturnValueOnce("22222222-2222-4222-8222-222222222222");
      wrapper = await mountView();
      mocks.post.mockRejectedValue(new TypeError("network down"));

      await open("receive-stock");
      await fillReceipt(5);
      await submitReceipt();
      await dialog("receive-cancel").trigger("click");
      await settle();
      await open("receive-stock");
      await fillReceipt(5);
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
      await fillReceipt(0);
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
      await fillReceipt(100000);
      await submitReceipt();

      expect(dialog("receive-error").text()).toBe("Stan fizyczny nie może przekroczyć 1 000 000.");
    });

    it("shows the detail of a 409 about the key", async () => {
      serve(one);
      wrapper = await mountView();
      answer(409, { status: 409, detail: "Ten klucz został użyty dla innego przyjęcia." });

      await open("receive-stock");
      await fillReceipt(5);
      await submitReceipt();

      expect(dialog("receive-error").text()).toBe("Ten klucz został użyty dla innego przyjęcia.");
    });

    it("says the staff member may not on a 403", async () => {
      serve(one);
      wrapper = await mountView();
      answer(403, { status: 403 });

      await open("receive-stock");
      await fillReceipt(5);
      await submitReceipt();

      expect(dialog("receive-error").text()).toContain("Nie masz uprawnień");
      expect(wrapper.find("[data-testid=receive-dialog]").exists()).toBe(true);
    });
  });

  describe("a correction", () => {
    it("sends the count, the reason and the version of the row", async () => {
      serve(one);
      wrapper = await mountView();
      answer(200, state());

      await open("correct-stock");
      await fillCorrection(15, "damage", "Pęknięte opakowania");
      await submitCorrection();

      expect(mocks.post).toHaveBeenCalledWith(CORRECTIONS, {
        params: { path: { productId: "id-A" } },
        body: { physical: 15, reason: "damage", note: "Pęknięte opakowania", version: 3 },
      });
    });

    it("offers the four reasons", async () => {
      serve(one);
      wrapper = await mountView();

      await open("correct-stock");

      const options = wrapper.findComponent(Select).props("options") as { label: string; value: string }[];
      expect(options.map((option) => [option.label, option.value])).toEqual([
        ["Inwentaryzacja", "inventory_count"],
        ["Uszkodzenie", "damage"],
        ["Zaginięcie", "loss"],
        ["Inne", "other"],
      ]);
    });

    it("sends version null for a product without a stock record", async () => {
      serve(() => ({ items: [item("A", { stock: null })], next: null }));
      wrapper = await mountView();
      answer(200, state({ physical: 7, reserved: 0, available: 7, version: 1 }));

      await open("correct-stock");
      await fillCorrection(7);
      await submitCorrection();

      expect(mocks.post.mock.calls[0]![1].body).toMatchObject({ physical: 7, version: null });
      expect(physicalOfRow()).toBe("7");
    });

    it("requires a note for Inne and sends nothing without it", async () => {
      serve(one);
      wrapper = await mountView();
      answer(200, state());

      await open("correct-stock");
      await fillCorrection(15, "other", "  ");
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
      await fillCorrection(15, "loss");
      await submitCorrection();

      expect(mocks.post.mock.calls[0]![1].body).toEqual({ physical: 15, reason: "loss", note: null, version: 3 });
    });

    it("shows the stock the API answered with in the row and closes", async () => {
      serve(one);
      wrapper = await mountView();
      answer(200, state({ physical: 8, reserved: 4, available: 4, level: "low_stock", version: 4 }));

      await open("correct-stock");
      await fillCorrection(8);
      await submitCorrection();

      const row = wrapper.get("[data-testid=stock-row]");
      expect(["physical", "reserved", "available", "level"].map((id) => row.get(`[data-testid=${id}]`).text())).toEqual(["8", "4", "4", "Ostatnie sztuki"]);
      expect(wrapper.find("[data-testid=correct-dialog]").exists()).toBe(false);
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
      await fillCorrection(15);
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
      await fillCorrection(2);
      await submitCorrection();

      expect(dialog("correct-error").text()).toBe("Nie można ustawić stanu poniżej zarezerwowanych: 4 szt.");
      expect(wrapper.find("[data-testid=correct-conflict]").exists()).toBe(false);
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
      await fillCorrection(2000000, "loss");
      await submitCorrection();

      expect(dialog("correct-physical-error").text()).toBe("Stan musi wynosić od 0 do 1 000 000.");
      expect(dialog("correct-note-error").text()).toBe("Podaj notatkę.");
    });

    it("says the staff member may not on a 403", async () => {
      serve(one);
      wrapper = await mountView();
      answer(403, { status: 403 });

      await open("correct-stock");
      await fillCorrection(2);
      await submitCorrection();

      expect(dialog("correct-error").text()).toContain("Nie masz uprawnień");
    });

    it("does not let a late answer of a closed dialog touch the dialog opened for another product", async () => {
      serve(() => ({ items: [item("A"), item("B")], next: null }));
      wrapper = await mountView();
      let respond!: (value: unknown) => void;
      mocks.post.mockImplementationOnce(() => new Promise((done) => (respond = done)));

      await open("correct-stock", 0);
      await fillCorrection(15);
      await dialog("correct-submit").trigger("click");
      await dialog("correct-cancel").trigger("click");
      await settle();
      await open("correct-stock", 1);
      respond({ data: undefined, error: { status: 403 }, response: { status: 403 } });
      await settle();

      expect(dialog("correct-dialog").text()).toContain("B");
      expect(wrapper.find("[data-testid=correct-error]").exists()).toBe(false);
    });
  });
});
