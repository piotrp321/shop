import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { components } from "@/api/schema";
import { useStockHistory } from "@/inventory/useStockHistory";

type Entry = components["schemas"]["StockHistoryItemResponse"];
type Page = components["schemas"]["StockHistoryResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

const HISTORY = "/api/v1/admin/variants/{variantId}/stock-history";

function entry(physicalDelta: number, overrides: Partial<Entry> = {}): Entry {
  return {
    occurredAt: "2026-11-01T12:30:00+00:00",
    type: "receipt",
    physicalDelta,
    reservedDelta: 0,
    physicalAfter: physicalDelta,
    reservedAfter: 0,
    reason: null,
    note: null,
    reference: "key-1",
    source: "staff",
    actorName: "Anna Admin",
    ...overrides,
  };
}

function ok(page: Page) {
  return { data: page, response: { status: 200 } };
}

function failed(status: number) {
  return { data: undefined, response: { status } };
}

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((done) => {
    resolve = done;
  });
  return { promise, resolve };
}

describe("useStockHistory", () => {
  beforeEach(() => {
    mocks.get.mockReset();
  });

  it("starts empty and asks for nothing until a product is opened", () => {
    const history = useStockHistory();

    expect(history.variantId.value).toBeNull();
    expect(history.items.value).toEqual([]);
    expect(history.hasMore.value).toBe(false);
    expect(mocks.get).not.toHaveBeenCalled();
  });

  it("opens a product with its first page, newest first as the API sends it", async () => {
    mocks.get.mockResolvedValue(ok({ items: [entry(5990), entry(5490)], next: null }));
    const history = useStockHistory();

    await history.open("p-1");

    expect(mocks.get).toHaveBeenCalledWith(HISTORY, { params: { path: { variantId: "p-1" }, query: {} } });
    expect(history.variantId.value).toBe("p-1");
    expect(history.items.value.map((item) => item.physicalDelta)).toEqual([5990, 5490]);
    expect(history.loaded.value).toBe(true);
    expect(history.hasMore.value).toBe(false);
    expect(history.loading.value).toBe(false);
    expect(history.error.value).toBeNull();
  });

  it("appends the next page with the cursor of the previous one and stops on the last page", async () => {
    mocks.get
      .mockResolvedValueOnce(ok({ items: [entry(3)], next: "c1" }))
      .mockResolvedValueOnce(ok({ items: [entry(2)], next: "c2" }))
      .mockResolvedValueOnce(ok({ items: [entry(1)], next: null }));
    const history = useStockHistory();

    await history.open("p-1");
    expect(history.hasMore.value).toBe(true);
    await history.loadMore();
    await history.loadMore();

    expect(mocks.get.mock.calls.map(([, options]) => options.params.query)).toEqual([{}, { after: "c1" }, { after: "c2" }]);
    expect(history.items.value.map((item) => item.physicalDelta)).toEqual([3, 2, 1]);
    expect(history.hasMore.value).toBe(false);
    await history.loadMore();
    expect(mocks.get).toHaveBeenCalledTimes(3);
  });

  it("ignores a second request for more while one is on its way", async () => {
    const second = deferred<ReturnType<typeof ok>>();
    mocks.get.mockResolvedValueOnce(ok({ items: [entry(3)], next: "c1" })).mockReturnValueOnce(second.promise);
    const history = useStockHistory();
    await history.open("p-1");

    const first = history.loadMore();
    await history.loadMore();
    second.resolve(ok({ items: [entry(2)], next: null }));
    await first;

    expect(mocks.get).toHaveBeenCalledTimes(2);
    expect(history.items.value.map((item) => item.physicalDelta)).toEqual([3, 2]);
  });

  it("keeps the entries it has when a further page fails and retries the same page", async () => {
    mocks.get
      .mockResolvedValueOnce(ok({ items: [entry(3)], next: "c1" }))
      .mockResolvedValueOnce(failed(500))
      .mockResolvedValueOnce(ok({ items: [entry(2)], next: null }));
    const history = useStockHistory();
    await history.open("p-1");

    await history.loadMore();
    expect(history.error.value).toBe("Nie udało się pobrać historii stanów (HTTP 500).");
    expect(history.items.value.map((item) => item.physicalDelta)).toEqual([3]);
    expect(history.hasMore.value).toBe(true);

    await history.retry();
    expect(mocks.get.mock.calls.at(-1)![1].params.query).toEqual({ after: "c1" });
    expect(history.items.value.map((item) => item.physicalDelta)).toEqual([3, 2]);
    expect(history.error.value).toBeNull();
  });

  it("retries the first page when it failed", async () => {
    mocks.get.mockResolvedValueOnce(failed(500)).mockResolvedValueOnce(ok({ items: [entry(1)], next: null }));
    const history = useStockHistory();

    await history.open("p-1");
    expect(history.loaded.value).toBe(false);
    expect(history.error.value).not.toBeNull();
    await history.retry();

    expect(mocks.get.mock.calls.map(([, options]) => options.params.query)).toEqual([{}, {}]);
    expect(history.items.value).toHaveLength(1);
    expect(history.loaded.value).toBe(true);
  });

  it.each([
    [404, "Ten produkt już nie istnieje."],
    [400, "Nieprawidłowe parametry historii (HTTP 400)."],
    [503, "Nie udało się pobrać historii stanów (HTTP 503)."],
  ])("explains a %i of the API", async (status, message) => {
    mocks.get.mockResolvedValue(failed(status));
    const history = useStockHistory();

    await history.open("p-1");

    expect(history.error.value).toBe(message);
    expect(history.items.value).toEqual([]);
  });

  it("explains a request that fails itself", async () => {
    mocks.get.mockRejectedValue(new TypeError("network"));
    const history = useStockHistory();

    await history.open("p-1");

    expect(history.error.value).toBe("Nie udało się pobrać historii stanów. Sprawdź połączenie z API.");
    expect(history.loading.value).toBe(false);
  });

  it("forgets the previous product when another is opened, and ignores a late answer for the first", async () => {
    const late = deferred<ReturnType<typeof ok>>();
    mocks.get.mockReturnValueOnce(late.promise).mockResolvedValueOnce(ok({ items: [entry(222)], next: null }));
    const history = useStockHistory();

    const first = history.open("p-1");
    await history.open("p-2");
    late.resolve(ok({ items: [entry(111)], next: "stale" }));
    await first;
    await flushPromises();

    expect(history.variantId.value).toBe("p-2");
    expect(history.items.value.map((item) => item.physicalDelta)).toEqual([222]);
    expect(history.hasMore.value).toBe(false);
  });

  it("clears everything on close and ignores an answer that arrives afterwards", async () => {
    const late = deferred<ReturnType<typeof ok>>();
    mocks.get.mockReturnValueOnce(late.promise);
    const history = useStockHistory();

    const opening = history.open("p-1");
    history.close();
    late.resolve(ok({ items: [entry(1)], next: "c1" }));
    await opening;

    expect(history.variantId.value).toBeNull();
    expect(history.items.value).toEqual([]);
    expect(history.loading.value).toBe(false);
    expect(history.loaded.value).toBe(false);
    expect(history.hasMore.value).toBe(false);
  });

  it("starts a reopened product from the first page again", async () => {
    mocks.get
      .mockResolvedValueOnce(ok({ items: [entry(3)], next: "c1" }))
      .mockResolvedValueOnce(ok({ items: [entry(30)], next: null }));
    const history = useStockHistory();
    await history.open("p-1");
    history.close();

    await history.open("p-1");

    expect(mocks.get.mock.calls.map(([, options]) => options.params.query)).toEqual([{}, {}]);
    expect(history.items.value.map((item) => item.physicalDelta)).toEqual([30]);
  });
});
