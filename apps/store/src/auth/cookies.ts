import { createHmac, timingSafeEqual } from "node:crypto";

/** Holds only the signed session id. */
export const SESSION_COOKIE = "store_session";
/** Holds only the signed id of the login transaction, for the callback. */
export const LOGIN_COOKIE = "store_login";

export const LOGIN_TRANSACTION_TTL_SECONDS = 600;

const signature = (value: string, secret: string) => createHmac("sha256", secret).update(value).digest("base64url");

/** `<value>.<HMAC-SHA256>`: a cookie whose value was not issued by the store is rejected before any lookup. */
export function signValue(value: string, secret: string): string {
  return `${value}.${signature(value, secret)}`;
}

/** The value of a cookie signed by {@link signValue}, or null when it is missing, malformed or forged. */
export function readSignedValue(signed: string | undefined, secret: string): string | null {
  const parts = signed?.split(".");
  if (!parts || parts.length !== 2 || parts[0] === "" || parts[1] === "") {
    return null;
  }
  const [value, given] = parts;
  const expected = Buffer.from(signature(value, secret));
  const actual = Buffer.from(given);
  return actual.length === expected.length && timingSafeEqual(actual, expected) ? value : null;
}

const LOCAL_HOSTS = new Set(["localhost", "127.0.0.1", "[::1]"]);

/** `HttpOnly`, `SameSite=Lax`, `Path=/`; `Secure` everywhere except a local store. */
export function cookieOptions(storeUrl: string) {
  return {
    httpOnly: true,
    sameSite: "lax",
    path: "/",
    secure: !LOCAL_HOSTS.has(new URL(storeUrl).hostname),
  } as const;
}

/** The login cookie lives as long as the transaction and is sent only to the callback. */
export function loginCookieOptions(storeUrl: string) {
  return { ...cookieOptions(storeUrl), path: "/auth/callback", maxAge: LOGIN_TRANSACTION_TTL_SECONDS } as const;
}
