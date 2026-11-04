import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent } from "vue";
import type { components } from "@/api/schema";
import { SEARCH_DEBOUNCE_MS, useStockList } from "@/inventory/useStockList";

type StockList = components["schemas"]["StockListResponse"];
type StockListItem = components["schemas"]["StockListItemResponse"];
type ListedStock = components["schemas"]["ListedStockResponse"];
type StockState = components["schemas"]["StockStateResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get, POST: mocks.post }) }));

const STOCK = "/api/v1/admin/stock";

/** A product with one default variant (empty label) unless `variants` names others; `stock` is that variant's. */
function item(name: string, overrides: { stock?: ListedStock | null; variants?: StockListItem["variants"] } = {}): StockListItem {
  return {
    productId: `id-${name}`,
    name,
    variants: overrides.variants ?? [{ variantId: `var-${name}`, label: "", stock: overrides.stock === undefined ? { physical: 10, reserved: 4, available: 6, level: "in_stock", version: 3, updatedAt: "2026-11-02T12:30:00+00:00" } : overrides.stock }],
  };
}

/** Answers the stock list by the `after` cursor of the request ("" for the first page). */
function serve(pages: Record<string, StockList>, status?: number) {
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

function mountList() {
  let list!: ReturnType<typeof useStockList>;
  const wrapper = mount(
    defineComponent({
      setup() {
        list = useStockList();
        return () => null;
      },
    }),
  );
  return { wrapper, list };
}

describe("useStockList", () => {
  let wrapper: ReturnType<typeof mountList>["wrapper"] | undefined;

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    mocks.get.mockReset();
    mocks.post.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    vi.useRealTimers();
  });

  async function start(pages: Record<string, StockList>, status?: number) {
    serve(pages, status);
    const mounted = mountList();
    wrapper = mounted.wrapper;
    await settle();
    return mounted.list;
  }

  it("has a row per variant in the order of the products and of their variants, the first one of a product marked", async () => {
    const list = await start({
      "": {
        items: [
          item("Kajak", { variants: [{ variantId: "v-green", label: "zielony", stock: null }, { variantId: "v-orange", label: "pomarańczowy", stock: null }] }),
          item("Bidon"),
        ],
        next: null,
      },
    });

    expect(list.rows.value.map((row) => [row.productId, row.name, row.variantId, row.label, row.first])).toEqual([
      ["id-Kajak", "Kajak", "v-green", "zielony", true],
      ["id-Kajak", "Kajak", "v-orange", "pomarańczowy", false],
      ["id-Bidon", "Bidon", "var-Bidon", "", true],
    ]);
  });

  it("loads the first page of the stock list on mount", async () => {
    const list = await start({ "": { items: [item("Bidon")], next: null } });

    expect(mocks.get).toHaveBeenCalledWith(STOCK, { params: { query: {} } });
    expect(list.items.value.map((row) => row.name)).toEqual(["Bidon"]);
    expect(list.loaded.value).toBe(true);
    expect(list.hasNext.value).toBe(false);
    expect(list.hasPrevious.value).toBe(false);
  });

  it("pages forward and back with the cursors of the API, keeping the cursor stack", async () => {
    const list = await start({
      "": { items: [item("A")], next: "c1" },
      c1: { items: [item("B")], next: "c2" },
      c2: { items: [item("C")], next: null },
    });

    await list.goNext();
    await list.goNext();
    expect(list.items.value.map((row) => row.name)).toEqual(["C"]);
    expect(list.pageNumber.value).toBe(3);
    expect(list.hasNext.value).toBe(false);

    await list.goPrevious();
    expect(list.items.value.map((row) => row.name)).toEqual(["B"]);
    await list.goPrevious();
    expect(list.items.value.map((row) => row.name)).toEqual(["A"]);
    expect(queries()).toEqual([{}, { after: "c1" }, { after: "c2" }, { after: "c1" }, {}]);
    expect(list.hasPrevious.value).toBe(false);
  });

  it("does not ask for a search shorter than two characters", async () => {
    const list = await start({ "": { items: [item("A")], next: null } });

    list.search.value = "a";
    await vi.advanceTimersByTimeAsync(1000);
    await settle();

    expect(queries()).toHaveLength(1);
    expect(list.searchTooShort.value).toBe(true);
  });

  it("counts characters, not UTF-16 units", async () => {
    const list = await start({ "": { items: [item("A")], next: null } });

    list.search.value = "😀";
    await vi.advanceTimersByTimeAsync(1000);
    expect(queries()).toHaveLength(1);

    list.search.value = "😀😀";
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 100);
    await settle();
    expect(queries().at(-1)).toEqual({ q: "😀😀" });
  });

  it("searches once after the typing pauses, with the trimmed term, and goes back to the full list when cleared", async () => {
    const list = await start({ "": { items: [item("A")], next: null } });

    list.search.value = "bi";
    await vi.advanceTimersByTimeAsync(100);
    list.search.value = "  bidon ";
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 100);
    await settle();
    expect(queries()).toEqual([{}, { q: "bidon" }]);

    list.search.value = "";
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 100);
    await settle();
    expect(queries().at(-1)).toEqual({});
  });

  it("starts from the first page when the search or the category changes", async () => {
    const list = await start({
      "": { items: [item("A")], next: "c1" },
      c1: { items: [item("B")], next: null },
    });
    await list.goNext();

    list.selectCategory("c-run");
    await settle();
    expect(queries().at(-1)).toEqual({ category: "c-run" });
    expect(list.hasPrevious.value).toBe(false);

    list.search.value = "baton";
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 100);
    await settle();
    expect(queries().at(-1)).toEqual({ q: "baton", category: "c-run" });

    list.selectCategory(null);
    await settle();
    expect(queries().at(-1)).toEqual({ q: "baton" });
  });

  it("keeps the page that is shown and explains a failure, then retries", async () => {
    const list = await start({ "": { items: [item("A")], next: "c1" } });

    serve({}, 500);
    await list.goNext();
    expect(list.error.value).toContain("HTTP 500");
    expect(list.items.value.map((row) => row.name)).toEqual(["A"]);
    expect(list.pageNumber.value).toBe(1);

    serve({ c1: { items: [item("B")], next: null } });
    await list.retry();
    expect(list.error.value).toBeNull();
    expect(list.items.value.map((row) => row.name)).toEqual(["B"]);
  });

  it("tells that the chosen category no longer exists on a 404", async () => {
    const list = await start({ "": { items: [item("A")], next: null } });

    serve({}, 404);
    list.selectCategory("gone");
    await settle();

    expect(list.error.value).toContain("kategoria");
  });

  it("reports a network failure", async () => {
    const list = await start({ "": { items: [item("A")], next: null } });

    mocks.get.mockRejectedValue(new Error("offline"));
    await list.goNext().catch(() => undefined);
    list.selectCategory("c1");
    await settle();

    expect(list.error.value).toContain("połączenie");
  });

  it("ignores an older response that arrives after a newer request", async () => {
    let finishFirst!: (value: unknown) => void;
    mocks.get.mockImplementationOnce(() => new Promise((resolve) => (finishFirst = resolve)));
    mocks.get.mockImplementationOnce(async () => ({ data: { items: [item("Nowy")], next: null }, response: { status: 200 } }));
    const mounted = mountList();
    wrapper = mounted.wrapper;

    mounted.list.selectCategory("c1");
    await settle();
    finishFirst({ data: { items: [item("Stary")], next: null }, response: { status: 200 } });
    await settle();

    expect(mounted.list.items.value.map((row) => row.name)).toEqual(["Nowy"]);
  });
});

