import { beforeEach, describe, expect, it, vi } from "vitest";
import { CART_COOKIE } from "@/cart/cookies";
import { getCartDeps } from "@/cart/deps";
import { RefreshUnavailableError } from "@/auth/refresh";
import { SESSION_COOKIE } from "@/auth/cookies";
import { sessionData } from "@/auth/test-support";
import {
  cartRequest,
  cartTestDeps,
  emptyView,
  GUEST_CART_ID,
  isCleared,
  problem,
  sessionCookie,
  setCookie,
  signedCartCookie,
  viewWithOneLine,
} from "@/cart/test-support";
import { GET } from "./route";

vi.mock("@/cart/deps", () => ({ getCartDeps: vi.fn() }));

let setup: ReturnType<typeof cartTestDeps>;

beforeEach(() => {
  setup = cartTestDeps();
  vi.mocked(getCartDeps).mockResolvedValue(setup.deps);
  vi.spyOn(console, "error").mockImplementation(() => {});
});

const read = (cookie?: string) => GET(cartRequest("GET", "/api/cart", { cookie }));

describe("GET /api/cart as a guest", () => {
  it("returns an empty cart without a cookie, without asking the API, and is never cached", async () => {
    const response = await read();

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual(emptyView());
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(response.headers.get("set-cookie")).toBeNull();
    expect(setup.calls).toEqual([]);
  });

  it("reads the guest cart named by the signed cookie, with no token", async () => {
    setup.answer("GET", `/api/v1/guest-carts/${GUEST_CART_ID}`, () => Response.json(viewWithOneLine(GUEST_CART_ID, 2)));
    const response = await read(signedCartCookie(GUEST_CART_ID));

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual(viewWithOneLine(GUEST_CART_ID, 2));
    expect(setup.calls).toEqual([
      { method: "GET", path: `/api/v1/guest-carts/${GUEST_CART_ID}`, authorization: null, body: undefined },
    ]);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    // A read does not renew the cookie: the cart expires 30 days after its last change.
    expect(response.headers.get("set-cookie")).toBeNull();
  });

  it.each([
    ["unsigned", `${CART_COOKIE}=${GUEST_CART_ID}`],
    ["signed with another secret", signedCartCookie(GUEST_CART_ID, "x".repeat(64))],
    ["signed but not a cart id", signedCartCookie("session-1")],
  ])("clears a cookie that is %s and answers as without one, without asking the API", async (_name, cookie) => {
    const response = await read(cookie);

    expect(await response.json()).toEqual(emptyView());
    expect(isCleared(setCookie(response, CART_COOKIE))).toBe(true);
    expect(setup.calls).toEqual([]);
  });

  it("clears the cookie and returns an empty cart when the API does not know the cart", async () => {
    setup.answer("GET", `/api/v1/guest-carts/${GUEST_CART_ID}`, () => problem(404, "Koszyk nie jest już dostępny."));
    const response = await read(signedCartCookie(GUEST_CART_ID));

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual(emptyView());
    expect(isCleared(setCookie(response, CART_COOKIE))).toBe(true);
    expect(setup.calls).toHaveLength(1);
  });

  it.each([
    ["500", () => problem(500)],
    ["503", () => problem(503)],
    ["a network error", () => Promise.reject(new TypeError("fetch failed"))],
  ])("answers 503 and keeps the cookie when the API answers with %s", async (_name, respond) => {
    setup.answer("GET", `/api/v1/guest-carts/${GUEST_CART_ID}`, respond as () => Response);
    const response = await read(signedCartCookie(GUEST_CART_ID));

    expect(response.status).toBe(503);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(response.headers.get("set-cookie")).toBeNull();
  });
});

describe("GET /api/cart with a session", () => {
  it("reads the cart of the customer with the session's fresh access token and ignores the guest cookie", async () => {
    const id = await setup.deps.sessions.create(sessionData({ accessToken: "access-9" }));
    setup.answer("GET", "/api/v1/me/cart", () => Response.json(viewWithOneLine(null, 3)));
    const response = await read(`${sessionCookie(id)}; ${signedCartCookie(GUEST_CART_ID)}`);

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual(viewWithOneLine(null, 3));
    expect(setup.deps.freshAccessToken).toHaveBeenCalledWith(id);
    expect(setup.calls).toEqual([{ method: "GET", path: "/api/v1/me/cart", authorization: "Bearer access-9", body: undefined }]);
    expect(response.headers.get("set-cookie")).toBeNull();
  });

  it("ends the session and answers 401 when the token cannot be refreshed", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    setup.deps.freshAccessToken.mockResolvedValue(null);
    const response = await read(sessionCookie(id));

    expect(response.status).toBe(401);
    expect(setup.sessions.has(id)).toBe(false);
    expect(isCleared(setCookie(response, SESSION_COOKIE))).toBe(true);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(setup.calls).toEqual([]);
  });

  it("ends the session and answers 401 when the API rejects the fresh token", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    setup.answer("GET", "/api/v1/me/cart", () => problem(401));
    const response = await read(sessionCookie(id));

    expect(response.status).toBe(401);
    expect(setup.sessions.has(id)).toBe(false);
    expect(isCleared(setCookie(response, SESSION_COOKIE))).toBe(true);
  });

  it.each([
    ["the token cannot be refreshed right now", () => setup.deps.freshAccessToken.mockRejectedValue(new RefreshUnavailableError())],
    ["Redis fails", () => setup.deps.freshAccessToken.mockRejectedValue(new Error("Redis is down"))],
    ["the API answers 500", () => setup.answer("GET", "/api/v1/me/cart", () => problem(500))],
  ])("answers 503 and keeps the session when %s", async (_name, arrange) => {
    const id = await setup.deps.sessions.create(sessionData());
    arrange();
    const response = await read(sessionCookie(id));

    expect(response.status).toBe(503);
    expect(setup.sessions.has(id)).toBe(true);
    expect(response.headers.get("set-cookie")).toBeNull();
    expect(response.headers.get("cache-control")).toBe("private, no-store");
  });

  it("treats a session cookie with a bad signature as no session", async () => {
    const response = await read(`${SESSION_COOKIE}=session-1.forged`);

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual(emptyView());
    expect(setup.deps.freshAccessToken).not.toHaveBeenCalled();
  });
});
