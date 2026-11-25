import { DOMWrapper, flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { afterEach, beforeEach, describe, expect, it, type MockInstance, vi } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import { toast } from "vue-sonner";
import type { components } from "@/api/schema";
import { type StaffMe, useAuthStore } from "@/stores/auth";
import OrderDetailView from "@/views/OrderDetailView.vue";

type BackofficeOrder = components["schemas"]["BackofficeOrder"];

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get, POST: mocks.post, PUT: mocks.put }) }));

const STATUS_CHANGES = "/api/v1/admin/orders/{orderId}/status-changes";
const REFUND_COMPLETION = "/api/v1/admin/orders/{orderId}/refund-completion";
const TRACKING_NUMBER = "/api/v1/admin/orders/{orderId}/tracking-number";
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
    // An order placed before the delivery; the totals are those of its lines.
    delivery: null,
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
    shipment: null,
    allowedTransitions: ["ship", "cancel"],
    canCompleteRefund: false,
    canChangeTrackingNumber: false,
    ...overrides,
  };
}

/** The windows are teleported to <body>, so a query through the whole document finds them. */
const page = () => new DOMWrapper(document.body);

async function mountView(orderId = "o-1", roles: StaffMe["roles"] | null = ["admin"]) {
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/", name: "dashboard", component: empty },
      { path: "/orders", name: "orders", component: empty },
      { path: "/orders/:orderId", name: "order-detail", component: OrderDetailView },
      { path: "/customers/:customerId", name: "customer", component: empty },
    ],
  });
  await router.push(`/orders/${orderId}`);
  const pinia = createPinia();
  setActivePinia(pinia);
  if (roles !== null) {
    useAuthStore().staff = { id: "u1", username: "anna", roles };
  }
  const wrapper = mount(OrderDetailView, { attachTo: document.body, global: { plugins: [pinia, router] } });
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
    mocks.put.mockReset();
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

  describe("the link „Karta klienta”", () => {
    it.each(["admin", "support"] as const)("leads to the card of the customer of the order for %s", async (role) => {
      serve(order({ customerId: "c-1" }));
      wrapper = await mountView("o-1", [role]);

      const link = wrapper.get("[data-testid=customer-card]");
      expect(link.text()).toBe("Karta klienta");
      expect(link.attributes("href")).toBe("/customers/c-1");
      expect(wrapper.find("[data-testid=other-orders]").exists()).toBe(true);
    });

    it("is not there for the order of a guest, who has no customer card", async () => {
      serve(order({ customerId: null }));
      wrapper = await mountView("o-1", ["admin"]);

      expect(wrapper.find("[data-testid=customer-card]").exists()).toBe(false);
    });

    it.each([["warehouse"], ["marketing"]] as [StaffMe["roles"][number]][])("is not there for %s, who may not see customers", async (role) => {
      serve(order({ customerId: "c-1" }));
      wrapper = await mountView("o-1", [role]);

      expect(wrapper.find("[data-testid=customer-card]").exists()).toBe(false);
    });

    it("is not there while the staff member is unknown", async () => {
      serve(order({ customerId: "c-1" }));
      wrapper = await mountView("o-1", null);

      expect(wrapper.find("[data-testid=customer-card]").exists()).toBe(false);
    });
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

  describe("the delivery and the shipment", () => {
    const FORMAT = "Numer przesyłki może mieć 1–40 znaków: litery bez polskich znaków, cyfry i myślniki.";
    const shipped = (overrides: Partial<BackofficeOrder> = {}) =>
      order({
        status: "shipped",
        version: 3,
        shippedAt: "2026-11-06T09:00:00+00:00",
        delivery: { methodName: "Kurier standardowy", gross: 1999 },
        totals: { net: 34144, vat: 7853, gross: 41997, currency: "PLN" },
        allowedTransitions: ["deliver", "return"],
        canChangeTrackingNumber: true,
        ...overrides,
      });
    const numbered = { carrier: "Kurier standardowy", trackingNumber: "JJD0002-4455", trackingUrl: "https://tracking.example.com/JJD0002-4455" };

    async function open(testid: string) {
      await wrapper!.get(`[data-testid=${testid}]`).trigger("click");
      await flushPromises();
    }
    const tracking = (id: string) => page().get(`[data-testid=tracking-dialog] [data-testid=${id}]`);

    it("shows the delivery method with its cost before the totals, which include it", async () => {
      serve(order({ delivery: { methodName: "Kurier standardowy", gross: 1999 }, totals: { net: 34144, vat: 7853, gross: 41997, currency: "PLN" } }));
      wrapper = await mountView();

      expect(text("delivery-line")).toBe("Dostawa (Kurier standardowy): 19,99 zł");
      expect(text("total-gross")).toBe("419,97 zł");
    });

    it("shows a free delivery as 0,00 zł and nothing for an order placed without a method", async () => {
      serve(order({ delivery: { methodName: "Kurier standardowy", gross: 0 } }));
      wrapper = await mountView();
      expect(text("delivery-line")).toBe("Dostawa (Kurier standardowy): 0,00 zł");
      wrapper.unmount();

      serve(order());
      wrapper = await mountView();
      expect(wrapper.find("[data-testid=delivery-line]").exists()).toBe(false);
    });

    it("shows the number with a link that opens in a new tab only for an order with a shipment", async () => {
      serve(shipped({ shipment: numbered }));
      wrapper = await mountView();

      expect(wrapper.get("[data-testid=shipment] h2").text()).toBe("Przesyłka");
      expect(text("tracking-number")).toBe("JJD0002-4455");
      const link = wrapper.get("[data-testid=tracking-link]");
      expect(link.text()).toBe("Śledź przesyłkę (otwiera się w nowej karcie)");
      expect(link.attributes("href")).toBe("https://tracking.example.com/JJD0002-4455");
      expect(link.attributes("target")).toBe("_blank");
      expect(link.attributes("rel")).toBe("noopener noreferrer");
      wrapper.unmount();

      serve(shipped());
      wrapper = await mountView();
      expect(wrapper.find("[data-testid=shipment]").exists()).toBe(false);
      expect(wrapper.find("[data-testid=tracking-link]").exists()).toBe(false);
    });

    it("shows the number without a link when the method has no template", async () => {
      serve(shipped({ shipment: { carrier: null, trackingNumber: "AB-12", trackingUrl: null } }));
      wrapper = await mountView();

      expect(text("tracking-number")).toBe("AB-12");
      expect(wrapper.find("[data-testid=tracking-link]").exists()).toBe(false);
    });

    it("has the action „Dodaj numer przesyłki” without a number and „Zmień numer przesyłki” with one, only when the API allows it", async () => {
      serve(shipped());
      wrapper = await mountView();
      expect(text("change-tracking-number")).toBe("Dodaj numer przesyłki");
      wrapper.unmount();

      serve(shipped({ shipment: numbered }));
      wrapper = await mountView();
      expect(text("change-tracking-number")).toBe("Zmień numer przesyłki");
      wrapper.unmount();

      serve(shipped({ canChangeTrackingNumber: false, shipment: numbered }));
      wrapper = await mountView();
      expect(wrapper.find("[data-testid=change-tracking-number]").exists()).toBe(false);
    });

    it("keeps the card Akcje for an order that has no other action, so that the warehouse can still correct the number", async () => {
      serve(shipped({ status: "delivered", allowedTransitions: [], shipment: numbered }));
      wrapper = await mountView("o-1", ["warehouse"]);

      expect(wrapper.findAll("[data-testid=actions] button").map((button) => button.text())).toEqual(["Zmień numer przesyłki"]);
    });

    it("has the optional field „Numer przesyłki” with a hint in the window of a shipment, and in no other window", async () => {
      serve(order({ allowedTransitions: ["ship", "cancel"] }));
      wrapper = await mountView();

      await open("action-ship");
      const labels = page().findAll("[data-testid=change-dialog] label").map((label) => label.text());
      expect(labels).toEqual(["Notatka (opcjonalnie)", "Numer przesyłki (opcjonalnie)"]);
      const field = dialog("change-tracking-number-field");
      expect(page().get("label[for=" + field.attributes("id") + "]").text()).toBe("Numer przesyłki (opcjonalnie)");
      expect(page().get(`#${field.attributes("aria-describedby")!.split(" ")[0]}`).text()).toBe("Litery, cyfry i myślniki, do 40 znaków");
      await dialog("change-cancel").trigger("click");
      await flushPromises();

      await open("action-cancel");
      expect(page().find("[data-testid=change-tracking-number-field]").exists()).toBe(false);
    });

    it("limits the tracking number field of the shipping window to 40 characters, as the API does", async () => {
      serve(order({ allowedTransitions: ["ship", "cancel"] }));
      wrapper = await mountView();

      await open("action-ship");

      expect(dialog("change-tracking-number-field").attributes("maxlength")).toBe("40");
    });

    it("keeps the order number of the window titles in one unbreakable element", async () => {
      serve(order({ allowedTransitions: ["ship", "cancel"] }));
      wrapper = await mountView();

      await open("action-ship");

      const nowrap = page().findAll("[role=dialog] h2 .whitespace-nowrap");
      expect(nowrap.map((element) => element.text())).toEqual(["R-100001"]);
      expect(page().get("[role=dialog] h2").text()).toBe("Oznaczyć zamówienie R-100001 jako wysłane?");
    });

    it("ships with the number, shows the shipment of the order the API answers with, and has no number in the field next time", async () => {
      serve(order());
      wrapper = await mountView();
      mocks.post.mockResolvedValue({ data: shipped({ shipment: numbered }), response: { status: 200 } });

      await open("action-ship");
      await dialog("change-tracking-number-field").setValue("JJD0002-4455");
      await dialog("change-submit").trigger("click");
      await flushPromises();

      expect(mocks.post).toHaveBeenCalledWith(STATUS_CHANGES, {
        params: { path: { orderId: "o-1" } },
        body: { transition: "ship", version: 2, note: null, trackingNumber: "JJD0002-4455" },
      });
      expect(text("tracking-number")).toBe("JJD0002-4455");
      expect(text("delivery-line")).toBe("Dostawa (Kurier standardowy): 19,99 zł");
    });

    it("submits the number with Enter in its field", async () => {
      serve(order());
      wrapper = await mountView();
      mocks.post.mockResolvedValue({ data: shipped(), response: { status: 200 } });

      await open("action-ship");
      await dialog("change-tracking-number-field").setValue("AB-1");
      await dialog("change-tracking-number-field").trigger("keydown.enter");
      await flushPromises();

      expect(mocks.post).toHaveBeenCalledTimes(1);
    });

    it("shows a 422 of the number at its field, tied to it and with the focus in it, and keeps the window open", async () => {
      serve(order());
      wrapper = await mountView();
      mocks.post.mockResolvedValue({ data: undefined, error: { violations: [{ propertyPath: "trackingNumber", title: FORMAT }] }, response: { status: 422 } });

      await open("action-ship");
      await dialog("change-tracking-number-field").setValue("Żółć 1");
      await dialog("change-submit").trigger("click");
      await flushPromises();

      expect(dialog("change-tracking-number-field-error").text()).toBe(FORMAT);
      expect(dialog("change-tracking-number-field").attributes("aria-invalid")).toBe("true");
      expect(dialog("change-tracking-number-field").attributes("aria-describedby")).toContain("change-tracking-number-field-error");
      expect(document.activeElement).toBe(dialog("change-tracking-number-field").element);
      expect(page().find("[data-testid=change-dialog]").exists()).toBe(true);
      expect(successToast).not.toHaveBeenCalled();
    });

    it("limits the field of the number to 40 characters and keeps the order number of the title unbreakable", async () => {
      serve(shipped({ shipment: numbered }));
      wrapper = await mountView();

      await open("change-tracking-number");

      expect(tracking("tracking-number-input").attributes("maxlength")).toBe("40");
      expect(page().findAll("[role=dialog] h2 .whitespace-nowrap").map((element) => element.text())).toEqual(["R-100001"]);
    });

    it("opens the window of the number on its field, with the number there is, the title of the change and a hint", async () => {
      serve(shipped({ shipment: numbered }));
      wrapper = await mountView();

      await open("change-tracking-number");

      expect(page().get("[role=dialog] h2").text()).toBe("Zmienić numer przesyłki zamówienia R-100001?");
      expect(page().get("[role=dialog] label").text()).toBe("Numer przesyłki");
      expect((tracking("tracking-number-input").element as HTMLInputElement).value).toBe("JJD0002-4455");
      expect(document.activeElement).toBe(tracking("tracking-number-input").element);
      expect(page().get("#tracking-number-hint").text()).toBe("Litery, cyfry i myślniki, do 40 znaków. Puste pole usuwa numer.");
      expect(tracking("tracking-number-input").attributes("aria-describedby")).toBe("tracking-number-hint");
      expect(tracking("tracking-submit").text()).toBe("Zapisz numer");
      expect(tracking("tracking-cancel").text()).toBe("Wróć");
    });

    it("opens the window of a number to add with the title „Dodać” and an empty field", async () => {
      serve(shipped());
      wrapper = await mountView();

      await open("change-tracking-number");

      expect(page().get("[role=dialog] h2").text()).toBe("Dodać numer przesyłki zamówienia R-100001?");
      expect((tracking("tracking-number-input").element as HTMLInputElement).value).toBe("");
    });

    it("saves the number with the version, announces it, and gives the focus back to the action", async () => {
      serve(shipped());
      wrapper = await mountView();
      mocks.put.mockResolvedValue({ data: shipped({ version: 4, shipment: numbered }), response: { status: 200 } });

      const action = wrapper.get("[data-testid=change-tracking-number]").element as HTMLElement;
      action.focus();
      await open("change-tracking-number");
      await tracking("tracking-number-input").setValue("JJD0002-4455");
      await tracking("tracking-submit").trigger("click");
      await flushPromises();

      expect(mocks.put).toHaveBeenCalledWith(TRACKING_NUMBER, { params: { path: { orderId: "o-1" } }, body: { trackingNumber: "JJD0002-4455", version: 3 } });
      expect(page().find("[data-testid=tracking-dialog]").exists()).toBe(false);
      expect(text("tracking-number")).toBe("JJD0002-4455");
      expect(successToast).toHaveBeenCalledWith("Numer przesyłki zapisany.");
      expect(text("change-tracking-number")).toBe("Zmień numer przesyłki");
      expect(document.activeElement).toBe(wrapper.get("[data-testid=change-tracking-number]").element);
    });

    it("removes the number with an empty field and says so", async () => {
      serve(shipped({ shipment: numbered }));
      wrapper = await mountView();
      mocks.put.mockResolvedValue({ data: shipped({ version: 4 }), response: { status: 200 } });

      await open("change-tracking-number");
      await tracking("tracking-number-input").setValue("");
      await tracking("tracking-submit").trigger("click");
      await flushPromises();

      expect(mocks.put).toHaveBeenCalledWith(TRACKING_NUMBER, { params: { path: { orderId: "o-1" } }, body: { trackingNumber: null, version: 3 } });
      expect(wrapper.find("[data-testid=shipment]").exists()).toBe(false);
      expect(successToast).toHaveBeenCalledWith("Numer przesyłki usunięty.");
      expect(text("change-tracking-number")).toBe("Dodaj numer przesyłki");
    });

    it("submits with Enter in the field", async () => {
      serve(shipped());
      wrapper = await mountView();
      mocks.put.mockResolvedValue({ data: shipped({ version: 4, shipment: numbered }), response: { status: 200 } });

      await open("change-tracking-number");
      await tracking("tracking-number-input").setValue("AB-1");
      await tracking("tracking-number-input").trigger("keydown.enter");
      await flushPromises();

      expect(mocks.put).toHaveBeenCalledTimes(1);
    });

    it("shows a 422 at the field of the window, tied to it, with the focus in it, in a live region", async () => {
      serve(shipped());
      wrapper = await mountView();
      mocks.put.mockResolvedValue({ data: undefined, error: { violations: [{ propertyPath: "trackingNumber", title: FORMAT }] }, response: { status: 422 } });

      await open("change-tracking-number");
      await tracking("tracking-number-input").setValue("Żółć");
      await tracking("tracking-submit").trigger("click");
      await flushPromises();

      expect(tracking("tracking-number-error").text()).toBe(FORMAT);
      expect(tracking("tracking-number-input").attributes("aria-invalid")).toBe("true");
      expect(tracking("tracking-number-input").attributes("aria-describedby")).toBe("tracking-number-hint tracking-number-error");
      expect(document.activeElement).toBe(tracking("tracking-number-input").element);
      expect(page().find("[data-testid=tracking-dialog]").exists()).toBe(true);
      expect(successToast).not.toHaveBeenCalled();
    });

    it("says in the window, as an alert with the focus, that the order changed (409), and shows the reloaded order", async () => {
      serve(shipped());
      wrapper = await mountView();
      mocks.put.mockResolvedValue({ data: undefined, error: { status: 409 }, response: { status: 409 } });
      serve(order({ status: "returned", version: 5, allowedTransitions: [], canChangeTrackingNumber: false }));

      await open("change-tracking-number");
      await tracking("tracking-submit").trigger("click");
      await flushPromises();

      expect(tracking("tracking-error").text()).toBe("Zamówienie zostało w międzyczasie zmienione – sprawdź jego stan.");
      expect(tracking("tracking-error").attributes("role")).toBe("alert");
      expect(document.activeElement).toBe(tracking("tracking-error").element);
      expect(text("status")).toBe("Zwrócone");
      expect(wrapper.find("[data-testid=change-tracking-number]").exists()).toBe(false);
      expect(successToast).not.toHaveBeenCalled();
    });

    it("says in the window that the staff member may not make the change (403)", async () => {
      serve(shipped());
      wrapper = await mountView();
      mocks.put.mockResolvedValue({ data: undefined, response: { status: 403 } });

      await open("change-tracking-number");
      await tracking("tracking-submit").trigger("click");
      await flushPromises();

      expect(tracking("tracking-error").text()).toBe("Nie masz uprawnień do tej zmiany.");
      expect(tracking("tracking-error").attributes("role")).toBe("alert");
      expect(document.activeElement).toBe(tracking("tracking-error").element);
    });

    it("closes with „Wróć”, gives the focus back to the action, and opens a fresh window next time", async () => {
      serve(shipped({ shipment: numbered }));
      wrapper = await mountView();

      const action = wrapper.get("[data-testid=change-tracking-number]").element as HTMLElement;
      action.focus();
      await open("change-tracking-number");
      await tracking("tracking-number-input").setValue("ZMIENIONY-1");
      await tracking("tracking-cancel").trigger("click");
      await flushPromises();

      expect(page().find("[data-testid=tracking-dialog]").exists()).toBe(false);
      expect(document.activeElement).toBe(action);
      expect(mocks.put).not.toHaveBeenCalled();
      await open("change-tracking-number");
      expect((tracking("tracking-number-input").element as HTMLInputElement).value).toBe("JJD0002-4455");
    });

    it("closes the window of the number when the screen moves to another order", async () => {
      serve(shipped());
      wrapper = await mountView();
      await open("change-tracking-number");
      expect(page().find("[data-testid=tracking-dialog]").exists()).toBe(true);

      serve(order({ orderId: "o-2", number: "R-100002" }));
      await wrapper.router.push("/orders/o-2");
      await flushPromises();

      expect(page().find("[data-testid=tracking-dialog]").exists()).toBe(false);
      expect(wrapper.get("h1").text()).toBe("Zamówienie R-100002");
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
        body: { transition: "ship", version: 2, note: "Kurier DPD", trackingNumber: null },
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

  // K3 (docs/features/admin-ux-fixes/spec.md): while a change is sent, the window it was sent from stays open and takes no input.
  describe("a save in flight", () => {
    interface SaveWindow {
      name: string;
      /** The order the window is opened on. */
      order: (overrides?: Partial<BackofficeOrder>) => BackofficeOrder;
      /** The action that opens the window. */
      opener: string;
      root: string;
      submit: string;
      cancel: string;
      /** The fields that take text. */
      fields: string[];
      /** The field a 422 points at, which Enter submits from. */
      field: string;
      send: () => typeof mocks.post;
      error: string;
    }

    const answered = (overrides: Partial<BackofficeOrder> = {}) => order({ status: "shipped", version: 3, allowedTransitions: ["deliver"], canChangeTrackingNumber: true, ...overrides });
    const windows: SaveWindow[] = [
      {
        name: "the window of a change",
        order: (overrides) => order(overrides),
        opener: "action-ship",
        root: "change-dialog",
        submit: "change-submit",
        cancel: "change-cancel",
        fields: ["change-note", "change-tracking-number-field"],
        field: "change-tracking-number-field",
        send: () => mocks.post,
        error: "change-error",
      },
      {
        name: "the window of the tracking number",
        order: (overrides) => answered(overrides),
        opener: "change-tracking-number",
        root: "tracking-dialog",
        submit: "tracking-submit",
        cancel: "tracking-cancel",
        fields: ["tracking-number-input"],
        field: "tracking-number-input",
        send: () => mocks.put,
        error: "tracking-error",
      },
    ];

    type Answer = { data?: BackofficeOrder; error?: unknown; response: { status: number } };
    function deferred() {
      let resolve!: (value: Answer) => void;
      let reject!: (reason: unknown) => void;
      const promise = new Promise<Answer>((done, fail) => {
        resolve = done;
        reject = fail;
      });
      return { promise, resolve, reject };
    }

    const pressEscape = () => (document.activeElement ?? document.body).dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }));
    const clickOutside = () => document.querySelector("[data-slot=dialog-overlay]")!.dispatchEvent(new PointerEvent("pointerdown", { bubbles: true, cancelable: true, button: 0 }));
    const settle = async () => {
      await new Promise((done) => setTimeout(done, 10));
      await flushPromises();
    };

    describe.each(windows)("$name", (target) => {
      const inWindow = (id: string) => page().get(`[data-testid=${target.root}] [data-testid=${id}]`);
      const windowExists = () => page().find(`[data-testid=${target.root}]`).exists();
      const closeButton = () => page().get(`[data-testid=${target.root}] [data-slot=dialog-close]`);

      /** Opens the window, types into its field and sends the change, which the test answers later. */
      async function sendHeld() {
        serve(target.order());
        wrapper = await mountView();
        const held = deferred();
        target.send().mockReturnValue(held.promise);
        await wrapper.get(`[data-testid=${target.opener}]`).trigger("click");
        await settle();
        await inWindow(target.field).setValue("AB-1");
        (inWindow(target.submit).element as HTMLElement).focus();
        await inWindow(target.submit).trigger("click");
        await flushPromises();
        return held;
      }

      it.each(["Escape", "a click outside", "„Wróć”", "„Zamknij”"])("stays open on %s, and the change still ends with its toast", async (way) => {
        const held = await sendHeld();
        expect(target.send()).toHaveBeenCalledTimes(1);

        const close: Record<string, () => Promise<unknown> | unknown> = {
          Escape: pressEscape,
          "a click outside": clickOutside,
          "„Wróć”": () => inWindow(target.cancel).trigger("click"),
          "„Zamknij”": () => closeButton().trigger("click"),
        };
        await close[way]!();
        await settle();

        expect(windowExists()).toBe(true);
        expect(successToast).not.toHaveBeenCalled();
        held.resolve({ data: answered({ shipment: { carrier: "Kurier", trackingNumber: "AB-1", trackingUrl: null } }), response: { status: 200 } });
        await settle();

        expect(successToast).toHaveBeenCalledTimes(1);
        expect(windowExists()).toBe(false);
      });

      it("has aria-disabled instead of disabled on the button of the effect, keeps the focus on it, and takes no typing", async () => {
        await sendHeld();

        const submit = inWindow(target.submit);
        expect(submit.attributes("aria-disabled")).toBe("true");
        expect(submit.attributes("disabled")).toBeUndefined();
        expect(document.activeElement).toBe(submit.element);
        for (const id of target.fields) {
          expect(inWindow(id).attributes("readonly"), id).toBeDefined();
        }
      });

      it("sends one request for the second click, Enter and Space on the button and Enter in the field", async () => {
        await sendHeld();
        const submit = inWindow(target.submit);

        await submit.trigger("click");
        await submit.trigger("keydown.enter");
        await submit.trigger("keydown.space");
        await submit.trigger("keyup.space");
        await submit.trigger("click");
        await inWindow(target.field).trigger("keydown.enter");
        await flushPromises();

        expect(target.send()).toHaveBeenCalledTimes(1);
      });

      it("lifts the block after a 422: the window stays, the field keeps its value and takes changes, the error is at it with the focus", async () => {
        const held = await sendHeld();
        held.resolve({ data: undefined, error: { violations: [{ propertyPath: "trackingNumber", title: "Zły numer." }] }, response: { status: 422 } });
        await settle();

        expect(windowExists()).toBe(true);
        expect(inWindow(target.submit).attributes("aria-disabled")).toBeUndefined();
        expect((inWindow(target.field).element as HTMLInputElement).value).toBe("AB-1");
        expect(inWindow(target.field).attributes("readonly")).toBeUndefined();
        expect(page().get(`[data-testid=${target.root}] [data-form-error]`).text()).toBe("Zły numer.");
        expect(document.activeElement).toBe(inWindow(target.field).element);
        await inWindow(target.field).setValue("AB-2");
        target.send().mockResolvedValue({ data: answered(), response: { status: 200 } });
        await inWindow(target.submit).trigger("click");
        await settle();

        expect(target.send()).toHaveBeenCalledTimes(2);
        expect(successToast).toHaveBeenCalledTimes(1);
      });

      it.each([
        ["a network error", { reject: new Error("offline") }, /Sprawdź połączenie z API\./],
        ["a 403", { answer: { data: undefined, response: { status: 403 } } }, /^Nie masz uprawnień do tej zmiany\.$/],
        ["a 500", { answer: { data: undefined, response: { status: 500 } } }, /\(HTTP 500\)/],
      ] as const)("lifts the block after %s: the message is in the window with the focus, the value stays, and the next try sends a new request", async (_name, outcome, message) => {
        const held = await sendHeld();
        if ("reject" in outcome) {
          held.reject(outcome.reject);
        } else {
          held.resolve(outcome.answer);
        }
        await settle();

        expect(windowExists()).toBe(true);
        expect(inWindow(target.error).text()).toMatch(message);
        expect(document.activeElement).toBe(inWindow(target.error).element);
        expect(inWindow(target.submit).attributes("aria-disabled")).toBeUndefined();
        expect((inWindow(target.field).element as HTMLInputElement).value).toBe("AB-1");
        expect(inWindow(target.field).attributes("readonly")).toBeUndefined();
        target.send().mockResolvedValue({ data: answered(), response: { status: 200 } });
        await inWindow(target.submit).trigger("click");
        await settle();

        expect(target.send()).toHaveBeenCalledTimes(2);
        expect(successToast).toHaveBeenCalledTimes(1);
      });

      it("lifts the block after a 409 and reloads the order", async () => {
        const held = await sendHeld();
        serve(answered({ version: 5, allowedTransitions: [] }));
        held.resolve({ data: undefined, error: { status: 409 }, response: { status: 409 } });
        await settle();

        expect(mocks.get).toHaveBeenCalledTimes(2);
        expect(text("status")).toBe("Wysłane");
        expect(successToast).not.toHaveBeenCalled();
        if (target.root === "change-dialog") {
          // The window of a change closes, and the conflict, not the window, says what happened.
          expect(windowExists()).toBe(false);
          expect(document.activeElement).toBe(wrapper!.get("[data-testid=conflict]").element);
        } else {
          // The window of the number stays with the message and the value.
          expect(windowExists()).toBe(true);
          expect(inWindow(target.error).text()).toBe("Zamówienie zostało w międzyczasie zmienione – sprawdź jego stan.");
          expect(document.activeElement).toBe(inWindow(target.error).element);
          expect((inWindow(target.field).element as HTMLInputElement).value).toBe("AB-1");
          expect(inWindow(target.field).attributes("readonly")).toBeUndefined();
          expect(inWindow(target.submit).attributes("aria-disabled")).toBeUndefined();
        }
      });

      it("closes the window when the screen moves to another order, drops the late answer, and closes the window opened there with Escape", async () => {
        const held = await sendHeld();

        serve(target.order({ orderId: "o-2", number: "R-100002" }));
        await wrapper!.router.push("/orders/o-2");
        await settle();
        expect(windowExists()).toBe(false);
        expect(wrapper!.get("h1").text()).toBe("Zamówienie R-100002");

        // The save of the first order is still in flight: the new window waits for it, but is not locked.
        const statusBefore = text("status");
        await wrapper!.get(`[data-testid=${target.opener}]`).trigger("click");
        await settle();
        expect(windowExists()).toBe(true);
        expect(inWindow(target.submit).attributes("aria-disabled")).toBe("true");
        held.resolve({ data: answered(), response: { status: 200 } });
        await settle();

        expect(successToast).not.toHaveBeenCalled();
        expect(wrapper!.get("h1").text()).toBe("Zamówienie R-100002");
        expect(text("status")).toBe(statusBefore);
        expect(windowExists()).toBe(true);
        expect(inWindow(target.submit).attributes("aria-disabled")).toBeUndefined();
        pressEscape();
        await settle();
        expect(windowExists()).toBe(false);
      });
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
