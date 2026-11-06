import { NextRequest } from "next/server";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { components } from "@/api/schema";
import { SESSION_COOKIE, signValue } from "@/auth/cookies";
import { sessionData, testConfig } from "@/auth/test-support";
import { CART_COOKIE } from "@/cart/cookies";
import { getCartDeps } from "@/cart/deps";
import {
  cartTestDeps,
  GUEST_CART_ID,
  isCleared,
  mergeAnswer,
  problem,
  sessionCookie,
  setCookie,
  signedCartCookie,
  VARIANT_ID,
} from "@/cart/test-support";
import { CONFIRMATION_COOKIE, readOrderConfirmation } from "@/checkout/cookies";
import { POST } from "./route";

vi.mock("@/cart/deps", () => ({ getCartDeps: vi.fn() }));

let setup: ReturnType<typeof cartTestDeps>;

beforeEach(() => {
  setup = cartTestDeps();
  vi.mocked(getCartDeps).mockResolvedValue(setup.deps);
  vi.spyOn(console, "error").mockImplementation(() => {});
});

const KEY = "5f0c3c1e-8d7a-4b55-9b0a-0e8e3c1d2a11";
const GUEST_ORDERS = "/api/v1/orders";
const CUSTOMER_ORDERS = "/api/v1/me/orders";
const MERGE = "/api/v1/me/cart/merge";
const PROBLEMS = "tag:piotrp321.github.io,2026:shop/problems/";

/** A 409 of the API with the type of its cause. */
const conflict = (slug: string, detail: string) =>
  Response.json({ type: `${PROBLEMS}${slug}`, title: "Conflict", status: 409, detail }, { status: 409, headers: { "Content-Type": "application/problem+json" } });

const CONTACT = { fullName: "Anna Nowak", phone: "600 100 200", street: "ul. Długa 5/3", postalCode: "00-950", city: "Warszawa" };
const GUEST_FORM = { email: "anna.nowak@example.com", ...CONTACT, expectedTotalGross: 39998 };
const CUSTOMER_FORM = { ...CONTACT, expectedTotalGross: 39998 };

const orderView = (overrides: Partial<components["schemas"]["OrderView"]> = {}): components["schemas"]["OrderView"] => ({
  orderId: "0199a0b0-0000-7000-8000-000000000001",
  number: "R-100001",
  status: "new",
  email: "anna.nowak@example.com",
  fullName: "Anna Nowak",
  phone: "+48600100200",
  shippingAddress: { street: "ul. Długa 5/3", postalCode: "00-950", city: "Warszawa", country: "PL" },
  lines: [{ variantId: VARIANT_ID, productName: "Produkt 1", variantLabel: "", quantity: 2, unitGross: 19999, lineGross: 39998 }],
  totals: { net: 32519, vat: 7479, gross: 39998, currency: "PLN" },
  placedAt: "2026-11-05T12:30:00+00:00",
  ...overrides,
});

type Options = { cookie?: string; origin?: string | null; contentType?: string | null; key?: string | null; body?: unknown };

function checkout(options: Options = {}) {
  const headers = new Headers();
  if (options.cookie) headers.set("cookie", options.cookie);
  const origin = options.origin === undefined ? testConfig.storeUrl : options.origin;
  if (origin !== null) headers.set("origin", origin);
  const contentType = options.contentType === undefined ? "application/json" : options.contentType;
  if (contentType !== null) headers.set("content-type", contentType);
  const key = options.key === undefined ? KEY : options.key;
  if (key !== null) headers.set("idempotency-key", key);
  const body = options.body === undefined ? GUEST_FORM : options.body;
  return POST(
    new NextRequest("http://0.0.0.0:3000/api/checkout", {
      method: "POST",
      headers,
      body: typeof body === "string" ? body : JSON.stringify(body),
    }),
  );
}

const confirmationOf = (response: Response) => {
  const cookie = setCookie(response, CONFIRMATION_COOKIE);
  const value = cookie?.split(";")[0].slice(CONFIRMATION_COOKIE.length + 1);
  return readOrderConfirmation(value === undefined ? undefined : decodeURIComponent(value), testConfig.sessionSecret);
};

const signedIn = async (accessToken = "access-9") => {
  const id = await setup.deps.sessions.create(sessionData({ accessToken }));
  return { id, cookie: sessionCookie(id) };
};

