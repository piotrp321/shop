import type { NextRequest } from "next/server";
import type { ApiClient } from "@/api/client";
import { readSignedValue, SESSION_COOKIE } from "@/auth/cookies";
import { clearSessionCookie, json, logFailure, noStore } from "@/auth/responses";
import { invalidBody, readJsonObject, refuseForeignWrite } from "@/cart/bff";
import { CART_COOKIE, clearCartCookie, readCartId } from "@/cart/cookies";
import type { CartDeps } from "@/cart/deps";
import { applyGuestCartMerge, type GuestCartMerge, mergeGuestCart } from "@/cart/merge";
import { CUSTOMER_ORDER_FIELDS, GUEST_ORDER_FIELDS, type OrderResult, type OrderView, pickFields, placeCustomerOrder, placeGuestOrder } from "./api";
import { setOrderConfirmation } from "./cookies";
import { listPaymentProviders, startCustomerPayment, startGuestPayment } from "./payment-api";
import { CART_EMPTY_TYPE } from "./problems";

const CART_EMPTY = "Koszyk jest pusty.";
const CHOOSE_PROVIDER = "Wybierz sposób płatności.";

type Outcome = { status: number; body: unknown };
/** What the answer does to the browser's cookies, as in the cart BFF, plus the confirmation of a placed order. */
type Effects = { clearCart?: boolean; merge?: GuestCartMerge; endedSession?: boolean; order?: OrderView };

/** The browser's choice of the provider, checked against the list of the API before anything is placed. */
type Choice = { provider: string };

/**
 * `POST /api/checkout`: places the order of the whole cart and starts its payment at the provider the browser chose
 * (`provider` of the body, one of `GET /api/v1/payment-providers`; anything else is a 422 for that field, an
 * unreadable list a 503, both before anything is placed). The customer's cart with a session (after the merge of
 * `store_cart`, as before every cart operation), otherwise the guest cart of `store_cart`. The browser chooses the
 * `Idempotency-Key` and sends it again on a retry; the payment start carries the same key. Answers 201/200
 * `{ number, redirectUrl }` with the signed confirmation cookie (`redirectUrl` is null when the payment could not be
 * started: the order stands and the confirmation page offers the payment again), 404, 409 and 422 of the API with
 * their `detail` and `violations` (and the `type` of a 409, which names its cause), 401 when the session ended and 503
 * for the rest. Nothing here computes a price or a total: the API takes lines and prices from the cart and Pricing.
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
    const providers = await listPaymentProviders(deps.api());
    if (providers === null) {
      console.error("The list of payment providers is not available.");
      return finish({ status: 503, body: {} }, effects, deps);
    }
    if (typeof body.provider !== "string" || !providers.some(({ code }) => code === body.provider)) {
      return finish({ status: 422, body: { violations: [{ propertyPath: "provider", title: CHOOSE_PROVIDER }] } }, effects, deps);
    }
    const choice: Choice = { provider: body.provider };
    const sessionId = readSignedValue(request.cookies.get(SESSION_COOKIE)?.value, deps.config.sessionSecret);
    outcome = sessionId
      ? await placeForCustomer(sessionId, cartCookie, idempotencyKey, body, choice, deps, effects)
      : await placeForGuest(cartCookie, idempotencyKey, body, choice, deps, effects);
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
    const { orderId, number, email, totals, accessToken } = effects.order;
    setOrderConfirmation(response, { orderId, number, email, totalGross: totals.gross, currency: totals.currency, accessToken }, config);
  }
  return effects.endedSession ? clearSessionCookie(response, config.storeUrl) : noStore(response);
}

/**
 * The answer for the browser. Refusals the browser can act on keep their `detail` and `violations`, a 409 also its
 * `type` as the API sent it; the rest is a 503.
 */
async function toOutcome(result: OrderResult, effects: Effects, api: ApiClient, idempotencyKey: string, { provider }: Choice): Promise<Outcome> {
  if (result.ok) {
    effects.order = result.data;
    const redirectUrl = await startPayment(api, result.data, idempotencyKey, provider);
    return { status: result.status, body: { number: result.data.number, redirectUrl } };
  }
  if (result.status === 409) {
    return { status: 409, body: { type: result.type, detail: result.detail, violations: result.violations } };
  }
  if (result.status === 404 || result.status === 422) {
    return { status: result.status, body: { detail: result.detail, violations: result.violations } };
  }
  // Includes a 400 or 415 of the API (a bug of this BFF, nothing the browser can fix) and a 401 of the guest endpoint.
  console.error(`The API answered ${result.status} to an order.`);
  return { status: 503, body: {} };
}

/**
 * Starts the payment of the order just placed (or placed by the same request before) with the key of the placement and
 * answers the address of the provider. Any failure leaves the order as it is and answers null: the customer pays from
 * the confirmation page, and this includes the 409 of the same key meeting another provider. The log names the status
 * only, never the access token.
 */
async function startPayment(api: ApiClient, order: OrderView, idempotencyKey: string, provider: string): Promise<string | null> {
  try {
    const result =
      order.accessToken === null
        ? await startCustomerPayment(api, order.orderId, idempotencyKey, provider)
        : await startGuestPayment(api, order.orderId, order.accessToken, idempotencyKey, provider);
    if (result.ok) {
      return result.data.redirectUrl;
    }
    console.error(`The API answered ${result.status} to the start of a payment.`);
  } catch (error) {
    logFailure("The start of a payment failed", error);
  }
  return null;
}

async function placeForCustomer(
  sessionId: string,
  cartCookie: string | undefined,
  idempotencyKey: string,
  body: Record<string, unknown>,
  choice: Choice,
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
  return toOutcome(result, effects, api, idempotencyKey, choice);
}

async function placeForGuest(
  cartCookie: string | undefined,
  idempotencyKey: string,
  body: Record<string, unknown>,
  choice: Choice,
  deps: CartDeps,
  effects: Effects,
): Promise<Outcome> {
  const cartId = readCartId(cartCookie, deps.config.sessionSecret);
  if (cartId === null) {
    // No cart, or a cookie the store did not issue (cleared, as in the cart BFF): nothing to order.
    effects.clearCart = cartCookie !== undefined;
    return { status: 409, body: { type: CART_EMPTY_TYPE, detail: CART_EMPTY } };
  }
  const api = deps.api();
  const result = await placeGuestOrder(api, idempotencyKey, { cartId, ...pickFields(body, GUEST_ORDER_FIELDS) });
  if (!result.ok && result.status === 404) {
    // The only 404 of this endpoint: the cart is gone (unknown, expired or merged).
    effects.clearCart = true;
  }
  return toOutcome(result, effects, api, idempotencyKey, choice);
}
