import type { NextResponse } from "next/server";
import type { ApiClient } from "@/api/client";
import { logFailure } from "@/auth/responses";
import { mergeIntoCustomerCart } from "./api";
import { clearCartCookie, readCartId, setMergeNotice } from "./cookies";

/**
 * How the merge of the guest cart of `store_cart` into the cart of the signed-in customer ended:
 * - `none`: there is no `store_cart`;
 * - `forged`: the store did not issue the cookie, so it is cleared and nothing is merged;
 * - `merged`: the API answered (`merged`, `already_merged` or `nothing_to_merge`), so the cookie is cleared;
 * - `failed`: the cookie stays and the next request of the cart tries again;
 * - `rejected`: the API refused the access token (401); the cookie stays.
 */
export type GuestCartMerge = { kind: "none" | "forged" | "failed" | "rejected" } | { kind: "merged"; truncated: boolean };

/**
 * `POST /api/v1/me/cart/merge` for the guest cart of the `store_cart` cookie, with the client of the customer. Never
 * throws: a failed merge is logged and the customer goes on with the own cart.
 */
export async function mergeGuestCart(cookie: string | undefined, secret: string, client: ApiClient): Promise<GuestCartMerge> {
  if (cookie === undefined) {
    return { kind: "none" };
  }
  const guestCartId = readCartId(cookie, secret);
  if (guestCartId === null) {
    return { kind: "forged" };
  }
  try {
    const result = await mergeIntoCustomerCart(client, guestCartId);
    if (result.ok) {
      return { kind: "merged", truncated: result.data.truncated };
    }
    logFailure("The merge of the guest cart failed", new Error(`The API answered ${result.status}.`));
    return { kind: result.status === 401 ? "rejected" : "failed" };
  } catch (error) {
    logFailure("The merge of the guest cart failed", error);
    return { kind: "failed" };
  }
}

/** The cookies after a merge: `store_cart` cleared once the API answered (or when forged), the notice after a truncation. */
export function applyGuestCartMerge(response: NextResponse, merge: GuestCartMerge, config: { storeUrl: string; sessionSecret: string }) {
  if (merge.kind === "merged" || merge.kind === "forged") {
    clearCartCookie(response, config.storeUrl);
  }
  if (merge.kind === "merged" && merge.truncated) {
    setMergeNotice(response, config);
  }
}
