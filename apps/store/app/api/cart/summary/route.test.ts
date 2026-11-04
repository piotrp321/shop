import { beforeEach, describe, expect, it, vi } from "vitest";
import { SESSION_COOKIE } from "@/auth/cookies";
import { sessionData } from "@/auth/test-support";
import { CART_COOKIE } from "@/cart/cookies";
import { getCartDeps } from "@/cart/deps";
import {
  cartRequest,
  cartTestDeps,
  GUEST_CART_ID,
  isCleared,
  mergeAnswer,
  NOTICE_COOKIE_NAME,
  problem,
  sessionCookie,
  setCookie,
  signedCartCookie,
  signedNoticeCookie,
} from "@/cart/test-support";
import { GET } from "./route";

vi.mock("@/cart/deps", () => ({ getCartDeps: vi.fn() }));

let setup: ReturnType<typeof cartTestDeps>;

beforeEach(() => {
  setup = cartTestDeps();
  vi.mocked(getCartDeps).mockResolvedValue(setup.deps);
  vi.spyOn(console, "error").mockImplementation(() => {});
});

const summary = (cookie?: string) => GET(cartRequest("GET", "/api/cart/summary", { cookie }));

describe("GET /api/cart/summary", () => {
  it("returns 0 and a null notice without a cookie, without asking the API", async () => {
    const response = await summary();

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual({ itemCount: 0, notice: null });
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(setup.calls).toEqual([]);
  });

  it("returns the units of the guest cart from its summary endpoint", async () => {
    setup.answer("GET", `/api/v1/guest-carts/${GUEST_CART_ID}/summary`, () => Response.json({ itemCount: 4 }));
    const response = await summary(signedCartCookie(GUEST_CART_ID));

    expect(await response.json()).toEqual({ itemCount: 4, notice: null });
    expect(setup.calls).toEqual([
      { method: "GET", path: `/api/v1/guest-carts/${GUEST_CART_ID}/summary`, authorization: null, body: undefined },
    ]);
  });

  it("returns 0 and clears the cookie when the API does not know the cart", async () => {
    setup.answer("GET", `/api/v1/guest-carts/${GUEST_CART_ID}/summary`, () => problem(404));
    const response = await summary(signedCartCookie(GUEST_CART_ID));

    expect(await response.json()).toEqual({ itemCount: 0, notice: null });
    expect(isCleared(setCookie(response, CART_COOKIE))).toBe(true);
  });

  it("clears a forged cookie and returns 0 without asking the API", async () => {
    const response = await summary(`${CART_COOKIE}=${GUEST_CART_ID}.forged`);

    expect(await response.json()).toEqual({ itemCount: 0, notice: null });
    expect(isCleared(setCookie(response, CART_COOKIE))).toBe(true);
    expect(setup.calls).toEqual([]);
  });

  it("returns the units of the customer cart with the session's token", async () => {
    const id = await setup.deps.sessions.create(sessionData({ accessToken: "access-9" }));
    setup.answer("GET", "/api/v1/me/cart/summary", () => Response.json({ itemCount: 7 }));
    const response = await summary(sessionCookie(id));

    expect(await response.json()).toEqual({ itemCount: 7, notice: null });
    expect(setup.calls[0].authorization).toBe("Bearer access-9");
  });

  it("ends the session and answers 401 when the token cannot be refreshed", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    setup.deps.freshAccessToken.mockResolvedValue(null);
    const response = await summary(sessionCookie(id));

    expect(response.status).toBe(401);
    expect(setup.sessions.has(id)).toBe(false);
    expect(isCleared(setCookie(response, SESSION_COOKIE))).toBe(true);
  });

  it("answers 503 and keeps the session when the API fails", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    setup.answer("GET", "/api/v1/me/cart/summary", () => problem(500));
    const response = await summary(sessionCookie(id));

    expect(response.status).toBe(503);
    expect(setup.sessions.has(id)).toBe(true);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
  });

  describe("notice of a truncated merge", () => {
    const customerSummary = (itemCount: number) => setup.answer("GET", "/api/v1/me/cart/summary", () => Response.json({ itemCount }));

    it("returns the notice of a signed notice cookie once and clears the cookie", async () => {
      const id = await setup.deps.sessions.create(sessionData());
      customerSummary(99);
      const response = await summary(`${sessionCookie(id)}; ${signedNoticeCookie()}`);

      expect(response.status).toBe(200);
      expect(await response.json()).toEqual({ itemCount: 99, notice: "merge_truncated" });
      expect(isCleared(setCookie(response, NOTICE_COOKIE_NAME))).toBe(true);

      // The browser dropped the cookie: the next summary has no notice.
      const next = await summary(sessionCookie(id));
      expect(await next.json()).toEqual({ itemCount: 99, notice: null });
    });

    it.each([
      ["unsigned", `${NOTICE_COOKIE_NAME}=merge_truncated`],
      ["signed with another secret", signedNoticeCookie("merge_truncated", "x".repeat(64))],
      ["signed with another value", signedNoticeCookie("something_else")],
    ])("returns a null notice for a notice cookie that is %s", async (_name, cookie) => {
      const id = await setup.deps.sessions.create(sessionData());
      customerSummary(2);
      const response = await summary(`${sessionCookie(id)}; ${cookie}`);

      expect(await response.json()).toEqual({ itemCount: 2, notice: null });
    });

    it("returns the notice of a truncated merge made by the same request, without leaving a notice cookie", async () => {
      const id = await setup.deps.sessions.create(sessionData());
      setup.answer("POST", "/api/v1/me/cart/merge", () => mergeAnswer("merged", true));
      customerSummary(99);
      const response = await summary(`${sessionCookie(id)}; ${signedCartCookie(GUEST_CART_ID)}`);

      expect(await response.json()).toEqual({ itemCount: 99, notice: "merge_truncated" });
      expect(isCleared(setCookie(response, CART_COOKIE))).toBe(true);
      const notice = setCookie(response, NOTICE_COOKIE_NAME);
      expect(notice === undefined || isCleared(notice)).toBe(true);
    });

    it("returns a null notice after a merge that was not truncated", async () => {
      const id = await setup.deps.sessions.create(sessionData());
      setup.answer("POST", "/api/v1/me/cart/merge", () => mergeAnswer("merged", false));
      customerSummary(3);
      const response = await summary(`${sessionCookie(id)}; ${signedCartCookie(GUEST_CART_ID)}`);

      expect(await response.json()).toEqual({ itemCount: 3, notice: null });
      expect(setCookie(response, NOTICE_COOKIE_NAME)).toBeUndefined();
    });

    it("keeps the notice cookie when the summary fails", async () => {
      const id = await setup.deps.sessions.create(sessionData());
      setup.answer("GET", "/api/v1/me/cart/summary", () => problem(500));
      const response = await summary(`${sessionCookie(id)}; ${signedNoticeCookie()}`);

      expect(response.status).toBe(503);
      expect(setCookie(response, NOTICE_COOKIE_NAME)).toBeUndefined();
    });

    it("keeps the notice of a truncated merge for later when the summary after it fails", async () => {
      const id = await setup.deps.sessions.create(sessionData());
      setup.answer("POST", "/api/v1/me/cart/merge", () => mergeAnswer("merged", true));
      setup.answer("GET", "/api/v1/me/cart/summary", () => problem(500));
      const response = await summary(`${sessionCookie(id)}; ${signedCartCookie(GUEST_CART_ID)}`);

      expect(response.status).toBe(503);
      const notice = setCookie(response, NOTICE_COOKIE_NAME);
      expect(notice).toBeDefined();
      expect(isCleared(notice)).toBe(false);
    });
  });
});