describe("useStockList: receipts and corrections", () => {
  const RECEIPTS = "/api/v1/admin/variants/{variantId}/stock/receipts";
  const CORRECTIONS = "/api/v1/admin/variants/{variantId}/stock/corrections";
  let wrapper: ReturnType<typeof mountList>["wrapper"] | undefined;

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    mocks.get.mockReset();
    mocks.post.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    vi.useRealTimers();
  });

  async function start(pages: Record<string, StockList>) {
    serve(pages);
    const mounted = mountList();
    wrapper = mounted.wrapper;
    await settle();
    return mounted.list;
  }

  function state(overrides: Partial<StockState> = {}): StockState {
    return {
      variantId: "var-A",
      physical: 15,
      reserved: 4,
      available: 11,
      level: "in_stock",
      version: 4,
      updatedAt: "2026-11-02T13:00:00+00:00",
      ...overrides,
    };
  }

  function respond(status: number, body?: unknown) {
    const ok = status < 300;
    mocks.post.mockResolvedValue({ data: ok ? body : undefined, error: ok ? undefined : body, response: { status } });
  }

  const receipt = { quantity: 5, note: "Dostawa", key: "key-1" };
  const correction = { physical: 15, reason: "inventory_count" as const, note: null, version: 3 };

  describe("receiveStock", () => {
    it("posts the quantity and note with the key as Idempotency-Key and puts the stock of the API into the row", async () => {
      const list = await start({ "": { items: [item("A")], next: null } });
      respond(201, state());

      const result = await list.receiveStock("var-A", receipt);

      expect(mocks.post).toHaveBeenCalledWith(RECEIPTS, {
        params: { path: { variantId: "var-A" }, header: { "Idempotency-Key": "key-1" } },
        body: { quantity: 5, note: "Dostawa" },
      });
      expect(result).toEqual({ status: "saved" });
      expect(list.rows.value[0]!.stock).toEqual({
        physical: 15,
        reserved: 4,
        available: 11,
        level: "in_stock",
        version: 4,
        updatedAt: "2026-11-02T13:00:00+00:00",
      });
    });

    it("takes the 200 of a repeated receipt the same way", async () => {
      const list = await start({ "": { items: [item("A")], next: null } });
      respond(200, state({ physical: 15 }));

      expect(await list.receiveStock("var-A", receipt)).toEqual({ status: "saved" });
      expect(list.rows.value[0]!.stock?.physical).toBe(15);
    });

    it("gives a row without a stock record the stock the API created", async () => {
      const list = await start({ "": { items: [item("A", { stock: null })], next: null } });
      respond(201, state({ physical: 5, reserved: 0, available: 5, version: 1 }));

      await list.receiveStock("var-A", receipt);

      expect(list.rows.value[0]!.stock).toMatchObject({ physical: 5, available: 5, version: 1 });
    });

    it("does not let a list answer that started before the receipt bring back the old stock", async () => {
      const list = await start({ "": { items: [item("A")], next: "c1" }, c1: { items: [item("A")], next: null } });
      let answerList!: (value: unknown) => void;
      mocks.get.mockImplementationOnce(() => new Promise((done) => (answerList = done)));
      const reload = list.goNext();
      respond(201, state());

      await list.receiveStock("var-A", receipt);
      answerList({ data: { items: [item("A")], next: null }, response: { status: 200 } });
      await reload;

      expect(list.rows.value[0]!.stock).toMatchObject({ physical: 15, version: 4 });
    });

    it("takes a newer stock from a list answer over the receipt's", async () => {
      const list = await start({ "": { items: [item("A")], next: "c1" }, c1: { items: [item("A")], next: null } });
      let answerList!: (value: unknown) => void;
      mocks.get.mockImplementationOnce(() => new Promise((done) => (answerList = done)));
      const reload = list.goNext();
      respond(201, state());
      await list.receiveStock("var-A", receipt);

      answerList({
        data: { items: [item("A", { stock: { physical: 20, reserved: 4, available: 16, level: "in_stock", version: 5, updatedAt: "2026-11-02T14:00:00+00:00" } })], next: null },
        response: { status: 200 },
      });
      await reload;

      expect(list.rows.value[0]!.stock).toMatchObject({ physical: 20, version: 5 });
    });

    it("sends a blank note as null", async () => {
      const list = await start({ "": { items: [item("A")], next: null } });
      respond(201, state());

      await list.receiveStock("var-A", { ...receipt, note: "   " });

      expect(mocks.post.mock.calls[0]![1].body).toEqual({ quantity: 5, note: null });
    });

    it("shows the violations of a 422 at their fields", async () => {
      const list = await start({ "": { items: [item("A")], next: null } });
      respond(422, {
        status: 422,
        violations: [
          { propertyPath: "quantity", title: "Ilość musi wynosić od 1 do 100 000." },
          { propertyPath: "note", title: "Notatka jest za długa." },
        ],
      });

      expect(await list.receiveStock("var-A", receipt)).toEqual({
        status: "invalid",
        fields: { quantity: "Ilość musi wynosić od 1 do 100 000.", note: "Notatka jest za długa." },
        message: null,
      });
      expect(list.rows.value[0]!.stock?.physical).toBe(10);
    });

    it("keeps a violation of an unknown path as a message of the dialog", async () => {
      const list = await start({ "": { items: [item("A")], next: null } });
      respond(422, { status: 422, violations: [{ propertyPath: "Idempotency-Key", title: "Klucz jest nieprawidłowy." }] });

      expect(await list.receiveStock("var-A", receipt)).toEqual({ status: "invalid", fields: {}, message: "Klucz jest nieprawidłowy." });
    });

    it("shows the detail of a 422 without violations as the dialog's message", async () => {
      const list = await start({ "": { items: [item("A")], next: null } });
      respond(422, { status: 422, detail: "Stan fizyczny nie może przekroczyć 1 000 000." });

      expect(await list.receiveStock("var-A", receipt)).toEqual({
        status: "invalid",
        fields: {},
        message: "Stan fizyczny nie może przekroczyć 1 000 000.",
      });
    });

    it("shows the detail of a 409 about the key and reloads the page, so a receipt whose answer was lost shows in the row", async () => {
      const list = await start({ "": { items: [item("A")], next: "c1" }, c1: { items: [item("A")], next: null } });
      await list.goNext();
      respond(409, { status: 409, detail: "Ten klucz został użyty dla innego przyjęcia." });
      serve({ c1: { items: [item("A", { stock: { physical: 15, reserved: 4, available: 11, level: "in_stock", version: 4, updatedAt: "2026-11-02T13:00:00+00:00" } })], next: null } });

      expect(await list.receiveStock("var-A", receipt)).toEqual({ status: "failed", message: "Ten klucz został użyty dla innego przyjęcia." });
      expect(queries().at(-1)).toEqual({ after: "c1" });
      expect(list.rows.value[0]!.stock).toMatchObject({ physical: 15, version: 4 });
    });

    it("shows the detail of a 409 about the key also when the reload fails", async () => {
      const list = await start({ "": { items: [item("A")], next: null } });
      respond(409, { status: 409, detail: "Ten klucz został użyty dla innego przyjęcia." });
      serve({}, 500);

      expect(await list.receiveStock("var-A", receipt)).toEqual({ status: "failed", message: "Ten klucz został użyty dla innego przyjęcia." });
    });

    it("says the staff member may not receive stock on a 403", async () => {
      const list = await start({ "": { items: [item("A")], next: null } });
      respond(403, { status: 403 });

      const result = await list.receiveStock("var-A", receipt);

      expect(result).toMatchObject({ status: "failed" });
      expect((result as { message: string }).message).toContain("Nie masz uprawnień");
    });

    it("says the product is gone on a 404", async () => {
      const list = await start({ "": { items: [item("A")], next: null } });
      respond(404, { status: 404 });

      expect(await list.receiveStock("var-A", receipt)).toEqual({ status: "failed", message: "Ten wariant już nie istnieje." });
    });

    it("says so when the request itself fails", async () => {
      const list = await start({ "": { items: [item("A")], next: null } });
      mocks.post.mockRejectedValue(new TypeError("network down"));

      const result = await list.receiveStock("var-A", receipt);

      expect(result).toMatchObject({ status: "failed" });
      expect((result as { message: string }).message).toContain("połączenie");
    });
  });

  describe("correctStock", () => {
    it("posts the count, reason, note and the version of the row and puts the stock of the API into the row", async () => {
      const list = await start({ "": { items: [item("A")], next: null } });
      respond(200, state({ physical: 15 }));

      const result = await list.correctStock("var-A", { ...correction, reason: "other", note: "Policzone ręcznie" });

      expect(mocks.post).toHaveBeenCalledWith(CORRECTIONS, {
        params: { path: { variantId: "var-A" } },
        body: { physical: 15, reason: "other", note: "Policzone ręcznie", version: 3 },
      });
      expect(result).toEqual({ status: "saved" });
      expect(list.rows.value[0]!.stock).toMatchObject({ physical: 15, version: 4 });
    });

    it("sends version null for a product without a stock record", async () => {
      const list = await start({ "": { items: [item("A", { stock: null })], next: null } });
      respond(200, state({ physical: 7, reserved: 0, available: 7, version: 1 }));

      await list.correctStock("var-A", { ...correction, physical: 7, version: null });

      expect(mocks.post.mock.calls[0]![1].body).toMatchObject({ physical: 7, version: null });
      expect(list.rows.value[0]!.stock).toMatchObject({ physical: 7, version: 1 });
    });

    it("leaves a row without a stock record without one when the API still has none (counted zero)", async () => {
      const list = await start({ "": { items: [item("A", { stock: null })], next: null } });
      respond(200, state({ physical: 0, reserved: 0, available: 0, level: "out_of_stock", version: null, updatedAt: null }));

      expect(await list.correctStock("var-A", { ...correction, physical: 0, version: null })).toEqual({ status: "saved" });
      expect(list.rows.value[0]!.stock).toBeNull();
    });

    it("on a 409 reloads the page and, when the row's version changed, reports a version conflict", async () => {
      const list = await start({ "": { items: [item("A")], next: "c1" }, c1: { items: [item("A")], next: null } });
      await list.goNext();
      respond(409, { type: "about:blank", status: 409, detail: "The stock was changed." });
      serve({ c1: { items: [item("A", { stock: { physical: 12, reserved: 4, available: 8, level: "in_stock", version: 4, updatedAt: "2026-11-02T13:00:00+00:00" } })], next: null } });

      const result = await list.correctStock("var-A", correction);

      expect(result).toEqual({ status: "conflict", message: "Stan został w międzyczasie zmieniony – odśwież" });
      expect(queries().at(-1)).toEqual({ after: "c1" });
      expect(list.rows.value[0]!.stock).toMatchObject({ physical: 12, version: 4 });
    });

    it("on a 409 reports a version conflict when a row without a record got one meanwhile", async () => {
      const list = await start({ "": { items: [item("A", { stock: null })], next: null } });
      respond(409, { status: 409, detail: "x" });
      serve({ "": { items: [item("A")], next: null } });

      expect(await list.correctStock("var-A", { ...correction, version: null })).toMatchObject({ status: "conflict" });
    });

    it("on a 409 with the version unchanged shows the detail of the API (below the reserved units)", async () => {
      const list = await start({ "": { items: [item("A")], next: null } });
      respond(409, { status: 409, detail: "Nie można ustawić stanu poniżej zarezerwowanych: 4 szt." });

      const result = await list.correctStock("var-A", { ...correction, physical: 2 });

      expect(result).toEqual({ status: "failed", message: "Nie można ustawić stanu poniżej zarezerwowanych: 4 szt." });
      expect(mocks.get).toHaveBeenCalledTimes(2);
    });

    it("on a 409 shows the detail when the reload fails", async () => {
      const list = await start({ "": { items: [item("A")], next: null } });
      respond(409, { status: 409, detail: "Detail of the API." });
      serve({}, 500);

      expect(await list.correctStock("var-A", correction)).toEqual({ status: "failed", message: "Detail of the API." });
    });

    it("on a 409 without a detail still says the correction was rejected", async () => {
      const list = await start({ "": { items: [item("A")], next: null } });
      respond(409, undefined);

      expect(await list.correctStock("var-A", correction)).toEqual({ status: "failed", message: "API odrzuciło korektę (HTTP 409)." });
    });

    it("shows the violations of a 422 at their fields", async () => {
      const list = await start({ "": { items: [item("A")], next: null } });
      respond(422, {
        status: 422,
        violations: [
          { propertyPath: "physical", title: "Stan musi wynosić od 0 do 1 000 000." },
          { propertyPath: "reason", title: "Nieznany powód." },
          { propertyPath: "note", title: "Podaj notatkę." },
        ],
      });

      expect(await list.correctStock("var-A", correction)).toEqual({
        status: "invalid",
        fields: { physical: "Stan musi wynosić od 0 do 1 000 000.", reason: "Nieznany powód.", note: "Podaj notatkę." },
        message: null,
      });
    });

    it("says the staff member may not correct stock on a 403", async () => {
      const list = await start({ "": { items: [item("A")], next: null } });
      respond(403, { status: 403 });

      const result = await list.correctStock("var-A", correction);

      expect(result).toMatchObject({ status: "failed" });
      expect((result as { message: string }).message).toContain("Nie masz uprawnień");
    });
  });
});