describe("POST /api/checkout: request checks", () => {
  it.each([
    ["without Origin", { origin: null }],
    ["from another origin", { origin: "http://evil.example" }],
    ["from the same host on another port", { origin: "http://localhost:3999" }],
    ["without Content-Type", { contentType: null }],
    ["with a form Content-Type", { contentType: "application/x-www-form-urlencoded" }],
    ["with text/plain", { contentType: "text/plain" }],
  ])("answers 403 without asking the API %s", async (_name, options) => {
    const response = await checkout({ cookie: signedCartCookie(GUEST_CART_ID), ...options });

    expect(response.status).toBe(403);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(response.headers.get("set-cookie")).toBeNull();
    expect(setup.calls).toEqual([]);
  });

  it.each([
    ["without Idempotency-Key", { key: null }],
    ["with an empty Idempotency-Key", { key: "" }],
    ["with a body that is not JSON", { body: "not json" }],
    ["with a JSON array", { body: [GUEST_FORM] }],
    ["with JSON null", { body: "null" }],
  ])("answers 422 „Nieprawidłowe dane.” without asking the API %s", async (_name, options) => {
    const response = await checkout({ cookie: signedCartCookie(GUEST_CART_ID), ...options });

    expect(response.status).toBe(422);
    expect(await response.json()).toEqual({ detail: "Nieprawidłowe dane." });
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(setup.calls).toEqual([]);
  });

  it("answers 503 with no-store when its dependencies cannot be set up", async () => {
    vi.mocked(getCartDeps).mockRejectedValue(new Error("Missing environment variables: STORE_URL."));
    const response = await checkout({ cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(503);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(setup.calls).toEqual([]);
  });
});

describe("POST /api/checkout as a guest", () => {
  it("answers 409 „Koszyk jest pusty.” without asking the API when there is no store_cart", async () => {
    const response = await checkout();

    expect(response.status).toBe(409);
    expect(await response.json()).toEqual({ type: `${PROBLEMS}cart-empty`, detail: "Koszyk jest pusty." });
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(setup.calls).toEqual([]);
  });

  it("clears a forged store_cart and answers 409 without asking the API", async () => {
    const response = await checkout({ cookie: `${CART_COOKIE}=${GUEST_CART_ID}.forged` });

    expect(response.status).toBe(409);
    expect(isCleared(setCookie(response, CART_COOKIE))).toBe(true);
    expect(setup.calls).toEqual([]);
  });

  it("places the order of the cart of store_cart with the key and sets the signed confirmation for an hour", async () => {
    setup.answer("POST", GUEST_ORDERS, () => Response.json(orderView(), { status: 201 }));
    const response = await checkout({ cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(201);
    expect(await response.json()).toEqual({ number: "R-100001" });
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(setup.calls).toEqual([
      { method: "POST", path: GUEST_ORDERS, authorization: null, idempotencyKey: KEY, body: { cartId: GUEST_CART_ID, ...GUEST_FORM } },
    ]);

    const cookie = setCookie(response, CONFIRMATION_COOKIE) ?? "";
    expect(cookie).toContain("HttpOnly");
    expect(cookie).toContain("SameSite=lax");
    expect(cookie).toContain("Path=/");
    expect(cookie).toContain("Max-Age=3600");
    expect(confirmationOf(response)).toEqual({ number: "R-100001", email: "anna.nowak@example.com", totalGross: 39998, currency: "PLN" });
    // The cart stays (empty now); its cookie is neither renewed nor cleared.
    expect(setCookie(response, CART_COOKIE)).toBeUndefined();
  });

  it("takes the confirmation from the order of the API, not from the request", async () => {
    setup.answer("POST", GUEST_ORDERS, () =>
      Response.json(orderView({ number: "R-100777", email: "inny@example.com", totals: { net: 813, vat: 187, gross: 1000, currency: "PLN" } }), {
        status: 201,
      }),
    );
    const response = await checkout({ cookie: signedCartCookie(GUEST_CART_ID) });

    expect(await response.json()).toEqual({ number: "R-100777" });
    expect(confirmationOf(response)).toEqual({ number: "R-100777", email: "inny@example.com", totalGross: 1000, currency: "PLN" });
  });

  it("sends the API only the fields of the contract: prices, lines and a cart id of the browser are dropped", async () => {
    setup.answer("POST", GUEST_ORDERS, () => Response.json(orderView(), { status: 201 }));
    await checkout({
      cookie: signedCartCookie(GUEST_CART_ID),
      body: {
        ...GUEST_FORM,
        cartId: "33333333-3333-4333-8333-333333333333",
        lines: [{ variantId: VARIANT_ID, quantity: 99 }],
        totals: { gross: 1 },
        unitGross: 1,
        price: 1,
      },
    });

    expect(setup.calls[0].body).toEqual({ cartId: GUEST_CART_ID, ...GUEST_FORM });
  });

  it("passes the values of the browser as they are, so that the API names what is wrong", async () => {
    setup.answer("POST", GUEST_ORDERS, () => problem(422));
    await checkout({ cookie: signedCartCookie(GUEST_CART_ID), body: { email: 5, phone: "" } });

    expect(setup.calls[0].body).toEqual({ cartId: GUEST_CART_ID, email: 5, phone: "" });
  });

  it("sets the confirmation also for a repeated request (200 with the same order)", async () => {
    setup.answer("POST", GUEST_ORDERS, () => Response.json(orderView()));
    const response = await checkout({ cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual({ number: "R-100001" });
    expect(confirmationOf(response)).toEqual({ number: "R-100001", email: "anna.nowak@example.com", totalGross: 39998, currency: "PLN" });
  });

  it("clears store_cart on a 404 (the cart is gone) and passes the detail", async () => {
    setup.answer("POST", GUEST_ORDERS, () => problem(404, "Koszyk nie jest już dostępny."));
    const response = await checkout({ cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(404);
    expect(await response.json()).toEqual({ detail: "Koszyk nie jest już dostępny." });
    expect(isCleared(setCookie(response, CART_COOKIE))).toBe(true);
    expect(setCookie(response, CONFIRMATION_COOKIE)).toBeUndefined();
  });

  it("passes a 409 with its type and detail and keeps store_cart", async () => {
    setup.answer("POST", GUEST_ORDERS, () => conflict("order-total-changed", "Kwota zamówienia się zmieniła. Sprawdź podsumowanie."));
    const response = await checkout({ cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(409);
    expect(await response.json()).toEqual({ type: `${PROBLEMS}order-total-changed`, detail: "Kwota zamówienia się zmieniła. Sprawdź podsumowanie." });
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(response.headers.get("set-cookie")).toBeNull();
  });

  it("passes a 422 with its violations", async () => {
    const violations = [
      { propertyPath: "phone", title: "Podaj 9-cyfrowy numer telefonu." },
      { propertyPath: "postalCode", title: "Podaj kod pocztowy w formacie 00-000." },
    ];
    setup.answer("POST", GUEST_ORDERS, () =>
      Response.json(
        { type: "about:blank", title: "Unprocessable Content", status: 422, violations },
        { status: 422, headers: { "Content-Type": "application/problem+json" } },
      ),
    );
    const response = await checkout({ cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(422);
    expect(await response.json()).toEqual({ violations });
    expect(response.headers.get("set-cookie")).toBeNull();
  });

  it.each([[400], [401], [415], [500], [503]])("answers 503 to a %s of the API", async (status) => {
    setup.answer("POST", GUEST_ORDERS, () => problem(status, status < 500 ? "The request is invalid." : undefined));
    const response = await checkout({ cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(503);
    expect(await response.json()).toEqual({});
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(response.headers.get("set-cookie")).toBeNull();
  });

  it("answers 503 when the API cannot be reached", async () => {
    setup.on(() => {
      throw new TypeError("fetch failed");
    });
    const response = await checkout({ cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(503);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
  });
});

describe("POST /api/checkout with a session", () => {
  it("merges store_cart first, then places the order of the customer's cart with the token and the key", async () => {
    const { cookie } = await signedIn();
    setup.answer("POST", MERGE, () => mergeAnswer("merged"));
    setup.answer("POST", CUSTOMER_ORDERS, () => Response.json(orderView({ email: "jan.kowalski@example.com" }), { status: 201 }));
    const response = await checkout({ cookie: `${cookie}; ${signedCartCookie(GUEST_CART_ID)}`, body: GUEST_FORM });

    expect(response.status).toBe(201);
    expect(await response.json()).toEqual({ number: "R-100001" });
    expect(setup.calls.map((call) => `${call.method} ${call.path}`)).toEqual([`POST ${MERGE}`, `POST ${CUSTOMER_ORDERS}`]);
    // No e-mail (the API takes the account's) and no cart id: only the fields of the customer's contract.
    expect(setup.calls[1]).toEqual({
      method: "POST",
      path: CUSTOMER_ORDERS,
      authorization: "Bearer access-9",
      idempotencyKey: KEY,
      body: CUSTOMER_FORM,
    });
    expect(isCleared(setCookie(response, CART_COOKIE))).toBe(true);
    expect(confirmationOf(response)).toEqual({ number: "R-100001", email: "jan.kowalski@example.com", totalGross: 39998, currency: "PLN" });
    expect(response.headers.get("cache-control")).toBe("private, no-store");
  });

  it("does not merge without store_cart", async () => {
    const { cookie } = await signedIn();
    setup.answer("POST", CUSTOMER_ORDERS, () => Response.json(orderView()));
    const response = await checkout({ cookie, body: CUSTOMER_FORM });

    expect(response.status).toBe(200);
    expect(setup.calls.map((call) => call.path)).toEqual([CUSTOMER_ORDERS]);
    expect(confirmationOf(response)).not.toBeNull();
  });

  it("places the order even when the merge fails, and keeps store_cart for the next try", async () => {
    const { cookie } = await signedIn();
    setup.answer("POST", MERGE, () => problem(500));
    setup.answer("POST", CUSTOMER_ORDERS, () => Response.json(orderView(), { status: 201 }));
    const response = await checkout({ cookie: `${cookie}; ${signedCartCookie(GUEST_CART_ID)}`, body: CUSTOMER_FORM });

    expect(response.status).toBe(201);
    expect(setCookie(response, CART_COOKIE)).toBeUndefined();
  });

  it("ends the session on a 401 of the API", async () => {
    const { id, cookie } = await signedIn();
    setup.answer("POST", CUSTOMER_ORDERS, () => problem(401, "Authentication is required."));
    const response = await checkout({ cookie, body: CUSTOMER_FORM });

    expect(response.status).toBe(401);
    expect(setup.sessions.has(id)).toBe(false);
    expect(isCleared(setCookie(response, SESSION_COOKIE))).toBe(true);
    expect(setCookie(response, CONFIRMATION_COOKIE)).toBeUndefined();
    expect(response.headers.get("cache-control")).toBe("private, no-store");
  });

  it("ends the session without placing the order when the merge is refused with 401", async () => {
    const { id, cookie } = await signedIn();
    setup.answer("POST", MERGE, () => problem(401));
    const response = await checkout({ cookie: `${cookie}; ${signedCartCookie(GUEST_CART_ID)}`, body: CUSTOMER_FORM });

    expect(response.status).toBe(401);
    expect(setup.calls.map((call) => call.path)).toEqual([MERGE]);
    expect(setup.sessions.has(id)).toBe(false);
  });

  it("ends the session without asking the API when the token cannot be refreshed", async () => {
    const { id, cookie } = await signedIn();
    setup.deps.freshAccessToken.mockResolvedValue(null);
    const response = await checkout({ cookie, body: CUSTOMER_FORM });

    expect(response.status).toBe(401);
    expect(setup.calls).toEqual([]);
    expect(setup.sessions.has(id)).toBe(false);
  });

  it("answers 503 and keeps the session when the token cannot be refreshed right now", async () => {
    const { id, cookie } = await signedIn();
    setup.deps.freshAccessToken.mockRejectedValue(new Error("Keycloak is down"));
    const response = await checkout({ cookie, body: CUSTOMER_FORM });

    expect(response.status).toBe(503);
    expect(setup.calls).toEqual([]);
    expect(setup.sessions.has(id)).toBe(true);
    expect(response.headers.get("set-cookie")).toBeNull();
  });

  it.each(["cart-empty", "cart-not-orderable", "insufficient-stock-for-order", "order-total-changed", "idempotency-key-reused"])(
    "passes a 409 %s with its type and detail",
    async (slug) => {
      const { cookie } = await signedIn();
      setup.answer("POST", CUSTOMER_ORDERS, () => conflict(slug, "Tekst."));
      const response = await checkout({ cookie, body: CUSTOMER_FORM });

      expect(response.status).toBe(409);
      expect(await response.json()).toEqual({ type: `${PROBLEMS}${slug}`, detail: "Tekst." });
    },
  );

  it("passes the type of a 409 as it is, also about:blank", async () => {
    const { cookie } = await signedIn();
    setup.answer("POST", CUSTOMER_ORDERS, () => problem(409, "Koszyk jest pusty."));
    const response = await checkout({ cookie, body: CUSTOMER_FORM });

    expect(await response.json()).toEqual({ type: "about:blank", detail: "Koszyk jest pusty." });
  });

  it("passes a 409 without a type without one", async () => {
    const { cookie } = await signedIn();
    setup.answer("POST", CUSTOMER_ORDERS, () => Response.json({ detail: "Koszyk jest pusty." }, { status: 409 }));
    const response = await checkout({ cookie, body: CUSTOMER_FORM });

    expect(await response.json()).toEqual({ detail: "Koszyk jest pusty." });
  });

  it("answers 503 and keeps the session when the API answers 503", async () => {
    const { id, cookie } = await signedIn();
    setup.answer("POST", CUSTOMER_ORDERS, () => problem(503));
    const response = await checkout({ cookie, body: CUSTOMER_FORM });

    expect(response.status).toBe(503);
    expect(setup.sessions.has(id)).toBe(true);
    expect(response.headers.get("set-cookie")).toBeNull();
  });

  it("does not treat a session id signed with another secret as a session", async () => {
    await signedIn();
    const response = await checkout({ cookie: `${SESSION_COOKIE}=${signValue("session-1", "x".repeat(64))}` });

    expect(response.status).toBe(409);
    expect(setup.calls).toEqual([]);
  });
});
