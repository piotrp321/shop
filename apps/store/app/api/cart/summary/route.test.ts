import { beforeEach, describe, expect, it, vi } from "vitest";
import { SESSION_COOKIE } from "@/auth/cookies";
import { sessionData } from "@/auth/test-support";
import { CART_COOKIE } from "@/cart/cookies";
import { getCartDeps } from "@/cart/deps";
import { cartRequest, cartTestDeps, GUEST_CART_ID, isCleared, problem, sessionCookie, setCookie, signedCartCookie } from "@/cart/test-support";
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
});
