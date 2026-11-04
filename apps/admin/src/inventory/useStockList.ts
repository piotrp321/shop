import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";

export type StockListItem = components["schemas"]["StockListItemResponse"];
type ListedStock = components["schemas"]["ListedStockResponse"];
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

/**
 * A row of the stock list: one variant with the product it belongs to. `first` marks the first variant of a product,
 * the row that shows the name of the product above its other variants.
 */
export interface StockRow {
  productId: string;
  name: string;
  variantId: string;
  /** The values of the variant, empty for the default variant. */
  label: string;
  stock: ListedStock | null;
  first: boolean;
}

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

/** The API searches names of 2 to 100 characters (code points, as it counts them), not UTF-16 units. */
export const MIN_SEARCH_LENGTH = 2;
export const MAX_SEARCH_LENGTH = 100;
export const SEARCH_DEBOUNCE_MS = 300;

interface StockQuery {
  q?: string;
  category?: string;
  after?: string;
}

function characters(text: string): number {
  return [...text].length;
}

function failureMessage(status: number): string {
  switch (status) {
    case 404:
      return "Wybrana kategoria już nie istnieje. Wybierz inną.";
    case 400:
      return `Nieprawidłowe parametry wyszukiwania (HTTP ${status}).`;
    default:
      return `Nie udało się pobrać stanów magazynowych (HTTP ${status}).`;
  }
}

/**
 * The stock list with a search by name (from two characters, after a pause in typing), a category filter and paging
 * with the cursors of the API. A page holds products, each with its variants; `rows` has a row per variant, in the
 * order of the products and of their variants. The panel keeps the stack of the cursors it has visited: `cursors[i]`
 * is the `after` of page i (null for the first), so "previous" is the cursor below the current one. Changing the
 * search or the category starts again from the first page. A failed request keeps the page that is shown and can be
 * retried. Every number and level in a row is the API's; the panel only shows them.
 *
 * `receiveStock` and `correctStock` send a change of one variant and put the stock the API answers with into its row (the panel
 * computes nothing). A list answer that was asked before such a change is not allowed to bring back the older stock:
 * a stock saved since the newest list request started wins when its version is greater.
 */
