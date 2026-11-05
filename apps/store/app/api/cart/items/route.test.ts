import { beforeEach, describe, expect, it, vi } from "vitest";
import { SESSION_COOKIE } from "@/auth/cookies";
import { sessionData } from "@/auth/test-support";
import { CART_COOKIE } from "@/cart/cookies";
import { getCartDeps } from "@/cart/deps";
import {
  cartRequest,
  cartTestDeps,
  emptyView,
  GUEST_CART_ID,
  isCleared,
  mergeAnswer,
  NEW_GUEST_CART_ID,
  VARIANT_ID,
  problem,
  sessionCookie,
  setCookie,
  signedCartCookie,
  viewWithOneLine,
} from "@/cart/test-support";
import { signValue } from "@/auth/cookies";
import { testConfig } from "@/auth/test-support";
import { POST } from "./route";

vi.mock("@/cart/deps", () => ({ getCartDeps: vi.fn() }));

let setup: ReturnType<typeof cartTestDeps>;

beforeEach(() => {
  setup = cartTestDeps();
  vi.mocked(getCartDeps).mockResolvedValue(setup.deps);
  vi.spyOn(console, "error").mockImplementation(() => {});
});

const BODY = { variantId: VARIANT_ID, quantity: 1 };
const GUEST_ITEMS = `/api/v1/guest-carts/${GUEST_CART_ID}/items`;
const NEW_GUEST_ITEMS = `/api/v1/guest-carts/${NEW_GUEST_CART_ID}/items`;
const GUEST_SUMMARY = `/api/v1/guest-carts/${GUEST_CART_ID}/summary`;

const add = (options: Parameters<typeof cartRequest>[2] = { body: BODY }) => POST(cartRequest("POST", "/api/cart/items", options));
const createsGuestCart = () => setup.answer("POST", "/api/v1/guest-carts", () => Response.json(emptyView(NEW_GUEST_CART_ID), { status: 201 }));

