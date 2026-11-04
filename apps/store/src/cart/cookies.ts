import type { NextResponse } from "next/server";
import { cookieOptions, readSignedValue, signValue } from "@/auth/cookies";

/** Holds the signed id of the guest cart. The signature does not hide the id; it only proves the store issued it. */
export const CART_COOKIE = "store_cart";

/** Guest carts expire in the API 30 days after their last change; every successful write renews the cookie. */
export const CART_COOKIE_MAX_AGE_SECONDS = 30 * 24 * 60 * 60;

/** Holds the signed marker of a merge of the guest cart that did not fit, until the summary shows it once. */
export const NOTICE_COOKIE = "store_cart_notice";
export const NOTICE_COOKIE_MAX_AGE_SECONDS = 5 * 60;
const MERGE_TRUNCATED = "merge_truncated";

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/** Whether the value has the shape of a UUID, the only shape of an id the API accepts. */
export const isUuid = (value: string): boolean => UUID.test(value);

export function cartCookieOptions(storeUrl: string) {
  return { ...cookieOptions(storeUrl), maxAge: CART_COOKIE_MAX_AGE_SECONDS } as const;
}

/**
 * The guest cart id of a `store_cart` cookie, or null when it is missing, forged or not a cart id (a value signed
 * for another purpose, such as a session id, never reaches the API).
 */
export function readCartId(signed: string | undefined, secret: string): string | null {
  const value = readSignedValue(signed, secret);
  return value !== null && isUuid(value) ? value : null;
}

export function clearCartCookie(response: NextResponse, storeUrl: string) {
  response.cookies.set(CART_COOKIE, "", { ...cookieOptions(storeUrl), maxAge: 0 });
}

/** Whether a `store_cart_notice` cookie is the store's own marker of a truncated merge. */
export const hasMergeNotice = (signed: string | undefined, secret: string): boolean => readSignedValue(signed, secret) === MERGE_TRUNCATED;

export function setMergeNotice(response: NextResponse, config: { storeUrl: string; sessionSecret: string }) {
  response.cookies.set(NOTICE_COOKIE, signValue(MERGE_TRUNCATED, config.sessionSecret), {
    ...cookieOptions(config.storeUrl),
    maxAge: NOTICE_COOKIE_MAX_AGE_SECONDS,
  });
}

export function clearMergeNotice(response: NextResponse, storeUrl: string) {
  response.cookies.set(NOTICE_COOKIE, "", { ...cookieOptions(storeUrl), maxAge: 0 });
}
