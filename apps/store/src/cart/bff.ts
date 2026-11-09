import type { NextRequest } from "next/server";
import { readSignedValue, SESSION_COOKIE, signValue } from "@/auth/cookies";
import { clearSessionCookie, json, logFailure, noStore } from "@/auth/responses";
import type { CartSummaryBody } from "@/api/client";
import { type ApiResult, type CartApi, type CartView, createGuestCart, customerCart, guestCart } from "./api";
import { CART_COOKIE, cartCookieOptions, clearCartCookie, clearMergeNotice, hasMergeNotice, NOTICE_COOKIE, readCartId } from "./cookies";
import type { CartDeps } from "./deps";
import { applyGuestCartMerge, type GuestCartMerge, mergeGuestCart } from "./merge";

export type CartCommand =
  | { kind: "read"; discountCode?: string }
  | { kind: "summary" }
  | { kind: "add"; variantId: string; quantity: number }
  | { kind: "change"; variantId: string; quantity: number }
  | { kind: "remove"; variantId: string };

type Outcome = { status: number; body: unknown };
/**
 * What the answer does to the browser's cookies: the guest cart cookie (set or cleared), the merge of the guest cart
 * into the customer's (which clears it and may leave a notice), the notice shown by the summary, and the session.
 */
type Effects = { cart?: { id: string } | "clear"; merge?: GuestCartMerge; noticeShown?: boolean; endedSession?: boolean };

const LINE_MISSING = "Tej pozycji nie ma w koszyku.";
const INVALID_BODY = "Nieprawidłowe dane.";

const emptyCart: CartView = {
  id: null,
  lines: [],
  totals: { subtotalGross: 0, discountGross: 0, gross: 0, currency: "PLN", itemCount: 0 },
  hasExcludedLines: false,
  discount: null,
};
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
 * The 404 for a variant id that is not a UUID. The API would answer 404 for it too, which the BFF could mistake for a
 * cart that is gone, so such an id never reaches the API and the cookies stay as they are.
 */
export const lineMissing = () => json({ detail: LINE_MISSING }, 404);

/**
 * Chooses the cart (the customer's with a session, otherwise the guest's from `store_cart`), runs the command on
 * it and answers for the browser: 200 with the data, 404, 409 and 422 of the API with their `detail`, 401 when the
 * session ended, 503 for everything else. With a session, a guest cart of `store_cart` is merged into the customer's
 * first. Nothing here computes a price or a total.
 */
export async function runCartCommand(request: NextRequest, deps: CartDeps, command: CartCommand): Promise<Response> {
  const effects: Effects = {};
  const cartCookie = request.cookies.get(CART_COOKIE)?.value;
  let outcome: Outcome;
  try {
    const sessionId = readSignedValue(request.cookies.get(SESSION_COOKIE)?.value, deps.config.sessionSecret);
    outcome = sessionId
      ? await runForCustomer(sessionId, cartCookie, deps, command, effects)
      : await runForGuest(cartCookie, deps, command, effects);
  } catch (error) {
    logFailure("Cart request failed", error);
    outcome = { status: 503, body: {} };
  }
  if (command.kind === "summary" && outcome.status === 200) {
    outcome = withNotice(outcome, request.cookies.get(NOTICE_COOKIE)?.value, deps, effects);
  }
  return finish(outcome, effects, deps);
}

/**
 * The summary carries the notice of a truncated merge once: from the notice cookie, or from a merge of this very
 * request. A shown notice clears the cookie, also the one the merge would have set.
 */
function withNotice(outcome: Outcome, noticeCookie: string | undefined, deps: CartDeps, effects: Effects): Outcome {
  const truncatedNow = effects.merge?.kind === "merged" && effects.merge.truncated;
  if (!truncatedNow && !hasMergeNotice(noticeCookie, deps.config.sessionSecret)) {
    return outcome;
  }
  effects.noticeShown = true;
  return { status: 200, body: { ...(outcome.body as CartSummaryBody), notice: "merge_truncated" } satisfies CartSummaryBody };
}

function finish({ status, body }: Outcome, effects: Effects, { config }: CartDeps): Response {
  const response = json(body, status);
  if (effects.cart === "clear") {
    clearCartCookie(response, config.storeUrl);
  } else if (effects.cart) {
    response.cookies.set(CART_COOKIE, signValue(effects.cart.id, config.sessionSecret), cartCookieOptions(config.storeUrl));
  }
  if (effects.merge) {
    applyGuestCartMerge(response, effects.merge, config);
  }
  if (effects.noticeShown) {
    // Replaces a notice cookie the merge set above: the notice has been shown.
    clearMergeNotice(response, config.storeUrl);
  }
  return effects.endedSession ? clearSessionCookie(response, config.storeUrl) : noStore(response);
}

const isWrite = (command: CartCommand) => command.kind !== "read" && command.kind !== "summary";

function perform(cart: CartApi, command: CartCommand): Promise<ApiResult<CartView>> | Promise<ApiResult<{ itemCount: number }>> {
  switch (command.kind) {
    case "read":
      return cart.get(command.discountCode);
    case "summary":
      return cart.summary();
    case "add":
      return cart.add(command.variantId, command.quantity);
    case "change":
      return cart.change(command.variantId, command.quantity);
    case "remove":
      return cart.remove(command.variantId);
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
  if (result.status === 400 && command.kind === "read" && command.discountCode !== undefined) {
    // The code the customer typed is refused (too long, not text): the text of the API goes to the field of the code.
    return { status: 400, body: { detail: result.violation ?? result.detail } };
  }
  // Includes a 400 or 415 from the API, which would be a bug of this BFF and not something the browser can fix.
  console.error(`The API answered ${result.status} to a cart request.`);
  return { status: 503, body: {} };
}

const emptyFor = (command: CartCommand): Outcome =>
  command.kind === "summary" ? { status: 200, body: emptySummary } : { status: 200, body: emptyCart };

async function runForCustomer(
  sessionId: string,
  cartCookie: string | undefined,
  deps: CartDeps,
  command: CartCommand,
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
  // A failed merge keeps `store_cart`: the next request tries again, this one goes on with the customer's cart.
  effects.merge = await mergeGuestCart(cartCookie, deps.config.sessionSecret, api);
  if (effects.merge.kind === "rejected") {
    return endSession();
  }
  const result = await perform(customerCart(api), command);
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

  // A 404 of a write is a gone cart, a missing line or an unknown variant, and only the text of its `detail` tells
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
      const result = await guestCart(api, cartId).add(command.variantId, command.quantity);
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
    return toOutcome(await guestCart(api, created.data.id).add(command.variantId, command.quantity), command);
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
