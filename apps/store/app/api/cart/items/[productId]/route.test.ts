import { beforeEach, describe, expect, it, vi } from "vitest";
import { SESSION_COOKIE, signValue } from "@/auth/cookies";
import { sessionData, testConfig } from "@/auth/test-support";
import { CART_COOKIE } from "@/cart/cookies";
import { getCartDeps } from "@/cart/deps";
import {
  cartRequest,
  cartTestDeps,
  emptyView,
  GUEST_CART_ID,
  isCleared,
  PRODUCT_ID,
  problem,
  sessionCookie,
  setCookie,
  signedCartCookie,
  viewWithOneLine,
} from "@/cart/test-support";
import { DELETE, PATCH } from "./route";

vi.mock("@/cart/deps", () => ({ getCartDeps: vi.fn() }));

let setup: ReturnType<typeof cartTestDeps>;

beforeEach(() => {
  setup = cartTestDeps();
  vi.mocked(getCartDeps).mockResolvedValue(setup.deps);
  vi.spyOn(console, "error").mockImplementation(() => {});
});

const path = `/api/cart/items/${PRODUCT_ID}`;
const context = { params: Promise.resolve({ productId: PRODUCT_ID }) };
const GUEST_LINE = `/api/v1/guest-carts/${GUEST_CART_ID}/items/${PRODUCT_ID}`;
const GUEST_SUMMARY = `/api/v1/guest-carts/${GUEST_CART_ID}/summary`;
const ME_LINE = `/api/v1/me/cart/items/${PRODUCT_ID}`;
const LINE_MISSING = "Tej pozycji nie ma w koszyku.";

const change = (options: Parameters<typeof cartRequest>[2] = { body: { quantity: 3 } }) => PATCH(cartRequest("PATCH", path, options), context);
const remove = (options: Parameters<typeof cartRequest>[2] = {}) => DELETE(cartRequest("DELETE", path, options), context);
const notAnId = { params: Promise.resolve({ productId: "abc" }) };

