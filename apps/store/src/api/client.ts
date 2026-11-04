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

/** Body of the store's own `GET /auth/session` (route handler in app/auth/session): never tokens. */
export type AccountSession = { name: string } | null;

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

export const addCartItem = (productId: string, quantity: number) =>
  callCart<CartView>("/api/cart/items", { method: "POST", body: { productId, quantity } });

export const changeCartItemQuantity = (productId: string, quantity: number) =>
  callCart<CartView>(`/api/cart/items/${encodeURIComponent(productId)}`, { method: "PATCH", body: { quantity } });

export const removeCartItem = (productId: string) =>
  callCart<CartView>(`/api/cart/items/${encodeURIComponent(productId)}`, { method: "DELETE" });
