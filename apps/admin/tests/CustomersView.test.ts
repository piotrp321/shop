import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import type { components } from "@/api/schema";
import { Select } from "@/components/ui/select";
import CustomersView from "@/views/CustomersView.vue";
import type { WithOptionalTotal } from "./listPage";

type CustomerList = WithOptionalTotal<components["schemas"]["BackofficeCustomerListResponse"]>;
type CustomerListItem = components["schemas"]["BackofficeCustomerListItemResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

function customer(id: string, overrides: Partial<CustomerListItem> = {}): CustomerListItem {
  return { customerId: id, email: "anna.nowak@example.com", fullName: "Anna Nowak", orderCount: 3, paidGross: 24600, currency: "PLN", lastOrderAt: "2026-11-05T12:30:00+00:00", ...overrides };
}

function serve(pages: Record<string, CustomerList> | CustomerList | number) {
  mocks.get.mockImplementation(async (_path: string, options?: { params?: { query?: { after?: string } } }) => {
    if (typeof pages === "number") {
      return { data: undefined, response: { status: pages } };
    }
    const byCursor: Record<string, CustomerList> = Array.isArray((pages as CustomerList).items) ? { "": pages as CustomerList } : (pages as Record<string, CustomerList>);
    return { data: byCursor[options?.params?.query?.after ?? ""], response: { status: 200 } };
  });
}

function queries(): Record<string, unknown>[] {
  return mocks.get.mock.calls.map(([, options]) => (options as { params: { query: Record<string, unknown> } }).params.query);
}

/** The screen is as wide as the real one when `wide` (a table from 640 px), a phone otherwise (cards). */
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

async function mountView(path = "/customers") {
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/", name: "dashboard", component: empty },
      { path: "/customers", name: "customers", component: empty },
      { path: "/customers/:customerId", name: "customer", component: empty },
    ],
  });
  await router.push(path);
  const wrapper = mount(CustomersView, { attachTo: document.body, global: { plugins: [router] } });
  await flushPromises();
  return Object.assign(wrapper, { router });
}

