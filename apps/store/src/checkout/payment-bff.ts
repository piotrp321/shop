import type { NextRequest } from "next/server";
import type { ApiClient } from "@/api/client";
import { readSignedValue, SESSION_COOKIE } from "@/auth/cookies";
import { clearSessionCookie, json, logFailure, noStore } from "@/auth/responses";
import { invalidBody, readJsonObject, refuseForeignWrite } from "@/cart/bff";
import type { CartDeps } from "@/cart/deps";
import { pickFields } from "./api";
import { CONFIRMATION_COOKIE, type OrderConfirmation, readOrderConfirmation } from "./cookies";
import {
  type PaymentStatusAnswer,
  type PaymentStatusView,
  readCustomerPaymentStatus,
  readGuestPaymentStatus,
  type PaymentStartAnswer,
  startCustomerPayment,
  startGuestPayment,
} from "./payment-api";

export const ORDER_NOT_FOUND = "Nie znaleźliśmy zamówienia.";

/** How the order of the confirmation cookie is reached: a guest with its access token, a customer with the session. */
type Channel =
  | { kind: "guest"; api: ApiClient; accessToken: string }
  | { kind: "customer"; api: ApiClient; sessionId: string }
  /** `sessionId` is set when a session exists and has to end (its token cannot be refreshed). */
  | { kind: "unauthorized"; sessionId?: string };

/**
 * The token of the cookie decides, not the session: a guest who signed in after the order still reaches it with the
 * token, and an order of a customer (`accessToken: null`) needs the session of that customer.
 */
async function openChannel(confirmation: OrderConfirmation, sessionCookie: string | undefined, deps: CartDeps): Promise<Channel> {
  if (confirmation.accessToken !== null) {
    return { kind: "guest", api: deps.api(), accessToken: confirmation.accessToken };
  }
  const sessionId = readSignedValue(sessionCookie, deps.config.sessionSecret);
  if (sessionId === null) {
    return { kind: "unauthorized" };
  }
  const token = await deps.freshAccessToken(sessionId);
  return token === null ? { kind: "unauthorized", sessionId } : { kind: "customer", api: deps.api(token), sessionId };
}

/** The order of the `store_order_confirmation` cookie, or null for no cookie, a forged one or one of an older shape. */
function confirmationOf(request: NextRequest, deps: CartDeps): OrderConfirmation | null {
  return readOrderConfirmation(request.cookies.get(CONFIRMATION_COOKIE)?.value, deps.config.sessionSecret);
}

type Outcome = { status: number; body: unknown; endSession?: string };

async function finish(outcome: Outcome, deps: CartDeps): Promise<Response> {
  const response = json(outcome.body, outcome.status);
  if (outcome.endSession === undefined) {
    return noStore(response);
  }
  await deps.sessions.delete(outcome.endSession);
  return clearSessionCookie(response, deps.config.storeUrl);
}

const unavailable = (): Outcome => ({ status: 503, body: {} });
const notFound = (): Outcome => ({ status: 404, body: { detail: ORDER_NOT_FOUND } });

function toStartOutcome(result: PaymentStartAnswer, channel: Channel): Outcome {
  if (result.ok) {
    return { status: 200, body: { redirectUrl: result.data.redirectUrl } };
  }
  switch (result.status) {
    case 409:
      return { status: 409, body: { type: result.type, detail: result.detail } };
    case 404:
    case 422:
      return { status: result.status, body: { detail: result.detail, violations: result.violations } };
    case 401:
      // Only the customer's endpoint asks for a token: its 401 means the session is over.
      if (channel.kind === "customer") {
        return { status: 401, body: {}, endSession: channel.sessionId };
      }
  }
  // Includes a 400 or 415 of the API (a bug of this BFF, nothing the browser can fix) and a 401 of the guest endpoint.
  console.error(`The API answered ${result.status} to the start of a payment.`);
  return unavailable();
}

/**
 * `POST /api/checkout/payment`: starts a payment of the order of the confirmation cookie at the provider of the body
 * (`{ provider }`), for a retry or a second attempt from the confirmation page. The browser chooses the
 * `Idempotency-Key` as at the checkout. Answers 200 `{ redirectUrl }`; 404 (no or forged cookie, or the API does not
 * know the order), 409 with the `type` of its cause and 422 of the API with their `detail` and `violations`; 401 when a
 * customer's session ended and 503 for the rest.
 */
