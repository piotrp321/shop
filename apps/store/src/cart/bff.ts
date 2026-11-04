import type { NextRequest } from "next/server";
import { cookieOptions, readSignedValue, SESSION_COOKIE, signValue } from "@/auth/cookies";
import { clearSessionCookie, json, logFailure, noStore } from "@/auth/responses";
import type { CartSummaryBody } from "@/api/client";
import { type ApiResult, type CartApi, type CartView, createGuestCart, customerCart, guestCart } from "./api";
import { CART_COOKIE, cartCookieOptions, readCartId } from "./cookies";
import type { CartDeps } from "./deps";

export type CartCommand =
  | { kind: "read" }
  | { kind: "summary" }
  | { kind: "add"; productId: string; quantity: number }
  | { kind: "change"; productId: string; quantity: number }
  | { kind: "remove"; productId: string };

type Outcome = { status: number; body: unknown };
/** What the answer does to the browser's cookies: the guest cart cookie (set or cleared) and the session. */
type Effects = { cart?: { id: string } | "clear"; endedSession?: boolean };

const LINE_MISSING = "Tej pozycji nie ma w koszyku.";
const INVALID_BODY = "Nieprawidłowe dane.";

const emptyCart: CartView = { id: null, lines: [], totals: { gross: 0, currency: "PLN", itemCount: 0 }, hasExcludedLines: false };
const emptySummary: CartSummaryBody = { itemCount: 0, notice: null };

/**
 * CSRF guard of the writes. The cookies are SameSite=Lax, and on top of that a write must come from the store's
 * own pages (`Origin` equal to `STORE_URL`) and, when it has a body, say it is JSON (a cross-site form cannot).
 * Returns the 403 to send, or null.
 */
export function refuseForeignWrite(request: NextRequest, storeUrl: string, withBody: boolean): Response | null {
  const sameOrigin = request.headers.get("origin") === new URL(storeUrl).origin;
  const mediaType = request.headers.get("content-type")?.split(";")[0].trim().toLowerCase();
  if (!sameOrigin || (withBody && mediaType !== "application/json")) {
    return json({}, 403);
  }
  return null;
}

/** The JSON object of the request body, or null when the body is not one. */
export async function readJsonObject(request: NextRequest): Promise<Record<string, unknown> | null> {
  try {
    const body: unknown = await request.json();
    return typeof body === "object" && body !== null && !Array.isArray(body) ? (body as Record<string, unknown>) : null;
  } catch {
    return null;
  }
}

export const invalidBody = () => json({ detail: INVALID_BODY }, 422);

/**
 * The 404 for a product id that is not a UUID. The API would answer 404 for it too, which the BFF could mistake for a
 * cart that is gone, so such an id never reaches the API and the cookies stay as they are.
 */
export const lineMissing = () => json({ detail: LINE_MISSING }, 404);

/**
 * Chooses the cart (the customer's with a session, otherwise the guest's from `store_cart`), runs the command on
 * it and answers for the browser: 200 with the data, 404, 409 and 422 of the API with their `detail`, 401 when the
 * session ended, 503 for everything else. Nothing here computes a price or a total.
 */
export async function runCartCommand(request: NextRequest, deps: CartDeps, command: CartCommand): Promise<Response> {
  const effects: Effects = {};
  let outcome: Outcome;
  try {
    const sessionId = readSignedValue(request.cookies.get(SESSION_COOKIE)?.value, deps.config.sessionSecret);
    outcome = sessionId
      ? await runForCustomer(sessionId, deps, command, effects)
      : await runForGuest(request.cookies.get(CART_COOKIE)?.value, deps, command, effects);
  } catch (error) {
    logFailure("Cart request failed", error);
    outcome = { status: 503, body: {} };
  }
  return finish(outcome, effects, deps);
}

function finish({ status, body }: Outcome, effects: Effects, { config }: CartDeps): Response {
  const response = json(body, status);
  if (effects.cart === "clear") {
    response.cookies.set(CART_COOKIE, "", { ...cookieOptions(config.storeUrl), maxAge: 0 });
  } else if (effects.cart) {
    response.cookies.set(CART_COOKIE, signValue(effects.cart.id, config.sessionSecret), cartCookieOptions(config.storeUrl));
  }
  return effects.endedSession ? clearSessionCookie(response, config.storeUrl) : noStore(response);
}

const isWrite = (command: CartCommand) => command.kind !== "read" && command.kind !== "summary";

