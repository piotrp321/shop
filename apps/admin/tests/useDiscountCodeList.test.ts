import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent } from "vue";
import type { components } from "@/api/schema";
import { EMPTY_MESSAGE, FILTERED_EMPTY_MESSAGE, SEARCH_DEBOUNCE_MS, useDiscountCodeList } from "@/discount-codes/useDiscountCodeList";

type CodeList = components["schemas"]["DiscountCodeListResponse"];
type Code = components["schemas"]["DiscountCodeResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

const CODES = "/api/v1/admin/discount-codes";

function code(text: string, overrides: Partial<Code> = {}): Code {
  return {
    id: `id-${text}`,
    code: text,
    kind: "percent",
    value: 10,
    minimumOrderGross: null,
    startsAt: "2026-11-10T23:00:00+00:00",
    endsAt: null,
    usageLimit: null,
    activeUses: 0,
    enabled: true,
    status: "planned",
    version: 1,
    ...overrides,
  };
}

function page(codes: string[], next: string | null = null): CodeList {
  return { items: codes.map((text) => code(text)), next };
}

/** Answers the list by the `after` cursor of the request ("" for the first page). */
function serve(pages: Record<string, CodeList>, status?: number) {
  mocks.get.mockImplementation(async (_path: string, options?: { params?: { query?: { after?: string } } }) =>
    status ? { data: undefined, response: { status } } : { data: pages[options?.params?.query?.after ?? ""], response: { status: 200 } },
  );
}

function queries(): Record<string, unknown>[] {
  return mocks.get.mock.calls.map(([, options]) => (options as { params: { query: Record<string, unknown> } }).params.query);
}

function mountList() {
  let list!: ReturnType<typeof useDiscountCodeList>;
  const wrapper = mount(
    defineComponent({
      setup() {
        list = useDiscountCodeList();
        return () => null;
      },
    }),
  );
  return { wrapper, list };
}

describe("useDiscountCodeList", () => {
  let wrapper: ReturnType<typeof mountList>["wrapper"] | undefined;

  beforeEach(() => {
    mocks.get.mockReset();
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    vi.useRealTimers();
  });

  async function mounted(pages: Record<string, CodeList> = { "": page(["A"]) }) {
    serve(pages);
    const result = mountList();
    wrapper = result.wrapper;
    await flushPromises();
    return result.list;
  }

  async function type(list: Awaited<ReturnType<typeof mounted>>, text: string) {
    list.search.value = text;
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS);
    await flushPromises();
  }

  it("loads the first page without a filter, a search or a cursor", async () => {
    const list = await mounted({ "": page(["A", "B"], "c1") });

    expect(mocks.get).toHaveBeenCalledWith(CODES, { params: { query: {} } });
    expect(list.items.value.map((item) => item.code)).toEqual(["A", "B"]);
    expect(list.loaded.value).toBe(true);
    expect(list.hasNext.value).toBe(true);
    expect(list.hasPrevious.value).toBe(false);
    expect(list.pageNumber.value).toBe(1);
  });

  it("pages forward and back with the cursors of the API", async () => {
    const list = await mounted({ "": page(["A"], "c1"), c1: page(["B"], "c2"), c2: page(["C"], null) });

    await list.goNext();
    await list.goNext();
    expect(list.items.value.map((item) => item.code)).toEqual(["C"]);
    expect(list.hasNext.value).toBe(false);
    expect(list.pageNumber.value).toBe(3);

    await list.goPrevious();
    expect(list.items.value.map((item) => item.code)).toEqual(["B"]);
    expect(queries()).toEqual([{}, { after: "c1" }, { after: "c2" }, { after: "c1" }]);

    await list.goPrevious();
    expect(queries().at(-1)).toEqual({});
    expect(list.hasPrevious.value).toBe(false);
  });

  it("filters by status and starts again from the first page", async () => {
    const list = await mounted({ "": page(["A"], "c1"), c1: page(["B"], null) });
    await list.goNext();

    await list.selectStatus("active");

    expect(queries().at(-1)).toEqual({ status: "active" });
    expect(list.pageNumber.value).toBe(1);
    expect(list.hasPrevious.value).toBe(false);
  });

  it("keeps the status filter while paging and drops it for all statuses", async () => {
    const list = await mounted({ "": page(["A"], "c1"), c1: page(["B"], null) });

    await list.selectStatus("expired");
    await list.goNext();
    expect(queries().at(-1)).toEqual({ status: "expired", after: "c1" });

    await list.selectStatus(null);
    expect(queries().at(-1)).toEqual({});
  });

  it("does not ask again for the status that is already chosen", async () => {
    const list = await mounted();

    await list.selectStatus(null);

    expect(mocks.get).toHaveBeenCalledTimes(1);
  });

  it("searches by the beginning of the code after a pause in typing, from one character", async () => {
    const list = await mounted({ "": page(["A"], "c1"), c1: page(["B"]) });
    await list.goNext();

    list.search.value = "wit";
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS - 1);
    expect(mocks.get).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(1);
    await flushPromises();

    expect(queries().at(-1)).toEqual({ q: "wit" });
    expect(list.pageNumber.value).toBe(1);
  });

  it("asks once for a burst of typing and keeps the status with the search", async () => {
    const list = await mounted();
    await list.selectStatus("active");

    list.search.value = "W";
    await vi.advanceTimersByTimeAsync(100);
    list.search.value = "WI";
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS);
    await flushPromises();

    expect(queries().slice(2)).toEqual([{ status: "active", q: "WI" }]);
  });

  it("trims the search, treats white space only as no search, and does not ask twice for the same term", async () => {
    const list = await mounted();

    await type(list, "  ab  ");
    expect(queries().at(-1)).toEqual({ q: "ab" });
    const calls = mocks.get.mock.calls.length;

    await type(list, "ab ");
    expect(mocks.get).toHaveBeenCalledTimes(calls);

    await type(list, "   ");
    expect(queries().at(-1)).toEqual({});
  });

  it("does not search for a term longer than a code can be, and says so", async () => {
    const list = await mounted();

    await type(list, "A".repeat(33));

    expect(mocks.get).toHaveBeenCalledTimes(1);
    expect(list.searchTooLong.value).toBe(true);
    await type(list, "A".repeat(32));
    expect(list.searchTooLong.value).toBe(false);
    expect(queries().at(-1)).toEqual({ q: "A".repeat(32) });
  });

  it("drops the rows and the cursors of the old filter when the request for the new one fails, and recovers on retry", async () => {
    const list = await mounted({ "": page(["A"], "c1") });
    serve({}, 500);

    await list.selectStatus("planned");

    expect(list.items.value).toEqual([]);
    expect(list.loaded.value).toBe(false);
    expect(list.hasNext.value).toBe(false);
    expect(list.error.value).toBe("Nie udało się pobrać kodów rabatowych (HTTP 500).");

    serve({ "": page(["P"]) });
    await list.retry();
    expect(queries().at(-1)).toEqual({ status: "planned" });
    expect(list.items.value.map((item) => item.code)).toEqual(["P"]);
    expect(list.error.value).toBeNull();
  });

  it("drops the rows of the old search when the request for the new one fails", async () => {
    const list = await mounted({ "": page(["A"], "c1") });
    serve({}, 500);

    await type(list, "x");

    expect(list.items.value).toEqual([]);
    expect(list.loaded.value).toBe(false);
    expect(list.hasNext.value).toBe(false);
    expect(list.error.value).not.toBeNull();
  });

  it("keeps the page that is shown when the next page fails and retries that page", async () => {
    const list = await mounted({ "": page(["A"], "c1") });
    serve({}, 500);

    await list.goNext();
    expect(list.items.value.map((item) => item.code)).toEqual(["A"]);
    expect(list.pageNumber.value).toBe(1);
    expect(list.error.value).not.toBeNull();

    serve({ c1: page(["B"]) });
    await list.retry();
    expect(list.items.value.map((item) => item.code)).toEqual(["B"]);
    expect(list.pageNumber.value).toBe(2);
  });

  it("reports a 403 as such and a request that failed to leave as a connection problem", async () => {
    serve({}, 403);
    const result = mountList();
    wrapper = result.wrapper;
    await flushPromises();
    expect(result.list.forbidden.value).toBe(true);
    expect(result.list.error.value).toBe("Nie masz dostępu do kodów rabatowych.");

    mocks.get.mockRejectedValue(new TypeError("network down"));
    await result.list.retry();
    expect(result.list.forbidden.value).toBe(false);
    expect(result.list.error.value).toContain("połączenie");
  });

  it("ignores an answer that arrives after a newer request", async () => {
    let release!: (value: unknown) => void;
    mocks.get.mockImplementationOnce(() => new Promise((resolve) => (release = resolve)));
    const result = mountList();
    wrapper = result.wrapper;
    serve({ "": page(["Fresh"]) });

    await result.list.selectStatus("active");
    release({ data: page(["Stale"]), response: { status: 200 } });
    await flushPromises();

    expect(result.list.items.value.map((item) => item.code)).toEqual(["Fresh"]);
  });

  it("says why the list is empty: nothing yet, or nothing for the filter or the search", async () => {
    const list = await mounted({ "": { items: [], next: null } });
    expect(list.emptyText.value).toBe(EMPTY_MESSAGE);

    await list.selectStatus("expired");
    expect(list.emptyText.value).toBe(FILTERED_EMPTY_MESSAGE);

    await list.selectStatus(null);
    expect(list.emptyText.value).toBe(EMPTY_MESSAGE);
    await type(list, "ab");
    expect(list.emptyText.value).toBe(FILTERED_EMPTY_MESSAGE);
  });
});
