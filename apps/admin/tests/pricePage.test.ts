import { beforeEach, describe, expect, it, vi } from "vitest";
import type { components } from "@/api/schema";
import { fetchPricePage, priceRows } from "@/prices/pricePage";

type PriceListItem = components["schemas"]["PriceListItemResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

const PRICES = "/api/v1/admin/prices";

const price = { gross: 5490, net: 5229, vat: 261, vatRate: 500, currency: "PLN", version: 1, updatedAt: "2026-11-01T12:30:00+00:00", vatRateOutdated: false };

function item(name: string, variants: PriceListItem["variants"]): PriceListItem {
  return { productId: `id-${name}`, name, taxClass: "printed_book", applicableVatRate: 500, variants };
}

describe("fetchPricePage", () => {
  beforeEach(() => {
    mocks.get.mockReset();
  });

  const queryOfCall = () => (mocks.get.mock.calls[0]![1] as { params: { query: Record<string, unknown> } }).params.query;

  it("always sends the page size, and the search, the category and the cursor only when they are set", async () => {
    mocks.get.mockResolvedValue({ data: { items: [], next: null }, response: { status: 200 } });

    await fetchPricePage({ query: { q: null, category: null, perPage: 10 }, after: null });
    expect(mocks.get).toHaveBeenCalledWith(PRICES, { params: { query: { perPage: 10 } } });

    mocks.get.mockClear();
    await fetchPricePage({ query: { q: "hantle", category: "c-1", perPage: 25 }, after: "cursor" });
    expect(queryOfCall()).toEqual({ q: "hantle", category: "c-1", perPage: 25, after: "cursor" });
  });

  it("returns the products and the cursor of the next page", async () => {
    const items = [item("A", [{ variantId: "v", label: "", price }])];
    mocks.get.mockResolvedValue({ data: { items, next: "c1" }, response: { status: 200 } });

    expect(await fetchPricePage({ query: { q: null, category: null, perPage: 10 }, after: null })).toEqual({ items, next: "c1" });
  });

  it.each([
    [403, "Nie masz dostępu do cen.", true],
    [404, "Wybrana kategoria już nie istnieje. Wybierz inną.", false],
    [400, "Nieprawidłowe parametry wyszukiwania (HTTP 400).", false],
    [500, "Nie udało się pobrać cen (HTTP 500).", false],
  ])("answers a %i with its message, forbidden only for a 403", async (status, message, forbidden) => {
    mocks.get.mockResolvedValue({ data: undefined, response: { status } });

    const result = await fetchPricePage({ query: { q: null, category: null, perPage: 10 }, after: null });

    expect(result).toEqual({ error: message, forbidden });
  });

  it("says the connection failed when the request itself fails", async () => {
    mocks.get.mockRejectedValue(new TypeError("network down"));

    expect(await fetchPricePage({ query: { q: null, category: null, perPage: 10 }, after: null })).toEqual({
      error: "Nie udało się pobrać cen. Sprawdź połączenie z API.",
    });
  });
});

describe("priceRows", () => {
  it("makes a row per variant in the order of the products and of their variants, the first of a product marked", () => {
    const rows = priceRows([
      item("Buty", [
        { variantId: "v-40", label: "czarne, 40", price },
        { variantId: "v-41", label: "czarne, 41", price: null },
      ]),
      item("Hantle", [{ variantId: "v-h", label: "", price }]),
    ]);

    expect(rows.map((row) => [row.productId, row.name, row.variantId, row.label, row.first])).toEqual([
      ["id-Buty", "Buty", "v-40", "czarne, 40", true],
      ["id-Buty", "Buty", "v-41", "czarne, 41", false],
      ["id-Hantle", "Hantle", "v-h", "", true],
    ]);
    expect(rows[0]).toMatchObject({ taxClass: "printed_book", applicableVatRate: 500, price });
    expect(rows[1]!.price).toBeNull();
  });

  it("gives no rows for no products, and none for a product without variants", () => {
    expect(priceRows([])).toEqual([]);
    expect(priceRows([item("Pusty", [])])).toEqual([]);
  });
});
