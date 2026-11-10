import { NextRequest } from "next/server";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
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
  setup.answer("GET", PROVIDERS, () =>
    Response.json([
      { code: "demo-transfer", name: "Szybki przelew (demo)" },
      { code: "demo-card", name: "Karta płatnicza (demo)" },
    ]),
  );
  vi.spyOn(console, "error").mockImplementation(() => {});
});

const KEY = "5f0c3c1e-8d7a-4b55-9b0a-0e8e3c1d2a11";
const GUEST_ORDERS = "/api/v1/orders";
const CUSTOMER_ORDERS = "/api/v1/me/orders";
const MERGE = "/api/v1/me/cart/merge";
const PROVIDERS = "/api/v1/payment-providers";
const ORDER_ID = "0199a0b0-0000-7000-8000-000000000001";
const ACCESS_TOKEN = "dGhpcy1pcy1hLXRva2VuLW9mLXRoZS1ndWVzdC0xMjM";
const GUEST_PAYMENTS = `/api/v1/orders/${ORDER_ID}/payments`;
const CUSTOMER_PAYMENTS = `/api/v1/me/orders/${ORDER_ID}/payments`;
const REDIRECT_URL = "http://localhost:8000/payment-simulator/demo-transfer/pay/7d3f0a8e-4a1b-4c55-9f1e-2b3c4d5e6f70";
const PROBLEMS = "tag:piotrp321.github.io,2026:shop/problems/";

/** A 409 of the API with the type of its cause. */
const conflict = (slug: string, detail: string) =>
  Response.json({ type: `${PROBLEMS}${slug}`, title: "Conflict", status: 409, detail }, { status: 409, headers: { "Content-Type": "application/problem+json" } });

const PROVIDER = "demo-transfer";
const GUEST_CONFIRMATION = {
  orderId: ORDER_ID,
  number: "R-100001",
  email: "anna.nowak@example.com",
  totalGross: 39998,
  currency: "PLN",
  accessToken: ACCESS_TOKEN,
};
const CONTACT = { fullName: "Anna Nowak", phone: "600 100 200", street: "ul. Długa 5/3", postalCode: "00-950", city: "Warszawa" };
/** What the API receives of the form: the provider is the BFF's business. */
const GUEST_ORDER = { email: "anna.nowak@example.com", ...CONTACT, expectedTotalGross: 39998 };
const CUSTOMER_ORDER = { ...CONTACT, expectedTotalGross: 39998 };
const GUEST_FORM = { ...GUEST_ORDER, provider: PROVIDER };
const CUSTOMER_FORM = { ...CUSTOMER_ORDER, provider: PROVIDER };

const paymentView = (overrides: Record<string, unknown> = {}) => ({
  paymentId: "0199a0b0-0000-7000-8000-0000000000aa",
  provider: PROVIDER,
  status: "pending",
  redirectUrl: REDIRECT_URL,
  ...overrides,
});

/** The calls that are not the list of providers every checkout reads first. */
const orderCalls = () => setup.calls.filter((call) => call.path !== PROVIDERS);

const orderView = (overrides: Partial<components["schemas"]["OrderView"]> = {}): components["schemas"]["OrderView"] => ({
  orderId: ORDER_ID,
  number: "R-100001",
  status: "new",
  email: "anna.nowak@example.com",
  fullName: "Anna Nowak",
  phone: "+48600100200",
  shippingAddress: { street: "ul. Długa 5/3", postalCode: "00-950", city: "Warszawa", country: "PL" },
  lines: [{ variantId: VARIANT_ID, productName: "Produkt 1", variantLabel: "", quantity: 2, unitGross: 19999, grossBeforeDiscount: 39998, discountGross: 0, lineGross: 39998 }],
  discount: null,
  totals: { net: 32519, vat: 7479, gross: 39998, currency: "PLN" },
  placedAt: "2026-11-05T12:30:00+00:00",
  paymentDeadline: "2026-11-05T13:00:00+00:00",
  accessToken: ACCESS_TOKEN,
  ...overrides,
});

type Options = {
  cookie?: string;
  origin?: string | null;
  contentType?: string | null;
  key?: string | null;
  body?: unknown;
  headers?: Record<string, string>;
};

