import { getApiClient } from "@/api/client";
import type { components, operations } from "@/api/schema";
import type { ProductSort } from "@/composables/listSort";
import type { PageRequest, PageResult } from "@/composables/useCursorPager";

export type StockListItem = components["schemas"]["StockListItemResponse"];
export type ListedStock = components["schemas"]["ListedStockResponse"];
/** The levels the list is filtered by: the products with a variant out of stock or with a low stock. */
export type StockLevelFilter = NonNullable<NonNullable<operations["listStock"]["parameters"]["query"]>["level"]>;

/**
 * The query of the stock list: a part of the name (null: all), a category (null: all), the number of products per page
 * and the order (the default, by name, when it is missing). A level (null: all) excludes the name, the category and the
 * order: the API refuses them together, so only the level is sent.
 */
export interface StockQuery {
  q: string | null;
  category: string | null;
  perPage: number;
  sort?: ProductSort;
  level?: StockLevelFilter | null;
}

/**
 * A row of the stock list: one variant with the product it belongs to. `first` marks the first variant of a product,
 * the row that the list puts under the row of its product.
 */
export interface StockRow {
  productId: string;
  name: string;
  variantId: string;
  /** The values of the variant, empty for the default variant. */
  label: string;
  stock: ListedStock | null;
  first: boolean;
  /** The stock of the variant is at the level the list is filtered by (the API's `matchesLevel`). */
  matchesLevel: boolean;
}

export const FORBIDDEN_MESSAGE = "Nie masz dostępu do stanów magazynowych.";

function failureMessage(status: number): string {
  switch (status) {
    case 403:
      return FORBIDDEN_MESSAGE;
    case 404:
      return "Wybrana kategoria już nie istnieje. Wybierz inną.";
    case 400:
      return `Nieprawidłowe parametry wyszukiwania (HTTP ${status}).`;
    default:
      return `Nie udało się pobrać stanów magazynowych (HTTP ${status}).`;
  }
}

/**
 * One page of the stock list (`GET /api/v1/admin/stock`): products, each with all its variants, `perPage` counting
 * products. The page size is always sent: the default of the API is 25, the lists of the panel start at 10. It is the
 * page source of `useCursorPager` for the stock list.
 */
export async function fetchStockPage({ query, after, signal }: PageRequest<StockQuery>): Promise<PageResult<StockListItem>> {
  const apiQuery: { q?: string; category?: string; sort?: ProductSort; level?: StockLevelFilter; after?: string; perPage: number } = { perPage: query.perPage };
  if (query.level) {
    apiQuery.level = query.level;
  } else {
    if (query.q !== null) {
      apiQuery.q = query.q;
    }
    if (query.category !== null) {
      apiQuery.category = query.category;
    }
    if (query.sort !== undefined && query.sort !== "name") {
      apiQuery.sort = query.sort;
    }
  }
  if (after) {
    apiQuery.after = after;
  }
  try {
    const result = await getApiClient().GET("/api/v1/admin/stock", { params: { query: apiQuery }, signal });
    if (result.data) {
      return { items: result.data.items, next: result.data.next, total: result.data.total };
    }
    return { error: failureMessage(result.response.status), forbidden: result.response.status === 403 };
  } catch {
    return { error: "Nie udało się pobrać stanów magazynowych. Sprawdź połączenie z API." };
  }
}

/** The rows of a page: a row per variant, in the order of the products and of their variants. */
export function stockRows(items: StockListItem[]): StockRow[] {
  return items.flatMap((item) =>
    item.variants.map((variant, index) => ({
      productId: item.productId,
      name: item.name,
      variantId: variant.variantId,
      label: variant.label,
      stock: variant.stock,
      first: index === 0,
      matchesLevel: variant.matchesLevel,
    })),
  );
}
