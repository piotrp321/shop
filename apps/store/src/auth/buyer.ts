import { createHash, createHmac, hkdfSync, timingSafeEqual } from "node:crypto";

export { GUEST_BUYER } from "@/checkout/buyer";

/**
 * The key of the buyer tags: HKDF-SHA256 of the session secret (no salt, info `checkout-buyer`), 32 bytes. The cookies
 * are signed with the secret itself (`signValue`), so no tag is the signature of any cookie value and no signature a
 * tag, whatever the shape of the values; nor is this key the signature of any value (HKDF does not sign its info with
 * the secret).
 */
const tagKey = (secret: string) => Buffer.from(hkdfSync("sha256", secret, Buffer.alloc(0), "checkout-buyer", 32));

/**
 * The buyer tag of the account `sub` (docs/features/cart-order-consistency/spec.md, Z4): HMAC-SHA256 of the sub with
 * the key of the tags (`tagKey`), base64url. The same for every session of the account, also after a refresh of its
 * token; it opens nothing and only lets the checkout BFF compare the buyer the checkout showed with the session of the
 * order.
 */
export function buyerTag(sub: string, secret: string): string {
  return createHmac("sha256", tagKey(secret)).update(sub).digest("base64url");
}

const digest = (value: string) => createHash("sha256").update(value).digest();

/**
 * Whether `shown`, the `buyer` the checkout sent, is the tag of `sub`. Compared in constant time on digests of equal
 * length, so neither the content nor the length of the text tells anything.
 */
export function isBuyerOf(shown: string, sub: string, secret: string): boolean {
  return timingSafeEqual(digest(shown), digest(buyerTag(sub, secret)));
}