describe("CustomersView", () => {
  let wrapper: Awaited<ReturnType<typeof mountView>> | undefined;

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

  const head = (label: string) => wrapper!.findAll("th").find((th) => th.text() === label)!;
  const sortSelect = () => wrapper!.findAllComponents(Select)[0]!;
  const sortTrigger = () => wrapper!.get("[data-testid=sort]");

  it("has the H1 „Klienci” and the crumbs „Pulpit › Klienci”", async () => {
    serve({ items: [customer("c1")], nextCursor: null });
    wrapper = await mountView();

    expect(wrapper.get("h1").text()).toBe("Klienci");
    expect(wrapper.get("nav[aria-label=Okruszki]").text()).toMatch(/^Pulpit.*Klienci$/);
  });

  it("shows the customer with the e-mail, the orders, the paid value and the last order, the name leading to the card", async () => {
    serve({ items: [customer("c1")], nextCursor: null, total: { value: 1, capped: false } });
    wrapper = await mountView();

    expect(wrapper.findAll("th").map((th) => th.text())).toEqual(["Klient", "Zamówienia", "Wartość", "Ostatnie zamówienie"]);
    const row = wrapper.get("[data-testid=customer-row]");
    expect(row.get("[data-testid=full-name]").text()).toBe("Anna Nowak");
    expect(row.get("[data-testid=full-name]").attributes("href")).toBe("/customers/c1");
    expect(row.get("[data-testid=email]").text()).toBe("anna.nowak@example.com");
    expect(row.get("[data-testid=order-count]").text()).toBe("3");
    expect(row.get("[data-testid=paid-gross]").text().replace(/\s/g, " ")).toBe("246,00 zł");
    expect(row.get("[data-testid=last-order-at]").text()).toMatch(/05\.11\.2026|2026/);
    expect(wrapper.get("[data-testid=range]").text()).toContain("z 1");
  });

  it("shows the rows as cards on a phone, each name leading to the card", async () => {
    screen(false);
    serve({ items: [customer("c1"), customer("c2")], nextCursor: null });
    wrapper = await mountView();

    expect(wrapper.find("table").exists()).toBe(false);
    const rows = wrapper.findAll("[data-testid=customer-row]");
    expect(rows).toHaveLength(2);
    expect(rows[1]!.get("[data-testid=full-name]").attributes("href")).toBe("/customers/c2");
  });

  it("marks the column of the sort with aria-sort, none for the others, and follows the sort of the address", async () => {
    serve({ items: [customer("c1")], nextCursor: null });
    wrapper = await mountView();
    expect(head("Ostatnie zamówienie").attributes("aria-sort")).toBe("descending");
    expect(head("Klient").attributes("aria-sort")).toBeUndefined();

    sortSelect().vm.$emit("update:modelValue", "name");
    await flushPromises();

    expect(queries().at(-1)).toEqual({ sort: "name" });
    expect(wrapper.router.currentRoute.value.fullPath).toBe("/customers?sort=name");
    expect(sortTrigger().text()).toBe("Nazwa A–Z");
    expect(head("Klient").attributes("aria-sort")).toBe("ascending");
    expect(head("Ostatnie zamówienie").attributes("aria-sort")).toBeUndefined();

    sortSelect().vm.$emit("update:modelValue", "-paidGross");
    await flushPromises();
    expect(head("Wartość").attributes("aria-sort")).toBe("descending");
  });

  it("disables the sorts other than the last order while a search is applied and says why", async () => {
    serve({ items: [customer("c1")], nextCursor: null });
    wrapper = await mountView("/customers?q=nowak");

    expect(wrapper.get("[data-testid=sort-hint]").text()).toBe("Wyszukiwanie działa tylko z sortowaniem po ostatnim zamówieniu.");
    sortTrigger().element.dispatchEvent(new PointerEvent("pointerdown", { bubbles: true, cancelable: true, button: 0, ctrlKey: false }));
    await flushPromises();
    const options = [...document.body.querySelectorAll<HTMLElement>("[role=option]")].slice(-4);
    expect(options.map((option) => option.getAttribute("aria-disabled"))).toEqual([null, "true", "true", "true"]);
  });

  it("searches on Enter and says the search needs 3 characters while the text is shorter", async () => {
    serve({ items: [customer("c1")], nextCursor: null });
    wrapper = await mountView();
    const input = wrapper.get("[data-testid=search]");

    await input.setValue("ab");
    expect(wrapper.get("[data-testid=search-hint]").text()).toBe("Wpisz co najmniej 3 znaki, aby wyszukać.");
    await input.trigger("keydown.enter");
    await flushPromises();
    expect(mocks.get).toHaveBeenCalledTimes(1);

    await input.setValue("nowak");
    await input.trigger("keydown.enter");
    await flushPromises();
    expect(queries().at(-1)).toEqual({ q: "nowak" });
    expect(wrapper.router.currentRoute.value.fullPath).toBe("/customers?q=nowak");
  });

  it("says that nobody matches the search and clears it with „Wyczyść filtry”, focusing the search field", async () => {
    serve({ items: [], nextCursor: null });
    wrapper = await mountView("/customers?q=zzz");

    expect(wrapper.get("[data-testid=empty]").text()).toContain("Brak wyników dla wybranych filtrów.");
    await wrapper.get("[data-testid=empty-clear-filters]").trigger("click");
    await flushPromises();

    expect(wrapper.router.currentRoute.value.fullPath).toBe("/customers");
    expect(document.activeElement).toBe(wrapper.get("[data-testid=search]").element);
  });

  it("says that there are no customers yet", async () => {
    serve({ items: [], nextCursor: null });
    wrapper = await mountView();

    expect(wrapper.get("[data-testid=empty]").text()).toContain("Nie ma jeszcze klientów z zamówieniami.");
  });

  it("shows the error with a retry, and the message of a 403 without one", async () => {
    serve(500);
    wrapper = await mountView();
    expect(wrapper.get("[data-testid=customers-error]").text()).toContain("Nie udało się pobrać klientów (HTTP 500).");
    expect(wrapper.find("[data-testid=retry]").exists()).toBe(true);
    wrapper.unmount();

    serve(403);
    wrapper = await mountView();
    expect(wrapper.get("[data-testid=customers-error]").text()).toContain("Nie masz dostępu do klientów.");
    expect(wrapper.find("[data-testid=retry]").exists()).toBe(false);
  });

  it("pages with the next page and keeps the total of the first one", async () => {
    serve({ "": { items: [customer("c1")], nextCursor: "n1", total: { value: 2, capped: false } }, n1: { items: [customer("c2")], nextCursor: null } });
    wrapper = await mountView();

    await wrapper.get("[data-testid=next-page]").trigger("click");
    await flushPromises();

    expect(wrapper.get("[data-testid=customer-row] [data-testid=full-name]").attributes("href")).toBe("/customers/c2");
    expect(wrapper.get("[data-testid=range]").text()).toContain("z 2");
  });
});
