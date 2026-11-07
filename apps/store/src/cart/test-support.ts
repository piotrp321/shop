// Doubles for the cart and checkout BFF tests. Never imported by application code.
import { NextRequest } from "next/server";
import { vi } from "vitest";
import { createApiClient } from "@/api/client";
import type { components } from "@/api/schema";
import { SESSION_COOKIE, signValue } from "@/auth/cookies";
import { memorySessionStore, testConfig } from "@/auth/test-support";
import { CONFIRMATION_COOKIE, type OrderConfirmation } from "@/checkout/cookies";
import { CART_COOKIE } from "./cookies";
import type { CartDeps } from "./deps";

type CartView = components["schemas"]["CartView"];

export const GUEST_CART_ID = "11111111-1111-4111-8111-111111111111";
export const NEW_GUEST_CART_ID = "22222222-2222-4222-8222-222222222222";
export const VARIANT_ID = "00000000-0000-4000-8000-000000000001";
/** The product of VARIANT_ID; a variant id is never the id of its product. */
export const PRODUCT_ID = "00000000-0000-4000-8000-0000000000f1";

export const emptyView = (id: string | null = null): CartView => ({
  id,
  lines: [],
  totals: { gross: 0, currency: "PLN", itemCount: 0 },
  hasExcludedLines: false,
});

export const viewWithOneLine = (cartId: string | null, quantity = 1): CartView => ({
  id: cartId,
  lines: [
    {
      variantId: VARIANT_ID,
      product: { productId: PRODUCT_ID, name: "Produkt 1", variantLabel: "", image: null },
      quantity,
      unitPrice: { gross: 1000, currency: "PLN" },
      lineTotal: { gross: 1000 * quantity, currency: "PLN" },
      state: "purchasable",
    },
  ],
  totals: { gross: 1000 * quantity, currency: "PLN", itemCount: quantity },
  hasExcludedLines: false,
});

export const problem = (status: number, detail?: string) =>
  Response.json(
    { type: "about:blank", title: "Problem", status, ...(detail === undefined ? {} : { detail }) },
    { status, headers: { "Content-Type": "application/problem+json" } },
  );

/** `idempotencyKey` only when the request has the header (`toEqual` ignores a property that is undefined). */
export type ApiCall = {
  method: string;
  path: string;
  authorization: string | null;
  body: unknown;
  idempotencyKey?: string;
  orderAccessToken?: string;
};

/** The API client of the BFF on a fake network: every request is recorded and answered by `respond`. */
export function cartTestDeps() {
  const sessions = memorySessionStore();
  const calls: ApiCall[] = [];
  const handlers: ((call: ApiCall) => Response | undefined)[] = [];

  const deps = {
    config: testConfig,
    sessions: sessions.store,
    freshAccessToken: vi.fn(async (id: string) => (await sessions.store.get(id))?.accessToken ?? null),
    api: (accessToken?: string) =>
      createApiClient({
        baseUrl: "http://api",
        accessToken,
        fetch: async (request) => {
          const text = await request.clone().text();
          const call: ApiCall = {
            method: request.method,
            path: new URL(request.url).pathname,
            authorization: request.headers.get("authorization"),
            body: text === "" ? undefined : JSON.parse(text),
            idempotencyKey: request.headers.get("idempotency-key") ?? undefined,
            orderAccessToken: request.headers.get("order-access-token") ?? undefined,
          };
          calls.push(call);
          for (const handler of handlers) {
            const response = handler(call);
            if (response) return response;
          }
          throw new Error(`Unexpected API call: ${call.method} ${call.path}`);
        },
      }),
  } satisfies CartDeps;

  return {
    deps,
    calls,
    sessions: sessions.sessions,
    /** Handlers are tried in order; the first that returns a response answers. */
    on: (handler: (call: ApiCall) => Response | undefined) => void handlers.push(handler),
    /** Answers `method path` (path of the call, query-less) with a fresh response. */
    answer: (method: string, path: string, respond: (call: ApiCall) => Response) =>
      void handlers.push((call) => (call.method === method && call.path === path ? respond(call) : undefined)),
  };
}

export const signedCartCookie = (id: string, secret: string = testConfig.sessionSecret) =>
  `${CART_COOKIE}=${signValue(id, secret)}`;

/** The cookie of the merge notice, as the store sets it after a truncated merge. */
export const NOTICE_COOKIE_NAME = "store_cart_notice";
export const signedNoticeCookie = (value = "merge_truncated", secret: string = testConfig.sessionSecret) =>
  `${NOTICE_COOKIE_NAME}=${signValue(value, secret)}`;

/** A 200 of `POST /api/v1/me/cart/merge`. */
export const mergeAnswer = (
  result: "merged" | "already_merged" | "nothing_to_merge",
  truncated = false,
  cart: CartView = viewWithOneLine(null, 2),
) => Response.json({ cart, result, truncated });

export const sessionCookie = (id: string) => `${SESSION_COOKIE}=${signValue(id, testConfig.sessionSecret)}`;

export function cartRequest(
  method: string,
  path: string,
  options: { cookie?: string; origin?: string | null; contentType?: string | null; body?: unknown } = {},
) {
  const headers = new Headers();
  if (options.cookie) headers.set("cookie", options.cookie);
  const origin = options.origin === undefined ? testConfig.storeUrl : options.origin;
  if (origin !== null && method !== "GET") headers.set("origin", origin);
  const contentType = options.contentType === undefined ? "application/json" : options.contentType;
  if (contentType !== null && (method === "POST" || method === "PATCH")) headers.set("content-type", contentType);
  const body = options.body === undefined ? undefined : typeof options.body === "string" ? options.body : JSON.stringify(options.body);
  return new NextRequest(`http://0.0.0.0:3000${path}`, { method, headers, body });
}

/** Set-Cookie header of `name`, or undefined. */
export const setCookie = (response: Response, name: string) =>
  response.headers.getSetCookie().find((cookie) => cookie.startsWith(`${name}=`));

export const isCleared = (cookie: string | undefined) => cookie !== undefined && /^[^=]+=;/.test(cookie) && cookie.includes("Max-Age=0");

export const ORDER_ID = "0199a0b0-0000-7000-8000-000000000001";
export const ORDER_ACCESS_TOKEN = "dGhpcy1pcy1hLXRva2VuLW9mLXRoZS1ndWVzdC0xMjM";

/** What the checkout keeps in `store_order_confirmation` for a guest order; `accessToken: null` is a customer's. */
export const guestConfirmation: OrderConfirmation = {
  orderId: ORDER_ID,
  number: "R-100001",
  email: "anna.nowak@example.com",
  totalGross: 39998,
  currency: "PLN",
  accessToken: ORDER_ACCESS_TOKEN,
};
export const customerConfirmation: OrderConfirmation = { ...guestConfirmation, email: "jan.kowalski@example.com", accessToken: null };

/** The value of the signed `store_order_confirmation` cookie, as the checkout sets it. */
export const signedConfirmationValue = (confirmation: OrderConfirmation = guestConfirmation, secret: string = testConfig.sessionSecret) =>
  signValue(Buffer.from(JSON.stringify(confirmation)).toString("base64url"), secret);

export const confirmationCookie = (confirmation: OrderConfirmation = guestConfirmation, secret: string = testConfig.sessionSecret) =>
  `${CONFIRMATION_COOKIE}=${signedConfirmationValue(confirmation, secret)}`;
