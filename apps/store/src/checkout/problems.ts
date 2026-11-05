import type { components } from "@/api/schema";

/** The `type` of a 409 of the order endpoints; the union comes from the schema of the API (`OrderConflictProblem`). */
export type OrderConflictType = components["schemas"]["OrderConflictProblem"]["type"];

/** What the checkout does about a 409; `unknown` is any other `type` (or none), handled as a failure to retry. */
export type OrderConflictReason = "cart_empty" | "cart_not_orderable" | "insufficient_stock" | "total_changed" | "key_reused" | "unknown";

/** The own 409 of the BFF: no cart to order. */
export const CART_EMPTY_TYPE: OrderConflictType = "tag:piotrp321.github.io,2026:shop/problems/cart-empty";

// `satisfies` makes the compiler demand one reason for every type the schema lists, so a new type cannot be forgotten.
const REASONS = {
  "tag:piotrp321.github.io,2026:shop/problems/cart-empty": "cart_empty",
  "tag:piotrp321.github.io,2026:shop/problems/cart-not-orderable": "cart_not_orderable",
  "tag:piotrp321.github.io,2026:shop/problems/insufficient-stock-for-order": "insufficient_stock",
  "tag:piotrp321.github.io,2026:shop/problems/order-total-changed": "total_changed",
  "tag:piotrp321.github.io,2026:shop/problems/idempotency-key-reused": "key_reused",
} as const satisfies Record<OrderConflictType, Exclude<OrderConflictReason, "unknown">>;

/** The reason for the `type` of a 409; only the `type` decides, never the `detail`. */
export function conflictReason(type: unknown): OrderConflictReason {
  return typeof type === "string" && Object.hasOwn(REASONS, type) ? REASONS[type as OrderConflictType] : "unknown";
}