function checkout(options: Options = {}) {
  const headers = new Headers(options.headers);
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
    expect(orderCalls()).toEqual([]);
  });

  it("clears a forged store_cart and answers 409 without asking the API", async () => {
    const response = await checkout({ cookie: `${CART_COOKIE}=${GUEST_CART_ID}.forged` });

    expect(response.status).toBe(409);
    expect(isCleared(setCookie(response, CART_COOKIE))).toBe(true);
    expect(orderCalls()).toEqual([]);
  });

  it("places the order of the cart of store_cart with the key and sets the signed confirmation for an hour", async () => {
    setup.answer("POST", GUEST_ORDERS, () => Response.json(orderView(), { status: 201 }));
    setup.answer("POST", GUEST_PAYMENTS, () => Response.json(paymentView(), { status: 201 }));
    const response = await checkout({ cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(201);
    expect(await response.json()).toEqual({ number: "R-100001", redirectUrl: REDIRECT_URL });
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(orderCalls()).toEqual([
      { method: "POST", path: GUEST_ORDERS, authorization: null, idempotencyKey: KEY, body: { cartId: GUEST_CART_ID, ...GUEST_ORDER } },
      { method: "POST", path: GUEST_PAYMENTS, authorization: null, idempotencyKey: KEY, orderAccessToken: ACCESS_TOKEN, body: { provider: PROVIDER } },
    ]);

    const cookie = setCookie(response, CONFIRMATION_COOKIE) ?? "";
    expect(cookie).toContain("HttpOnly");
    expect(cookie).toContain("SameSite=lax");
    expect(cookie).toContain("Path=/");
    expect(cookie).toContain("Max-Age=3600");
    expect(confirmationOf(response)).toEqual(GUEST_CONFIRMATION);
    // The cart stays (empty now); its cookie is neither renewed nor cleared.
    expect(setCookie(response, CART_COOKIE)).toBeUndefined();
  });

  it("takes the confirmation from the order of the API, not from the request", async () => {
    setup.answer("POST", GUEST_ORDERS, () =>
      Response.json(orderView({ number: "R-100777", email: "inny@example.com", totals: { net: 813, vat: 187, gross: 1000, currency: "PLN" } }), {
        status: 201,
      }),
    );
    setup.answer("POST", GUEST_PAYMENTS, () => Response.json(paymentView(), { status: 201 }));
    const response = await checkout({ cookie: signedCartCookie(GUEST_CART_ID) });

    expect(await response.json()).toEqual({ number: "R-100777", redirectUrl: REDIRECT_URL });
    expect(confirmationOf(response)).toEqual({ ...GUEST_CONFIRMATION, number: "R-100777", email: "inny@example.com", totalGross: 1000 });
  });

  it("sends the API only the fields of the contract: prices, lines and a cart id of the browser are dropped", async () => {
    setup.answer("POST", GUEST_ORDERS, () => Response.json(orderView(), { status: 201 }));
    setup.answer("POST", GUEST_PAYMENTS, () => Response.json(paymentView(), { status: 201 }));
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

    expect(orderCalls()[0].body).toEqual({ cartId: GUEST_CART_ID, ...GUEST_ORDER });
  });

  it("passes the discount code to the API and drops the other fields, for a guest and for a customer", async () => {
    setup.answer("POST", GUEST_ORDERS, () => Response.json(orderView(), { status: 201 }));
    setup.answer("POST", GUEST_PAYMENTS, () => Response.json(paymentView(), { status: 201 }));
    await checkout({ cookie: signedCartCookie(GUEST_CART_ID), body: { ...GUEST_FORM, discountCode: "WITAJ10", discountGross: 5000 } });
    const { cookie } = await signedIn();
    setup.answer("POST", CUSTOMER_ORDERS, () => Response.json(orderView({ accessToken: null }), { status: 201 }));
    setup.answer("POST", CUSTOMER_PAYMENTS, () => Response.json(paymentView(), { status: 201 }));
    await checkout({ cookie, body: { ...CUSTOMER_FORM, discountCode: "WITAJ10", discountGross: 5000 } });

    const orders = orderCalls().filter((call) => call.path === GUEST_ORDERS || call.path === CUSTOMER_ORDERS);
    expect(orders.map((call) => call.body)).toEqual([
      { cartId: GUEST_CART_ID, ...GUEST_ORDER, discountCode: "WITAJ10" },
      { ...CUSTOMER_ORDER, discountCode: "WITAJ10" },
    ]);
  });

  it.each([
    ["discount-code-invalid", "Ten kod jest nieprawidłowy albo nieaktywny."],
    ["discount-code-used-up", "Ten kod został już wykorzystany."],
    ["discount-code-below-minimum", "Ten kod działa przy zamówieniu od 300,00 zł."],
  ])("passes the 409 %s with its type and detail", async (slug, detail) => {
    setup.answer("POST", GUEST_ORDERS, () => conflict(slug, detail));
    const response = await checkout({ cookie: signedCartCookie(GUEST_CART_ID), body: { ...GUEST_FORM, discountCode: "X" } });

    expect(response.status).toBe(409);
    expect(await response.json()).toMatchObject({ type: `${PROBLEMS}${slug}`, detail });
  });

  it("passes the values of the browser as they are, so that the API names what is wrong", async () => {
    setup.answer("POST", GUEST_ORDERS, () => problem(422));
    await checkout({ cookie: signedCartCookie(GUEST_CART_ID), body: { email: 5, phone: "", provider: PROVIDER } });

    expect(orderCalls()[0].body).toEqual({ cartId: GUEST_CART_ID, email: 5, phone: "" });
  });

  it("sets the confirmation also for a repeated request (200 with the same order)", async () => {
    setup.answer("POST", GUEST_ORDERS, () => Response.json(orderView()));
    setup.answer("POST", GUEST_PAYMENTS, () => Response.json(paymentView()));
    const response = await checkout({ cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual({ number: "R-100001", redirectUrl: REDIRECT_URL });
    expect(confirmationOf(response)).toEqual(GUEST_CONFIRMATION);
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
    setup.answer("POST", CUSTOMER_ORDERS, () => Response.json(orderView({ email: "jan.kowalski@example.com", accessToken: null }), { status: 201 }));
    setup.answer("POST", CUSTOMER_PAYMENTS, () => Response.json(paymentView(), { status: 201 }));
    const response = await checkout({ cookie: `${cookie}; ${signedCartCookie(GUEST_CART_ID)}`, body: GUEST_FORM });

    expect(response.status).toBe(201);
    expect(await response.json()).toEqual({ number: "R-100001", redirectUrl: REDIRECT_URL });
    expect(orderCalls().map((call) => `${call.method} ${call.path}`)).toEqual([`POST ${MERGE}`, `POST ${CUSTOMER_ORDERS}`, `POST ${CUSTOMER_PAYMENTS}`]);
    // No e-mail (the API takes the account's) and no cart id: only the fields of the customer's contract.
    expect(orderCalls()[1]).toEqual({
      method: "POST",
      path: CUSTOMER_ORDERS,
      authorization: "Bearer access-9",
      idempotencyKey: KEY,
      body: CUSTOMER_ORDER,
    });
    // The payment of the customer's order starts with the token of the session and the same key, no access token.
    expect(orderCalls()[2]).toEqual({
      method: "POST",
      path: CUSTOMER_PAYMENTS,
      authorization: "Bearer access-9",
      idempotencyKey: KEY,
      body: { provider: PROVIDER },
    });
    expect(isCleared(setCookie(response, CART_COOKIE))).toBe(true);
    expect(confirmationOf(response)).toEqual({ ...GUEST_CONFIRMATION, email: "jan.kowalski@example.com", accessToken: null });
    expect(response.headers.get("cache-control")).toBe("private, no-store");
  });

  it("does not merge without store_cart", async () => {
    const { cookie } = await signedIn();
    setup.answer("POST", CUSTOMER_ORDERS, () => Response.json(orderView({ accessToken: null })));
    setup.answer("POST", CUSTOMER_PAYMENTS, () => Response.json(paymentView()));
    const response = await checkout({ cookie, body: CUSTOMER_FORM });

    expect(response.status).toBe(200);
    expect(orderCalls().map((call) => call.path)).toEqual([CUSTOMER_ORDERS, CUSTOMER_PAYMENTS]);
    expect(confirmationOf(response)).not.toBeNull();
  });

  it("places the order even when the merge fails, and keeps store_cart for the next try", async () => {
    const { cookie } = await signedIn();
    setup.answer("POST", MERGE, () => problem(500));
    setup.answer("POST", CUSTOMER_ORDERS, () => Response.json(orderView({ accessToken: null }), { status: 201 }));
    setup.answer("POST", CUSTOMER_PAYMENTS, () => Response.json(paymentView(), { status: 201 }));
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
    expect(orderCalls().map((call) => call.path)).toEqual([MERGE]);
    expect(setup.sessions.has(id)).toBe(false);
  });

  it("ends the session without asking the API when the token cannot be refreshed", async () => {
    const { id, cookie } = await signedIn();
    setup.deps.freshAccessToken.mockResolvedValue(null);
    const response = await checkout({ cookie, body: CUSTOMER_FORM });

    expect(response.status).toBe(401);
    expect(orderCalls()).toEqual([]);
    expect(setup.sessions.has(id)).toBe(false);
  });

  it("answers 503 and keeps the session when the token cannot be refreshed right now", async () => {
    const { id, cookie } = await signedIn();
    setup.deps.freshAccessToken.mockRejectedValue(new Error("Keycloak is down"));
    const response = await checkout({ cookie, body: CUSTOMER_FORM });

    expect(response.status).toBe(503);
    expect(orderCalls()).toEqual([]);
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
    expect(orderCalls()).toEqual([]);
  });
});

describe("POST /api/checkout: the payment provider", () => {
  const violation = { propertyPath: "provider", title: "Wybierz sposób płatności." };

  it.each([
    ["missing", { ...GUEST_ORDER }],
    ["null", { ...GUEST_ORDER, provider: null }],
    ["empty", { ...GUEST_ORDER, provider: "" }],
    ["not a string", { ...GUEST_ORDER, provider: 5 }],
    ["unknown", { ...GUEST_ORDER, provider: "demo-crypto" }],
    ["a prototype key", { ...GUEST_ORDER, provider: "constructor" }],
  ])("answers 422 for the field provider without placing the order when it is %s", async (_name, body) => {
    const response = await checkout({ cookie: signedCartCookie(GUEST_CART_ID), body });

    expect(response.status).toBe(422);
    expect(await response.json()).toEqual({ violations: [violation] });
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(response.headers.get("set-cookie")).toBeNull();
    expect(orderCalls()).toEqual([]);
  });

  it("answers 422 for a customer too, before the cart is merged", async () => {
    const { cookie } = await signedIn();
    const response = await checkout({ cookie: `${cookie}; ${signedCartCookie(GUEST_CART_ID)}`, body: CUSTOMER_ORDER });

    expect(response.status).toBe(422);
    expect(orderCalls()).toEqual([]);
  });

  it.each([
    ["answers 500", () => problem(500)],
    ["answers 404", () => problem(404, "Not found.")],
    ["answers something that is not a list", () => Response.json({ providers: [] })],
    ["cannot be reached", () => {
      throw new TypeError("fetch failed");
    }],
  ])("answers 503 without placing the order when the list of providers %s", async (_name, respond) => {
    setup = cartTestDeps();
    vi.mocked(getCartDeps).mockResolvedValue(setup.deps);
    setup.answer("GET", PROVIDERS, respond);
    const response = await checkout({ cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(503);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(response.headers.get("set-cookie")).toBeNull();
    expect(setup.calls.map((call) => call.path)).toEqual([PROVIDERS]);
  });

  it("checks the provider with the list of the API and without a token", async () => {
    setup.answer("POST", GUEST_ORDERS, () => problem(404, "Koszyk nie jest już dostępny."));
    await checkout({ cookie: signedCartCookie(GUEST_CART_ID) });

    expect(setup.calls[0]).toMatchObject({ method: "GET", path: PROVIDERS, authorization: null });
  });
});

describe("POST /api/checkout: starting the payment of the placed order", () => {
  it("starts the payment of a guest order with its access token and the key of the placement", async () => {
    setup.answer("POST", GUEST_ORDERS, () => Response.json(orderView(), { status: 201 }));
    setup.answer("POST", GUEST_PAYMENTS, () => Response.json(paymentView({ provider: "demo-card" }), { status: 201 }));
    const response = await checkout({ cookie: signedCartCookie(GUEST_CART_ID), body: { ...GUEST_FORM, provider: "demo-card" } });

    expect(await response.json()).toEqual({ number: "R-100001", redirectUrl: REDIRECT_URL });
    expect(orderCalls()[1]).toMatchObject({ path: GUEST_PAYMENTS, idempotencyKey: KEY, orderAccessToken: ACCESS_TOKEN, body: { provider: "demo-card" } });
  });

  it("sets the confirmation before the payment starts, so it stays when the start fails", async () => {
    setup.answer("POST", GUEST_ORDERS, () => Response.json(orderView(), { status: 201 }));
    setup.answer("POST", GUEST_PAYMENTS, () => problem(503));
    const response = await checkout({ cookie: signedCartCookie(GUEST_CART_ID) });

    expect(confirmationOf(response)).toEqual(GUEST_CONFIRMATION);
  });

  it.each([
    ["a 503", () => problem(503)],
    ["a 404", () => problem(404, "Nie znaleźliśmy zamówienia.")],
    ["a 422", () => problem(422, "Nieznany sposób płatności.")],
    ["a 401", () => problem(401)],
    ["a 409 order already paid", () => conflict("order-already-paid", "Zamówienie jest już opłacone.")],
    ["a 409 deadline passed", () => conflict("payment-deadline-passed", "Minął termin płatności za to zamówienie.")],
    ["a payment without an address", () => Response.json(paymentView({ redirectUrl: null }), { status: 201 })],
    ["a network error", () => {
      throw new TypeError("fetch failed");
    }],
  ])("answers the placed order without an address when the start gets %s", async (_name, respond) => {
    setup.answer("POST", GUEST_ORDERS, () => Response.json(orderView(), { status: 201 }));
    setup.answer("POST", GUEST_PAYMENTS, respond);
    const response = await checkout({ cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(201);
    expect(await response.json()).toEqual({ number: "R-100001", redirectUrl: null });
    expect(confirmationOf(response)).toEqual(GUEST_CONFIRMATION);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
  });

  it("answers the order without an address when the same key meets another provider (409 key reused)", async () => {
    // The browser kept the key of the placement and changed the provider before the repeated click.
    setup.answer("POST", GUEST_ORDERS, () => Response.json(orderView()));
    setup.answer("POST", GUEST_PAYMENTS, () => conflict("payment-idempotency-key-reused", "Ten klucz żądania był już użyty dla innej płatności."));
    const response = await checkout({ cookie: signedCartCookie(GUEST_CART_ID), body: { ...GUEST_FORM, provider: "demo-card" } });

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual({ number: "R-100001", redirectUrl: null });
    expect(orderCalls()[0].idempotencyKey).toBe(KEY);
    expect(orderCalls()[1]).toMatchObject({ idempotencyKey: KEY, body: { provider: "demo-card" } });
  });

  it("logs a failed start without the access token", async () => {
    const log = vi.spyOn(console, "error").mockImplementation(() => {});
    setup.answer("POST", GUEST_ORDERS, () => Response.json(orderView(), { status: 201 }));
    setup.answer("POST", GUEST_PAYMENTS, () => problem(503));
    await checkout({ cookie: signedCartCookie(GUEST_CART_ID) });

    expect(log).toHaveBeenCalled();
    expect(JSON.stringify(log.mock.calls)).not.toContain(ACCESS_TOKEN);
  });

  it("does not start a payment when the order was not placed", async () => {
    setup.answer("POST", GUEST_ORDERS, () => conflict("cart-empty", "Koszyk jest pusty."));
    await checkout({ cookie: signedCartCookie(GUEST_CART_ID) });

    expect(orderCalls().map((call) => call.path)).toEqual([GUEST_ORDERS]);
  });
});

/** A 429 of the API: the rate limit of the placement or of the payment start. */
const tooManyRequests = () =>
  Response.json(
    { type: "about:blank", title: "Too Many Requests", status: 429, detail: "Zbyt wiele prób. Spróbuj ponownie za kilka minut." },
    { status: 429, headers: { "Content-Type": "application/problem+json", "Retry-After": "600" } },
  );

describe("POST /api/checkout: the rate limits of the API", () => {
  it("passes a 429 of the placement with its detail and Retry-After, keeps store_cart and starts no payment", async () => {
    setup.answer("POST", GUEST_ORDERS, tooManyRequests);
    const response = await checkout({ cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(429);
    expect(await response.json()).toEqual({ detail: "Zbyt wiele prób. Spróbuj ponownie za kilka minut." });
    expect(response.headers.get("retry-after")).toBe("600");
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(setCookie(response, CART_COOKIE)).toBeUndefined();
    expect(setCookie(response, CONFIRMATION_COOKIE)).toBeUndefined();
    expect(orderCalls().map((call) => call.path)).toEqual([GUEST_ORDERS]);
  });

  it("passes a 429 of the placement of a customer and keeps the session", async () => {
    const { id, cookie } = await signedIn();
    setup.answer("POST", CUSTOMER_ORDERS, tooManyRequests);
    const response = await checkout({ cookie, body: CUSTOMER_FORM });

    expect(response.status).toBe(429);
    expect(response.headers.get("retry-after")).toBe("600");
    expect(await setup.deps.sessions.get(id)).not.toBeNull();
    expect(setCookie(response, SESSION_COOKIE)).toBeUndefined();
  });

  it("answers the order without an address when the payment start is over the limit (429), so it is paid from the confirmation page", async () => {
    setup.answer("POST", GUEST_ORDERS, () => Response.json(orderView(), { status: 201 }));
    setup.answer("POST", GUEST_PAYMENTS, tooManyRequests);
    const response = await checkout({ cookie: signedCartCookie(GUEST_CART_ID) });

    expect(response.status).toBe(201);
    expect(await response.json()).toEqual({ number: "R-100001", redirectUrl: null });
    expect(confirmationOf(response)).toEqual(GUEST_CONFIRMATION);
  });
});

describe("POST /api/checkout: the address of the client", () => {
  afterEach(() => {
    vi.unstubAllEnvs();
  });

  it("copies the header named by CLIENT_IP_HEADER from the request of the browser to every call of the API of a guest", async () => {
    vi.stubEnv("CLIENT_IP_HEADER", "CF-Connecting-IP");
    setup.answer("POST", GUEST_ORDERS, () => Response.json(orderView(), { status: 201 }));
    setup.answer("POST", GUEST_PAYMENTS, () => Response.json(paymentView(), { status: 201 }));
    await checkout({ cookie: signedCartCookie(GUEST_CART_ID), headers: { "CF-Connecting-IP": "203.0.113.7" } });

    expect(setup.calls.map((call) => [call.path, call.clientIp])).toEqual([
      [PROVIDERS, "203.0.113.7"],
      [GUEST_ORDERS, "203.0.113.7"],
      [GUEST_PAYMENTS, "203.0.113.7"],
    ]);
  });

  it("copies it to every call of the API of a customer", async () => {
    vi.stubEnv("CLIENT_IP_HEADER", "CF-Connecting-IP");
    const { cookie } = await signedIn();
    setup.answer("POST", MERGE, () => mergeAnswer("merged"));
    setup.answer("POST", CUSTOMER_ORDERS, () => Response.json(orderView({ accessToken: null }), { status: 201 }));
    setup.answer("POST", CUSTOMER_PAYMENTS, () => Response.json(paymentView(), { status: 201 }));
    await checkout({ cookie: `${cookie}; ${signedCartCookie(GUEST_CART_ID)}`, body: CUSTOMER_FORM, headers: { "CF-Connecting-IP": "2001:db8::7" } });

    expect(setup.calls.map((call) => [call.path, call.clientIp])).toEqual([
      [PROVIDERS, "2001:db8::7"],
      [MERGE, "2001:db8::7"],
      [CUSTOMER_ORDERS, "2001:db8::7"],
      [CUSTOMER_PAYMENTS, "2001:db8::7"],
    ]);
  });

  it.each([
    ["without CLIENT_IP_HEADER", undefined, { "CF-Connecting-IP": "203.0.113.7" }],
    ["with an empty CLIENT_IP_HEADER", "", { "CF-Connecting-IP": "203.0.113.7" }],
    ["when the request has no such header", "CF-Connecting-IP", {}],
  ])("sends no address of the client %s", async (_name, variable, headers) => {
    if (variable !== undefined) vi.stubEnv("CLIENT_IP_HEADER", variable);
    setup.answer("POST", GUEST_ORDERS, () => Response.json(orderView(), { status: 201 }));
    setup.answer("POST", GUEST_PAYMENTS, () => Response.json(paymentView(), { status: 201 }));
    await checkout({ cookie: signedCartCookie(GUEST_CART_ID), headers });

    expect(setup.calls.map((call) => call.clientIp)).toEqual([undefined, undefined, undefined]);
  });
});
