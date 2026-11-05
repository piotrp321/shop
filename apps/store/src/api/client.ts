import createClient, { type Client, type ClientOptions } from "openapi-fetch";
import type { components, paths } from "./schema";

export type ApiClient = Client<paths>;

/**
 * The only place that talks to the API. Use it from the server only: `baseUrl` is the internal address.
 * `accessToken` adds a bearer header for endpoints that need a signed-in user.
 */
export function createApiClient(options: { baseUrl: string; accessToken?: string; fetch?: ClientOptions["fetch"] }): ApiClient {
  return createClient<paths>({
    baseUrl: options.baseUrl,
    fetch: options.fetch,
    headers: options.accessToken ? { Authorization: `Bearer ${options.accessToken}` } : undefined,
  });
}

/**
 * Body of the store's own `GET /auth/session` (route handler in app/auth/session): never tokens. `name` falls back to
 * the e-mail when the account has no name; `email` is the address of the account (the checkout confirms orders to it).
 */
export type AccountSession = { name: string; email: string } | null;

export type AccountSessionResult = { status: "ok"; session: AccountSession } | { status: "unavailable" };

/**
 * For the browser: asks the store's `/auth/session` (same origin, so the session cookie goes along) who is signed
 * in. It does not talk to the API; the store's server does that with the token from the session.
 */
export async function loadAccountSession(): Promise<AccountSessionResult> {
  try {
    const response = await fetch("/auth/session", { credentials: "same-origin", cache: "no-store" });
    if (!response.ok) {
      return { status: "unavailable" };
    }
    return { status: "ok", session: (await response.json()) as AccountSession };
  } catch {
    return { status: "unavailable" };
  }
}

/** Body of the store's own `GET /api/cart/summary` (route handler in app/api/cart/summary). */
export type CartSummaryBody = { itemCount: number; notice: "merge_truncated" | null };

export type CartView = components["schemas"]["CartView"];

/** Why a call to the store's cart endpoints did not give data. `detail` is the Polish text of the API, when there is one. */
export type CartFailure = { status: "conflict" | "not_found" | "unauthorized" | "failed"; detail?: string };

export type CartResult<T> = { status: "ok"; data: T } | CartFailure;

async function callCart<T>(path: string, init: { method: string; body?: unknown } = { method: "GET" }): Promise<CartResult<T>> {
  try {
    const response = await fetch(path, {
      method: init.method,
      credentials: "same-origin",
      cache: "no-store",
      ...(init.body === undefined
        ? {}
        : { headers: { "Content-Type": "application/json" }, body: JSON.stringify(init.body) }),
    });
    if (response.ok) {
      return { status: "ok", data: (await response.json()) as T };
    }
    switch (response.status) {
      case 401:
        return { status: "unauthorized" };
      case 404:
      case 409: {
        const body = (await response.json().catch(() => null)) as { detail?: unknown } | null;
        const detail = typeof body?.detail === "string" ? body.detail : undefined;
        return { status: response.status === 404 ? "not_found" : "conflict", detail };
      }
      default:
        return { status: "failed" };
    }
  } catch {
    return { status: "failed" };
  }
}

/** For the browser: the cart pages and buttons talk to the store's `/api/cart/*`, never to the API. */
export const loadCart = () => callCart<CartView>("/api/cart");

export const loadCartSummary = () => callCart<CartSummaryBody>("/api/cart/summary");

/** The one Web Locks API lock that the cart writes of all tabs of the store in this browser take in turn. */
const CART_WRITE_LOCK = "store-cart-write";

let lastWrite: Promise<unknown> = Promise.resolve();

/**
 * Runs the writes to the cart one after another. Two first additions of a guest started together would each create
 * a guest cart, and the last cookie would win; started one after another, the second finds the cart of the first.
 * The queue orders the writes of this tab; each write also holds the cart lock of Web Locks API, when the browser has
 * it (a secure context), so the writes of other tabs wait too. A failed write does not hold back the next one.
 */
function queueWrite<T>(write: () => Promise<CartResult<T>>): Promise<CartResult<T>> {
  const locked = () => underCartLock(write);
  const result = lastWrite.then(locked, locked);
  lastWrite = result.catch(() => undefined);
  return result;
}

/** A lock request the browser refuses (e.g. a `SecurityError`) gives `failed` without sending the write. */
async function underCartLock<T>(write: () => Promise<CartResult<T>>): Promise<CartResult<T>> {
  const locks = globalThis.navigator?.locks as LockManager | undefined;
  if (locks === undefined) {
    return write();
  }
  try {
    return await locks.request(CART_WRITE_LOCK, { mode: "exclusive" }, write);
  } catch {
    return { status: "failed" };
  }
}

export const addCartItem = (variantId: string, quantity: number) =>
  queueWrite(() => callCart<CartView>("/api/cart/items", { method: "POST", body: { variantId, quantity } }));

export const changeCartItemQuantity = (variantId: string, quantity: number) =>
  queueWrite(() =>
    callCart<CartView>(`/api/cart/items/${encodeURIComponent(variantId)}`, { method: "PATCH", body: { quantity } }),
  );

export const removeCartItem = (variantId: string) =>
  queueWrite(() => callCart<CartView>(`/api/cart/items/${encodeURIComponent(variantId)}`, { method: "DELETE" }));
