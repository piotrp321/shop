import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import type { components } from "@/api/schema";
import CustomerDetailView from "@/views/CustomerDetailView.vue";

type Customer = components["schemas"]["BackofficeCustomerResponse"];
type OrderListItem = components["schemas"]["BackofficeOrderListItem"];

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

function customer(overrides: Partial<Customer> = {}): Customer {
  return {
    customerId: "c-1",
    contact: { email: "anna.nowak@example.com", fullName: "Anna Nowak", phone: "+48600100200" },
    summary: { orderCount: 3, paidCount: 2, paidGross: 24600, currency: "PLN", firstOrderAt: "2026-10-01T10:00:00+00:00", lastOrderAt: "2026-11-05T12:30:00+00:00" },
    addresses: [
      { street: "Długa 1", postalCode: "00-950", city: "Warszawa", country: "PL" },
      { street: "Krótka 2", postalCode: "30-001", city: "Kraków", country: "PL" },
    ],
    ...overrides,
  };
}

function order(number: string, overrides: Partial<OrderListItem> = {}): OrderListItem {
  return { orderId: `id-${number}`, number, placedAt: "2026-11-05T12:30:00+00:00", status: "paid", email: "anna.nowak@example.com", fullName: "Anna Nowak", totalGross: 39998, currency: "PLN", itemCount: 3, refund: null, ...overrides };
}

interface Served {
  customer?: Customer | number;
  orders?: { items: OrderListItem[]; nextCursor: string | null; total?: { value: number; capped: boolean } | null } | number;
}

function serve({ customer: card = customer(), orders = { items: [order("R-2"), order("R-1", { status: "new" })], nextCursor: null, total: { value: 2, capped: false } } }: Served = {}) {
  mocks.get.mockImplementation(async (path: string) => {
    const answer = path === "/api/v1/admin/orders" ? orders : card;
    return typeof answer === "number" ? { data: undefined, response: { status: answer } } : { data: answer, response: { status: 200 } };
  });
}

function screen(wide: boolean) {
  vi.stubGlobal("matchMedia", (query: string) => ({
    matches: wide && query === "(min-width: 640px)",
    media: query,
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
    addListener: () => undefined,
    removeListener: () => undefined,
  }));
}

async function mountView(customerId = "c-1") {
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/", name: "dashboard", component: empty },
      { path: "/customers", name: "customers", component: empty },
      { path: "/customers/:customerId", name: "customer", component: CustomerDetailView },
      { path: "/orders/:orderId", name: "order-detail", component: empty },
    ],
  });
  await router.push(`/customers/${customerId}`);
  const wrapper = mount(CustomerDetailView, { attachTo: document.body, global: { plugins: [router] } });
  await flushPromises();
  return Object.assign(wrapper, { router });
}

