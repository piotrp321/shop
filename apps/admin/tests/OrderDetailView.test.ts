import { DOMWrapper, flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, type MockInstance, vi } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import { toast } from "vue-sonner";
import type { components } from "@/api/schema";
import OrderDetailView from "@/views/OrderDetailView.vue";

type BackofficeOrder = components["schemas"]["BackofficeOrder"];

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get, POST: mocks.post }) }));

const STATUS_CHANGES = "/api/v1/admin/orders/{orderId}/status-changes";
const REFUND_COMPLETION = "/api/v1/admin/orders/{orderId}/refund-completion";
const PAYMENT_ID = "0192f0c4-aaaa-7000-8000-000000000001";

function order(overrides: Partial<BackofficeOrder> = {}): BackofficeOrder {
  return {
    orderId: "o-1",
    customerId: null,
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
    paymentId: PAYMENT_ID,
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

/** The windows are teleported to <body>, so a query through the whole document finds them. */
const page = () => new DOMWrapper(document.body);

async function mountView(orderId = "o-1") {
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/", name: "dashboard", component: empty },
      { path: "/orders", name: "orders", component: empty },
      { path: "/orders/:orderId", name: "order-detail", component: OrderDetailView },
    ],
  });
  await router.push(`/orders/${orderId}`);
  const wrapper = mount(OrderDetailView, { attachTo: document.body, global: { plugins: [router] } });
  await flushPromises();
  return Object.assign(wrapper, { router });
}

function serve(data: BackofficeOrder | number) {
  mocks.get.mockResolvedValue(typeof data === "number" ? { data: undefined, response: { status: data } } : { data, response: { status: 200 } });
}

function refundOf(overrides: Partial<NonNullable<BackofficeOrder["refund"]>> = {}): NonNullable<BackofficeOrder["refund"]> {
  return { amount: 39998, currency: "PLN", dueAt: "2026-11-19T12:30:00+00:00", overdue: false, completedAt: null, completedByStaffId: null, completedByUsername: null, note: null, ...overrides };
}

