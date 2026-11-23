import { createHash, createHmac, timingSafeEqual } from "node:crypto";

export { GUEST_BUYER } from "@/checkout/buyer";

/**
 * The buyer tag of the account `sub` (docs/features/cart-order-consistency/spec.md, Z4): HMAC-SHA256 of
 * `checkout-buyer:<sub>` with the session secret, base64url. The same for every session of the account, also after a
 * refresh of its token; it opens nothing and only lets the checkout BFF compare the buyer the checkout showed with
 * the session of the order. The prefix keeps it apart from the signatures of the cookies made with the same secret.
 */
export function buyerTag(sub: string, secret: string): string {
  return createHmac("sha256", secret).update(`checkout-buyer:${sub}`).digest("base64url");
}

const digest = (value: string) => createHash("sha256").update(value).digest();

/**
 * Whether `shown`, the `buyer` the checkout sent, is the tag of `sub`. Compared in constant time on digests of equal
 * length, so neither the content nor the length of the text tells anything.
 */
export function isBuyerOf(shown: string, sub: string, secret: string): boolean {
  return timingSafeEqual(digest(shown), digest(buyerTag(sub, secret)));
}