export function useStockList() {
  const search = ref("");
  const categoryId = ref<string | null>(null);
  const items = ref<StockListItem[]>([]);
  const next = ref<string | null>(null);
  const cursors = ref<(string | null)[]>([null]);
  const loading = ref(false);
  const loaded = ref(false);
  const error = ref<string | null>(null);

  // The term the list was last asked for (null: all names); a pending debounce compares against it.
  let appliedTerm: string | null = null;
  let attempted: (string | null)[] = [null];
  let timer: ReturnType<typeof setTimeout> | undefined;
  // Only the newest request may change the state; an older one that finishes late is ignored.
  let latest = 0;
  // The stocks saved since the newest list request started, by variant. Its answer can predate such a save, so a row
  // it brings is replaced by the saved stock when that is newer (a greater version). Cleared when a request starts:
  // the next one is asked after those saves, so it already has them.
  const savedSinceLoad = new Map<string, ListedStock>();

  const searchTooShort = computed(() => {
    const length = characters(search.value.trim());
    return length > 0 && length < MIN_SEARCH_LENGTH;
  });
  const hasPrevious = computed(() => cursors.value.length > 1);
  const hasNext = computed(() => next.value !== null);
  const pageNumber = computed(() => cursors.value.length);
  const rows = computed<StockRow[]>(() =>
    items.value.flatMap((item) =>
      item.variants.map((variant, index) => ({
        productId: item.productId,
        name: item.name,
        variantId: variant.variantId,
        label: variant.label,
        stock: variant.stock,
        first: index === 0,
      })),
    ),
  );

  async function load(stack: (string | null)[]): Promise<void> {
    const request = ++latest;
    savedSinceLoad.clear();
    attempted = stack;
    loading.value = true;
    const query: StockQuery = {};
    if (appliedTerm !== null) {
      query.q = appliedTerm;
    }
    if (categoryId.value !== null) {
      query.category = categoryId.value;
    }
    const after = stack[stack.length - 1];
    if (after) {
      query.after = after;
    }
    try {
      const result = await getApiClient().GET("/api/v1/admin/stock", { params: { query } });
      if (request !== latest) {
        return;
      }
      if (result.data) {
        items.value = result.data.items.map(withSavedStock);
        next.value = result.data.next;
        cursors.value = stack;
        loaded.value = true;
        error.value = null;
      } else {
        error.value = failureMessage(result.response.status);
      }
    } catch {
      if (request === latest) {
        error.value = "Nie udało się pobrać stanów magazynowych. Sprawdź połączenie z API.";
      }
    } finally {
      if (request === latest) {
        loading.value = false;
      }
    }
  }

  function withSavedStock(item: StockListItem): StockListItem {
    return {
      ...item,
      variants: item.variants.map((variant) => {
        const saved = savedSinceLoad.get(variant.variantId);
        return saved && (variant.stock === null || saved.version > variant.stock.version) ? { ...variant, stock: saved } : variant;
      }),
    };
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
      savedSinceLoad.set(variantId, stock);
    }
    items.value = items.value.map((item) => ({
      ...item,
      variants: item.variants.map((variant) => (variant.variantId === variantId ? { ...variant, stock } : variant)),
    }));
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
        await load(cursors.value);
      }
      return changeFailure(result.response.status, result.error, "API odrzuciło przyjęcie");
    } catch {
      return { status: "failed", message: "Nie udało się zapisać przyjęcia. Sprawdź połączenie z API." };
    }
  }

  /**
   * A correction based on the `version` the row shows (null: no stock record). The API answers both of its 409s with
   * `type: about:blank` and tells them apart only by `detail`, so the panel does not read the text. On a 409 it
   * reloads the page shown: when the row then has another version than the one sent, the stock changed meanwhile
   * (a version conflict, the row is now current); when the version is the same, or the reload failed, the correction
   * was refused for another reason (below the reserved units) and the `detail` of the API is shown.
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
        await load(cursors.value);
        const row = rows.value.find((candidate) => candidate.variantId === variantId);
        if (row && (row.stock?.version ?? null) !== input.version) {
          return { status: "conflict", message: CONFLICT_MESSAGE };
        }
      }
      return changeFailure(result.response.status, result.error, "API odrzuciło korektę");
    } catch {
      return { status: "failed", message: "Nie udało się zapisać korekty. Sprawdź połączenie z API." };
    }
  }

  watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
      const term = search.value.trim();
      const length = characters(term);
      if (length > 0 && length < MIN_SEARCH_LENGTH) {
        // Too short to ask: the list stays as it is and the hint says why.
        return;
      }
      const effective = length >= MIN_SEARCH_LENGTH ? term : null;
      if (effective !== appliedTerm) {
        appliedTerm = effective;
        startFromFirstPage();
        void load([null]);
      }
    }, SEARCH_DEBOUNCE_MS);
  });

  // The cursors and `next` belong to the list of the old filter. Dropping them now means that if the request for the
  // new filter fails, the pager cannot pair the new filter with an old cursor: it stays off until a retry succeeds.
  function startFromFirstPage(): void {
    cursors.value = [null];
    next.value = null;
  }

  function selectCategory(id: string | null): void {
    if (id !== categoryId.value) {
      categoryId.value = id;
      startFromFirstPage();
      void load([null]);
    }
  }

  function goNext(): Promise<void> {
    return next.value === null ? Promise.resolve() : load([...cursors.value, next.value]);
  }

  function goPrevious(): Promise<void> {
    return hasPrevious.value ? load(cursors.value.slice(0, -1)) : Promise.resolve();
  }

  function retry(): Promise<void> {
    return load(attempted);
  }

  onMounted(() => {
    void load([null]);
  });

  onUnmounted(() => {
    clearTimeout(timer);
    latest++;
  });

  return {
    search,
    searchTooShort,
    items,
    rows,
    loading,
    loaded,
    error,
    hasPrevious,
    hasNext,
    pageNumber,
    selectCategory,
    goNext,
    goPrevious,
    retry,
    receiveStock,
    correctStock,
  };
}
