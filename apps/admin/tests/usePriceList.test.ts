import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, ref } from "vue";
import { createMemoryHistory, createRouter, type Router } from "vue-router";
import type { components } from "@/api/schema";
import type { CategoryTreeNode } from "@/composables/useCategoryTree";
import { CONFLICT_MESSAGE, SEARCH_DEBOUNCE_MS, usePriceList } from "@/prices/usePriceList";
import { requestSignal, type WithOptionalTotal } from "./listPage";

type PriceList = WithOptionalTotal<components["schemas"]["PriceListResponse"]>;
type PriceListItem = components["schemas"]["PriceListItemResponse"];
type PriceListVariant = components["schemas"]["PriceListVariantResponse"];
type ListedPrice = components["schemas"]["ListedPriceResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get, PUT: mocks.put }) }));

const PRICES = "/api/v1/admin/prices";
const PRICE = "/api/v1/admin/variants/{variantId}/price";
const LOCKED = "tag:piotrp321.github.io,2026:shop/problems/price-locked-by-promotion";

function price(overrides: Partial<ListedPrice> = {}): ListedPrice {
  return { gross: 5490, net: 5229, vat: 261, vatRate: 500, currency: "PLN", version: 1, updatedAt: "2026-11-01T12:30:00+00:00", vatRateOutdated: false, ...overrides };
}

function variant(variantId: string, label = "", variantPrice: ListedPrice | null = price()): PriceListVariant {
  return { variantId, label, price: variantPrice };
}

function item(name: string, variants: PriceListVariant[] = [variant(`v-${name}`)]): PriceListItem {
  return { productId: `id-${name}`, name, taxClass: "printed_book", applicableVatRate: 500, variants };
}

/** Answers the price list by the `after` cursor of the request ("" for the first page). */
function serve(pages: Record<string, PriceList>, status?: number) {
  mocks.get.mockImplementation(async (_path: string, options?: { params?: { query?: { after?: string } } }) =>
    status ? { data: undefined, response: { status } } : { data: pages[options?.params?.query?.after ?? ""], response: { status: 200 } },
  );
}

function queries(): Record<string, unknown>[] {
  return mocks.get.mock.calls.map(([, options]) => (options as { params: { query: Record<string, unknown> } }).params.query);
}

async function settle() {
  await vi.advanceTimersByTimeAsync(0);
  await flushPromises();
}

async function mountList(address = "/prices", tree?: CategoryTreeNode[] | null) {
  let list!: ReturnType<typeof usePriceList>;
  const empty = { template: "<div />" };
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: "/prices", name: "prices", component: empty }] });
  const nodes = ref<CategoryTreeNode[]>(tree ?? []);
  const loaded = ref(tree !== undefined && tree !== null);
  await router.push(address);
  const wrapper = mount(
    defineComponent({
      setup() {
        list = usePriceList(tree === undefined ? undefined : { nodes, loaded });
        return () => null;
      },
    }),
    { global: { plugins: [router] } },
  );
  return { wrapper, list, router };
}

