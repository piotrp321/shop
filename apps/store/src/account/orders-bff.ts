import type { NextRequest } from "next/server";
import type { ApiClient } from "@/api/client";
import { readSignedValue, SESSION_COOKIE } from "@/auth/cookies";
import type { AuthDeps } from "@/auth/deps";
import { clearSessionCookie, json, logFailure } from "@/auth/responses";
import { isUuid } from "@/cart/cookies";

export const ORDER_NOT_FOUND = "Nie znaleźliśmy zamówienia.";

/** What the history BFF needs of the auth dependencies. */
export type AccountDeps = Pick<AuthDeps, "config" | "sessions" | "freshAccessToken" | "api">;

/** The answer of a read: `unauthorized` ends the session, `unavailable` is the generic 503. */
type Outcome = { kind: "ok"; body: unknown } | { kind: "not_found" } | { kind: "bad_request" } | { kind: "unauthorized" } | { kind: "unavailable" };

/**
 * Runs `read` with the API client of the signed-in customer. No session cookie or a forged one is a 401 (the forged
 * one is cleared); a session whose token cannot be refreshed or that the API refuses ends, with a 401. A failure
 * (API, Redis, Keycloak: nothing says the session is bad) is a 503 that keeps the session. Bodies carry no tokens.
 */
async function withSession(request: NextRequest, deps: AccountDeps, read: (api: ApiClient) => Promise<Outcome>): Promise<Response> {
  const cookie = request.cookies.get(SESSION_COOKIE)?.value;
  if (cookie === undefined) {
    return json({}, 401);
  }
  const sessionId = readSignedValue(cookie, deps.config.sessionSecret);
  if (sessionId === null) {
    return clearSessionCookie(json({}, 401), deps.config.storeUrl);
  }

  const endSession = async () => {
    await deps.sessions.delete(sessionId);
    return clearSessionCookie(json({}, 401), deps.config.storeUrl);
  };
  try {
    const accessToken = await deps.freshAccessToken(sessionId);
    if (accessToken === null) {
      return await endSession();
    }
    const outcome = await read(deps.api(accessToken));
    switch (outcome.kind) {
      case "ok":
        return json(outcome.body);
      case "not_found":
        return json({ detail: ORDER_NOT_FOUND }, 404);
      case "bad_request":
        return json({}, 400);
      case "unauthorized":
        return await endSession();
      case "unavailable":
        return json({}, 503);
    }
  } catch (error) {
    logFailure("The order history cannot be read", error);
    return json({}, 503);
  }
}

function answerOf(status: number, body: unknown, isExpected: (body: unknown) => boolean, what: string): Outcome {
  if (status === 200 && isExpected(body)) {
    return { kind: "ok", body };
  }
  switch (status) {
    case 401:
      return { kind: "unauthorized" };
    case 404:
      return { kind: "not_found" };
    case 400:
      return { kind: "bad_request" };
  }
  // Includes a 200 with an unexpected body.
  console.error(`The API answered ${status} to the read of ${what}.`);
  return { kind: "unavailable" };
}

const isPage = (body: unknown) => typeof body === "object" && body !== null && Array.isArray((body as { items?: unknown }).items);
const isOrder = (body: unknown) =>
  typeof body === "object" && body !== null && typeof (body as { orderId?: unknown }).orderId === "string" && Array.isArray((body as { lines?: unknown }).lines);

/**
 * `GET /api/account/orders?after=`: a page of the history of the signed-in customer (`GET /api/v1/me/orders`, the
 * page size of the API). Only the cursor of the query goes on; the customer is the one of the token. 200 with the page
 * as the API gives it, 400 for a cursor the API refuses, 401 without a session, 503 for the rest.
 */
export function runOrderList(request: NextRequest, deps: AccountDeps): Promise<Response> {
  const after = request.nextUrl.searchParams.get("after");
  return withSession(request, deps, async (api) => {
    const { data, response } = await api.GET("/api/v1/me/orders", { params: { query: after === null ? {} : { after } } });
    return answerOf(response.status, data, isPage, "the order list");
  });
}

/**
 * `GET /api/account/orders/{orderId}`: an order of the signed-in customer. 404 for an id that is no UUID (the API is
 * not asked) and for any order the API does not give this customer (unknown, another customer's, a guest's).
 */
export function runOrderRead(request: NextRequest, deps: AccountDeps, orderId: string): Promise<Response> {
  return withSession(request, deps, async (api) => {
    if (!isUuid(orderId)) {
      return { kind: "not_found" };
    }
    const { data, response } = await api.GET("/api/v1/me/orders/{orderId}", { params: { path: { orderId } } });
    return answerOf(response.status, data, isOrder, "an order");
  });
}
