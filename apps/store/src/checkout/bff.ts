import type { NextRequest } from "next/server";
import { readSignedValue, SESSION_COOKIE } from "@/auth/cookies";
import { clearSessionCookie, json, logFailure, noStore } from "@/auth/responses";
import { invalidBody, readJsonObject, refuseForeignWrite } from "@/cart/bff";
import { CART_COOKIE, clearCartCookie, readCartId } from "@/cart/cookies";
import type { CartDeps } from "@/cart/deps";
import { applyGuestCartMerge, type GuestCartMerge, mergeGuestCart } from "@/cart/merge";
import { CUSTOMER_ORDER_FIELDS, GUEST_ORDER_FIELDS, type OrderResult, type OrderView, pickFields, placeCustomerOrder, placeGuestOrder } from "./api";
import { setOrderConfirmation } from "./cookies";

const CART_EMPTY = "Koszyk jest pusty.";

type Outcome = { status: number; body: unknown };
/** What the answer does to the browser's cookies, as in the cart BFF, plus the confirmation of a placed order. */
type Effects = { clearCart?: boolean; merge?: GuestCartMerge; endedSession?: boolean; order?: OrderView };

/**
 * `POST /api/checkout`: places the order of the whole cart. The customer's cart with a session (after the merge of
 * `store_cart`, as before every cart operation), otherwise the guest cart of `store_cart`. The browser chooses the
 * `Idempotency-Key` and sends it again on a retry. Answers 201/200 `{ number }` with the signed confirmation cookie,
 * 404, 409 and 422 of the API with their `detail` and `violations`, 401 when the session ended and 503 for the rest.
 * Nothing here computes a price or a total: the API takes lines and prices from the cart and Pricing.
 */
export async function runCheckout(request: NextRequest, deps: CartDeps): Promise<Response> {
  const refused = refuseForeignWrite(request, deps.config.storeUrl, true);
  if (refused) {
    return refused;
  }
  const idempotencyKey = request.headers.get("idempotency-key");
  const body = await readJsonObject(request);
  if (!idempotencyKey || !body) {
    return invalidBody();
  }

  const effects: Effects = {};
  const cartCookie = request.cookies.get(CART_COOKIE)?.value;
  let outcome: Outcome;
  try {
    const sessionId = readSignedValue(request.cookies.get(SESSION_COOKIE)?.value, deps.config.sessionSecret);
    outcome = sessionId
      ? await placeForCustomer(sessionId, cartCookie, idempotencyKey, body, deps, effects)
      : await placeForGuest(cartCookie, idempotencyKey, body, deps, effects);
  } catch (error) {
    logFailure("Checkout request failed", error);
    outcome = { status: 503, body: {} };
  }
  return finish(outcome, effects, deps);
}

function finish({ status, body }: Outcome, effects: Effects, { config }: CartDeps): Response {
  const response = json(body, status);
  if (effects.clearCart) {
    clearCartCookie(response, config.storeUrl);
  }
  if (effects.merge) {
    applyGuestCartMerge(response, effects.merge, config);
  }
  if (effects.order) {
    const { number, email, totals } = effects.order;
    setOrderConfirmation(response, { number, email, totalGross: totals.gross, currency: totals.currency }, config);
  }
  return effects.endedSession ? clearSessionCookie(response, config.storeUrl) : noStore(response);
}

/** The answer for the browser. Refusals the browser can act on keep their `detail` and `violations`; the rest is a 503. */
function toOutcome(result: OrderResult, effects: Effects): Outcome {
  if (result.ok) {
    effects.order = result.data;
    return { status: result.status, body: { number: result.data.number } };
  }
  if (result.status === 404 || result.status === 409 || result.status === 422) {
    return { status: result.status, body: { detail: result.detail, violations: result.violations } };
  }
  // Includes a 400 or 415 of the API (a bug of this BFF, nothing the browser can fix) and a 401 of the guest endpoint.
  console.error(`The API answered ${result.status} to an order.`);
  return { status: 503, body: {} };
}

async function placeForCustomer(
  sessionId: string,
  cartCookie: string | undefined,
  idempotencyKey: string,
  body: Record<string, unknown>,
  deps: CartDeps,
  effects: Effects,
): Promise<Outcome> {
  const endSession = async (): Promise<Outcome> => {
    await deps.sessions.delete(sessionId);
    effects.endedSession = true;
    return { status: 401, body: {} };
  };

  const accessToken = await deps.freshAccessToken(sessionId);
  if (!accessToken) {
    return endSession();
  }
  const api = deps.api(accessToken);
  // A failed merge keeps `store_cart` for the next request; the order is of the customer's cart as it is.
  effects.merge = await mergeGuestCart(cartCookie, deps.config.sessionSecret, api);
  if (effects.merge.kind === "rejected") {
    return endSession();
  }
  const result = await placeCustomerOrder(api, idempotencyKey, pickFields(body, CUSTOMER_ORDER_FIELDS));
  if (!result.ok && result.status === 401) {
    return endSession();
  }
  return toOutcome(result, effects);
}

async function placeForGuest(
  cartCookie: string | undefined,
  idempotencyKey: string,
  body: Record<string, unknown>,
  deps: CartDeps,
  effects: Effects,
): Promise<Outcome> {
  const cartId = readCartId(cartCookie, deps.config.sessionSecret);
  if (cartId === null) {
    // No cart, or a cookie the store did not issue (cleared, as in the cart BFF): nothing to order.
    effects.clearCart = cartCookie !== undefined;
    return { status: 409, body: { detail: CART_EMPTY } };
  }
  const result = await placeGuestOrder(deps.api(), idempotencyKey, { cartId, ...pickFields(body, GUEST_ORDER_FIELDS) });
  if (!result.ok && result.status === 404) {
    // The only 404 of this endpoint: the cart is gone (unknown, expired or merged).
    effects.clearCart = true;
  }
  return toOutcome(result, effects);
}
