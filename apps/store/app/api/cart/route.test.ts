import { beforeEach, describe, expect, it, vi } from "vitest";
import { CART_COOKIE } from "@/cart/cookies";
import { getCartDeps } from "@/cart/deps";
import { RefreshUnavailableError } from "@/auth/refresh";
import { readSignedValue, SESSION_COOKIE } from "@/auth/cookies";
import { sessionData, testConfig } from "@/auth/test-support";
import {
  cartRequest,
  cartTestDeps,
  emptyView,
  GUEST_CART_ID,
  isCleared,
  mergeAnswer,
  NOTICE_COOKIE_NAME,
  problem,
  sessionCookie,
  setCookie,
  signedCartCookie,
  signedNoticeCookie,
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
  it("reads the cart of the customer with the session's fresh access token", async () => {
    const id = await setup.deps.sessions.create(sessionData({ accessToken: "access-9" }));
    setup.answer("GET", "/api/v1/me/cart", () => Response.json(viewWithOneLine(null, 3)));
    const response = await read(sessionCookie(id));

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

describe("GET /api/cart with a session and a guest cart cookie", () => {
  const MERGE = "/api/v1/me/cart/merge";
  const withGuestCart = (sessionId: string) => `${sessionCookie(sessionId)}; ${signedCartCookie(GUEST_CART_ID)}`;

  it.each([["merged" as const], ["already_merged" as const], ["nothing_to_merge" as const]])(
    "merges the guest cart into the customer cart first and clears store_cart when the merge ends with %s",
    async (result) => {
      const id = await setup.deps.sessions.create(sessionData({ accessToken: "access-9" }));
      setup.answer("POST", MERGE, () => mergeAnswer(result));
      setup.answer("GET", "/api/v1/me/cart", () => Response.json(viewWithOneLine(null, 3)));
      const response = await read(withGuestCart(id));

      expect(response.status).toBe(200);
      expect(await response.json()).toEqual(viewWithOneLine(null, 3));
      expect(setup.calls).toEqual([
        { method: "POST", path: MERGE, authorization: "Bearer access-9", body: { guestCartId: GUEST_CART_ID } },
        { method: "GET", path: "/api/v1/me/cart", authorization: "Bearer access-9", body: undefined },
      ]);
      expect(isCleared(setCookie(response, CART_COOKIE))).toBe(true);
      expect(setCookie(response, NOTICE_COOKIE_NAME)).toBeUndefined();
      expect(response.headers.get("cache-control")).toBe("private, no-store");
    },
  );

  it("sets the signed notice cookie for 5 minutes when the merge was truncated", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    setup.answer("POST", MERGE, () => mergeAnswer("merged", true));
    setup.answer("GET", "/api/v1/me/cart", () => Response.json(viewWithOneLine(null, 99)));
    const response = await read(withGuestCart(id));

    expect(response.status).toBe(200);
    expect(isCleared(setCookie(response, CART_COOKIE))).toBe(true);
    const notice = setCookie(response, NOTICE_COOKIE_NAME)!;
    const value = decodeURIComponent(notice.split(";")[0].slice(NOTICE_COOKIE_NAME.length + 1));
    expect(readSignedValue(value, testConfig.sessionSecret)).toBe("merge_truncated");
    expect(notice).toMatch(/HttpOnly/i);
    expect(notice).toMatch(/SameSite=lax/i);
    expect(notice).toContain("Path=/");
    expect(notice).toContain("Max-Age=300");
    expect(notice).not.toMatch(/Secure/i);
  });

  it("marks the notice cookie Secure outside a local store", async () => {
    setup.deps.config = { ...setup.deps.config, storeUrl: "https://shop.example" };
    const id = await setup.deps.sessions.create(sessionData());
    setup.answer("POST", MERGE, () => mergeAnswer("merged", true));
    setup.answer("GET", "/api/v1/me/cart", () => Response.json(viewWithOneLine(null)));
    const response = await read(withGuestCart(id));

    expect(setCookie(response, NOTICE_COOKIE_NAME)).toMatch(/Secure/i);
  });

  it.each([
    ["500", () => problem(500)],
    ["503", () => problem(503)],
    ["a network error", () => Promise.reject(new TypeError("fetch failed"))],
  ])("keeps store_cart, logs the failure and reads the customer cart when the merge fails with %s", async (_name, respond) => {
    const id = await setup.deps.sessions.create(sessionData());
    setup.answer("POST", MERGE, respond as () => Response);
    setup.answer("GET", "/api/v1/me/cart", () => Response.json(viewWithOneLine(null, 3)));
    const response = await read(withGuestCart(id));

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual(viewWithOneLine(null, 3));
    expect(setup.calls.map((call) => `${call.method} ${call.path}`)).toEqual([`POST ${MERGE}`, "GET /api/v1/me/cart"]);
    expect(response.headers.get("set-cookie")).toBeNull();
    expect(setup.sessions.has(id)).toBe(true);
    expect(console.error).toHaveBeenCalledWith(expect.stringMatching(/merge/i));
  });

  it("ends the session and answers 401, keeping store_cart, when the API rejects the token of the merge", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    setup.answer("POST", MERGE, () => problem(401));
    const response = await read(withGuestCart(id));

    expect(response.status).toBe(401);
    expect(setup.sessions.has(id)).toBe(false);
    expect(isCleared(setCookie(response, SESSION_COOKIE))).toBe(true);
    expect(setCookie(response, CART_COOKIE)).toBeUndefined();
    expect(setup.calls.map((call) => call.path)).toEqual([MERGE]);
  });

  it("ends the session and answers 401 without merging, keeping store_cart, when the token cannot be refreshed", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    setup.deps.freshAccessToken.mockResolvedValue(null);
    const response = await read(withGuestCart(id));

    expect(response.status).toBe(401);
    expect(setup.calls).toEqual([]);
    expect(setCookie(response, CART_COOKIE)).toBeUndefined();
  });

  it("answers 503 without merging, keeping the session and store_cart, when the token cannot be refreshed right now", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    setup.deps.freshAccessToken.mockRejectedValue(new RefreshUnavailableError());
    const response = await read(withGuestCart(id));

    expect(response.status).toBe(503);
    expect(setup.calls).toEqual([]);
    expect(setup.sessions.has(id)).toBe(true);
    expect(response.headers.get("set-cookie")).toBeNull();
  });

  it.each([
    ["unsigned", `${CART_COOKIE}=${GUEST_CART_ID}`],
    ["signed with another secret", signedCartCookie(GUEST_CART_ID, "x".repeat(64))],
    ["signed but not a cart id", signedCartCookie("session-1")],
  ])("clears a store_cart cookie that is %s and reads the customer cart without merging", async (_name, cookie) => {
    const id = await setup.deps.sessions.create(sessionData());
    setup.answer("GET", "/api/v1/me/cart", () => Response.json(viewWithOneLine(null)));
    const response = await read(`${sessionCookie(id)}; ${cookie}`);

    expect(response.status).toBe(200);
    expect(setup.calls.map((call) => call.path)).toEqual(["/api/v1/me/cart"]);
    expect(isCleared(setCookie(response, CART_COOKIE))).toBe(true);
  });

  it("leaves the notice cookie to the summary", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    setup.answer("GET", "/api/v1/me/cart", () => Response.json(viewWithOneLine(null)));
    const response = await read(`${sessionCookie(id)}; ${signedNoticeCookie()}`);

    expect(response.status).toBe(200);
    expect(response.headers.get("set-cookie")).toBeNull();
  });
});
