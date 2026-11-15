import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import type { components } from "@/api/schema";
import { Select } from "@/components/ui/select";
import OrdersView from "@/views/OrdersView.vue";
import type { WithOptionalTotal } from "./listPage";

type OrderList = WithOptionalTotal<components["schemas"]["BackofficeOrderList"]>;
type OrderListItem = components["schemas"]["BackofficeOrderListItem"];

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

function order(number: string, overrides: Partial<OrderListItem> = {}): OrderListItem {
  return {
    orderId: `id-${number}`,
    number,
    placedAt: "2026-11-05T12:30:00+00:00",
    status: "paid",
    email: "anna.nowak@example.com",
    fullName: "Anna Nowak",
    totalGross: 39998,
    currency: "PLN",
    itemCount: 3,
    refund: null,
    ...overrides,
  };
}

function serve(pages: Record<string, OrderList> | OrderList | number) {
  mocks.get.mockImplementation(async (_path: string, options?: { params?: { query?: { after?: string } } }) => {
    if (typeof pages === "number") {
      return { data: undefined, response: { status: pages } };
    }
    const byCursor: Record<string, OrderList> = Array.isArray((pages as OrderList).items) ? { "": pages as OrderList } : (pages as Record<string, OrderList>);
    return { data: byCursor[options?.params?.query?.after ?? ""], response: { status: 200 } };
  });
}

function queries(): Record<string, unknown>[] {
  return mocks.get.mock.calls.map(([, options]) => (options as { params: { query: Record<string, unknown> } }).params.query);
}

/**
 * The screen is as wide as the real one when `wide` (a table from 640 px), a phone otherwise (cards); `resize` changes
 * the width and reports it to the listeners of the media query, as a browser does.
 */
function screen(wide: boolean) {
  let isWide = wide;
  const listeners = new Set<(event: { matches: boolean }) => void>();
  vi.stubGlobal("matchMedia", (query: string) => ({
    get matches() {
      return isWide && query === "(min-width: 640px)";
    },
    media: query,
    addEventListener: (_type: string, listener: (event: { matches: boolean }) => void) => listeners.add(listener),
    removeEventListener: (_type: string, listener: (event: { matches: boolean }) => void) => listeners.delete(listener),
    addListener: (listener: (event: { matches: boolean }) => void) => listeners.add(listener),
    removeListener: (listener: (event: { matches: boolean }) => void) => listeners.delete(listener),
  }));
  return {
    resize(nowWide: boolean) {
      isWide = nowWide;
      for (const listener of listeners) {
        listener({ matches: nowWide });
      }
    },
  };
}

async function mountView(path = "/orders") {
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/", name: "dashboard", component: empty },
      { path: "/orders", name: "orders", component: empty },
      { path: "/orders/:orderId", name: "order-detail", component: empty },
    ],
  });
  await router.push(path);
  const wrapper = mount(OrdersView, { attachTo: document.body, global: { plugins: [router] } });
  await flushPromises();
  return Object.assign(wrapper, { router });
}

