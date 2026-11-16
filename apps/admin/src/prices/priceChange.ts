import { getApiClient } from "@/api/client";
import type { ListedPrice } from "./pricePage";

/** How a price change ended; the messages are for the staff member. */
export type PriceChange =
  | { status: "saved" }
  | { status: "invalid"; message: string }
  | { status: "conflict"; message: string }
  | { status: "locked"; message: string }
  | { status: "failed"; message: string };

export const CONFLICT_MESSAGE = "Cena została w międzyczasie zmieniona – odśwież";
const PRICE_LOCKED_TYPE = "tag:piotrp321.github.io,2026:shop/problems/price-locked-by-promotion";

/** The reason of a rejected request from a problem details body (`violations[].title`, else `detail`), if it has one. */
function problemMessage(body: unknown): string | null {
  if (typeof body !== "object" || body === null) {
    return null;
  }
  const { violations, detail } = body as { violations?: unknown; detail?: unknown };
  if (Array.isArray(violations)) {
    const titles = violations
      .map((violation) => (violation as { title?: unknown } | null)?.title)
      .filter((title): title is string => typeof title === "string" && title !== "");
    if (titles.length > 0) {
      return titles.join(" ");
    }
  }
  return typeof detail === "string" && detail !== "" ? detail : null;
}

/** The `detail` of a 409 saying the variant is in an active promotion; null for any other 409. */
function lockedDetail(body: unknown): string | null {
  if (typeof body !== "object" || body === null) {
    return null;
  }
  const { type, detail } = body as { type?: unknown; detail?: unknown };
  if (type !== PRICE_LOCKED_TYPE) {
    return null;
  }
  return typeof detail === "string" && detail !== "" ? detail : "Cena wariantu w aktywnej promocji nie może się zmienić.";
}

function changeFailure(status: number, body: unknown): PriceChange {
  switch (status) {
    case 422:
      // The rules of the amount are the API's; the panel only shows the reason it gives.
      return { status: "invalid", message: problemMessage(body) ?? `API odrzuciło cenę (HTTP ${status}).` };
    case 403:
      return { status: "failed", message: "Nie masz uprawnień do zmiany cen." };
    case 404:
      return { status: "failed", message: "Ten wariant już nie istnieje." };
    default:
      return { status: "failed", message: `Nie udało się zapisać ceny (HTTP ${status}).` };
  }
}

/** What a view does with the outcome of a price change: keep the saved price, reload what it shows after a conflict. */
export interface PriceChangeHooks {
  save: (variantId: string, price: ListedPrice) => void;
  refresh: () => Promise<void>;
}

/**
 * Sends a new gross price (in grosze) of one variant with the version its row shows (the shared request of the price
 * list and of the card of a product). The saved price from the API goes to `hooks.save`. A conflict (the price changed
 * meanwhile) reloads what the view shows (`hooks.refresh`). A 409 of the type `price-locked-by-promotion` (the variant
 * is in an active promotion) is told apart by its `type`: it is `locked`, with the `detail` of the API, and reloads
 * nothing. The API decides on the amount and the permission, the panel only shows its answer.
 */
export async function requestPriceChange(variantId: string, gross: number, version: number | null, hooks: PriceChangeHooks): Promise<PriceChange> {
  try {
    const result = await getApiClient().PUT("/api/v1/admin/variants/{variantId}/price", {
      params: { path: { variantId } },
      body: { gross, version },
    });
    if (result.data) {
      hooks.save(variantId, result.data);
      return { status: "saved" };
    }
    if (result.response.status === 409) {
      // The price did not change meanwhile, it is locked by a promotion: the row is current, nothing to reload.
      const locked = lockedDetail(result.error);
      if (locked !== null) {
        return { status: "locked", message: locked };
      }
      await hooks.refresh();
      return { status: "conflict", message: CONFLICT_MESSAGE };
    }
    return changeFailure(result.response.status, result.error);
  } catch {
    return { status: "failed", message: "Nie udało się zapisać ceny. Sprawdź połączenie z API." };
  }
}
