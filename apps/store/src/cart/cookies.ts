import { cookieOptions, readSignedValue } from "@/auth/cookies";

/** Holds the signed id of the guest cart. The signature does not hide the id; it only proves the store issued it. */
export const CART_COOKIE = "store_cart";

/** Guest carts expire in the API 30 days after their last change; every successful write renews the cookie. */
export const CART_COOKIE_MAX_AGE_SECONDS = 30 * 24 * 60 * 60;

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export function cartCookieOptions(storeUrl: string) {
  return { ...cookieOptions(storeUrl), maxAge: CART_COOKIE_MAX_AGE_SECONDS } as const;
}

/**
 * The guest cart id of a `store_cart` cookie, or null when it is missing, forged or not a cart id (a value signed
 * for another purpose, such as a session id, never reaches the API).
 */
export function readCartId(signed: string | undefined, secret: string): string | null {
  const value = readSignedValue(signed, secret);
  return value !== null && UUID.test(value) ? value : null;
}
