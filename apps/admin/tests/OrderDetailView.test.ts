import { flushPromises, mount } from "@vue/test-utils";
import PrimeVue from "primevue/config";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import type { components } from "@/api/schema";
import { primeVueOptions } from "@/primevue";
import OrderDetailView from "@/views/OrderDetailView.vue";

type BackofficeOrder = components["schemas"]["BackofficeOrder"];

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get, POST: mocks.post }) }));

const STATUS_CHANGES = "/api/v1/admin/orders/{orderId}/status-changes";
const REFUND_COMPLETION = "/api/v1/admin/orders/{orderId}/refund-completion";

function order(overrides: Partial<BackofficeOrder> = {}): BackofficeOrder {
  return {
    orderId: "o-1",
    number: "R-100001",
    status: "paid",
    version: 2,
    email: "anna.nowak@example.com",
    fullName: "Anna Nowak",
    phone: "+48600100200",
    shippingAddress: { street: "ul. Długa 5/3", postalCode: "00-950", city: "Warszawa", country: "PL" },
    lines: [
      { variantId: "v-1", productName: "Buty szosowe Tarvik Asfalt 200", variantLabel: "czarne, 42", quantity: 2, unitGross: 19999, grossBeforeDiscount: 39998, discountGross: 0, lineGross: 39998, promotion: null },
      { variantId: "v-2", productName: "Bidon", variantLabel: "", quantity: 1, unitGross: 2500, grossBeforeDiscount: 2500, discountGross: 0, lineGross: 2500, promotion: null },
    ],
    discount: null,
    totals: { net: 32519, vat: 7479, gross: 39998, currency: "PLN" },
    placedAt: "2026-11-05T12:30:00+00:00",
    paymentDeadline: "2026-11-05T13:00:00+00:00",
    paidAt: "2026-11-05T12:40:00+00:00",
    paymentId: "0192f0c4-aaaa-7000-8000-000000000001",
    shippedAt: null,
    deliveredAt: null,
    cancelledAt: null,
    cancellationReason: null,
    returnedAt: null,
    history: [
      { from: null, to: "new", occurredAt: "2026-11-05T12:30:00+00:00", actorType: "customer", staffId: null, staffUsername: null, note: null },
      { from: "new", to: "paid", occurredAt: "2026-11-05T12:40:00+00:00", actorType: "system", staffId: null, staffUsername: null, note: null },
    ],
    refund: null,
    allowedTransitions: ["ship", "cancel"],
    canCompleteRefund: false,
    ...overrides,
  };
}

async function mountView(orderId = "o-1") {
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/orders", name: "orders", component: empty },
      { path: "/orders/:orderId", name: "order-detail", component: OrderDetailView },
    ],
  });
  await router.push(`/orders/${orderId}`);
  // The dialog is teleported to <body>; rendered in place it can be found in the wrapper.
  const wrapper = mount(OrderDetailView, { global: { plugins: [router, [PrimeVue, primeVueOptions]] as never[], stubs: { teleport: true } } });
  await flushPromises();
  return wrapper;
}

function serve(data: BackofficeOrder | number) {
  mocks.get.mockResolvedValue(typeof data === "number" ? { data: undefined, response: { status: data } } : { data, response: { status: 200 } });
}