export async function runPaymentStart(request: NextRequest, deps: CartDeps): Promise<Response> {
  const refused = refuseForeignWrite(request, deps.config.storeUrl, true);
  if (refused) {
    return refused;
  }
  const idempotencyKey = request.headers.get("idempotency-key");
  const body = await readJsonObject(request);
  if (!idempotencyKey || !body) {
    return invalidBody();
  }
  const confirmation = confirmationOf(request, deps);
  if (confirmation === null) {
    return finish(notFound(), deps);
  }

  let outcome: Outcome;
  try {
    const channel = await openChannel(confirmation, request.cookies.get(SESSION_COOKIE)?.value, deps);
    if (channel.kind === "unauthorized") {
      outcome = { status: 401, body: {}, endSession: channel.sessionId };
    } else {
      const fields = pickFields(body, ["provider"]) as { provider?: string };
      const result =
        channel.kind === "guest"
          ? await startGuestPayment(channel.api, confirmation.orderId, channel.accessToken, idempotencyKey, fields.provider as string)
          : await startCustomerPayment(channel.api, confirmation.orderId, idempotencyKey, fields.provider as string);
      outcome = toStartOutcome(result, channel);
    }
  } catch (error) {
    logFailure("Payment start failed", error);
    outcome = unavailable();
  }
  return finish(outcome, deps);
}

/** What reading the payment status of an order came to; the confirmation page and `GET /api/checkout/payment` share it. */
export type PaymentStatusOutcome =
  | { kind: "ok"; status: PaymentStatusView }
  | { kind: "not_found" }
  /** `endSession` is the session to end (its token is gone or the API refused it). */
  | { kind: "unauthorized"; endSession?: string }
  | { kind: "failed" };

function isStatusView(data: unknown): data is PaymentStatusView {
  const view = data as Partial<PaymentStatusView> | null;
  return (
    typeof view === "object" &&
    view !== null &&
    typeof view.orderNumber === "string" &&
    (view.orderStatus === "new" || view.orderStatus === "paid" || view.orderStatus === "cancelled") &&
    typeof view.canStartPayment === "boolean"
  );
}

function toStatusOutcome(result: PaymentStatusAnswer, channel: Channel): PaymentStatusOutcome {
  if (result.ok) {
    return isStatusView(result.data) ? { kind: "ok", status: result.data } : { kind: "failed" };
  }
  if (result.status === 404) {
    return { kind: "not_found" };
  }
  if (result.status === 401 && channel.kind === "customer") {
    return { kind: "unauthorized", endSession: channel.sessionId };
  }
  console.error(`The API answered ${result.status} to the read of a payment status.`);
  return { kind: "failed" };
}

/**
 * The status of the order of `confirmation` from the API: with its access token for a guest order, with the session
 * for a customer's. Never throws: a failure of any kind is `failed`.
 */
export async function readPaymentStatus(confirmation: OrderConfirmation, sessionCookie: string | undefined, deps: CartDeps): Promise<PaymentStatusOutcome> {
  try {
    const channel = await openChannel(confirmation, sessionCookie, deps);
    if (channel.kind === "unauthorized") {
      return { kind: "unauthorized", endSession: channel.sessionId };
    }
    const result =
      channel.kind === "guest"
        ? await readGuestPaymentStatus(channel.api, confirmation.orderId, channel.accessToken)
        : await readCustomerPaymentStatus(channel.api, confirmation.orderId);
    return toStatusOutcome(result, channel);
  } catch (error) {
    logFailure("The payment status cannot be read", error);
    return { kind: "failed" };
  }
}

/**
 * `GET /api/checkout/payment`: the `PaymentStatusView` of the API for the order of the confirmation cookie. 404 without
 * the cookie or when the API does not know the order, 401 when a customer's order has no session (a 401 of the API also
 * ends it), 503 for the rest.
 */
export async function runPaymentStatus(request: NextRequest, deps: CartDeps): Promise<Response> {
  const confirmation = confirmationOf(request, deps);
  if (confirmation === null) {
    return finish(notFound(), deps);
  }
  const read = await readPaymentStatus(confirmation, request.cookies.get(SESSION_COOKIE)?.value, deps);
  switch (read.kind) {
    case "ok":
      return finish({ status: 200, body: read.status }, deps);
    case "not_found":
      return finish(notFound(), deps);
    case "unauthorized":
      return finish({ status: 401, body: {}, endSession: read.endSession }, deps);
    case "failed":
      return finish(unavailable(), deps);
  }
}
