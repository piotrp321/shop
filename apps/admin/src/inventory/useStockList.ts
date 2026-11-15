import { computed, shallowRef, watch } from "vue";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";
import { useCursorPager } from "@/composables/useCursorPager";
import { type ListParam, useListUrlState } from "@/composables/useListUrlState";
import { MAX_NAME_SEARCH_LENGTH, NAME_SEARCH_DEBOUNCE_MS, useNameSearch, validNameTerm } from "@/composables/useNameSearch";
import type { CategoryLookup } from "@/products/useProductList";
import { fetchStockPage, type ListedStock, type StockListItem, stockRows } from "./stockPage";

export type { StockListItem, StockRow } from "./stockPage";

type StockState = components["schemas"]["StockStateResponse"];

type ReceiveStockRequest = components["schemas"]["ReceiveStockRequest"];
type CorrectStockRequest = components["schemas"]["CorrectStockRequest"];

/** The fields of the receipt and correction dialogs that a 422 of the API can point at. */
export type ChangeField = "quantity" | "physical" | "reason" | "note";

/** How a receipt or a correction ended; the messages are for the staff member. */
export type StockChange =
  | { status: "saved" }
  | { status: "invalid"; fields: Partial<Record<ChangeField, string>>; message: string | null }
  | { status: "conflict"; message: string }
  | { status: "failed"; message: string };

export const CONFLICT_MESSAGE = "Stan został w międzyczasie zmieniony – odśwież";

const CHANGE_FIELDS: readonly string[] = ["quantity", "physical", "reason", "note"];

/** The `detail` of a problem details body, if it has a non-empty one. */
function problemDetail(body: unknown): string | null {
  const detail = typeof body === "object" && body !== null ? (body as { detail?: unknown }).detail : undefined;
  return typeof detail === "string" && detail !== "" ? detail : null;
}

/**
 * A 422 of the API: each violation goes to the field its `propertyPath` names; a violation of any other path and
 * the `detail` of a 422 without violations (the stock would exceed 1 000 000) are the dialog's message.
 */
function invalidChange(body: unknown): StockChange {
  const fields: Partial<Record<ChangeField, string[]>> = {};
  const rest: string[] = [];
  const violations = typeof body === "object" && body !== null ? (body as { violations?: unknown }).violations : undefined;
  if (Array.isArray(violations)) {
    for (const violation of violations) {
      const { propertyPath, title } = (violation ?? {}) as { propertyPath?: unknown; title?: unknown };
      if (typeof title !== "string" || title === "") {
        continue;
      }
      if (typeof propertyPath === "string" && CHANGE_FIELDS.includes(propertyPath)) {
        (fields[propertyPath as ChangeField] ??= []).push(title);
      } else {
        rest.push(title);
      }
    }
  }
  const joined = Object.fromEntries(Object.entries(fields).map(([field, titles]) => [field, titles.join(" ")]));
  const hasViolations = Object.keys(joined).length > 0 || rest.length > 0;
  const message = hasViolations ? (rest.length > 0 ? rest.join(" ") : null) : (problemDetail(body) ?? "API odrzuciło zmianę stanu (HTTP 422).");
  return { status: "invalid", fields: joined, message };
}

/** The rules of the search by name are in `useNameSearch`. */
export const MAX_SEARCH_LENGTH = MAX_NAME_SEARCH_LENGTH;
export const SEARCH_DEBOUNCE_MS = NAME_SEARCH_DEBOUNCE_MS;

/** The page sizes of the list, in products; the default of the list is 10 (the API's own default is 25, so the size is always sent). */
export const PER_PAGE_OPTIONS = [10, 25, 50];
export const DEFAULT_PER_PAGE = 10;

export const EMPTY_MESSAGE = "Nie ma jeszcze produktów z wariantami.";

