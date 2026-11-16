import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent } from "vue";
import { createMemoryHistory, createRouter } from "vue-router";
import type { components } from "@/api/schema";
import { useCustomerList } from "@/customers/useCustomerList";
import type { WithOptionalTotal } from "./listPage";

type CustomerList = WithOptionalTotal<components["schemas"]["BackofficeCustomerListResponse"]>;
type CustomerListItem = components["schemas"]["BackofficeCustomerListItemResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

const CUSTOMERS = "/api/v1/admin/customers";

function customer(id: string, overrides: Partial<CustomerListItem> = {}): CustomerListItem {
  return { customerId: id, email: `${id}@example.com`, fullName: "Anna Nowak", orderCount: 3, paidGross: 24600, currency: "PLN", lastOrderAt: "2026-11-05T12:30:00+00:00", ...overrides };
}

function page(ids: string[], nextCursor: string | null = null, total?: CustomerList["total"]): CustomerList {
  return { items: ids.map((id) => customer(id)), nextCursor, ...(total === undefined ? {} : { total }) };
}

/** Answers the list by the `after` cursor of the request ("" for the first page). */
function serve(pages: Record<string, CustomerList>, status?: number) {
  mocks.get.mockImplementation(async (_path: string, options?: { params?: { query?: { after?: string } } }) =>
    status ? { data: undefined, response: { status } } : { data: pages[options?.params?.query?.after ?? ""], response: { status: 200 } },
  );
}

function queries(): Record<string, unknown>[] {
  return mocks.get.mock.calls.map(([, options]) => (options as { params: { query: Record<string, unknown> } }).params.query);
}

async function mountList(path = "/customers") {
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/customers", name: "customers", component: empty },
      { path: "/orders", name: "orders", component: empty },
    ],
  });
  await router.push(path);
  let list!: ReturnType<typeof useCustomerList>;
  const wrapper = mount(
    defineComponent({
      setup() {
        list = useCustomerList();
        return () => null;
      },
    }),
    { global: { plugins: [router] } },
  );
  return { wrapper, list, router };
}

