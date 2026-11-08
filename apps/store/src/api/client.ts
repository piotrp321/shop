import createClient, { type Client, type ClientOptions } from "openapi-fetch";
import { conflictReason, type OrderConflictReason, type PaymentConflictReason, paymentConflictReason } from "@/checkout/problems";
import type { components, paths } from "./schema";

export type ApiClient = Client<paths>;

/**
 * The only place that talks to the API. Use it from the server only: `baseUrl` is the internal address.
 * `accessToken` adds a bearer header for endpoints that need a signed-in user.
 * `tags` are the Next.js cache tags of every read of the client (`next: { tags }` of fetch): a page that renders from
 * these reads is revalidated with `revalidateTag`. openapi-fetch calls fetch with a ready Request, so the tags travel
 * as its second argument (`requestInitExt`).
 */
export function createApiClient(options: { baseUrl: string; accessToken?: string; fetch?: ClientOptions["fetch"]; tags?: string[] }): ApiClient {
  return createClient<paths>({
    baseUrl: options.baseUrl,
    fetch: options.fetch,
    headers: options.accessToken ? { Authorization: `Bearer ${options.accessToken}` } : undefined,
    requestInitExt: options.tags === undefined ? undefined : { next: { tags: options.tags } },
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

/** Body of the store's own `GET /api/site` (route handler in app/api/site): the switches of this deployment. */
export type SiteSwitches = { demoNotice: boolean; customerAccounts: boolean };

/** The state of a store without the variables, and of a failed read. */
export const DEFAULT_SITE_SWITCHES: SiteSwitches = { demoNotice: false, customerAccounts: true };

/**
 * For the browser: asks the store's `/api/site` (same origin) for the switches of this deployment. A failed read, an
 * error status or a body that is not JSON gives the defaults, and so does every switch that is not a boolean. Never
 * rejects.
 */
export async function loadSiteSwitches(): Promise<SiteSwitches> {
  try {
    const response = await fetch("/api/site", { credentials: "same-origin", cache: "no-store" });
    if (!response.ok) {
      return DEFAULT_SITE_SWITCHES;
    }
    const body = (await response.json()) as Partial<Record<keyof SiteSwitches, unknown>> | null;
    return {
      demoNotice: typeof body?.demoNotice === "boolean" ? body.demoNotice : DEFAULT_SITE_SWITCHES.demoNotice,
      customerAccounts:
        typeof body?.customerAccounts === "boolean" ? body.customerAccounts : DEFAULT_SITE_SWITCHES.customerAccounts,
    };
  } catch {
    return DEFAULT_SITE_SWITCHES;
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

export type CustomerOrderList = components["schemas"]["CustomerOrderList"];
export type CustomerOrderListItem = components["schemas"]["CustomerOrderListItem"];
export type CustomerOrder = components["schemas"]["CustomerOrder"];
export type CustomerOrderStatus = components["schemas"]["CustomerOrderStatus"];

/** Why a call to the store's order history endpoints did not give data: `unauthorized` is a session that is over. */
export type AccountResult<T> = { status: "ok"; data: T } | { status: "unauthorized" | "not_found" | "failed" };

async function callAccount<T>(path: string): Promise<AccountResult<T>> {
  try {
    const response = await fetch(path, { method: "GET", credentials: "same-origin", cache: "no-store" });
    switch (response.status) {
      case 200:
        return { status: "ok", data: (await response.json()) as T };
      case 401:
        return { status: "unauthorized" };
      case 404:
        return { status: "not_found" };
      default:
        return { status: "failed" };
    }
  } catch {
    // A network error and a body that is no JSON.
    return { status: "failed" };
  }
}

/**
 * For the browser: a page of the order history from the store's `/api/account/orders`; `after` is the `nextCursor` of
 * the previous page. Never rejects.
 */
export const loadCustomerOrders = (after?: string) =>
  callAccount<CustomerOrderList>(after === undefined ? "/api/account/orders" : `/api/account/orders?after=${encodeURIComponent(after)}`);

/** For the browser: one order of the customer from the store's `/api/account/orders/<id>`. Never rejects. */
export const loadCustomerOrder = (orderId: string) => callAccount<CustomerOrder>(`/api/account/orders/${encodeURIComponent(orderId)}`);

/** The checkout form as the browser sends it to `/api/checkout`; a signed-in customer has no `email` (the account's). */
export type PlaceOrderInput = {
  email?: string;
  fullName: string;
  phone: string;
  street: string;
  postalCode: string;
  city: string;
  /** `totals.gross` of the cart the customer saw; the API only compares it with the total it computes. */
  expectedTotalGross: number;
  /** Code of the payment provider chosen from `GET /api/v1/payment-providers`; the BFF checks it. */
  provider: string;
};

export type OrderViolation = NonNullable<components["schemas"]["Problem"]["violations"]>[number];

/**
 * How a call to the store's `/api/checkout` ended. `detail` and `violations` are the Polish texts of the API. The cause
 * of a conflict is told by the `type` of the problem (`reason`), never by its text.
 */
export type PlaceOrderResult =
  | { status: "ok"; number: string; redirectUrl: string | null }
  | { status: "invalid"; detail?: string; violations: OrderViolation[] }
  | { status: "conflict"; reason: OrderConflictReason; detail?: string }
  | { status: "not_found"; detail?: string }
  | { status: "unauthorized" }
  | { status: "failed" };

/**
 * For the browser: places the order of the cart through the store's `/api/checkout`; `ok` carries the page of the
 * provider where the customer pays, or null when the order stands but its payment could not be started.
 * `idempotencyKey` is chosen by the checkout page and sent again on a retry, so a repeated request never places a second order. Never rejects.
 */
export async function placeOrder(input: PlaceOrderInput, idempotencyKey: string): Promise<PlaceOrderResult> {
  try {
    const response = await fetch("/api/checkout", {
      method: "POST",
      credentials: "same-origin",
      cache: "no-store",
      headers: { "Content-Type": "application/json", "Idempotency-Key": idempotencyKey },
      body: JSON.stringify(input),
    });
    const body = (await response.json().catch(() => null)) as {
      number?: unknown;
      redirectUrl?: unknown;
      type?: unknown;
      detail?: unknown;
      violations?: unknown;
    } | null;
    const detail = typeof body?.detail === "string" ? body.detail : undefined;
    if (response.ok) {
      return typeof body?.number === "string"
        ? { status: "ok", number: body.number, redirectUrl: typeof body.redirectUrl === "string" ? body.redirectUrl : null }
        : { status: "failed" };
    }
    switch (response.status) {
      case 401:
        return { status: "unauthorized" };
      case 404:
        return { status: "not_found", detail };
      case 409:
        return { status: "conflict", reason: conflictReason(body?.type), detail };
      case 422:
        return { status: "invalid", detail, violations: Array.isArray(body?.violations) ? (body.violations as OrderViolation[]) : [] };
      default:
        return { status: "failed" };
    }
  } catch {
    return { status: "failed" };
  }
}

/**
 * How a call to the store's `/api/checkout/payment` ended. `redirectUrl` is the page of the provider; it is null when
 * the API has none to give (the payment is not pending any more), and then the status is to be read again.
 */
export type StartPaymentResult =
  | { status: "ok"; redirectUrl: string | null }
  | { status: "conflict"; reason: PaymentConflictReason; detail?: string }
  | { status: "invalid"; detail?: string; violations: OrderViolation[] }
  | { status: "not_found"; detail?: string }
  | { status: "unauthorized" }
  | { status: "failed" };

/**
 * For the browser: starts a payment of the order just placed at `provider` through the store's `/api/checkout/payment`.
 * `idempotencyKey` is new for every attempt and every change of provider and sent again on a retry of an uncertain
 * result, so a repeated request never starts a second payment. Never rejects.
 */
export async function startPayment(provider: string, idempotencyKey: string): Promise<StartPaymentResult> {
  try {
    const response = await fetch("/api/checkout/payment", {
      method: "POST",
      credentials: "same-origin",
      cache: "no-store",
      headers: { "Content-Type": "application/json", "Idempotency-Key": idempotencyKey },
      body: JSON.stringify({ provider }),
    });
    const body = (await response.json().catch(() => null)) as { redirectUrl?: unknown; type?: unknown; detail?: unknown; violations?: unknown } | null;
    const detail = typeof body?.detail === "string" ? body.detail : undefined;
    if (response.ok) {
      return { status: "ok", redirectUrl: typeof body?.redirectUrl === "string" ? body.redirectUrl : null };
    }
    switch (response.status) {
      case 401:
        return { status: "unauthorized" };
      case 404:
        return { status: "not_found", detail };
      case 409:
        return { status: "conflict", reason: paymentConflictReason(body?.type), detail };
      case 422:
        return { status: "invalid", detail, violations: Array.isArray(body?.violations) ? (body.violations as OrderViolation[]) : [] };
      default:
        return { status: "failed" };
    }
  } catch {
    return { status: "failed" };
  }
}

export type PaymentStatusView = components["schemas"]["PaymentStatusView"];

export type PaymentStatusReadResult =
  | { status: "ok"; data: PaymentStatusView }
  | { status: "not_found" }
  | { status: "unauthorized" }
  | { status: "failed" };

/** For the browser: the payment status of the order just placed, from the store's `/api/checkout/payment`. Never rejects. */
export async function getPaymentStatus(): Promise<PaymentStatusReadResult> {
  try {
    const response = await fetch("/api/checkout/payment", { method: "GET", credentials: "same-origin", cache: "no-store" });
    switch (response.status) {
      case 200: {
        const data = (await response.json().catch(() => null)) as PaymentStatusView | null;
        return typeof data?.orderNumber === "string" ? { status: "ok", data } : { status: "failed" };
      }
      case 401:
        return { status: "unauthorized" };
      case 404:
        return { status: "not_found" };
      default:
        return { status: "failed" };
    }
  } catch {
    return { status: "failed" };
  }
}