describe("OrderDetailView", () => {
  let wrapper: Awaited<ReturnType<typeof mountView>> | undefined;
  let successToast: MockInstance<typeof toast.success>;

  const text = (id: string) => wrapper!.get(`[data-testid=${id}]`).text().replace(/\s+/g, " ");
  const dialog = (id: string) => page().get(`[data-testid=change-dialog] [data-testid=${id}]`);
  const heading = (name: string) => [...document.querySelectorAll("h2")].find((element) => element.textContent?.trim() === name);

  beforeEach(() => {
    mocks.get.mockReset();
    mocks.post.mockReset();
    successToast = vi.spyOn(toast, "success").mockImplementation(() => 1);
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    document.body.innerHTML = "";
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  it("has the H1 „Zamówienie R-100001” with the status beside it, and the crumbs „Pulpit › Zamówienia › R-100001” instead of a back link", async () => {
    serve(order());
    wrapper = await mountView();

    expect(wrapper.get("h1").text()).toBe("Zamówienie R-100001");
    expect(text("status")).toBe("Opłacone");
    expect(wrapper.get("[data-testid=status]").classes().join(" ")).toContain("text-status-info");
    const crumbs = wrapper.get("nav[aria-label=Okruszki]");
    expect(crumbs.text()).toMatch(/^Pulpit.*Zamówienia.*R-100001$/);
    expect(crumbs.get("a[href='/orders']").text()).toBe("Zamówienia");
    expect(crumbs.get("[aria-current=page]").text()).toBe("R-100001");
    expect(wrapper.find("[data-testid=back]").exists()).toBe(false);
  });

  it("puts the side column before the main one in the source, so that Tab starts there", async () => {
    serve(order());
    wrapper = await mountView();

    const side = wrapper.get("[data-testid=order-side]").element;
    const main = wrapper.get("[data-testid=order-main]").element;
    expect(side.compareDocumentPosition(main) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(side.parentElement!.className).toContain("xl:grid-cols-");
    expect(main.querySelector("h2")?.textContent).toBe("Pozycje");
  });

  it("has the cards Akcje, Klient, Adres dostawy and Płatność in the side column and Pozycje and Historia in the main one", async () => {
    serve(order());
    wrapper = await mountView();

    const names = (id: string) => wrapper!.get(`[data-testid=${id}]`).findAll("h2").map((element) => element.text());
    expect(names("order-side")).toEqual(["Akcje", "Klient", "Adres dostawy", "Płatność"]);
    expect(names("order-main")).toEqual(["Pozycje", "Historia"]);
  });

  it("shows the contact with a mailto link and a link to the other orders of the customer", async () => {
    serve(order());
    wrapper = await mountView();

    expect(text("contact")).toContain("Anna Nowak");
    expect(text("contact")).toContain("+48600100200");
    const mail = wrapper.get("[data-testid=contact] a[href^='mailto:']");
    expect(mail.attributes("href")).toBe("mailto:anna.nowak@example.com");
    expect(mail.classes()).toContain("break-all");
    const others = wrapper.get("[data-testid=other-orders]");
    expect(others.text()).toBe("Inne zamówienia klienta");
    expect(decodeURIComponent(others.attributes("href")!)).toBe("/orders?q=anna.nowak@example.com");
  });

  it("shows the address, the dates of the payment and the payment id in a monospace font", async () => {
    serve(order({ status: "cancelled", cancelledAt: "2026-11-06T10:00:00+00:00", cancellationReason: "staff", allowedTransitions: [] }));
    wrapper = await mountView();

    expect(text("address")).toContain("ul. Długa 5/3");
    expect(text("address")).toContain("00-950 Warszawa");
    expect(text("dates")).toContain("Opłacone");
    expect(text("dates")).toContain("05.11.2026, 13:40:00");
    expect(text("cancelled-at")).toBe("06.11.2026, 11:00:00 (Anulowane przez pracownika)");
    expect(text("payment-id")).toBe(PAYMENT_ID);
    expect(wrapper.get("[data-testid=payment-id]").classes()).toContain("font-mono");
  });

  it("shows the payment deadline only for an order that waits for the payment", async () => {
    serve(order({ status: "new", paidAt: null, paymentId: null, allowedTransitions: [] }));
    wrapper = await mountView();
    expect(text("payment-deadline")).toBe("05.11.2026, 14:00:00");
    expect(wrapper.find("[data-testid=copy-payment-id]").exists()).toBe(false);
    wrapper.unmount();

    serve(order());
    wrapper = await mountView();
    expect(wrapper.find("[data-testid=payment-deadline]").exists()).toBe(false);
  });

  describe("the lines", () => {
    it("has the columns Produkt, Ilość, Cena brutto and Wartość brutto, and below 640 px the price is a line of the product cell", async () => {
      serve(order());
      wrapper = await mountView();

      const table = wrapper.get("[data-testid=lines]");
      expect(table.findAll("th").map((header) => header.text())).toEqual(["Produkt", "Ilość", "Cena brutto", "Wartość brutto"]);
      expect(wrapper.findAll("[data-testid=line-row]")).toHaveLength(2);
      const first = wrapper.get("[data-testid=line-row]");
      expect(first.get("[data-testid=line-name]").text()).toBe("Buty szosowe Tarvik Asfalt 200");
      expect(first.text()).toContain("czarne, 42");
      expect(first.get("[data-testid=line-quantity]").text()).toBe("2");
      expect(first.get("[data-testid=line-meta-unit]").text().replace(/\s/g, " ")).toBe("Cena brutto: 199,99 zł");
      expect(first.get("[data-testid=line-meta-unit]").element.parentElement!.classList.contains("sm:hidden")).toBe(true);
      expect(table.findAll("th")[2]!.classes()).toContain("hidden");
    });

    it("keeps the product, the quantity and the value visible below 640 px, and hides the other columns", async () => {
      serve(order());
      wrapper = await mountView();

      const headers = wrapper.get("[data-testid=lines]").findAll("th");
      expect(headers.map((header) => header.classes().includes("hidden"))).toEqual([false, false, true, false]);
    });

    it("has the columns for the discount of a code, and the lines of their values below 640 px", async () => {
      serve(order({ discount: { code: "MINUS50", kind: "amount", value: 5000, amountGross: 5000 } }));
      wrapper = await mountView();

      const headers = wrapper.get("[data-testid=lines]").findAll("th");
      expect(headers.map((header) => header.text())).toEqual(["Produkt", "Ilość", "Cena brutto", "Wartość przed rabatem", "Rabat", "Wartość po rabacie"]);
      expect(headers.map((header) => header.classes().includes("hidden"))).toEqual([false, false, true, true, true, false]);
      const first = wrapper.get("[data-testid=line-row]");
      expect(first.get("[data-testid=line-meta-before-discount]").text().replace(/\s/g, " ")).toBe("Wartość przed rabatem: 399,98 zł");
      expect(first.get("[data-testid=line-meta-discount]").text()).toContain("Rabat:");
    });
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


  it("lists the history as a timeline of the entries of the API, in a card „Historia”", async () => {
    serve(order({ history: [...order().history, { from: "paid", to: "shipped", occurredAt: "2026-11-06T09:00:00+00:00", actorType: "staff", staffId: "s1", staffUsername: "bartek", note: "Kurier DPD" }] }));
    wrapper = await mountView();

    const history = wrapper.findAll("[data-testid=history-row]").map((row) => ["history-change", "history-actor"].map((id) => row.get(`[data-testid=${id}]`).text()));
    expect(history).toEqual([
      ["Czeka na płatność", "Klient"],
      ["Czeka na płatność → Opłacone", "System"],
      ["Opłacone → Wysłane", "bartek"],
    ]);
    expect(wrapper.get("[data-testid=order-main]").findAll("li[data-testid=history-row]")).toHaveLength(3);
    expect(wrapper.findAll("[data-testid=history-note]").map((note) => note.text())).toEqual(["Kurier DPD"]);
  });

  describe("copying the payment id", () => {
    const copy = async () => {
      await wrapper!.get("[data-testid=copy-payment-id]").trigger("click");
      await flushPromises();
    };

    it("has a button with a name, copies the id and announces „Skopiowano” in a status region", async () => {
      const writeText = vi.fn().mockResolvedValue(undefined);
      vi.stubGlobal("navigator", { ...navigator, clipboard: { writeText } });
      serve(order());
      wrapper = await mountView();
      const status = wrapper.get("[data-testid=copy-status]");
      expect(status.attributes("role")).toBe("status");
      expect(status.text()).toBe("");
      expect(wrapper.get("[data-testid=copy-payment-id]").attributes("aria-label")).toBe("Kopiuj identyfikator płatności");

      await copy();

      expect(writeText).toHaveBeenCalledWith(PAYMENT_ID);
      expect(wrapper.get("[data-testid=copy-status]").text()).toBe("Skopiowano");
    });

    it("says it could not copy and selects the id when the clipboard refuses", async () => {
      vi.stubGlobal("navigator", { ...navigator, clipboard: { writeText: vi.fn().mockRejectedValue(new DOMException("denied", "NotAllowedError")) } });
      serve(order());
      wrapper = await mountView();

      await copy();

      expect(wrapper.get("[data-testid=copy-status]").text()).toBe("Nie udało się skopiować – zaznacz identyfikator ręcznie");
      expect(window.getSelection()?.toString()).toBe(PAYMENT_ID);
    });

    it("does the same when there is no clipboard at all", async () => {
      vi.stubGlobal("navigator", { ...navigator, clipboard: undefined });
      serve(order());
      wrapper = await mountView();

      await copy();

      expect(wrapper.get("[data-testid=copy-status]").text()).toBe("Nie udało się skopiować – zaznacz identyfikator ręcznie");
      expect(window.getSelection()?.toString()).toBe(PAYMENT_ID);
    });
  });

  describe("the card Akcje", () => {
    it("shows only the buttons the API allows, red for the cancellation", async () => {
      serve(order({ allowedTransitions: ["ship", "cancel"] }));
      wrapper = await mountView();

      expect(wrapper.findAll("[data-testid=actions] button").map((button) => button.text())).toEqual(["Oznacz jako wysłane", "Anuluj zamówienie"]);
      expect(wrapper.get("[data-testid=action-cancel]").classes().join(" ")).toContain("bg-destructive");
      expect(wrapper.find("[data-testid=complete-refund]").exists()).toBe(false);
    });

    it("is not there when the API allows nothing", async () => {
      serve(order({ allowedTransitions: [], canCompleteRefund: false }));
      wrapper = await mountView();

      expect(wrapper.find("[data-testid=actions]").exists()).toBe(false);
      expect(heading("Akcje")).toBeUndefined();
    });

    it("has „Oznacz zwrot jako wykonany” when the API allows completing the refund", async () => {
      serve(order({ status: "cancelled", allowedTransitions: [], canCompleteRefund: true, refund: refundOf() }));
      wrapper = await mountView();

      expect(text("complete-refund")).toBe("Oznacz zwrot jako wykonany");
    });
  });

  describe("the card Zwrot pieniędzy", () => {
    it("shows the refund with „Po terminie” and the payment to refund", async () => {
      serve(order({ status: "cancelled", allowedTransitions: [], canCompleteRefund: true, refund: refundOf({ overdue: true }) }));
      wrapper = await mountView();

      expect(wrapper.get("[data-testid=refund] h2").text()).toBe("Zwrot pieniędzy");
      expect(text("refund-amount")).toBe("399,98 zł");
      expect(text("refund-due")).toBe("19.11.2026, 13:30:00");
      expect(text("refund-overdue")).toBe("Po terminie");
      expect(text("refund-state")).toBe("Do wykonania");
      expect(text("refund-payment-id")).toBe(PAYMENT_ID);
    });

    it("shows who made the refund, when, and the note", async () => {
      serve(order({ status: "cancelled", allowedTransitions: [], refund: refundOf({ completedAt: "2026-11-10T08:00:00+00:00", completedByStaffId: "s2", completedByUsername: "celina", note: "Przelew wysłany" }) }));
      wrapper = await mountView();

      expect(text("refund-state")).toBe("Wykonany");
      expect(text("refund-completed")).toBe("10.11.2026, 09:00:00, celina");
      expect(text("refund-note")).toBe("Przelew wysłany");
      expect(wrapper.find("[data-testid=refund-overdue]").exists()).toBe(false);
    });

    it("is not there for an order without a refund", async () => {
      serve(order());
      wrapper = await mountView();

      expect(wrapper.find("[data-testid=refund]").exists()).toBe(false);
    });
  });

  describe("the window of a change", () => {
    async function open(testid: string) {
      await wrapper!.get(`[data-testid=${testid}]`).trigger("click");
      await flushPromises();
    }

    it.each([
      ["action-cancel", "Anulować zamówienie R-100001?", "Anuluj zamówienie", true],
      ["action-ship", "Oznaczyć zamówienie R-100001 jako wysłane?", "Oznacz jako wysłane", false],
    ])("has the title with the effect and the number for %s, a button of the effect and „Wróć”", async (button, title, submit, destructive) => {
      serve(order());
      wrapper = await mountView();

      await open(button);

      expect(page().get("[role=dialog] h2").text()).toBe(title);
      expect(dialog("change-submit").text()).toBe(submit);
      expect(dialog("change-submit").classes().join(" ")).toContain(destructive ? "bg-destructive" : "bg-interface");
      expect(dialog("change-cancel").text()).toBe("Wróć");
      expect(page().get("[role=dialog] h2").element.id).toBeTruthy();
    });

    it.each([
      [["deliver"], "action-deliver", "Oznaczyć zamówienie R-100001 jako dostarczone?", "Oznacz jako dostarczone", false],
      [["return"], "action-return", "Przyjąć zwrot zamówienia R-100001?", "Przyjmij zwrot", true],
    ] as const)("has the title of %s", async (transitions, button, title, submit, destructive) => {
      serve(order({ status: "shipped", allowedTransitions: [...transitions] }));
      wrapper = await mountView();

      await open(button);

      expect(page().get("[role=dialog] h2").text()).toBe(title);
      expect(dialog("change-submit").text()).toBe(submit);
      expect(dialog("change-submit").classes().join(" ")).toContain(destructive ? "bg-destructive" : "bg-interface");
    });

    it("has the title of the completion of the refund and a button of its effect", async () => {
      serve(order({ status: "cancelled", allowedTransitions: [], canCompleteRefund: true, refund: refundOf() }));
      wrapper = await mountView();

      await open("complete-refund");

      expect(page().get("[role=dialog] h2").text()).toBe("Oznaczyć zwrot pieniędzy za R-100001 jako wykonany?");
      expect(dialog("change-submit").text()).toBe("Oznacz zwrot jako wykonany");
    });

    it("puts the focus in the field „Notatka”, which is required to cancel and optional to ship", async () => {
      serve(order());
      wrapper = await mountView();

      await open("action-cancel");
      expect(document.activeElement).toBe(dialog("change-note").element);
      expect(page().get("[data-testid=change-dialog] label").text()).toBe("Notatka (wymagana)");
      await dialog("change-cancel").trigger("click");
      await flushPromises();

      await open("action-ship");
      expect(page().get("[data-testid=change-dialog] label").text()).toBe("Notatka (opcjonalnie)");
    });

    it("fits the screen: the width is the smaller of 28 rem and the screen less 2 rem", async () => {
      serve(order());
      wrapper = await mountView();

      await open("action-ship");

      expect(page().get("[data-testid=change-dialog]").classes().join(" ")).toContain("w-[min(28rem,calc(100vw-2rem))]");
    });

    it("ships with an optional note and shows the order the API answers with, with a toast with the Polish name of its status", async () => {
      serve(order());
      wrapper = await mountView();
      mocks.post.mockResolvedValue({ data: order({ status: "shipped", version: 3, allowedTransitions: ["deliver", "return"] }), response: { status: 200 } });

      await open("action-ship");
      await dialog("change-note").setValue("Kurier DPD");
      await dialog("change-submit").trigger("click");
      await flushPromises();

      expect(mocks.post).toHaveBeenCalledWith(STATUS_CHANGES, {
        params: { path: { orderId: "o-1" } },
        body: { transition: "ship", version: 2, note: "Kurier DPD" },
      });
      expect(page().find("[data-testid=change-dialog]").exists()).toBe(false);
      expect(text("status")).toBe("Wysłane");
      expect(wrapper.findAll("[data-testid=actions] button").map((button) => button.text())).toEqual(["Oznacz jako dostarczone", "Przyjmij zwrot"]);
      expect(successToast).toHaveBeenCalledWith("Status zamówienia zmieniony: Wysłane.");
    });

    it.each([
      ["paid", "Opłacone"],
      ["delivered", "Dostarczone"],
      ["cancelled", "Anulowane"],
      ["returned", "Zwrócone"],
      ["new", "Czeka na płatność"],
    ] as const)("#15: the toast after a change to %s says „%s”, never an empty name", async (status, label) => {
      serve(order());
      wrapper = await mountView();
      mocks.post.mockResolvedValue({ data: order({ status, allowedTransitions: [] }), response: { status: 200 } });

      await open("action-ship");
      await dialog("change-submit").trigger("click");
      await flushPromises();

      expect(successToast).toHaveBeenCalledWith(`Status zamówienia zmieniony: ${label}.`);
    });

    it("#15: the toast names a status the panel does not know as the API sent it", async () => {
      serve(order());
      wrapper = await mountView();
      mocks.post.mockResolvedValue({ data: order({ status: "on_hold" as never, allowedTransitions: [] }), response: { status: 200 } });

      await open("action-ship");
      await dialog("change-submit").trigger("click");
      await flushPromises();

      expect(successToast).toHaveBeenCalledWith("Status zamówienia zmieniony: on_hold.");
    });

    it("asks for a note to cancel, sends nothing without it and puts the focus in the field", async () => {
      serve(order());
      wrapper = await mountView();

      await open("action-cancel");
      await dialog("change-submit").trigger("click");
      await flushPromises();

      expect(dialog("change-note-error").text()).toBe("Podaj notatkę.");
      expect(dialog("change-note").attributes("aria-invalid")).toBe("true");
      expect(mocks.post).not.toHaveBeenCalled();
      expect(document.activeElement).toBe(dialog("change-note").element);
    });

    it("completes the refund with the version of the view, with a toast, and the focus on „Historia” when the card is gone", async () => {
      serve(order({ status: "cancelled", version: 3, allowedTransitions: [], canCompleteRefund: true, refund: refundOf() }));
      wrapper = await mountView();
      mocks.post.mockResolvedValue({ data: order({ status: "cancelled", version: 4, allowedTransitions: [], canCompleteRefund: false, refund: refundOf({ completedAt: "2026-11-10T08:00:00+00:00" }) }), response: { status: 200 } });

      await open("complete-refund");
      await dialog("change-submit").trigger("click");
      await flushPromises();

      expect(mocks.post).toHaveBeenCalledWith(REFUND_COMPLETION, { params: { path: { orderId: "o-1" } }, body: { version: 3, note: null } });
      expect(wrapper.find("[data-testid=complete-refund]").exists()).toBe(false);
      expect(successToast).toHaveBeenCalledWith("Zwrot oznaczony jako wykonany.");
      expect(document.activeElement).toBe(heading("Historia"));
    });

    it("puts the focus on the heading „Akcje” after a change that leaves an action", async () => {
      serve(order());
      wrapper = await mountView();
      mocks.post.mockResolvedValue({ data: order({ status: "shipped", version: 3, allowedTransitions: ["deliver"] }), response: { status: 200 } });

      await open("action-ship");
      await dialog("change-submit").trigger("click");
      await flushPromises();

      expect(document.activeElement).toBe(heading("Akcje"));
      expect(heading("Akcje")!.getAttribute("tabindex")).toBe("-1");
    });

    it("puts the focus on the heading „Historia” when the change leaves no action", async () => {
      serve(order({ allowedTransitions: ["cancel"] }));
      wrapper = await mountView();
      mocks.post.mockResolvedValue({ data: order({ status: "cancelled", version: 3, allowedTransitions: [], canCompleteRefund: false }), response: { status: 200 } });

      await open("action-cancel");
      await dialog("change-note").setValue("Klient zrezygnował");
      await dialog("change-submit").trigger("click");
      await flushPromises();

      expect(heading("Akcje")).toBeUndefined();
      expect(document.activeElement).toBe(heading("Historia"));
    });

    it("closes the dialog on a 409, reloads the order, and shows the conflict with the focus on it", async () => {
      serve(order());
      wrapper = await mountView();
      mocks.post.mockResolvedValue({ data: undefined, error: { status: 409 }, response: { status: 409 } });
      serve(order({ status: "shipped", version: 3, allowedTransitions: ["deliver"] }));

      await open("action-ship");
      await dialog("change-submit").trigger("click");
      await flushPromises();

      expect(page().find("[data-testid=change-dialog]").exists()).toBe(false);
      expect(text("conflict")).toBe("Zamówienie zostało w międzyczasie zmienione – sprawdź jego stan.");
      expect(wrapper.get("[data-testid=conflict]").attributes("tabindex")).toBe("-1");
      expect(document.activeElement).toBe(wrapper.get("[data-testid=conflict]").element);
      expect(text("status")).toBe("Wysłane");
      expect(wrapper.findAll("[data-testid=actions] button").map((button) => button.text())).toEqual(["Oznacz jako dostarczone"]);
      expect(successToast).not.toHaveBeenCalled();
    });

    it("shows a 422 at the note field with the focus in it, and keeps the dialog open", async () => {
      serve(order());
      wrapper = await mountView();
      mocks.post.mockResolvedValue({
        data: undefined,
        error: { violations: [{ propertyPath: "note", title: "Notatka może mieć najwyżej 500 znaków." }] },
        response: { status: 422 },
      });

      await open("action-cancel");
      await dialog("change-note").setValue("Powód");
      await dialog("change-submit").trigger("click");
      await flushPromises();

      expect(dialog("change-note-error").text()).toBe("Notatka może mieć najwyżej 500 znaków.");
      expect(document.activeElement).toBe(dialog("change-note").element);
      expect(successToast).not.toHaveBeenCalled();
    });

    it("#260 F8: shows a 403 in the message of the dialog, with the focus on it", async () => {
      serve(order());
      wrapper = await mountView();
      mocks.post.mockResolvedValue({ data: undefined, response: { status: 403 } });

      await open("action-ship");
      await dialog("change-submit").trigger("click");
      await flushPromises();

      expect(dialog("change-error").text()).toBe("Nie masz uprawnień do tej zmiany.");
      expect(dialog("change-error").attributes("role")).toBe("alert");
      expect(document.activeElement).toBe(dialog("change-error").element);
    });

    it("closes with „Wróć” and gives the focus back to the button that opened it, and opens a fresh window next time", async () => {
      serve(order());
      wrapper = await mountView();

      const opener = wrapper.get("[data-testid=action-ship]").element as HTMLElement;
      opener.focus();
      await open("action-ship");
      await dialog("change-note").setValue("coś");
      await dialog("change-cancel").trigger("click");
      await flushPromises();

      expect(page().find("[data-testid=change-dialog]").exists()).toBe(false);
      expect(document.activeElement).toBe(opener);
      await open("action-ship");
      expect((dialog("change-note").element as HTMLInputElement).value).toBe("");
      expect(mocks.post).not.toHaveBeenCalled();
    });

    it("closes the window, and forgets a conflict, when the screen moves to another order", async () => {
      serve(order());
      wrapper = await mountView();
      mocks.post.mockResolvedValue({ data: undefined, error: { status: 409 }, response: { status: 409 } });
      await open("action-ship");
      await dialog("change-submit").trigger("click");
      await flushPromises();
      expect(wrapper.find("[data-testid=conflict]").exists()).toBe(true);
      await open("action-cancel");
      expect(page().find("[data-testid=change-dialog]").exists()).toBe(true);

      serve(order({ orderId: "o-2", number: "R-100002" }));
      await wrapper.router.push("/orders/o-2");
      await flushPromises();

      expect(page().find("[data-testid=change-dialog]").exists()).toBe(false);
      expect(wrapper.find("[data-testid=conflict]").exists()).toBe(false);
      expect(wrapper.get("h1").text()).toBe("Zamówienie R-100002");
    });

    it("submits with Enter in the field", async () => {
      serve(order());
      wrapper = await mountView();
      mocks.post.mockResolvedValue({ data: order({ status: "shipped", allowedTransitions: [] }), response: { status: 200 } });

      await open("action-ship");
      await dialog("change-note").trigger("keydown.enter");
      await flushPromises();

      expect(mocks.post).toHaveBeenCalledTimes(1);
    });
  });

  describe("the states of the screen", () => {
    it("says the order was not found, with the H1 „Nie znaleziono zamówienia” and a link to the list", async () => {
      serve(404);
      wrapper = await mountView("nie-ma");

      expect(wrapper.get("h1").text()).toBe("Nie znaleziono zamówienia");
      expect(text("not-found")).toContain("Nie znaleźliśmy zamówienia.");
      expect(wrapper.get("[data-testid=to-list]").attributes("href")).toBe("/orders");
      expect(wrapper.find("[data-testid=retry]").exists()).toBe(false);
    });

    it("shows another read error with a retry, and puts the focus on the H1 when the retry works", async () => {
      serve(500);
      wrapper = await mountView();
      expect(text("order-error")).toContain("Nie udało się pobrać zamówienia (HTTP 500).");

      serve(order());
      (wrapper.get("[data-testid=retry]").element as HTMLElement).focus();
      await wrapper.get("[data-testid=retry]").trigger("click");
      await flushPromises();

      expect(wrapper.find("[data-testid=order-error]").exists()).toBe(false);
      expect(wrapper.get("h1").text()).toBe("Zamówienie R-100001");
      expect(document.activeElement).toBe(wrapper.get("h1").element);
    });

    it("keeps the focus on the retry button when the retry fails again", async () => {
      serve(500);
      wrapper = await mountView();

      (wrapper.get("[data-testid=retry]").element as HTMLElement).focus();
      await wrapper.get("[data-testid=retry]").trigger("click");
      await flushPromises();

      expect(document.activeElement).toBe(wrapper.get("[data-testid=retry]").element);
    });

    it("tells a role without access that it has none, without a retry", async () => {
      serve(403);
      wrapper = await mountView();

      expect(text("order-error")).toBe("Nie masz dostępu do zamówień.");
      expect(wrapper.find("[data-testid=retry]").exists()).toBe(false);
    });

    it("shows a skeleton while the order loads", async () => {
      mocks.get.mockReturnValue(new Promise(() => {}));
      wrapper = await mountView();

      expect(wrapper.find("[data-testid=order-loading]").exists()).toBe(true);
      expect(wrapper.get("h1").text()).toBe("Zamówienie");
    });
  });
});