describe("useCustomerList", () => {
  let wrapper: Awaited<ReturnType<typeof mountList>>["wrapper"] | undefined;

  beforeEach(() => {
    mocks.get.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
  });

  async function start(pages: Record<string, CustomerList>, path?: string, status?: number) {
    serve(pages, status);
    const mounted = await mountList(path);
    wrapper = mounted.wrapper;
    await flushPromises();
    return mounted;
  }

  it("loads the first page without parameters and keeps the total of the first page while paging", async () => {
    const { list } = await start({ "": page(["c1", "c2"], "n1", { value: 42, capped: false }), n1: page(["c3"]) });

    expect(mocks.get).toHaveBeenCalledWith(CUSTOMERS, { params: { query: {} } });
    expect(list.items.value.map((item) => item.customerId)).toEqual(["c1", "c2"]);
    expect(list.total.value).toEqual({ value: 42, capped: false });
    expect(list.sort.value).toBe("-lastOrderAt");

    await list.goNext();
    expect(queries().at(-1)).toEqual({ after: "n1" });
    expect(list.items.value.map((item) => item.customerId)).toEqual(["c3"]);
    expect(list.total.value).toEqual({ value: 42, capped: false });
  });

  it("lists the four sorts with their labels, all enabled without a search", async () => {
    const { list } = await start({ "": page(["c1"]) });

    expect(list.sortOptions.value.map((option) => [option.value, option.label, option.disabledReason])).toEqual([
      ["-lastOrderAt", "Ostatnie zamówienie", undefined],
      ["name", "Nazwa A–Z", undefined],
      ["-orderCount", "Najwięcej zamówień", undefined],
      ["-paidGross", "Najwyższa wartość", undefined],
    ]);
  });

  it.each(["name", "-orderCount", "-paidGross"])("reads the sort %s of the address with one request", async (sort) => {
    const { list } = await start({ "": page(["c1"]) }, `/customers?sort=${sort}`);

    expect(queries()).toEqual([{ sort }]);
    expect(list.sort.value).toBe(sort);
  });

  it("writes the chosen sort to the address, asks once from the first page and drops the default", async () => {
    const { list, router } = await start({ "": page(["c1"]) });

    list.setSort("-paidGross");
    await flushPromises();
    expect(router.currentRoute.value.fullPath).toBe("/customers?sort=-paidGross");
    expect(queries().at(-1)).toEqual({ sort: "-paidGross" });

    list.setSort("-lastOrderAt");
    await flushPromises();
    expect(router.currentRoute.value.fullPath).toBe("/customers");
    expect(queries().at(-1)).toEqual({});
    expect(mocks.get).toHaveBeenCalledTimes(3);
  });

  it.each(["/customers?sort=price", "/customers?sort=-name", "/customers?sort=NAME", "/customers?sort="])("skips the invalid sort of %s and corrects the address", async (path) => {
    const { list, router } = await start({ "": page(["c1"]) }, path);

    expect(list.sort.value).toBe("-lastOrderAt");
    expect(queries()).toEqual([{}]);
    expect(router.currentRoute.value.fullPath).toBe("/customers");
  });

  it("searches on Enter with the trimmed term and writes it to the address", async () => {
    const { list, router } = await start({ "": page(["c1"]) });

    list.search.value = "  nowak ";
    list.submitSearch();
    await flushPromises();

    expect(queries().at(-1)).toEqual({ q: "nowak" });
    expect(router.currentRoute.value.fullPath).toBe("/customers?q=nowak");
    expect(list.filtered.value).toBe(true);
  });

  it("does not search for a term of 1 or 2 characters and says that the search needs 3", async () => {
    const { list } = await start({ "": page(["c1"]) });

    list.search.value = "ab";
    expect(list.searchHint.value).toBe("Wpisz co najmniej 3 znaki, aby wyszukać.");
    list.submitSearch();
    await flushPromises();

    expect(mocks.get).toHaveBeenCalledTimes(1);
  });

  it.each(["x\u0000yz", "tab\tin", "line\u2028break"])("does not search for %j: the API refuses control characters", async (text) => {
    const { list } = await start({ "": page(["c1"]) });

    list.search.value = text;
    list.submitSearch();
    await flushPromises();

    expect(mocks.get).toHaveBeenCalledTimes(1);
  });

  it("takes a field of only no-break spaces as blank, not as a search", async () => {
    const { list } = await start({ "": page(["c1"]) }, "/customers?q=nowak");

    list.search.value = "\u00a0\u00a0\u00a0";
    list.submitSearch();
    await flushPromises();

    expect(queries().at(-1)).toEqual({});
  });

  it("takes a term of up to 100 characters and not one of 101", async () => {
    const { list } = await start({ "": page(["c1"]) });

    list.search.value = "ż".repeat(101);
    list.submitSearch();
    await flushPromises();
    expect(mocks.get).toHaveBeenCalledTimes(1);

    list.search.value = "ż".repeat(100);
    list.submitSearch();
    await flushPromises();
    expect(queries().at(-1)).toEqual({ q: "ż".repeat(100) });
  });

  it("loads again when the same term is submitted, and all customers when the field is blank", async () => {
    const { list } = await start({ "": page(["c1"]) });
    list.search.value = "nowak";
    list.submitSearch();
    await flushPromises();

    list.submitSearch();
    await flushPromises();
    expect(mocks.get).toHaveBeenCalledTimes(3);

    list.search.value = "   ";
    list.submitSearch();
    await flushPromises();
    expect(queries().at(-1)).toEqual({});
  });

  it("reads a search of the address into the field and sends it", async () => {
    const { list } = await start({ "": page(["c1"]) }, "/customers?q=nowak");

    expect(list.search.value).toBe("nowak");
    expect(queries()).toEqual([{ q: "nowak" }]);
  });

  it("disables the sorts other than the last order while a search is applied, with the reason, and sends none", async () => {
    const { list } = await start({ "": page(["c1"]) }, "/customers?q=nowak&sort=name");

    expect(list.sort.value).toBe("-lastOrderAt");
    expect(queries()).toEqual([{ q: "nowak" }]);
    expect(list.sortOptions.value.map((option) => option.disabledReason)).toEqual([undefined, "Wyszukiwanie działa tylko z sortowaniem po ostatnim zamówieniu.", "Wyszukiwanie działa tylko z sortowaniem po ostatnim zamówieniu.", "Wyszukiwanie działa tylko z sortowaniem po ostatnim zamówieniu."]);
  });

  it("returns to the last order when a search is applied over another sort", async () => {
    const { list, router } = await start({ "": page(["c1"]) }, "/customers?sort=-orderCount");

    list.search.value = "nowak";
    list.submitSearch();
    await flushPromises();

    expect(queries().at(-1)).toEqual({ q: "nowak" });
    expect(router.currentRoute.value.fullPath).toBe("/customers?q=nowak");
  });

  it("sends the page size only when it is not the default", async () => {
    const { list } = await start({ "": page(["c1"]) });

    list.setPerPage(50);
    await flushPromises();

    expect(queries().at(-1)).toEqual({ perPage: 50 });
  });

  it("drops the search, the sort and the page size with „Wyczyść filtry”", async () => {
    const { list, router } = await start({ "": page(["c1"]) }, "/customers?q=nowak&perPage=10");

    list.clearFilters();
    await flushPromises();

    expect(list.search.value).toBe("");
    expect(router.currentRoute.value.fullPath).toBe("/customers");
    expect(queries().at(-1)).toEqual({});
  });

  it.each([
    [403, "Nie masz dostępu do klientów.", true],
    [400, "Nieprawidłowe parametry wyszukiwania (HTTP 400).", false],
    [500, "Nie udało się pobrać klientów (HTTP 500).", false],
  ])("reports a %s of the API in Polish", async (status, message, forbidden) => {
    const { list } = await start({}, undefined, status);

    expect(list.error.value).toBe(message);
    expect(list.forbidden.value).toBe(forbidden);
    expect(list.loaded.value).toBe(false);
  });

  it("reports a failed request in Polish", async () => {
    mocks.get.mockRejectedValue(new Error("network"));
    const mounted = await mountList();
    wrapper = mounted.wrapper;
    await flushPromises();

    expect(mounted.list.error.value).toBe("Nie udało się pobrać klientów. Sprawdź połączenie z API.");
  });
});