function perform(cart: CartApi, command: CartCommand): Promise<ApiResult<CartView>> | Promise<ApiResult<{ itemCount: number }>> {
  switch (command.kind) {
    case "read":
      return cart.get();
    case "summary":
      return cart.summary();
    case "add":
      return cart.add(command.productId, command.quantity);
    case "change":
      return cart.change(command.productId, command.quantity);
    case "remove":
      return cart.remove(command.productId);
  }
}

/** The answer for the browser. Refusals the browser can act on keep their `detail`; the rest is a 503. */
function toOutcome(result: ApiResult<CartView | { itemCount: number }>, command: CartCommand): Outcome {
  if (result.ok) {
    return command.kind === "summary"
      ? { status: 200, body: { itemCount: (result.data as { itemCount: number }).itemCount, notice: null } satisfies CartSummaryBody }
      : { status: 200, body: result.data };
  }
  if (result.status === 404 || result.status === 409 || result.status === 422) {
    return { status: result.status, body: { detail: result.detail } };
  }
  // Includes a 400 or 415 from the API, which would be a bug of this BFF and not something the browser can fix.
  console.error(`The API answered ${result.status} to a cart request.`);
  return { status: 503, body: {} };
}

const emptyFor = (command: CartCommand): Outcome =>
  command.kind === "summary" ? { status: 200, body: emptySummary } : { status: 200, body: emptyCart };

async function runForCustomer(sessionId: string, deps: CartDeps, command: CartCommand, effects: Effects): Promise<Outcome> {
  const endSession = async (): Promise<Outcome> => {
    await deps.sessions.delete(sessionId);
    effects.endedSession = true;
    return { status: 401, body: {} };
  };

  const accessToken = await deps.freshAccessToken(sessionId);
  if (!accessToken) {
    return endSession();
  }
  const result = await perform(customerCart(deps.api(accessToken)), command);
  if (!result.ok && result.status === 401) {
    return endSession();
  }
  return toOutcome(result, command);
}

async function runForGuest(cookie: string | undefined, deps: CartDeps, command: CartCommand, effects: Effects): Promise<Outcome> {
  const api = deps.api();
  const cartId = readCartId(cookie, deps.config.sessionSecret);
  if (cookie !== undefined && cartId === null) {
    effects.cart = "clear";
  }

  // A 404 of a write is a gone cart, a missing line or an unknown product, and only the text of its `detail` tells
  // them apart. A change of that text must not turn a gone cart into an endless error, so the cart is asked for
  // its summary instead (404 only when the cart is gone).
  const cartIsGone = async (id: string): Promise<boolean> => {
    const probe = await guestCart(api, id).summary();
    if (probe.ok) return false;
    if (probe.status === 404) return true;
    throw new Error(`The API answered ${probe.status} to the check of a guest cart.`);
  };

  if (command.kind === "add") {
    if (cartId) {
      const result = await guestCart(api, cartId).add(command.productId, command.quantity);
      if (result.ok) {
        effects.cart = { id: cartId };
      }
      if (result.ok || result.status !== 404 || !(await cartIsGone(cartId))) {
        return toOutcome(result, command);
      }
      effects.cart = "clear";
    }
    const created = await createGuestCart(api);
    if (!created.ok || created.data.id === null) {
      console.error("A guest cart could not be created.");
      return { status: 503, body: {} };
    }
    effects.cart = { id: created.data.id };
    return toOutcome(await guestCart(api, created.data.id).add(command.productId, command.quantity), command);
  }

  if (!cartId) {
    // Nothing to read or remove, and no line to change.
    return command.kind === "change" ? { status: 404, body: { detail: LINE_MISSING } } : emptyFor(command);
  }

  const result = await perform(guestCart(api, cartId), command);
  if (result.ok) {
    if (isWrite(command)) {
      effects.cart = { id: cartId };
    }
    return toOutcome(result, command);
  }
  if (result.status !== 404) {
    return toOutcome(result, command);
  }
  if (command.kind === "change") {
    // A 404 of the cart and of the line look the same; only a gone cart is cleared, and the line is missing either way.
    if (await cartIsGone(cartId)) {
      effects.cart = "clear";
      return { status: 404, body: { detail: LINE_MISSING } };
    }
    return toOutcome(result, command);
  }
  // Reading or removing in a cart that is gone: it is empty now.
  effects.cart = "clear";
  return emptyFor(command);
}
