import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";
import type { ProductSort } from "@/composables/listSort";
import type { PageRequest, PageResult } from "@/composables/useCursorPager";

export type PriceListItem = components["schemas"]["PriceListItemResponse"];
export type ListedPrice = components["schemas"]["ListedPriceResponse"];

/**
 * The query of the price list: a part of the name (null: all), a category (null: all), the number of products per page
 * and the order (the default, by name, when it is missing).
 */
export interface PriceQuery {
  q: string | null;
  category: string | null;
  perPage: number;
  sort?: ProductSort;
}

/**
 * A row of the price list: one variant with the product it belongs to. `first` marks the first variant of a product.
 */
export interface PriceRow {
  productId: string;
  name: string;
  taxClass: string;
  applicableVatRate: number;
  variantId: string;
  /** The values of the variant, empty for the default variant. */
  label: string;
  price: ListedPrice | null;
  first: boolean;
}

export const FORBIDDEN_MESSAGE = "Nie masz dostępu do cen.";

function failureMessage(status: number): string {
  switch (status) {
    case 403:
      return FORBIDDEN_MESSAGE;
    case 404:
      return "Wybrana kategoria już nie istnieje. Wybierz inną.";
    case 400:
      return `Nieprawidłowe parametry wyszukiwania (HTTP ${status}).`;
    default:
      return `Nie udało się pobrać cen (HTTP ${status}).`;
  }
}

/**
 * One page of the price list (`GET /api/v1/admin/prices`): products, each with all its variants, `perPage` counting
 * products. The page size is always sent: the default of the API is 25, the lists of the panel start at 10. It is the
 * page source of `useCursorPager` for the price list and for the choice of variants of a promotion.
 */
export async function fetchPricePage({ query, after, signal }: PageRequest<PriceQuery>): Promise<PageResult<PriceListItem>> {
  const apiQuery: { q?: string; category?: string; sort?: ProductSort; after?: string; perPage: number } = { perPage: query.perPage };
  if (query.q !== null) {
    apiQuery.q = query.q;
  }
  if (query.category !== null) {
    apiQuery.category = query.category;
  }
  if (query.sort !== undefined && query.sort !== "name") {
    apiQuery.sort = query.sort;
  }
  if (after) {
    apiQuery.after = after;
  }
  try {
    const result = await getApiClient().GET("/api/v1/admin/prices", { params: { query: apiQuery }, signal });
    if (result.data) {
      return { items: result.data.items, next: result.data.next, total: result.data.total };
    }
    return { error: failureMessage(result.response.status), forbidden: result.response.status === 403 };
  } catch {
    return { error: "Nie udało się pobrać cen. Sprawdź połączenie z API." };
  }
}

/** The rows of a page: a row per variant, in the order of the products and of their variants. */
export function priceRows(items: PriceListItem[]): PriceRow[] {
  return items.flatMap((item) =>
    item.variants.map((variant, index) => ({
      productId: item.productId,
      name: item.name,
      taxClass: item.taxClass,
      applicableVatRate: item.applicableVatRate,
      variantId: variant.variantId,
      label: variant.label,
      price: variant.price,
      first: index === 0,
    })),
  );
}