describe("usePriceList", () => {
  let wrapper: Awaited<ReturnType<typeof mountList>>["wrapper"] | undefined;
  let router: Router | undefined;

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    mocks.get.mockReset();
    mocks.put.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    vi.useRealTimers();
  });

  async function start(pages: Record<string, PriceList>, status?: number, address?: string) {
    serve(pages, status);
    const mounted = await mountList(address);
    wrapper = mounted.wrapper;
    router = mounted.router;
    await settle();
    return mounted.list;
  }

  it("asks for 10 products on the first page and shows a row per variant under its product", async () => {
    const list = await start({ "": { items: [item("Buty", [variant("v-40", "40"), variant("v-41", "41", null)]), item("Hantle")], next: null } });

    expect(mocks.get).toHaveBeenCalledWith(PRICES, { params: { query: { perPage: 10 } } });
    expect(list.items.value.map((product) => product.name)).toEqual(["Buty", "Hantle"]);
    expect(list.rows.value.map((row) => [row.name, row.variantId, row.first])).toEqual([
      ["Buty", "v-40", true],
      ["Buty", "v-41", false],
      ["Hantle", "v-Hantle", true],
    ]);
    expect(list.loaded.value).toBe(true);
    expect(list.hasPrevious.value).toBe(false);
    expect(list.hasNext.value).toBe(false);
  });

  it("numbers the products of a page, not its variants, for „Pokazano produkty X–Y”", async () => {
    const page = (prefix: string, next: string | null): PriceList => ({
      items: Array.from({ length: 10 }, (_, index) => item(`${prefix}${index}`, [variant(`${prefix}${index}-a`, "a"), variant(`${prefix}${index}-b`, "b")])),
      next,
    });
    const list = await start({ "": page("A", "c1"), c1: page("B", null) });
    expect(list.range.value).toEqual({ from: 1, to: 10 });

    await list.goNext();
    await settle();

    expect(list.rows.value).toHaveLength(20);
    expect(list.range.value).toEqual({ from: 11, to: 20 });
    expect(queries().at(-1)).toEqual({ perPage: 10, after: "c1" });
  });

  it("gives the total of products of the first page and keeps it on the next pages", async () => {
    const list = await start({
      "": { items: [item("Buty", [variant("v-40", "40")])], next: "c1", total: { value: 10_000, capped: true } },
      c1: { items: [item("Hantle")], next: null, total: null },
    });
    expect(list.total.value).toEqual({ value: 10_000, capped: true });

    await list.goNext();
    await settle();
    expect(list.total.value).toEqual({ value: 10_000, capped: true });
  });

  describe("the state of the list is in the address", () => {
    it("starts from the search, the category and the page size of the address, with one request", async () => {
      const list = await start({ "": { items: [item("Hantle")], next: null } }, undefined, "/prices?q=hantle&perPage=25");

      expect(queries()).toEqual([{ q: "hantle", perPage: 25 }]);
      expect(list.search.value).toBe("hantle");
      expect(list.query.value).toEqual({ q: "hantle", category: null, perPage: 25, sort: "name" });
      expect(list.filtered.value).toBe(true);
    });

    it("does not write the default page size (10) to the address, but writes the others and the applied search", async () => {
      const list = await start({ "": { items: [item("A")], next: null } });
      expect(router!.currentRoute.value.query).toEqual({});

      list.setPerPage(25);
      await settle();
      expect(router!.currentRoute.value.query).toEqual({ perPage: "25" });
      expect(queries().at(-1)).toEqual({ perPage: 25 });

      list.setPerPage(10);
      await settle();
      expect(router!.currentRoute.value.query).toEqual({});
      expect(queries().at(-1)).toEqual({ perPage: 10 });

      list.search.value = "  bidon ";
      await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 50);
      await settle();
      expect(router!.currentRoute.value.query).toEqual({ q: "bidon" });
      expect(queries().at(-1)).toEqual({ q: "bidon", perPage: 10 });
    });

    it("does not ask for a search shorter than two characters and says so in the hint", async () => {
      const list = await start({ "": { items: [item("A")], next: null } });
      const calls = queries().length;

      list.search.value = "a";
      await vi.advanceTimersByTimeAsync(1000);
      await settle();

      expect(queries()).toHaveLength(calls);
      expect(list.searchHint.value).toContain("2 znaki");
    });

    it("goes back to the first page when the page size changes", async () => {
      const list = await start({ "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: null } });
      await list.goNext();
      await settle();
      expect(list.pageNumber.value).toBe(2);

      list.setPerPage(50);
      await settle();

      expect(queries().at(-1)).toEqual({ perPage: 50 });
      expect(list.pageNumber.value).toBe(1);
      expect(list.items.value.map((product) => product.name)).toEqual(["A"]);
    });

    it("clears the search, the category and the page size: one request for the whole list", async () => {
      const list = await start({ "": { items: [item("A")], next: null } }, undefined, "/prices?q=hantle&perPage=50");
      const calls = queries().length;

      list.clearFilters();
      await settle();

      expect(router!.currentRoute.value.query).toEqual({});
      expect(list.search.value).toBe("");
      expect(queries().slice(calls)).toEqual([{ perPage: 10 }]);
      expect(list.filtered.value).toBe(false);
    });
  });

  describe("the sort", () => {
    const one = { "": { items: [item("A")], next: null } };

    it("lists by name without a sort, and does not send the default", async () => {
      const list = await start(one);

      expect(queries()).toEqual([{ perPage: 10,  }]);
      expect(list.query.value.sort).toBe("name");
      expect(list.sortOptions.map((option) => [option.value, option.label])).toEqual([
        ["name", "Nazwa A–Z"],
        ["-name", "Nazwa Z–A"],
        ["-updatedAt", "Ostatnio zmienione"],
        ["updatedAt", "Najdawniej zmienione"],
      ]);
    });

    it.each(["-name", "-updatedAt", "updatedAt"])("reads the sort %s of the address with one request", async (sort) => {
      const list = await start(one, undefined, "/prices?sort=" + sort);

      expect(queries()).toEqual([{ perPage: 10, sort }]);
      expect(list.query.value.sort).toBe(sort);
    });

    it("writes the chosen sort to the address without a new entry, asks once from the first page and drops the default", async () => {
      const list = await start({ "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: null } });
      await list.goNext();
      expect(list.pageNumber.value).toBe(2);
      const push = vi.spyOn(router!, "push");

      list.setSort("-updatedAt");
      await settle();

      expect(router!.currentRoute.value.query).toEqual({ sort: "-updatedAt" });
      expect(queries().at(-1)).toEqual({ perPage: 10, sort: "-updatedAt" });
      expect(list.pageNumber.value).toBe(1);
      expect(queries().filter((query) => query.sort === "-updatedAt")).toHaveLength(1);

      list.setSort("name");
      await settle();
      expect(router!.currentRoute.value.query).toEqual({});
      expect(queries().at(-1)).toEqual({ perPage: 10,  });
      expect(push).not.toHaveBeenCalled();
    });

    it("applies a change of the address from outside with one request", async () => {
      await start(one);
      mocks.get.mockClear();

      await router!.push({ path: "/prices", query: { sort: "updatedAt" } });
      await settle();

      expect(queries()).toEqual([{ perPage: 10, sort: "updatedAt" }]);
    });

    it.each(["/prices?sort=price", "/prices?sort=", "/prices?sort=--name", "/prices?sort=Name", "/prices?sort=-placedAt"])(
      "skips the invalid sort of %s and corrects the address",
      async (address) => {
        const list = await start(one, undefined, address);

        expect(queries()).toEqual([{ perPage: 10,  }]);
        expect(list.query.value.sort).toBe("name");
        expect(router!.currentRoute.value.fullPath).toBe("/prices");
      },
    );

    it("does not take a parameter of another name (sort[]) as the sort", async () => {
      const list = await start(one, undefined, "/prices?sort[]=-name");

      expect(queries()).toEqual([{ perPage: 10 }]);
      expect(list.query.value.sort).toBe("name");
    });

    it("is no filter: „Wyczyść filtry” does not show for it, but clearing drops it", async () => {
      const list = await start(one, undefined, "/prices?sort=-name");
      expect(list.filtered.value).toBe(false);

      list.clearFilters();
      await settle();

      expect(list.query.value.sort).toBe("name");
      expect(router!.currentRoute.value.query).toEqual({});
    });

    it("keeps the sort when a filter changes", async () => {
      const list = await start(one, undefined, "/prices?sort=-name");

      list.setPerPage(50);
      await settle();

      expect(queries().at(-1)).toEqual({ perPage: 50, sort: "-name" });
    });
  });

  describe("errors", () => {
    it("shows no rows after a failed search, so rows of the old list are not taken for its result (#272)", async () => {
      const list = await start({ "": { items: [item("A")], next: null } });
      serve({}, 500);

      list.search.value = "hantle";
      await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 50);
      await settle();

      expect(list.error.value).toBe("Nie udało się pobrać cen (HTTP 500).");
      expect(list.rows.value).toEqual([]);
      expect(list.loaded.value).toBe(false);
    });

    it("reports a 403 as forbidden, without a retry to offer", async () => {
      const list = await start({}, 403);

      expect(list.error.value).toBe("Nie masz dostępu do cen.");
      expect(list.forbidden.value).toBe(true);
    });
  });

  describe("changePrice", () => {
    const answer = (status: number, data?: ListedPrice, error?: unknown) =>
      mocks.put.mockResolvedValue(data ? { data, error: undefined, response: { status } } : { data: undefined, error, response: { status } });

    it("sends the gross with the version and puts the saved price of the API into the row, leaving the others", async () => {
      const list = await start({ "": { items: [item("Buty", [variant("v-40", "40", price({ version: 2 })), variant("v-41", "41", price({ version: 5 }))])], next: null } });
      answer(200, price({ gross: 5990, net: 5705, vat: 285, version: 6 }));

      const result = await list.changePrice("v-41", 5990, 5);

      expect(result).toEqual({ status: "saved" });
      expect(mocks.put).toHaveBeenCalledWith(PRICE, { params: { path: { variantId: "v-41" } }, body: { gross: 5990, version: 5 } });
      expect(list.rows.value.map((row) => row.price?.gross)).toEqual([5490, 5990]);
    });

    it("does not write to the items of the pager: the saved price is an overlay over them", async () => {
      const list = await start({ "": { items: [item("A")], next: null } });
      answer(200, price({ gross: 5990, version: 2 }));

      await list.changePrice("v-A", 5990, 1);

      expect(list.rows.value[0]!.price?.gross).toBe(5990);
      expect(list.items.value[0]!.variants[0]!.price?.gross).toBe(5990);
      expect(mocks.get).toHaveBeenCalledTimes(1);
    });

    it("keeps the saved price while the list is being refreshed, and when the refresh answers with the old one", async () => {
      const list = await start({ "": { items: [item("A")], next: null } });
      answer(200, price({ gross: 5990, version: 2 }));
      await list.changePrice("v-A", 5990, 1);

      let respond: (page: PriceList) => void = () => undefined;
      mocks.get.mockImplementation(() => new Promise((resolve) => (respond = (page) => resolve({ data: page, response: { status: 200 } }))));
      const retrying = list.retry();
      await settle();
      expect(list.rows.value[0]!.price?.gross, "during the request the row does not go back to the old price").toBe(5990);

      respond({ items: [item("A")], next: null });
      await retrying;
      await settle();
      expect(list.rows.value[0]!.price?.gross).toBe(5990);

      serve({ "": { items: [item("A", [variant("v-A", "", price({ gross: 7000, version: 3 }))])], next: null } });
      await list.retry();
      await settle();
      expect(list.rows.value[0]!.price?.gross, "a newer version from the list wins").toBe(7000);
    });

    it("on a 409 refreshes the current page, not the first, and returns the conflict", async () => {
      const list = await start({ "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: null } });
      await list.goNext();
      await settle();
      answer(409, undefined, { type: "about:blank", status: 409 });
      serve({ c1: { items: [item("B", [variant("v-B", "", price({ gross: 7000, version: 2 }))])], next: null } });
      const calls = queries().length;

      const result = await list.changePrice("v-B", 5990, 1);

      expect(result).toEqual({ status: "conflict", message: CONFLICT_MESSAGE });
      expect(queries().slice(calls)).toEqual([{ perPage: 10, after: "c1" }]);
      expect(list.rows.value[0]!.price?.gross).toBe(7000);
      expect(list.pageNumber.value).toBe(2);
    });

    it("tells a price locked by a promotion by its type: the detail of the API, no reload", async () => {
      const list = await start({ "": { items: [item("A")], next: null } });
      answer(409, undefined, { type: LOCKED, status: 409, detail: "Cena jest zablokowana." });
      const calls = queries().length;

      const result = await list.changePrice("v-A", 5990, 1);

      expect(result).toEqual({ status: "locked", message: "Cena jest zablokowana." });
      expect(queries()).toHaveLength(calls);
    });

    it.each([
      [422, { violations: [{ propertyPath: "gross", title: "Za dużo." }] }, { status: "invalid", message: "Za dużo." }],
      [422, undefined, { status: "invalid", message: "API odrzuciło cenę (HTTP 422)." }],
      [403, undefined, { status: "failed", message: "Nie masz uprawnień do zmiany cen." }],
      [404, undefined, { status: "failed", message: "Ten wariant już nie istnieje." }],
      [500, undefined, { status: "failed", message: "Nie udało się zapisać ceny (HTTP 500)." }],
    ])("answers a %i with its message", async (status, body, expected) => {
      const list = await start({ "": { items: [item("A")], next: null } });
      answer(status, undefined, body);

      expect(await list.changePrice("v-A", 5990, 1)).toEqual(expected);
    });

    it("says so when the request itself fails", async () => {
      const list = await start({ "": { items: [item("A")], next: null } });
      mocks.put.mockRejectedValue(new TypeError("network down"));

      expect(await list.changePrice("v-A", 5990, 1)).toEqual({ status: "failed", message: "Nie udało się zapisać ceny. Sprawdź połączenie z API." });
    });
  });
});

describe("usePriceList request abort", () => {
  let wrapper: { unmount: () => void } | undefined;

  beforeEach(() => {
    mocks.get.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
  });

  it("gives the request an abort signal and aborts it when the list is gone", async () => {
    mocks.get.mockImplementation(() => new Promise(() => {}));
    const mounted = await mountList();
    wrapper = mounted.wrapper;
    await flushPromises();

    const signal = requestSignal(mocks.get, PRICES);
    expect(signal).toBeInstanceOf(AbortSignal);
    expect(signal!.aborted).toBe(false);

    wrapper.unmount();
    wrapper = undefined;
    expect(signal!.aborted).toBe(true);
  });
});