describe("CustomerDetailView", () => {
  let wrapper: Awaited<ReturnType<typeof mountView>> | undefined;

  const text = (id: string) => wrapper!.get(`[data-testid=${id}]`).text().replace(/\s+/g, " ");

  beforeEach(() => {
    mocks.get.mockReset();
    screen(true);
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    document.body.innerHTML = "";
    vi.unstubAllGlobals();
  });

  it("has the name as the H1 and the crumbs „Pulpit › Klienci › Anna Nowak”", async () => {
    serve();
    wrapper = await mountView();

    expect(wrapper.get("h1").text()).toBe("Anna Nowak");
    const crumbs = wrapper.get("nav[aria-label=Okruszki]");
    expect(crumbs.text()).toMatch(/^Pulpit.*Klienci.*Anna Nowak$/);
    expect(crumbs.get("a[href='/customers']").text()).toBe("Klienci");
    expect(crumbs.get("[aria-current=page]").text()).toBe("Anna Nowak");
  });

  it("asks for the customer and for its orders by the id of the address", async () => {
    serve();
    wrapper = await mountView();

    expect(mocks.get).toHaveBeenCalledWith("/api/v1/admin/customers/{customerId}", { params: { path: { customerId: "c-1" } } });
    expect(mocks.get).toHaveBeenCalledWith("/api/v1/admin/orders", { params: { query: { customerId: "c-1" } }, signal: expect.any(AbortSignal) });
  });

  it("puts the side column before the main one in the source, so that Tab starts there", async () => {
    serve();
    wrapper = await mountView();

    const side = wrapper.get("[data-testid=customer-side]").element;
    const main = wrapper.get("[data-testid=customer-main]").element;
    expect(side.compareDocumentPosition(main) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect([...wrapper.findAll("h2")].map((heading) => heading.text())).toEqual(["Kontakt", "Podsumowanie", "Adresy", "Zamówienia"]);
  });

  it("links the e-mail with mailto: and the phone with tel:", async () => {
    serve();
    wrapper = await mountView();

    expect(wrapper.get("[data-testid=contact-email]").attributes("href")).toBe("mailto:anna.nowak@example.com");
    expect(wrapper.get("[data-testid=contact-phone]").attributes("href")).toBe("tel:+48600100200");
    expect(text("contact")).toContain("Anna Nowak");
  });

  it("shows the summary as the API sent it", async () => {
    serve();
    wrapper = await mountView();

    expect(text("summary-orders")).toBe("3");
    expect(text("summary-paid")).toBe("2");
    expect(text("summary-value")).toBe("246,00 zł");
    expect(text("summary-first")).toMatch(/2026/);
    expect(text("summary-last")).toMatch(/2026/);
  });

  it("lists the addresses in the order of the API, or says there are none", async () => {
    serve();
    wrapper = await mountView();
    expect(wrapper.findAll("[data-testid=address]").map((address) => address.text().replace(/\s+/g, " "))).toEqual(["Długa 1 00-950 Warszawa PL", "Krótka 2 30-001 Kraków PL"]);
    wrapper.unmount();

    serve({ customer: customer({ addresses: [] }) });
    wrapper = await mountView();
    expect(text("no-addresses")).toBe("Brak adresów dostawy.");
  });

  it("lists the orders of the customer with the total, each number leading to the order, and the status", async () => {
    serve();
    wrapper = await mountView();

    const rows = wrapper.findAll("[data-testid=order-row]");
    expect(rows).toHaveLength(2);
    expect(rows[0]!.get("[data-testid=number]").text()).toBe("R-2");
    expect(rows[0]!.get("[data-testid=number]").attributes("href")).toBe("/orders/id-R-2");
    expect(rows[1]!.get("[data-testid=status]").text()).toBe("Czeka na płatność");
    expect(rows[0]!.get("[data-testid=total]").text().replace(/\s/g, " ")).toBe("399,98 zł");
    expect(wrapper.get("[data-testid=range]").text()).toContain("z 2");
  });

  it("shows the orders as cards on a phone", async () => {
    screen(false);
    serve();
    wrapper = await mountView();

    expect(wrapper.find("table").exists()).toBe(false);
    expect(wrapper.findAll("[data-testid=order-row]")).toHaveLength(2);
  });

  it("pages the orders with the cursor of the API", async () => {
    serve({ orders: { items: [order("R-2")], nextCursor: "n1", total: { value: 30, capped: false } } });
    wrapper = await mountView();

    await wrapper.get("[data-testid=next-page]").trigger("click");
    await flushPromises();

    expect(mocks.get).toHaveBeenLastCalledWith("/api/v1/admin/orders", { params: { query: { customerId: "c-1", after: "n1" } }, signal: expect.any(AbortSignal) });
  });

  it("says that the customer has no orders", async () => {
    serve({ orders: { items: [], nextCursor: null, total: { value: 0, capped: false } } });
    wrapper = await mountView();

    expect(wrapper.get("[data-testid=empty]").text()).toContain("Ten klient nie ma zamówień.");
  });

  it("keeps the card when only its orders fail, with a retry", async () => {
    serve({ orders: 500 });
    wrapper = await mountView();

    expect(wrapper.get("h1").text()).toBe("Anna Nowak");
    expect(wrapper.get("[data-testid=orders-error]").text()).toContain("Nie udało się pobrać zamówień klienta (HTTP 500).");
    expect(wrapper.find("[data-testid=retry]").exists()).toBe(true);
  });

  it("says that the customer was not found on a 404, with a link to the list", async () => {
    serve({ customer: 404, orders: { items: [], nextCursor: null } });
    wrapper = await mountView("nobody");

    expect(wrapper.get("h1").text()).toBe("Nie znaleziono klienta");
    expect(text("not-found")).toContain("Nie znaleźliśmy klienta.");
    expect(wrapper.get("[data-testid=to-list]").attributes("href")).toBe("/customers");
    expect(wrapper.find("[data-testid=customer-side]").exists()).toBe(false);
  });

  it("shows the error of the card with a retry that loads it, and the message of a 403 without one", async () => {
    serve({ customer: 500 });
    wrapper = await mountView();
    expect(text("customer-error")).toContain("Nie udało się pobrać klienta (HTTP 500).");
    expect(wrapper.get("h1").text()).toBe("Nie udało się wczytać klienta");

    serve();
    await wrapper.get("[data-testid=retry]").trigger("click");
    await flushPromises();
    expect(wrapper.get("h1").text()).toBe("Anna Nowak");
    expect(wrapper.find("[data-testid=customer-error]").exists()).toBe(false);
    wrapper.unmount();

    serve({ customer: 403 });
    wrapper = await mountView();
    expect(text("customer-error")).toContain("Nie masz dostępu do klientów.");
    expect(wrapper.find("[data-testid=retry]").exists()).toBe(false);
  });
});