describe("POST /api/cart/items: request checks", () => {
  it.each([
    ["without Origin", { origin: null }],
    ["from another origin", { origin: "http://evil.example" }],
    ["from the same host on another port", { origin: "http://localhost:3999" }],
    ["without Content-Type", { contentType: null }],
    ["with a form Content-Type", { contentType: "application/x-www-form-urlencoded" }],
    ["with text/plain", { contentType: "text/plain" }],
  ])("answers 403 without asking the API %s", async (_name, options) => {
    const response = await add({ body: BODY, cookie: signedCartCookie(GUEST_CART_ID), ...options });

    expect(response.status).toBe(403);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(response.headers.get("set-cookie")).toBeNull();
    expect(setup.calls).toEqual([]);
  });

  it("accepts application/json with a charset", async () => {
    setup.answer("POST", GUEST_ITEMS, () => Response.json(viewWithOneLine(GUEST_CART_ID)));
    const response = await add({ body: BODY, cookie: signedCartCookie(GUEST_CART_ID), contentType: "application/json; charset=utf-8" });

    expect(response.status).toBe(200);
  });

  it.each([
    ["is not JSON", "not json"],
    ["is a JSON array", [VARIANT_ID, 1]],
    ["is JSON null", "null"],
    ["has no product", { quantity: 1 }],
    ["has a quantity that is not a number", { variantId: VARIANT_ID, quantity: "1" }],
    ["has a product that is not a string", { variantId: 5, quantity: 1 }],
  ])("answers 422 without asking the API when the body %s", async (_name, body) => {
    const response = await add({ body, cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(422);
    expect(setup.calls).toEqual([]);
  });
});

describe("POST /api/cart/items as a guest", () => {
  it("creates the guest cart on the first add and sets the signed cookie", async () => {
    createsGuestCart();
    setup.answer("POST", NEW_GUEST_ITEMS, () => Response.json(viewWithOneLine(NEW_GUEST_CART_ID)));
    const response = await add();

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual(viewWithOneLine(NEW_GUEST_CART_ID));
    expect(setup.calls.map((call) => `${call.method} ${call.path}`)).toEqual(["POST /api/v1/guest-carts", `POST ${NEW_GUEST_ITEMS}`]);
    expect(setup.calls[1].body).toEqual(BODY);
    expect(setup.calls.every((call) => call.authorization === null)).toBe(true);
    expect(response.headers.get("cache-control")).toBe("private, no-store");

    const cookie = setCookie(response, CART_COOKIE) ?? "";
    expect(cookie).toContain(`${CART_COOKIE}=${signValue(NEW_GUEST_CART_ID, testConfig.sessionSecret)}`);
    expect(cookie).toContain("HttpOnly");
    expect(cookie).toContain("SameSite=lax");
    expect(cookie).toContain("Path=/");
    expect(cookie).toContain(`Max-Age=${30 * 24 * 60 * 60}`);
    expect(cookie).not.toContain("Secure");
  });

  it("keeps the cookie of a cart that was created even when the add is refused", async () => {
    createsGuestCart();
    setup.answer("POST", NEW_GUEST_ITEMS, () => problem(409, "Ten produkt jest chwilowo niedostępny w sprzedaży."));
    const response = await add();

    expect(response.status).toBe(409);
    expect(setCookie(response, CART_COOKIE)).toContain(signValue(NEW_GUEST_CART_ID, testConfig.sessionSecret));
  });

  it("adds to the cart of the cookie and renews the cookie", async () => {
    setup.answer("POST", GUEST_ITEMS, () => Response.json(viewWithOneLine(GUEST_CART_ID, 2)));
    const response = await add({ body: { variantId: VARIANT_ID, quantity: 1, extra: "dropped" }, cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(200);
    expect(setup.calls).toHaveLength(1);
    expect(setup.calls[0].body).toEqual(BODY);
    expect(setCookie(response, CART_COOKIE)).toContain(`Max-Age=${30 * 24 * 60 * 60}`);
    expect(setCookie(response, CART_COOKIE)).toContain(signValue(GUEST_CART_ID, testConfig.sessionSecret));
  });

  it("clears a forged cookie and starts a new cart", async () => {
    createsGuestCart();
    setup.answer("POST", NEW_GUEST_ITEMS, () => Response.json(viewWithOneLine(NEW_GUEST_CART_ID)));
    const response = await add({ body: BODY, cookie: `${CART_COOKIE}=${GUEST_CART_ID}.forged` });

    expect(response.status).toBe(200);
    expect(setup.calls.map((call) => call.path)).toEqual(["/api/v1/guest-carts", NEW_GUEST_ITEMS]);
    expect(setCookie(response, CART_COOKIE)).toContain(signValue(NEW_GUEST_CART_ID, testConfig.sessionSecret));
  });

  it("starts a new cart once when the cart of the cookie is gone (404 and an unknown cart behind it)", async () => {
    setup.answer("POST", GUEST_ITEMS, () => problem(404, "Koszyk nie jest już dostępny."));
    setup.answer("GET", GUEST_SUMMARY, () => problem(404, "Koszyk nie jest już dostępny."));
    createsGuestCart();
    setup.answer("POST", NEW_GUEST_ITEMS, () => Response.json(viewWithOneLine(NEW_GUEST_CART_ID)));
    const response = await add({ body: BODY, cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(200);
    expect(setup.calls.map((call) => `${call.method} ${call.path}`)).toEqual([
      `POST ${GUEST_ITEMS}`,
      `GET ${GUEST_SUMMARY}`,
      "POST /api/v1/guest-carts",
      `POST ${NEW_GUEST_ITEMS}`,
    ]);
    const cookies = response.headers.getSetCookie().filter((cookie) => cookie.startsWith(`${CART_COOKIE}=`));
    expect(cookies).toHaveLength(1);
    expect(cookies[0]).toContain(signValue(NEW_GUEST_CART_ID, testConfig.sessionSecret));
  });

  it("does not retry more than once", async () => {
    setup.answer("POST", GUEST_ITEMS, () => problem(404, "Koszyk nie jest już dostępny."));
    setup.answer("GET", GUEST_SUMMARY, () => problem(404));
    createsGuestCart();
    setup.answer("POST", NEW_GUEST_ITEMS, () => problem(404, "Produkt nie jest już dostępny."));
    const response = await add({ body: BODY, cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(404);
    expect((await response.json()).detail).toBe("Produkt nie jest już dostępny.");
    expect(setup.calls).toHaveLength(4);
  });

  it("passes a 404 of the product through and keeps the cookie when the cart exists", async () => {
    setup.answer("POST", GUEST_ITEMS, () => problem(404, "Produkt nie jest już dostępny."));
    setup.answer("GET", GUEST_SUMMARY, () => Response.json({ itemCount: 1 }));
    const response = await add({ body: BODY, cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(404);
    expect(await response.json()).toEqual({ detail: "Produkt nie jest już dostępny." });
    expect(response.headers.get("set-cookie")).toBeNull();
    expect(setup.calls).toHaveLength(2);
  });

  it.each([
    [409, "Nie mamy tylu sztuk tego produktu."],
    [422, "Quantity must be between 1 and 99."],
  ])("passes %s through with the detail of the API", async (status, detail) => {
    setup.answer("POST", GUEST_ITEMS, () => problem(status, detail));
    const response = await add({ body: BODY, cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(status);
    expect(await response.json()).toEqual({ detail });
    expect(response.headers.get("cache-control")).toBe("private, no-store");
  });

  it.each([
    ["500", () => problem(500)],
    ["400 (a BFF bug, not for the browser)", () => problem(400, "Invalid JSON.")],
    ["a network error", () => Promise.reject(new TypeError("fetch failed"))],
  ])("answers 503 when the API answers with %s", async (_name, respond) => {
    setup.answer("POST", GUEST_ITEMS, respond as () => Response);
    const response = await add({ body: BODY, cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(503);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
  });

  it("answers 503 when the guest cart cannot be created", async () => {
    setup.answer("POST", "/api/v1/guest-carts", () => problem(500));
    const response = await add();

    expect(response.status).toBe(503);
    expect(response.headers.get("set-cookie")).toBeNull();
    expect(setup.calls).toHaveLength(1);
  });
});

describe("POST /api/cart/items with a session", () => {
  it("adds to the cart of the customer with the session's token and touches no guest cart or cookie", async () => {
    const id = await setup.deps.sessions.create(sessionData({ accessToken: "access-9" }));
    setup.answer("POST", "/api/v1/me/cart/items", () => Response.json(viewWithOneLine(null)));
    const response = await add({ body: BODY, cookie: sessionCookie(id) });

    expect(response.status).toBe(200);
    expect(setup.calls).toEqual([{ method: "POST", path: "/api/v1/me/cart/items", authorization: "Bearer access-9", body: BODY }]);
    expect(response.headers.get("set-cookie")).toBeNull();
  });

  it("merges the guest cart of store_cart first, clears the cookie and then adds to the customer cart", async () => {
    const id = await setup.deps.sessions.create(sessionData({ accessToken: "access-9" }));
    setup.answer("POST", "/api/v1/me/cart/merge", () => mergeAnswer("merged"));
    setup.answer("POST", "/api/v1/me/cart/items", () => Response.json(viewWithOneLine(null, 3)));
    const response = await add({ body: BODY, cookie: `${sessionCookie(id)}; ${signedCartCookie(GUEST_CART_ID)}` });

    expect(response.status).toBe(200);
    expect(setup.calls).toEqual([
      { method: "POST", path: "/api/v1/me/cart/merge", authorization: "Bearer access-9", body: { guestCartId: GUEST_CART_ID } },
      { method: "POST", path: "/api/v1/me/cart/items", authorization: "Bearer access-9", body: BODY },
    ]);
    expect(isCleared(setCookie(response, CART_COOKIE))).toBe(true);
  });

  it("adds to the customer cart and keeps store_cart when the merge fails, without touching the guest cart", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    setup.answer("POST", "/api/v1/me/cart/merge", () => problem(503));
    setup.answer("POST", "/api/v1/me/cart/items", () => Response.json(viewWithOneLine(null)));
    const response = await add({ body: BODY, cookie: `${sessionCookie(id)}; ${signedCartCookie(GUEST_CART_ID)}` });

    expect(response.status).toBe(200);
    expect(setup.calls.map((call) => `${call.method} ${call.path}`)).toEqual(["POST /api/v1/me/cart/merge", "POST /api/v1/me/cart/items"]);
    expect(response.headers.get("set-cookie")).toBeNull();
  });

  it("passes the 404 of a product through without any retry", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    setup.answer("POST", "/api/v1/me/cart/items", () => problem(404, "Produkt nie jest już dostępny."));
    const response = await add({ body: BODY, cookie: sessionCookie(id) });

    expect(response.status).toBe(404);
    expect(setup.calls).toHaveLength(1);
  });

  it("passes a 409 through", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    setup.answer("POST", "/api/v1/me/cart/items", () => problem(409, "Nie mamy tylu sztuk tego produktu."));
    const response = await add({ body: BODY, cookie: sessionCookie(id) });

    expect(response.status).toBe(409);
    expect(await response.json()).toEqual({ detail: "Nie mamy tylu sztuk tego produktu." });
  });

  it("ends the session and answers 401 when the token cannot be refreshed, without creating a guest cart", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    setup.deps.freshAccessToken.mockResolvedValue(null);
    const response = await add({ body: BODY, cookie: sessionCookie(id) });

    expect(response.status).toBe(401);
    expect(setup.sessions.has(id)).toBe(false);
    expect(isCleared(setCookie(response, SESSION_COOKIE))).toBe(true);
    expect(setup.calls).toEqual([]);
  });

  it("answers 503 and keeps the session when the API fails", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    setup.answer("POST", "/api/v1/me/cart/items", () => problem(503));
    const response = await add({ body: BODY, cookie: sessionCookie(id) });

    expect(response.status).toBe(503);
    expect(setup.sessions.has(id)).toBe(true);
  });
});