describe("OrdersView", () => {
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

  const statusSelect = () => wrapper!.findAllComponents(Select)[0]!;
  const sortSelect = () => wrapper!.findAllComponents(Select)[1]!;
  const perPageSelect = () => wrapper!.findAllComponents(Select)[2]!;
  const pager = (testid: string) => wrapper!.get(`[data-testid=${testid}]`);

  it("has the H1 „Zamówienia” and the crumbs „Pulpit › Zamówienia”", async () => {
    serve({ items: [order("R-100001")], nextCursor: null });
    wrapper = await mountView();

    expect(wrapper.get("h1").text()).toBe("Zamówienia");
    const crumbs = wrapper.get("nav[aria-label=Okruszki]");
    expect(crumbs.text()).toMatch(/^Pulpit.*Zamówienia$/);
    expect(crumbs.get("[aria-current=page]").text()).toBe("Zamówienia");
  });

  it("has the H1 „Zwroty do wykonania” and the crumbs „Pulpit › Zamówienia › Zwroty do wykonania” for the refunds, with a link back to the list", async () => {
    serve({ items: [order("R-100001")], nextCursor: null });
    wrapper = await mountView("/orders?refund=pending");

    expect(wrapper.get("h1").text()).toBe("Zwroty do wykonania");
    const crumbs = wrapper.get("nav[aria-label=Okruszki]");
    expect(crumbs.text()).toMatch(/^Pulpit.*Zamówienia.*Zwroty do wykonania$/);
    expect(crumbs.get("[aria-current=page]").text()).toBe("Zwroty do wykonania");
    expect(crumbs.get("a[href='/orders']").text()).toBe("Zamówienia");
  });

  it("shows the number as a link to the detail, the customer with the email, the count of items, the status and the amount", async () => {
    serve({ items: [order("R-100001", { status: "shipped" })], nextCursor: null });
    wrapper = await mountView();

    const row = wrapper.get("[data-testid=order-row]");
    expect(row.get("[data-testid=number]").text()).toBe("R-100001");
    expect(row.get("[data-testid=number]").attributes("href")).toBe("/orders/id-R-100001");
    expect(row.get("[data-testid=number]").classes()).toContain("whitespace-nowrap");
    expect(row.get("[data-testid=placed-at]").text()).toBe("05.11.2026, 13:30:00");
    expect(row.get("[data-testid=full-name]").text()).toBe("Anna Nowak");
    const email = row.get("[data-testid=email]");
    expect(email.text()).toBe("anna.nowak@example.com");
    expect(email.attributes("title")).toBe("anna.nowak@example.com");
    expect(email.classes()).toContain("truncate");
    expect(row.get("[data-testid=items]").text()).toBe("3");
    expect(row.get("[data-testid=status]").text()).toBe("Wysłane");
    expect(row.get("[data-testid=status]").classes().join(" ")).toContain("text-status-success");
    expect(row.get("[data-testid=total]").text().replace(/\s/g, " ")).toBe("399,98 zł");
    expect(row.find("[data-testid=refund-due]").exists()).toBe(false);
  });

  it("has the columns Numer, Data, Klient, Pozycje, Kwota and Status, and the date and the items as lines of the cell Numer below 1024 px", async () => {
    serve({ items: [order("R-100001")], nextCursor: null });
    wrapper = await mountView();

    expect(wrapper.findAll("th[scope=col]").map((header) => header.text())).toEqual(["Numer", "Data", "Klient", "Pozycje", "Kwota", "Status"]);
    const row = wrapper.get("[data-testid=order-row]");
    expect(row.get("[data-testid=line-placedAt]").text()).toBe("Data: 05.11.2026, 13:30:00");
    expect(row.get("[data-testid=line-items]").text()).toBe("Pozycje: 3");
    expect(row.get("td").text()).toContain("Data: 05.11.2026, 13:30:00");
  });

  it("shows the amount and the due date of the refunds to do, with „Po terminie” for an overdue one", async () => {
    serve({
      items: [
        order("R-1", { status: "cancelled", refund: { amount: 39998, dueAt: "2026-11-01T12:30:00+00:00", completedAt: null, overdue: true } }),
        order("R-2", { status: "returned", refund: { amount: 1000, dueAt: "2026-11-19T12:30:00+00:00", completedAt: null, overdue: false } }),
      ],
      nextCursor: null,
    });
    wrapper = await mountView("/orders?refund=pending");

    expect(wrapper.findAll("th[scope=col]").map((header) => header.text())).toEqual(["Numer", "Data", "Klient", "Pozycje", "Kwota", "Status", "Kwota zwrotu", "Termin zwrotu"]);
    const rows = wrapper.findAll("[data-testid=order-row]");
    expect(rows[0]!.get("[data-testid=refund-amount]").text().replace(/\s/g, " ")).toBe("399,98 zł");
    expect(rows[0]!.get("[data-testid=refund-due]").text()).toBe("01.11.2026, 13:30:00");
    expect(rows[0]!.get("[data-testid=refund-overdue]").text()).toBe("Po terminie");
    expect(rows[1]!.find("[data-testid=refund-overdue]").exists()).toBe(false);
  });

  describe("the sort", () => {
    const sortTrigger = () => wrapper!.get<HTMLButtonElement>("[data-testid=sort]");
    const head = (title: string) => wrapper!.findAll("th").find((th) => th.text() === title)!;

    it("has a Select „Sortuj” with a visible label that shows the newest first and offers every order of the API", async () => {
      serve({ items: [order("R-100001")], nextCursor: null });
      wrapper = await mountView();

      expect(sortTrigger().element.labels?.[0]?.textContent?.trim()).toBe("Sortuj");
      expect(sortTrigger().text()).toBe("Najnowsze");
      sortTrigger().element.dispatchEvent(new PointerEvent("pointerdown", { bubbles: true, cancelable: true, button: 0, ctrlKey: false }));
      await flushPromises();
      expect([...document.body.querySelectorAll("[role=option]")].map((option) => option.textContent?.trim()).slice(-4)).toEqual([
        "Najnowsze",
        "Najstarsze",
        "Najwyższa kwota",
        "Najniższa kwota",
      ]);
    });

    it("sets the sort in the address with one request from the first page, and marks the sorted column with aria-sort", async () => {
      serve({ "": { items: [order("R-100002")], nextCursor: "c1" }, c1: { items: [order("R-100001")], nextCursor: null } });
      wrapper = await mountView();
      expect(head("Data").attributes("aria-sort")).toBe("descending");
      await pager("next-page").trigger("click");
      await flushPromises();
      expect(pager("page-number").text()).toBe("Strona 2");

      sortSelect().vm.$emit("update:modelValue", "-total");
      await flushPromises();

      expect(queries().at(-1)).toEqual({ sort: "-total" });
      expect(queries().filter((query) => query.sort === "-total")).toHaveLength(1);
      expect(pager("page-number").text()).toBe("Strona 1");
      expect(wrapper.router.currentRoute.value.fullPath).toBe("/orders?sort=-total");
      expect(sortTrigger().text()).toBe("Najwyższa kwota");
      expect(head("Kwota").attributes("aria-sort")).toBe("descending");
      expect(head("Data").attributes("aria-sort")).toBeUndefined();

      sortSelect().vm.$emit("update:modelValue", "placedAt");
      await flushPromises();
      expect(head("Data").attributes("aria-sort")).toBe("ascending");
      expect(head("Kwota").attributes("aria-sort")).toBeUndefined();
    });

    it("applies a sort that the address brings from outside with one request", async () => {
      serve({ items: [order("R-100001")], nextCursor: null });
      wrapper = await mountView();
      mocks.get.mockClear();

      await wrapper.router.push("/orders?sort=total");
      await flushPromises();

      expect(queries()).toEqual([{ sort: "total" }]);
      expect(sortTrigger().text()).toBe("Najniższa kwota");
      expect(head("Kwota").attributes("aria-sort")).toBe("ascending");
    });

    it("disables every sort for the refunds to do, says why, and shows the order of the due date", async () => {
      serve({ items: [order("R-100001")], nextCursor: null });
      wrapper = await mountView("/orders?refund=pending");

      expect(sortTrigger().text()).toBe("Termin zwrotu");
      expect(wrapper.get("[data-testid=sort-hint]").text()).toBe("Zwroty do wykonania są w kolejności terminu zwrotu.");
      sortTrigger().element.dispatchEvent(new PointerEvent("pointerdown", { bubbles: true, cancelable: true, button: 0, ctrlKey: false }));
      await flushPromises();
      const options = [...document.body.querySelectorAll<HTMLElement>("[role=option]")].slice(-4);
      expect(options.map((option) => option.getAttribute("aria-disabled"))).toEqual(["true", "true", "true", "true"]);
      expect(wrapper.findAll("th[aria-sort]")).toHaveLength(0);
    });

    it("disables the amount while a search is applied and says why", async () => {
      serve({ items: [order("R-100001")], nextCursor: null });
      wrapper = await mountView("/orders?q=R-100001");

      expect(wrapper.get("[data-testid=sort-hint]").text()).toBe("Kwoty nie da się sortować przy wyszukiwaniu.");
      sortTrigger().element.dispatchEvent(new PointerEvent("pointerdown", { bubbles: true, cancelable: true, button: 0, ctrlKey: false }));
      await flushPromises();
      const options = [...document.body.querySelectorAll<HTMLElement>("[role=option]")].slice(-4);
      expect(options.map((option) => option.getAttribute("aria-disabled") === "true")).toEqual([false, false, true, true]);
    });

    it("returns to the first page with the newest first when the filters are cleared", async () => {
      serve({ items: [order("R-100001")], nextCursor: null });
      wrapper = await mountView("/orders?sort=total&status=paid");

      await wrapper.get("[data-testid=clear-filters]").trigger("click");
      await flushPromises();

      expect(wrapper.router.currentRoute.value.fullPath).toBe("/orders");
      expect(queries().at(-1)).toEqual({});
      expect(sortTrigger().text()).toBe("Najnowsze");
    });
  });

  describe("below 640 px", () => {
    beforeEach(() => screen(false));

    it("offers the same sort as the table, which sets the address and asks once", async () => {
      serve({ items: [order("R-100002"), order("R-100001")], nextCursor: null });
      wrapper = await mountView();

      const trigger = wrapper.get<HTMLButtonElement>("[data-testid=sort]");
      expect(trigger.element.labels?.[0]?.textContent?.trim()).toBe("Sortuj");
      expect(trigger.text()).toBe("Najnowsze");
      expect(wrapper.find("table").exists()).toBe(false);

      wrapper.findAllComponents(Select)[1]!.vm.$emit("update:modelValue", "total");
      await flushPromises();

      expect(queries().at(-1)).toEqual({ sort: "total" });
      expect(queries().filter((query) => query.sort === "total")).toHaveLength(1);
      expect(wrapper.router.currentRoute.value.fullPath).toBe("/orders?sort=total");
      expect(wrapper.get("[data-testid=sort]").text()).toBe("Najniższa kwota");
      expect(wrapper.findAll("[data-testid=order-row]")).toHaveLength(2);
    });

    it("applies a sort of the address from outside with one request", async () => {
      serve({ items: [order("R-100001")], nextCursor: null });
      wrapper = await mountView();
      mocks.get.mockClear();

      await wrapper.router.push("/orders?sort=-total");
      await flushPromises();

      expect(queries()).toEqual([{ sort: "-total" }]);
      expect(wrapper.get("[data-testid=sort]").text()).toBe("Najwyższa kwota");
    });

    it("lists the orders as cards of a list named „Zamówienia”, with no table, and one row per order", async () => {
      serve({ items: [order("R-100002", { status: "new" }), order("R-100001", { status: "shipped" })], nextCursor: null });
      wrapper = await mountView();

      expect(wrapper.find("table").exists()).toBe(false);
      expect(wrapper.find("ul[aria-label=Zamówienia]").exists()).toBe(true);
      const cards = wrapper.findAll("[data-testid=order-row]");
      expect(cards).toHaveLength(2);
      expect(cards.every((card) => card.element.tagName === "LI")).toBe(true);
      const first = cards[0]!;
      expect(first.get("[data-testid=number]").text()).toBe("R-100002");
      expect(first.get("[data-testid=number]").attributes("href")).toBe("/orders/id-R-100002");
      expect(first.get("[data-testid=status]").text()).toBe("Czeka na płatność");
      expect(first.get("[data-testid=total]").text().replace(/\s/g, " ")).toBe("399,98 zł");
      expect(first.get("[data-testid=full-name]").text()).toBe("Anna Nowak");
      expect(first.get("[data-testid=email]").text()).toBe("anna.nowak@example.com");
      expect(first.get("[data-testid=placed-at]").text()).toBe("05.11.2026, 13:30:00");
    });

    it("shows the amount and the due date of the refund on the card of a refund", async () => {
      serve({ items: [order("R-1", { status: "cancelled", refund: { amount: 39998, dueAt: "2026-11-01T12:30:00+00:00", completedAt: null, overdue: true } })], nextCursor: null });
      wrapper = await mountView("/orders?refund=pending");

      const card = wrapper.get("[data-testid=order-row]");
      expect(card.get("[data-testid=refund-amount]").text().replace(/\s/g, " ")).toBe("399,98 zł");
      expect(card.get("[data-testid=refund-due]").text()).toBe("01.11.2026, 13:30:00");
      expect(card.get("[data-testid=refund-overdue]").text()).toBe("Po terminie");
    });

    it("shows the count of items on the card, as the table does", async () => {
      serve({ items: [order("R-100001", { itemCount: 3 })], nextCursor: null });
      wrapper = await mountView();

      const card = wrapper.get("[data-testid=order-row]");
      expect(card.get("[data-testid=items]").text()).toBe("3");
      expect(card.text()).toContain("Pozycje: 3");
    });

    it("shows no refund on the card outside the refunds to do, as the table has no refund columns there", async () => {
      serve({ items: [order("R-1", { status: "cancelled", refund: { amount: 39998, dueAt: "2026-11-01T12:30:00+00:00", completedAt: null, overdue: true } })], nextCursor: null });
      wrapper = await mountView();

      const card = wrapper.get("[data-testid=order-row]");
      expect(card.find("[data-testid=refund-amount]").exists()).toBe(false);
      expect(card.find("[data-testid=refund-due]").exists()).toBe(false);
      expect(card.find("[data-testid=refund-overdue]").exists()).toBe(false);
    });

    it("keeps the states of the list: the error with a retry, and the empty state", async () => {
      serve(500);
      wrapper = await mountView();
      expect(wrapper.get("[data-testid=orders-error]").text()).toContain("Nie udało się pobrać zamówień (HTTP 500).");
      expect(wrapper.find("ul[aria-label=Zamówienia]").exists()).toBe(false);

      serve({ items: [], nextCursor: null });
      await wrapper.get("[data-testid=retry]").trigger("click");
      await flushPromises();
      expect(wrapper.get("[data-testid=empty]").text()).toContain("Nie ma jeszcze zamówień.");
    });
  });

  it("changes the layout with the width of the screen, never showing both, with one row per order in each", async () => {
    const viewport = screen(true);
    serve({ items: [order("R-100002"), order("R-100001")], nextCursor: null });
    wrapper = await mountView();

    expect(wrapper.findAll("[data-testid=order-row]")).toHaveLength(2);
    expect(wrapper.find("table").exists()).toBe(true);
    expect(wrapper.find("ul[aria-label=Zamówienia]").exists()).toBe(false);

    viewport.resize(false);
    await flushPromises();
    expect(wrapper.findAll("[data-testid=order-row]")).toHaveLength(2);
    expect(wrapper.find("table").exists()).toBe(false);
    expect(wrapper.find("ul[aria-label=Zamówienia]").exists()).toBe(true);

    viewport.resize(true);
    await flushPromises();
    expect(wrapper.findAll("[data-testid=order-row]")).toHaveLength(2);
    expect(wrapper.find("table").exists()).toBe(true);
    expect(wrapper.find("ul[aria-label=Zamówienia]").exists()).toBe(false);
  });

  it("searches an exact number or email on Enter, not while typing, and writes the search to the address", async () => {
    serve({ items: [order("R-100001")], nextCursor: null });
    wrapper = await mountView();
    const search = wrapper.get("[data-testid=search]");
    expect(search.attributes("aria-label")).toBe("Szukaj po numerze zamówienia lub e-mailu");
    expect(search.attributes("maxlength")).toBe("254");

    await search.setValue("R-100001");
    expect(queries()).toEqual([{}]);
    await search.trigger("keydown.enter");
    await flushPromises();

    expect(queries()).toEqual([{}, { q: "R-100001" }]);
    expect(wrapper.router.currentRoute.value.fullPath).toBe("/orders?q=R-100001");
    expect(wrapper.get("[data-testid=search-hint]").text()).toContain("zatwierdź Enterem");
  });

  it("puts the search of the address in the field and asks with it (the link „Inne zamówienia klienta”)", async () => {
    serve({ items: [order("R-100001")], nextCursor: null });
    wrapper = await mountView("/orders?q=anna.nowak%40example.com");

    expect(queries()).toEqual([{ q: "anna.nowak@example.com" }]);
    expect((wrapper.get("[data-testid=search]").element as HTMLInputElement).value).toBe("anna.nowak@example.com");
  });

  it("filters by status with the Select „Status” and writes it to the address, from the first page", async () => {
    serve({ "": { items: [order("R-2")], nextCursor: "c1" }, c1: { items: [order("R-1")], nextCursor: null } });
    wrapper = await mountView();
    await pager("next-page").trigger("click");
    await flushPromises();

    statusSelect().vm.$emit("update:modelValue", "cancelled");
    await flushPromises();

    expect(queries().at(-1)).toEqual({ status: "cancelled" });
    expect(pager("page-number").text()).toBe("Strona 1");
    expect(wrapper.router.currentRoute.value.fullPath).toBe("/orders?status=cancelled");

    statusSelect().vm.$emit("update:modelValue", "all");
    await flushPromises();
    expect(queries().at(-1)).toEqual({});
    expect(wrapper.router.currentRoute.value.fullPath).toBe("/orders");
  });

  it("has a Select „Status” with a visible label and every status", async () => {
    serve({ items: [], nextCursor: null });
    wrapper = await mountView();

    const status = wrapper.get<HTMLButtonElement>("[data-testid=status-filter]");
    expect(status.element.labels?.[0]?.textContent?.trim()).toBe("Status");
    expect(status.text()).toBe("Wszystkie");
    status.element.dispatchEvent(new PointerEvent("pointerdown", { bubbles: true, cancelable: true, button: 0, ctrlKey: false }));
    await flushPromises();
    const options = [...document.body.querySelectorAll("[role=option]")].map((option) => option.textContent?.trim());
    expect(options).toEqual(["Wszystkie", "Czeka na płatność", "Opłacone", "Wysłane", "Dostarczone", "Anulowane", "Zwrócone"]);
  });

  it("has the switch „Zwroty do wykonania” with a label for it, and switching it changes the list, the H1 and the address", async () => {
    serve({ items: [order("R-100001")], nextCursor: null });
    wrapper = await mountView();
    const toggle = wrapper.get<HTMLButtonElement>("[data-testid=refund-pending]");
    expect(toggle.attributes("id")).toBe("refund-pending");
    expect(toggle.attributes("role")).toBe("switch");
    expect(toggle.attributes("aria-checked")).toBe("false");
    expect(document.querySelector("label[for=refund-pending]")?.textContent?.trim()).toBe("Zwroty do wykonania");

    await toggle.trigger("click");
    await flushPromises();

    expect(queries().at(-1)).toEqual({ refund: "pending" });
    expect(wrapper.get("h1").text()).toBe("Zwroty do wykonania");
    expect(wrapper.router.currentRoute.value.fullPath).toBe("/orders?refund=pending");
    expect(wrapper.get("[data-testid=refund-pending]").attributes("aria-checked")).toBe("true");

    await wrapper.get("[data-testid=refund-pending]").trigger("click");
    await flushPromises();
    expect(queries().at(-1)).toEqual({});
    expect(wrapper.get("h1").text()).toBe("Zamówienia");
  });

  it("switches the open list to the refunds when the address changes (the menu item „Zwroty do wykonania”), without a reload", async () => {
    serve({ items: [order("R-100001")], nextCursor: null });
    wrapper = await mountView();
    mocks.get.mockClear();

    await wrapper.router.push({ name: "orders", query: { refund: "pending" } });
    await flushPromises();

    expect(queries()).toEqual([{ refund: "pending" }]);
    expect(wrapper.get("h1").text()).toBe("Zwroty do wykonania");
    expect(wrapper.get("[data-testid=refund-pending]").attributes("aria-checked")).toBe("true");
  });

  it("changes the page size with the Select „Wierszy na stronie” and starts from the first page", async () => {
    serve({ "": { items: [order("R-2")], nextCursor: "c1" }, c1: { items: [order("R-1")], nextCursor: null } });
    wrapper = await mountView();
    await pager("next-page").trigger("click");
    await flushPromises();

    perPageSelect().vm.$emit("update:modelValue", "10");
    await flushPromises();

    expect(queries().at(-1)).toEqual({ perPage: 10 });
    expect(pager("page-number").text()).toBe("Strona 1");
    expect(wrapper.router.currentRoute.value.fullPath).toBe("/orders?perPage=10");
  });

  it("shows the list filtered by the address, with the filters filled in", async () => {
    serve({ items: [order("R-100001")], nextCursor: null });
    wrapper = await mountView("/orders?status=paid&perPage=10");

    expect(queries()).toEqual([{ status: "paid", perPage: 10 }]);
    expect(wrapper.get("[data-testid=status-filter]").text()).toBe("Opłacone");
    expect(wrapper.get("[data-testid=per-page]").text()).toBe("10");
  });

  it("says „z N” with the total of the first page on the next page too", async () => {
    serve({ "": { items: [order("R-2")], nextCursor: "c1", total: { value: 12, capped: false } }, c1: { items: [order("R-1")], nextCursor: null, total: null } });
    wrapper = await mountView();
    expect(pager("range").text()).toBe("Pokazano 1–1 z 12");

    await pager("next-page").trigger("click");
    await flushPromises();

    expect(pager("range").text()).toBe("Pokazano 26–26 z 12");
  });

  it("pages with the cursors of the API and says which orders are shown", async () => {
    serve({ "": { items: [order("R-2")], nextCursor: "c1" }, c1: { items: [order("R-1")], nextCursor: null } });
    wrapper = await mountView();
    expect(pager("previous-page").attributes("disabled")).toBeDefined();
    expect(pager("range").text()).toBe("Pokazano 1–1");

    await pager("next-page").trigger("click");
    await flushPromises();

    expect(queries().at(-1)).toEqual({ after: "c1" });
    expect(pager("page-number").text()).toBe("Strona 2");
    expect(pager("number").text()).toBe("R-1");
    expect(pager("next-page").attributes("disabled")).toBeDefined();
  });

  it("#260 F7: leaves the focus on the pager after a change of the page, and moves it to the other button at the end of the list", async () => {
    serve({ "": { items: [order("R-3")], nextCursor: "c1" }, c1: { items: [order("R-2")], nextCursor: "c2" }, c2: { items: [order("R-1")], nextCursor: null } });
    wrapper = await mountView();

    (pager("next-page").element as HTMLElement).focus();
    await pager("next-page").trigger("click");
    await flushPromises();
    expect(pager("page-number").text()).toBe("Strona 2");
    expect(document.activeElement).toBe(pager("next-page").element);

    await pager("next-page").trigger("click");
    await flushPromises();
    expect(pager("next-page").attributes("disabled")).toBeDefined();
    expect(document.activeElement).toBe(pager("previous-page").element);
  });

  it("says there are no orders yet, and no results for the filters with a way to clear them, the focus going to the search", async () => {
    serve({ items: [], nextCursor: null });
    wrapper = await mountView();
    expect(wrapper.get("[data-testid=empty]").text()).toBe("Nie ma jeszcze zamówień.");

    (wrapper.get("[data-testid=search]").element as HTMLElement).focus();
    await wrapper.get("[data-testid=search]").setValue("nikt@example.com");
    await wrapper.get("[data-testid=search]").trigger("keydown.enter");
    await flushPromises();
    expect(wrapper.get("[data-testid=empty]").text()).toContain("Brak wyników dla wybranych filtrów.");

    await wrapper.get("[data-testid=empty-clear-filters]").trigger("click");
    await flushPromises();
    expect(wrapper.router.currentRoute.value.fullPath).toBe("/orders");
    expect((wrapper.get("[data-testid=search]").element as HTMLInputElement).value).toBe("");
    expect(document.activeElement).toBe(wrapper.get("[data-testid=search]").element);
  });

  it("shows an error with a retry that loads the list again", async () => {
    serve(500);
    wrapper = await mountView();
    expect(wrapper.get("[data-testid=orders-error]").text()).toContain("Nie udało się pobrać zamówień (HTTP 500).");
    expect(wrapper.find("[data-testid=order-row]").exists()).toBe(false);

    serve({ items: [order("R-100001")], nextCursor: null });
    await wrapper.get("[data-testid=retry]").trigger("click");
    await flushPromises();

    expect(wrapper.find("[data-testid=orders-error]").exists()).toBe(false);
    expect(wrapper.findAll("[data-testid=order-row]")).toHaveLength(1);
  });

  it("tells a role without access that it has none, without a retry", async () => {
    serve(403);
    wrapper = await mountView();

    expect(wrapper.get("[data-testid=orders-error]").text()).toBe("Nie masz dostępu do zamówień.");
    expect(wrapper.find("[data-testid=retry]").exists()).toBe(false);
  });

  it("shows a skeleton before the first answer", async () => {
    mocks.get.mockReturnValue(new Promise(() => {}));
    wrapper = await mountView();

    expect(wrapper.find("[data-testid=list-loading]").exists()).toBe(true);
  });
});
