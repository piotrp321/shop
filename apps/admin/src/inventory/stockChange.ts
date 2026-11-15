import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";
import type { ListedStock } from "./stockPage";

type StockState = components["schemas"]["StockStateResponse"];
type ReceiveStockRequest = components["schemas"]["ReceiveStockRequest"];
export type CorrectStockRequest = components["schemas"]["CorrectStockRequest"];

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

/** The row's stock from the answer of the API; a variant still without a record (version null) has none. */
function rowStock(state: StockState): ListedStock | null {
  if (state.version === null || state.updatedAt === null) {
    return null;
  }
  const { physical, reserved, available, level, version, updatedAt } = state;
  return { physical, reserved, available, level, version, updatedAt };
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

/** What a view does with the saved stock (the shared requests of the stock list and of the card of a product). */
export interface StockChangeHooks {
  save: (variantId: string, stock: ListedStock) => void;
}

/**
 * A receipt. `key` is the idempotency key of the dialog: the caller sends the same one when it repeats the same
 * receipt, so a retry after a lost answer adds the units once. A 409 here is a reused key; the API says so in `detail`.
 * The first request with this key may have been saved although its answer was lost, so the view reloads (`refresh`)
 * to show the row as it is now (and not to receive twice).
 */
export async function requestReceipt(
  variantId: string,
  input: { quantity: number; note: string | null; key: string },
  hooks: StockChangeHooks & { refresh: () => Promise<void> },
): Promise<StockChange> {
  const body: ReceiveStockRequest = { quantity: input.quantity, note: blankToNull(input.note) };
  try {
    const result = await getApiClient().POST("/api/v1/admin/variants/{variantId}/stock/receipts", {
      params: { path: { variantId }, header: { "Idempotency-Key": input.key } },
      body,
    });
    if (result.data) {
      const stock = rowStock(result.data);
      if (stock) {
        hooks.save(variantId, stock);
      }
      return { status: "saved" };
    }
    if (result.response.status === 409) {
      await hooks.refresh();
    }
    return changeFailure(result.response.status, result.error, "API odrzuciło przyjęcie");
  } catch {
    return { status: "failed", message: "Nie udało się zapisać przyjęcia. Sprawdź połączenie z API." };
  }
}

/**
 * A correction based on the `version` the row shows (null: no stock record). The API answers both of its 409s with
 * `type: about:blank` and tells them apart only by `detail` (one in English), so the panel does not read the text. On a
 * 409 the view reloads and reads the row again (`reread`: the version of the row now, null when it cannot be read): when
 * the row then has another version than the one sent, the stock changed meanwhile (a version conflict, the row is now
 * current); when the version is the same, the correction was refused because it is below the reserved units and the
 * `detail` of the API (in Polish) is shown. When the row cannot be reread the reason is not known, so it is the same
 * Polish „reload” conflict, never the `detail`.
 */
export async function requestCorrection(
  variantId: string,
  input: { physical: number; reason: CorrectStockRequest["reason"]; note: string | null; version: number | null },
  hooks: StockChangeHooks & { reread: () => Promise<{ version: number | null } | null> },
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
      const stock = rowStock(result.data);
      if (stock) {
        hooks.save(variantId, stock);
      }
      return { status: "saved" };
    }
    if (result.response.status === 409) {
      const row = await hooks.reread();
      if (row === null || row.version !== input.version) {
        return { status: "conflict", message: CONFLICT_MESSAGE };
      }
    }
    return changeFailure(result.response.status, result.error, "API odrzuciło korektę");
  } catch {
    return { status: "failed", message: "Nie udało się zapisać korekty. Sprawdź połączenie z API." };
  }
}