describe("PATCH /api/cart/items/[productId]", () => {
  it("answers 404 for the line without asking the API or touching the cookie when the product id is not a UUID", async () => {
    const response = await PATCH(
      cartRequest("PATCH", "/api/cart/items/abc", { body: { quantity: 3 }, cookie: signedCartCookie(GUEST_CART_ID) }),
      notAnId,
    );

    expect(response.status).toBe(404);
    expect(await response.json()).toEqual({ detail: LINE_MISSING });
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(response.headers.get("set-cookie")).toBeNull();
    expect(setup.calls).toEqual([]);
  });

  it.each([
    ["without Origin", { origin: null }],
    ["from another origin", { origin: "http://evil.example" }],
    ["without Content-Type", { contentType: null }],
    ["with text/plain", { contentType: "text/plain" }],
  ])("answers 403 without asking the API %s", async (_name, options) => {
    const response = await change({ body: { quantity: 3 }, cookie: signedCartCookie(GUEST_CART_ID), ...options });

    expect(response.status).toBe(403);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(setup.calls).toEqual([]);
  });

  it("answers 422 without asking the API when the body is not a JSON object", async () => {
    const response = await change({ body: "nope", cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(422);
    expect(setup.calls).toEqual([]);
  });

  it("sets the quantity of the line of the guest cart and renews the cookie", async () => {
    setup.answer("PUT", GUEST_LINE, () => Response.json(viewWithOneLine(GUEST_CART_ID, 3)));
    const response = await change({ body: { quantity: 3, extra: 1 }, cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual(viewWithOneLine(GUEST_CART_ID, 3));
    expect(setup.calls).toEqual([{ method: "PUT", path: GUEST_LINE, authorization: null, body: { quantity: 3 } }]);
    expect(setCookie(response, CART_COOKIE)).toContain(signValue(GUEST_CART_ID, testConfig.sessionSecret));
    expect(setCookie(response, CART_COOKIE)).toContain(`Max-Age=${30 * 24 * 60 * 60}`);
  });

  it("answers 404 for the line, without asking the API, when there is no guest cart", async () => {
    const response = await change();

    expect(response.status).toBe(404);
    expect(await response.json()).toEqual({ detail: LINE_MISSING });
    expect(setup.calls).toEqual([]);
  });

  it("passes the 404 of a missing line through and keeps the cookie when the cart exists", async () => {
    setup.answer("PUT", GUEST_LINE, () => problem(404, LINE_MISSING));
    setup.answer("GET", GUEST_SUMMARY, () => Response.json({ itemCount: 2 }));
    const response = await change({ body: { quantity: 3 }, cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(404);
    expect(await response.json()).toEqual({ detail: LINE_MISSING });
    expect(response.headers.get("set-cookie")).toBeNull();
    expect(setup.calls.map((call) => call.method)).toEqual(["PUT", "GET"]);
  });

  it("clears the cookie and answers 404 for the line, without a retry, when the cart is gone", async () => {
    setup.answer("PUT", GUEST_LINE, () => problem(404, "Koszyk nie jest już dostępny."));
    setup.answer("GET", GUEST_SUMMARY, () => problem(404));
    const response = await change({ body: { quantity: 3 }, cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(404);
    expect(await response.json()).toEqual({ detail: LINE_MISSING });
    expect(isCleared(setCookie(response, CART_COOKIE))).toBe(true);
    expect(setup.calls).toHaveLength(2);
  });

  it("answers 503 when the check after a 404 fails", async () => {
    setup.answer("PUT", GUEST_LINE, () => problem(404));
    setup.answer("GET", GUEST_SUMMARY, () => problem(500));
    const response = await change({ body: { quantity: 3 }, cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(503);
    expect(response.headers.get("set-cookie")).toBeNull();
  });

  it.each([
    [409, "Nie mamy tylu sztuk tego produktu."],
    [422, "Quantity must be between 1 and 99."],
  ])("passes %s through with the detail of the API", async (status, detail) => {
    setup.answer("PUT", GUEST_LINE, () => problem(status, detail));
    const response = await change({ body: { quantity: 3 }, cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(status);
    expect(await response.json()).toEqual({ detail });
  });

  it("changes the line in the cart of the customer with the session's token", async () => {
    const id = await setup.deps.sessions.create(sessionData({ accessToken: "access-9" }));
    setup.answer("PUT", ME_LINE, () => Response.json(viewWithOneLine(null, 3)));
    const response = await change({ body: { quantity: 3 }, cookie: `${sessionCookie(id)}; ${signedCartCookie(GUEST_CART_ID)}` });

    expect(response.status).toBe(200);
    expect(setup.calls).toEqual([{ method: "PUT", path: ME_LINE, authorization: "Bearer access-9", body: { quantity: 3 } }]);
    expect(response.headers.get("set-cookie")).toBeNull();
  });

  it("passes the 404 of a line of the customer through without any check", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    setup.answer("PUT", ME_LINE, () => problem(404, LINE_MISSING));
    const response = await change({ body: { quantity: 3 }, cookie: sessionCookie(id) });

    expect(response.status).toBe(404);
    expect(setup.calls).toHaveLength(1);
  });

  it("ends the session and answers 401 when the token cannot be refreshed", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    setup.deps.freshAccessToken.mockResolvedValue(null);
    const response = await change({ body: { quantity: 3 }, cookie: sessionCookie(id) });

    expect(response.status).toBe(401);
    expect(setup.sessions.has(id)).toBe(false);
    expect(isCleared(setCookie(response, SESSION_COOKIE))).toBe(true);
  });
});

describe("DELETE /api/cart/items/[productId]", () => {
  it("answers 404 for the line without asking the API or touching the cookie when the product id is not a UUID", async () => {
    const response = await DELETE(
      cartRequest("DELETE", "/api/cart/items/abc", { cookie: signedCartCookie(GUEST_CART_ID) }),
      notAnId,
    );

    expect(response.status).toBe(404);
    expect(await response.json()).toEqual({ detail: LINE_MISSING });
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(response.headers.get("set-cookie")).toBeNull();
    expect(setup.calls).toEqual([]);
  });

  it.each([
    ["without Origin", { origin: null }],
    ["from another origin", { origin: "http://evil.example" }],
  ])("answers 403 without asking the API %s", async (_name, options) => {
    const response = await remove({ cookie: signedCartCookie(GUEST_CART_ID), ...options });

    expect(response.status).toBe(403);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(setup.calls).toEqual([]);
  });

  it("needs no Content-Type", async () => {
    setup.answer("DELETE", GUEST_LINE, () => Response.json(emptyView(GUEST_CART_ID)));
    const response = await remove({ cookie: signedCartCookie(GUEST_CART_ID), contentType: null });

    expect(response.status).toBe(200);
  });

  it("returns an empty cart without asking the API when there is no guest cart", async () => {
    const response = await remove();

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual(emptyView());
    expect(setup.calls).toEqual([]);
  });

  it("removes the line of the guest cart and renews the cookie", async () => {
    setup.answer("DELETE", GUEST_LINE, () => Response.json(emptyView(GUEST_CART_ID)));
    const response = await remove({ cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual(emptyView(GUEST_CART_ID));
    expect(setup.calls).toEqual([{ method: "DELETE", path: GUEST_LINE, authorization: null, body: undefined }]);
    expect(setCookie(response, CART_COOKIE)).toContain(`Max-Age=${30 * 24 * 60 * 60}`);
  });

  it("clears the cookie and returns an empty cart, without a retry, when the cart is gone", async () => {
    setup.answer("DELETE", GUEST_LINE, () => problem(404, "Koszyk nie jest już dostępny."));
    const response = await remove({ cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual(emptyView());
    expect(isCleared(setCookie(response, CART_COOKIE))).toBe(true);
    expect(setup.calls).toHaveLength(1);
  });

  it("removes the line of the cart of the customer with the session's token", async () => {
    const id = await setup.deps.sessions.create(sessionData({ accessToken: "access-9" }));
    setup.answer("DELETE", ME_LINE, () => Response.json(emptyView(null)));
    const response = await remove({ cookie: sessionCookie(id) });

    expect(response.status).toBe(200);
    expect(setup.calls).toEqual([{ method: "DELETE", path: ME_LINE, authorization: "Bearer access-9", body: undefined }]);
  });

  it("ends the session and answers 401 when the API rejects the token", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    setup.answer("DELETE", ME_LINE, () => problem(401));
    const response = await remove({ cookie: sessionCookie(id) });

    expect(response.status).toBe(401);
    expect(setup.sessions.has(id)).toBe(false);
  });

  it("answers 503 and keeps the session when the API fails", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    setup.answer("DELETE", ME_LINE, () => problem(500));
    const response = await remove({ cookie: sessionCookie(id) });

    expect(response.status).toBe(503);
    expect(setup.sessions.has(id)).toBe(true);
  });
});