describe("OrderDetailView", () => {
  let wrapper: Awaited<ReturnType<typeof mountView>> | undefined;

  const text = (id: string) => wrapper!.get(`[data-testid=${id}]`).text().replace(/\s+/g, " ");
  const dialog = (id: string) => wrapper!.get(`[data-testid=change-dialog] [data-testid=${id}]`);

  beforeEach(() => {
    mocks.get.mockReset();
    mocks.post.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
  });

  it("shows the contact, the address, the lines with the sums, the payment id and the history", async () => {
    serve(order({ history: [...order().history, { from: "paid", to: "shipped", occurredAt: "2026-11-06T09:00:00+00:00", actorType: "staff", staffId: "s1", staffUsername: "bartek", note: "Kurier DPD" }] }));
    wrapper = await mountView();

    expect(wrapper.get("h1").text()).toBe("Zamówienie R-100001");
    expect(text("status")).toBe("Opłacone");
    expect(text("contact")).toContain("Anna Nowak");
    expect(text("contact")).toContain("anna.nowak@example.com");
    expect(text("contact")).toContain("+48600100200");
    expect(text("address")).toContain("ul. Długa 5/3");
    expect(text("address")).toContain("00-950 Warszawa");
    expect(wrapper.findAll("[data-testid=line-row]")).toHaveLength(2);
    expect(text("totals")).toContain("399,98 zł");
    expect(text("payment-id")).toBe("0192f0c4-aaaa-7000-8000-000000000001");
    const history = wrapper.findAll("[data-testid=history-row]").map((row) => ["history-change", "history-actor", "history-note"].map((id) => row.get(`[data-testid=${id}]`).text()));
    expect(history).toEqual([
      ["Czeka na płatność", "Klient", ""],
      ["Czeka na płatność → Opłacone", "System", ""],
      ["Opłacone → Wysłane", "bartek", "Kurier DPD"],
    ]);
  });

  describe("the discount code and the promotion of the lines", () => {
    // 2 × 199,99 and 25,00 with a code of 50,00 zł: the lines carry their share, the totals are after the discount.
    function discounted(): BackofficeOrder {
      return order({
        lines: [
          { variantId: "v-1", productName: "Buty szosowe Tarvik Asfalt 200", variantLabel: "czarne, 42", quantity: 2, unitGross: 19999, grossBeforeDiscount: 39998, discountGross: 4706, lineGross: 35292, promotion: { name: "Jesienna wyprzedaż", percentOff: 20, regularUnitGross: 24999 } },
          { variantId: "v-2", productName: "Bidon", variantLabel: "", quantity: 1, unitGross: 2500, grossBeforeDiscount: 2500, discountGross: 294, lineGross: 2206, promotion: null },
        ],
        discount: { code: "MINUS50", kind: "amount", value: 5000, amountGross: 5000 },
        totals: { net: 30486, vat: 7012, gross: 37498, currency: "PLN" },
      });
    }

    const rows = () => wrapper!.findAll("[data-testid=line-row]");
    const cell = (index: number, id: string) => rows()[index]!.get(`[data-testid=${id}]`).text().replace(/\s+/g, " ");

    it("shows the value of each line before the discount, its share of the discount and the value after it", async () => {
      serve(discounted());
      wrapper = await mountView();

      expect(cell(0, "line-gross-before-discount")).toBe("399,98 zł");
      expect(cell(0, "line-discount")).toBe("−47,06 zł");
      expect(cell(0, "line-gross")).toBe("352,92 zł");
      expect(cell(1, "line-gross-before-discount")).toBe("25,00 zł");
      expect(cell(1, "line-discount")).toBe("−2,94 zł");
      expect(cell(1, "line-gross")).toBe("22,06 zł");
      expect(wrapper.text()).toContain("Wartość przed rabatem");
      expect(wrapper.text()).toContain("Wartość po rabacie");
    });

    it("shows the copy of the promotion at the line that has one, and nothing at the others", async () => {
      serve(discounted());
      wrapper = await mountView();

      expect(cell(0, "line-promotion")).toBe("Promocja „Jesienna wyprzedaż”: −20% od ceny katalogowej 249,99 zł");
      expect(rows()[1]!.find("[data-testid=line-promotion]").exists()).toBe(false);
    });

    it("shows the line of the code and the totals after the discount", async () => {
      serve(discounted());
      wrapper = await mountView();

      expect(text("discount-line")).toBe("Kod MINUS50: −50,00 zł");
      expect(text("totals")).toContain("304,86 zł");
      expect(text("totals")).toContain("70,12 zł");
      expect(text("total-gross")).toBe("374,98 zł");
      expect(wrapper.get("[data-testid=totals]").text()).toContain("Brutto po rabacie");
    });

    it("shows the code of a percent code the same way", async () => {
      serve(order({ discount: { code: "WITAJ10", kind: "percent", value: 10, amountGross: 4250 } }));
      wrapper = await mountView();

      expect(text("discount-line")).toBe("Kod WITAJ10: −42,50 zł");
    });

    it("shows a line without a discount share as a dash", async () => {
      const data = discounted();
      data.lines[1] = { ...data.lines[1]!, discountGross: 0, lineGross: 2500 };
      serve(data);
      wrapper = await mountView();

      expect(cell(1, "line-discount")).toBe("—");
    });

    it("looks as before for an order without a code: one value per line, no discount line", async () => {
      serve(order());
      wrapper = await mountView();

      expect(cell(0, "line-gross")).toBe("399,98 zł");
      expect(rows()[0]!.find("[data-testid=line-gross-before-discount]").exists()).toBe(false);
      expect(rows()[0]!.find("[data-testid=line-discount]").exists()).toBe(false);
      expect(rows()[0]!.find("[data-testid=line-promotion]").exists()).toBe(false);
      expect(wrapper.find("[data-testid=discount-line]").exists()).toBe(false);
      expect(wrapper.text()).toContain("Wartość brutto");
      expect(wrapper.text()).not.toContain("Wartość przed rabatem");
      expect(text("totals")).toContain("Brutto");
      expect(text("totals")).not.toContain("po rabacie");
    });

    it("shows the promotion copy of a line also when there is no code", async () => {
      const data = order();
      data.lines[0] = { ...data.lines[0]!, promotion: { name: "Wiosna", percentOff: 5, regularUnitGross: 21000 } };
      serve(data);
      wrapper = await mountView();

      expect(cell(0, "line-promotion")).toBe("Promocja „Wiosna”: −5% od ceny katalogowej 210,00 zł");
    });
  });

  it("shows the payment deadline only for an order that waits for the payment", async () => {
    serve(order({ status: "new", paidAt: null, paymentId: null, allowedTransitions: [] }));
    wrapper = await mountView();
    expect(text("payment-deadline")).toBe("05.11.2026, 14:00:00");
    wrapper.unmount();

    serve(order());
    wrapper = await mountView();
    expect(wrapper.find("[data-testid=payment-deadline]").exists()).toBe(false);
  });

  it("shows only the buttons the API allows", async () => {
    serve(order({ allowedTransitions: ["ship", "cancel"] }));
    wrapper = await mountView();

    expect(wrapper.findAll("[data-testid=actions] button").map((button) => button.text())).toEqual(["Oznacz jako wysłane", "Anuluj zamówienie"]);
    expect(wrapper.find("[data-testid=complete-refund]").exists()).toBe(false);
  });

  it("has no buttons when the API allows nothing", async () => {
    serve(order({ allowedTransitions: [], canCompleteRefund: false }));
    wrapper = await mountView();

    expect(wrapper.find("[data-testid=actions]").exists()).toBe(false);
  });

  it("shows the refund with 'Po terminie', and a button to complete it when the API allows", async () => {
    serve(
      order({
        status: "cancelled",
        allowedTransitions: [],
        canCompleteRefund: true,
        refund: { amount: 39998, currency: "PLN", dueAt: "2026-11-19T12:30:00+00:00", overdue: true, completedAt: null, completedByStaffId: null, completedByUsername: null, note: null },
      }),
    );
    wrapper = await mountView();

    expect(text("refund-amount")).toBe("399,98 zł");
    expect(text("refund-due")).toBe("19.11.2026, 13:30:00");
    expect(text("refund-overdue")).toBe("Po terminie");
    expect(text("refund-state")).toBe("Do wykonania");
    expect(text("refund-payment-id")).toBe("0192f0c4-aaaa-7000-8000-000000000001");
    expect(text("complete-refund")).toBe("Zwrot pieniędzy wykonany");
  });

  it("shows who made the refund, when, and the note", async () => {
    serve(
      order({
        status: "cancelled",
        allowedTransitions: [],
        refund: { amount: 39998, currency: "PLN", dueAt: "2026-11-19T12:30:00+00:00", overdue: false, completedAt: "2026-11-10T08:00:00+00:00", completedByStaffId: "s2", completedByUsername: "celina", note: "Przelew wysłany" },
      }),
    );
    wrapper = await mountView();

    expect(text("refund-state")).toBe("Wykonany");
    expect(text("refund-completed")).toBe("10.11.2026, 09:00:00, celina");
    expect(text("refund-note")).toBe("Przelew wysłany");
    expect(wrapper.find("[data-testid=refund-overdue]").exists()).toBe(false);
  });

  it("ships with an optional note after a confirmation and shows the order the API answers with", async () => {
    serve(order());
    wrapper = await mountView();
    mocks.post.mockResolvedValue({ data: order({ status: "shipped", version: 3, allowedTransitions: ["deliver", "return"] }), response: { status: 200 } });

    await wrapper.get("[data-testid=action-ship]").trigger("click");
    expect(dialog("change-submit").text()).toBe("Oznacz jako wysłane");
    await dialog("change-note").setValue("Kurier DPD");
    await dialog("change-submit").trigger("click");
    await flushPromises();

    expect(mocks.post).toHaveBeenCalledWith(STATUS_CHANGES, {
      params: { path: { orderId: "o-1" } },
      body: { transition: "ship", version: 2, note: "Kurier DPD" },
    });
    expect(wrapper.find("[data-testid=change-dialog]").exists()).toBe(false);
    expect(text("status")).toBe("Wysłane");
    expect(wrapper.findAll("[data-testid=actions] button").map((button) => button.text())).toEqual(["Oznacz jako dostarczone", "Przyjmij zwrot"]);
  });

  it("asks for a note to cancel and sends nothing without it", async () => {
    serve(order());
    wrapper = await mountView();

    await wrapper.get("[data-testid=action-cancel]").trigger("click");
    await dialog("change-submit").trigger("click");
    await flushPromises();

    expect(wrapper.get("[data-testid=change-dialog] label").text()).toBe("Notatka (wymagana)");
    expect(dialog("change-note-error").text()).toBe("Podaj notatkę.");
    expect(mocks.post).not.toHaveBeenCalled();
  });

  it("completes the refund with the version of the view", async () => {
    serve(order({ status: "cancelled", version: 3, allowedTransitions: [], canCompleteRefund: true }));
    wrapper = await mountView();
    mocks.post.mockResolvedValue({ data: order({ status: "cancelled", version: 4, allowedTransitions: [], canCompleteRefund: false }), response: { status: 200 } });

    await wrapper.get("[data-testid=complete-refund]").trigger("click");
    await dialog("change-submit").trigger("click");
    await flushPromises();

    expect(mocks.post).toHaveBeenCalledWith(REFUND_COMPLETION, { params: { path: { orderId: "o-1" } }, body: { version: 3, note: null } });
    expect(wrapper.find("[data-testid=complete-refund]").exists()).toBe(false);
  });

  it("closes the dialog on a 409, reloads the order and says it changed", async () => {
    serve(order());
    wrapper = await mountView();
    mocks.post.mockResolvedValue({ data: undefined, error: { status: 409 }, response: { status: 409 } });
    serve(order({ status: "shipped", version: 3, allowedTransitions: ["deliver"] }));

    await wrapper.get("[data-testid=action-ship]").trigger("click");
    await dialog("change-submit").trigger("click");
    await flushPromises();

    expect(wrapper.find("[data-testid=change-dialog]").exists()).toBe(false);
    expect(text("conflict")).toBe("Zamówienie zostało w międzyczasie zmienione – sprawdź jego stan.");
    expect(text("status")).toBe("Wysłane");
    expect(wrapper.findAll("[data-testid=actions] button").map((button) => button.text())).toEqual(["Oznacz jako dostarczone"]);
  });

  it("shows a 422 at the note field and keeps the dialog open", async () => {
    serve(order());
    wrapper = await mountView();
    mocks.post.mockResolvedValue({
      data: undefined,
      error: { violations: [{ propertyPath: "note", title: "Notatka może mieć najwyżej 500 znaków." }] },
      response: { status: 422 },
    });

    await wrapper.get("[data-testid=action-cancel]").trigger("click");
    await dialog("change-note").setValue("Powód");
    await dialog("change-submit").trigger("click");
    await flushPromises();

    expect(dialog("change-note-error").text()).toBe("Notatka może mieć najwyżej 500 znaków.");
  });

  it("shows a 403 in the dialog", async () => {
    serve(order());
    wrapper = await mountView();
    mocks.post.mockResolvedValue({ data: undefined, response: { status: 403 } });

    await wrapper.get("[data-testid=action-ship]").trigger("click");
    await dialog("change-submit").trigger("click");
    await flushPromises();

    expect(dialog("change-error").text()).toBe("Nie masz uprawnień do tej zmiany.");
  });

  it("closes the dialog with 'Wróć' and opens a fresh one next time", async () => {
    serve(order());
    wrapper = await mountView();

    await wrapper.get("[data-testid=action-ship]").trigger("click");
    await dialog("change-note").setValue("coś");
    await dialog("change-cancel").trigger("click");
    expect(wrapper.find("[data-testid=change-dialog]").exists()).toBe(false);
    await wrapper.get("[data-testid=action-ship]").trigger("click");

    expect((dialog("change-note").element as HTMLInputElement).value).toBe("");
    expect(mocks.post).not.toHaveBeenCalled();
  });

  it("says the order was not found, with a link to the list", async () => {
    serve(404);
    wrapper = await mountView("nie-ma");

    expect(text("not-found")).toContain("Nie znaleźliśmy zamówienia.");
    expect(wrapper.get("[data-testid=to-list]").attributes("href")).toBe("/orders");
    expect(wrapper.find("[data-testid=retry]").exists()).toBe(false);
    expect(wrapper.find("h1").exists()).toBe(false);
  });

  it("shows another read error with a retry", async () => {
    serve(500);
    wrapper = await mountView();
    expect(text("order-error")).toContain("Nie udało się pobrać zamówienia (HTTP 500).");

    serve(order());
    await wrapper.get("[data-testid=retry]").trigger("click");
    await flushPromises();

    expect(wrapper.find("[data-testid=order-error]").exists()).toBe(false);
    expect(wrapper.get("h1").text()).toBe("Zamówienie R-100001");
  });

  it("tells a role without access that it has none, without a retry", async () => {
    serve(403);
    wrapper = await mountView();

    expect(text("order-error")).toBe("Nie masz dostępu do zamówień.");
    expect(wrapper.find("[data-testid=retry]").exists()).toBe(false);
  });
});