/**
 * The stock list: a search by name, a category filter and a page size (10, 25 or 50 products). The state lives in the
 * address (`useListUrlState`: `q`, `category`, `perPage`) and the paging is `useCursorPager` over products, each with
 * all its variants, so „Pokazano produkty X–Y” counts products; `rows` has a row per variant (`stockRows`), in the order
 * of the products and of their variants. The request is `fetchStockPage`, the search by name `useNameSearch`.
 * `categories` is the tree of the filter, as in `useProductList`: a category of the address that it does not have is skipped.
 * Every number and level in a row is the API's; the panel only shows them.
 *
 * `receiveStock` and `correctStock` send a change of one variant. The stock the API answers with is an overlay over the
 * items of the pager (`savedStocks`, not written to them): a row shows it while the stock of the list is older (a smaller
 * version) or missing, so neither a request that is still running nor a late answer of the list brings the old stock
 * back (the panel computes nothing). The overlay is never cleared.
 */
export function useStockList(categories?: CategoryLookup) {
  const known = computed(() => {
    const ids = new Set<string>();
    const walk = (level: CategoryLookup["nodes"]["value"]) => {
      for (const node of level) {
        ids.add(node.key);
        walk(node.children);
      }
    };
    walk(categories?.nodes.value ?? []);
    return ids;
  });

  const url = useListUrlState({
    q: { parse: validNameTerm, fallback: null } satisfies ListParam<string, null>,
    category: {
      parse: (raw) => raw,
      fallback: null,
      confirm: categories ? (id) => (categories.loaded.value ? known.value.has(id) : undefined) : undefined,
    } satisfies ListParam<string, null>,
    perPage: {
      parse: (raw) => PER_PAGE_OPTIONS.find((size) => String(size) === raw) ?? null,
      fallback: DEFAULT_PER_PAGE,
    } satisfies ListParam<number, number>,
  });

  const name = useNameSearch({ initial: url.address.value.q, apply: (term) => url.set({ q: term }) });
  const pager = useCursorPager(fetchStockPage, url.query, { ready: url.ready });

  // The address changed from outside: it is the new truth, also for what is in the field.
  watch(url.address, (address) => name.setFromAddress(address.q));

  // The stocks saved in this view, by variant. Never cleared: a stock of the list with a version that is not older wins.
  const savedStocks = shallowRef(new Map<string, ListedStock>());

  const items = computed<StockListItem[]>(() =>
    savedStocks.value.size === 0
      ? pager.items.value
      : pager.items.value.map((item) => ({
          ...item,
          variants: item.variants.map((variant) => {
            const saved = savedStocks.value.get(variant.variantId);
            return saved && (variant.stock === null || saved.version > variant.stock.version) ? { ...variant, stock: saved } : variant;
          }),
        })),
  );
  const rows = computed(() => stockRows(items.value));

  const filtered = computed(() => url.query.value.q !== null || url.query.value.category !== null);

  function selectCategory(id: string | null): void {
    url.set({ category: id });
  }

  function setPerPage(size: number): void {
    url.set({ perPage: size });
  }

  /** Drops the search, the category and the page size: the address is `/inventory` again. */
  function clearFilters(): void {
    name.reset();
    url.clear();
  }

  /** The row's stock from the answer of the API; a variant still without a record (version null) has none. */
  function rowStock(state: StockState): ListedStock | null {
    if (state.version === null || state.updatedAt === null) {
      return null;
    }
    const { physical, reserved, available, level, version, updatedAt } = state;
    return { physical, reserved, available, level, version, updatedAt };
  }

  function saveStock(variantId: string, state: StockState): void {
    const stock = rowStock(state);
    if (stock) {
      savedStocks.value = new Map(savedStocks.value).set(variantId, stock);
    }
  }

  function blankToNull(note: string | null): string | null {
    return note === null || note.trim() === "" ? null : note;
  }

  function changeFailure(status: number, body: unknown, fallback: string): StockChange {
    switch (status) {
      case 422:
        return invalidChange(body);
      case 403:
        return { status: "failed", message: "Nie masz uprawnień do zmiany stanów magazynowych." };
      case 404:
        return { status: "failed", message: "Ten wariant już nie istnieje." };
      case 409:
        return { status: "failed", message: problemDetail(body) ?? `${fallback} (HTTP ${status}).` };
      default:
        return { status: "failed", message: `Nie udało się zapisać zmiany stanu (HTTP ${status}).` };
    }
  }

  /**
   * A receipt. `key` is the idempotency key of the dialog: the caller sends the same one when it repeats the same
   * receipt, so a retry after a lost answer adds the units once.
   */
  async function receiveStock(variantId: string, input: { quantity: number; note: string | null; key: string }): Promise<StockChange> {
    const body: ReceiveStockRequest = { quantity: input.quantity, note: blankToNull(input.note) };
    try {
      const result = await getApiClient().POST("/api/v1/admin/variants/{variantId}/stock/receipts", {
        params: { path: { variantId }, header: { "Idempotency-Key": input.key } },
        body,
      });
      if (result.data) {
        saveStock(variantId, result.data);
        return { status: "saved" };
      }
      // A 409 here is a reused key; the API says so in `detail`. The first request with this key may have been saved
      // although its answer was lost, so the page is reloaded to show the row as it is now (and not to receive twice).
      if (result.response.status === 409) {
        await pager.refresh();
      }
      return changeFailure(result.response.status, result.error, "API odrzuciło przyjęcie");
    } catch {
      return { status: "failed", message: "Nie udało się zapisać przyjęcia. Sprawdź połączenie z API." };
    }
  }

  /**
   * A correction based on the `version` the row shows (null: no stock record). The API answers both of its 409s with
   * `type: about:blank` and tells them apart only by `detail` (one in English), so the panel does not read the text. On a
   * 409 it reloads the page shown and decides by the row: when the row then has another version than the one sent, the
   * stock changed meanwhile (a version conflict, the row is now current); when the version is the same, the correction
   * was refused because it is below the reserved units and the `detail` of the API (in Polish) is shown. When the row
   * cannot be reread (the reload failed or the row is no longer on the page) the reason is not known, so it is the same
   * Polish „reload” conflict, never the `detail`.
   */
  async function correctStock(
    variantId: string,
    input: { physical: number; reason: CorrectStockRequest["reason"]; note: string | null; version: number | null },
  ): Promise<StockChange> {
    const body: CorrectStockRequest = {
      physical: input.physical,
      reason: input.reason,
      note: blankToNull(input.note),
      version: input.version,
    };
    try {
      const result = await getApiClient().POST("/api/v1/admin/variants/{variantId}/stock/corrections", {
        params: { path: { variantId } },
        body,
      });
      if (result.data) {
        saveStock(variantId, result.data);
        return { status: "saved" };
      }
      if (result.response.status === 409) {
        await pager.refresh();
        const row = rows.value.find((candidate) => candidate.variantId === variantId);
        // A failed reload keeps the old page, so its row would look unchanged: the error of the pager says it failed.
        if (pager.error.value !== null || !row || (row.stock?.version ?? null) !== input.version) {
          return { status: "conflict", message: CONFLICT_MESSAGE };
        }
      }
      return changeFailure(result.response.status, result.error, "API odrzuciło korektę");
    } catch {
      return { status: "failed", message: "Nie udało się zapisać korekty. Sprawdź połączenie z API." };
    }
  }

  return {
    search: name.search,
    searchTooShort: name.tooShort,
    searchHint: name.hint,
    query: url.query,
    filtered,
    items,
    rows,
    loading: pager.loading,
    loaded: pager.loaded,
    error: pager.error,
    forbidden: pager.forbidden,
    hasPrevious: pager.hasPrevious,
    hasNext: pager.hasNext,
    pageNumber: pager.pageNumber,
    range: pager.range,
    selectCategory,
    setPerPage,
    clearFilters,
    goNext: pager.goNext,
    goPrevious: pager.goPrevious,
    retry: pager.retry,
    receiveStock,
    correctStock,
  };
}
