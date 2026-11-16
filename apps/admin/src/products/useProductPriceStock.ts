import { computed, onScopeDispose, ref, shallowRef } from "vue";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";
import { type CorrectStockRequest, requestCorrection, requestReceipt, type StockChange } from "@/inventory/stockChange";
import { type PriceChange, requestPriceChange } from "@/prices/priceChange";

type ListedPrice = components["schemas"]["ListedPriceResponse"];
type ListedStock = components["schemas"]["ListedStockResponse"];

/** A saved variant of the product with its price and its stock; both are `null` when the variant has none. */
export interface PriceStockRow {
  productId: string;
  name: string;
  taxClass: string;
  applicableVatRate: number;
  variantId: string;
  /** The values of the variant, empty for the default variant. */
  label: string;
  price: ListedPrice | null;
  stock: ListedStock | null;
}

export const FORBIDDEN_MESSAGE = "Nie masz dostępu do cen i stanów magazynowych.";
const FAILED_MESSAGE = "Nie udało się pobrać cen i stanów. Sprawdź połączenie z API.";

function failureMessage(status: number): string {
  return status === 403 ? FORBIDDEN_MESSAGE : `Nie udało się pobrać cen i stanów (HTTP ${status}).`;
}

/**
 * The prices and stocks of the saved variants of one product, for the card „Ceny i stany” of the product form: one
 * request for `GET /prices?productId=` and one for `GET /stock?productId=`, read together and joined by the variant id.
 * `load` reads both again (the form calls it after saving the product, which can add, relabel or remove variants);
 * only the newest request counts, and a failed one keeps the rows already shown. The price and the stock the API answers
 * a change with are overlays over the rows read (not written to them): a row shows one while the read one is older (a
 * smaller version) or missing, so neither a request that is still running nor a late answer brings the old value back.
 * The panel computes nothing: every amount, number and level is the API's.
 */
export function useProductPriceStock(productId: () => string) {
  const read = shallowRef<PriceStockRow[]>([]);
  const loading = ref(false);
  const loaded = ref(false);
  const error = ref<string | null>(null);
  const forbidden = ref(false);
  const savedPrices = shallowRef(new Map<string, ListedPrice>());
  const savedStocks = shallowRef(new Map<string, ListedStock>());
  let request = 0;
  let disposed = false;
  onScopeDispose(() => {
    disposed = true;
  });

  const rows = computed<PriceStockRow[]>(() =>
    read.value.map((row) => {
      const price = savedPrices.value.get(row.variantId);
      const stock = savedStocks.value.get(row.variantId);
      return {
        ...row,
        price: price && (row.price === null || price.version > row.price.version) ? price : row.price,
        stock: stock && (row.stock === null || stock.version > row.stock.version) ? stock : row.stock,
      };
    }),
  );

  async function load(): Promise<void> {
    const mine = ++request;
    const id = productId();
    loading.value = true;
    try {
      const [prices, stocks] = await Promise.all([
        getApiClient().GET("/api/v1/admin/prices", { params: { query: { productId: id } } }),
        getApiClient().GET("/api/v1/admin/stock", { params: { query: { productId: id } } }),
      ]);
      if (disposed || mine !== request) {
        return;
      }
      if (!prices.data || !stocks.data) {
        const status = !prices.data ? prices.response.status : stocks.response.status;
        error.value = failureMessage(status);
        forbidden.value = status === 403;
        return;
      }
      const stockOf = new Map<string, ListedStock | null>();
      for (const item of stocks.data.items) {
        for (const variant of item.variants) {
          stockOf.set(variant.variantId, variant.stock);
        }
      }
      read.value = prices.data.items
        .filter((item) => item.productId === id)
        .flatMap((item) =>
          item.variants.map((variant) => ({
            productId: item.productId,
            name: item.name,
            taxClass: item.taxClass,
            applicableVatRate: item.applicableVatRate,
            variantId: variant.variantId,
            label: variant.label,
            price: variant.price,
            stock: stockOf.get(variant.variantId) ?? null,
          })),
        );
      error.value = null;
      forbidden.value = false;
      loaded.value = true;
    } catch {
      if (!disposed && mine === request) {
        error.value = FAILED_MESSAGE;
        forbidden.value = false;
      }
    } finally {
      if (!disposed && mine === request) {
        loading.value = false;
      }
    }
  }

  function changePrice(variantId: string, gross: number, version: number | null): Promise<PriceChange> {
    return requestPriceChange(variantId, gross, version, {
      save: (id, price) => {
        savedPrices.value = new Map(savedPrices.value).set(id, price);
      },
      refresh: load,
    });
  }

  function saveStock(variantId: string, stock: ListedStock): void {
    savedStocks.value = new Map(savedStocks.value).set(variantId, stock);
  }

  function receiveStock(variantId: string, input: { quantity: number; note: string | null; key: string }): Promise<StockChange> {
    return requestReceipt(variantId, input, { save: saveStock, refresh: load });
  }

  function correctStock(
    variantId: string,
    input: { physical: number; reason: CorrectStockRequest["reason"]; note: string | null; version: number | null },
  ): Promise<StockChange> {
    return requestCorrection(variantId, input, {
      save: saveStock,
      // A failed reload keeps the old rows, which would look unchanged: the error says it failed.
      reread: async () => {
        await load();
        const row = rows.value.find((candidate) => candidate.variantId === variantId);
        return error.value !== null || !row ? null : { version: row.stock?.version ?? null };
      },
    });
  }

  return { rows, loading, loaded, error, forbidden, load, changePrice, receiveStock, correctStock };
}
