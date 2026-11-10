import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { components } from "@/api/schema";
import { SESSION_COOKIE, signValue } from "@/auth/cookies";
import { sessionData, testConfig } from "@/auth/test-support";
import { getCartDeps } from "@/cart/deps";
import {
  cartRequest,
  cartTestDeps,
  confirmationCookie,
  customerConfirmation,
  guestConfirmation,
  isCleared,
  ORDER_ACCESS_TOKEN,
  ORDER_ID,
  problem,
  sessionCookie,
  setCookie,
} from "@/cart/test-support";
import { GET, POST } from "./route";

vi.mock("@/cart/deps", () => ({ getCartDeps: vi.fn() }));

let setup: ReturnType<typeof cartTestDeps>;

beforeEach(() => {
  setup = cartTestDeps();
  vi.mocked(getCartDeps).mockResolvedValue(setup.deps);
  vi.spyOn(console, "error").mockImplementation(() => {});
});

const KEY = "5f0c3c1e-8d7a-4b55-9b0a-0e8e3c1d2a11";
const PROBLEMS = "tag:piotrp321.github.io,2026:shop/problems/";
const GUEST_PAY = `/api/v1/orders/${ORDER_ID}/payments`;
const CUSTOMER_PAY = `/api/v1/me/orders/${ORDER_ID}/payments`;
const GUEST_STATUS = `/api/v1/orders/${ORDER_ID}/payment`;
const CUSTOMER_STATUS = `/api/v1/me/orders/${ORDER_ID}/payment`;
const REDIRECT_URL = "http://localhost:8000/payment-simulator/demo-card/pay/7d3f0a8e-4a1b-4c55-9f1e-2b3c4d5e6f70";
const NOT_FOUND = "Nie znaleźliśmy zamówienia.";

const conflict = (slug: string, detail: string) =>
  Response.json({ type: `${PROBLEMS}${slug}`, title: "Conflict", status: 409, detail }, { status: 409, headers: { "Content-Type": "application/problem+json" } });

const paymentView = (overrides: Record<string, unknown> = {}) => ({
  paymentId: "0199a0b0-0000-7000-8000-0000000000aa",
  provider: "demo-card",
  status: "pending",
  redirectUrl: REDIRECT_URL,
  ...overrides,
});

const statusView = (overrides: Partial<components["schemas"]["PaymentStatusView"]> = {}): components["schemas"]["PaymentStatusView"] => ({
  orderNumber: "R-100001",
  orderStatus: "new",
  paymentDeadline: "2026-11-05T13:00:00+00:00",
  totalGross: 39998,
  currency: "PLN",
  canStartPayment: true,
  latestPayment: { provider: "demo-card", status: "pending", resumeUrl: REDIRECT_URL },
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

function pay(options: Options = {}) {
  const request = cartRequest("POST", "/api/checkout/payment", {
    cookie: options.cookie,
    origin: options.origin,
    contentType: options.contentType,
    body: options.body === undefined ? { provider: "demo-card" } : options.body,
  });
  const key = options.key === undefined ? KEY : options.key;
  if (key !== null) request.headers.set("idempotency-key", key);
  for (const [name, value] of Object.entries(options.headers ?? {})) request.headers.set(name, value);
  return POST(request);
}

const status = (cookie?: string) => GET(cartRequest("GET", "/api/checkout/payment", { cookie }));

const signedIn = async (accessToken = "access-9") => {
  const id = await setup.deps.sessions.create(sessionData({ accessToken }));
  return { id, cookie: sessionCookie(id) };
};

describe("POST /api/checkout/payment: request checks", () => {
  it.each([
    ["without Origin", { origin: null }],
    ["from another origin", { origin: "http://evil.example" }],
    ["without Content-Type", { contentType: null }],
    ["with a form Content-Type", { contentType: "application/x-www-form-urlencoded" }],
  ])("answers 403 without asking the API %s", async (_name, options) => {
    const response = await pay({ cookie: confirmationCookie(), ...options });

    expect(response.status).toBe(403);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(setup.calls).toEqual([]);
  });

  it.each([
    ["without Idempotency-Key", { key: null }],
    ["with an empty Idempotency-Key", { key: "" }],
    ["with a body that is not JSON", { body: "not json" }],
    ["with a JSON array", { body: [{ provider: "demo-card" }] }],
  ])("answers 422 „Nieprawidłowe dane.” without asking the API %s", async (_name, options) => {
    const response = await pay({ cookie: confirmationCookie(), ...options });

    expect(response.status).toBe(422);
    expect(await response.json()).toEqual({ detail: "Nieprawidłowe dane." });
    expect(setup.calls).toEqual([]);
  });

  it.each([
    ["without the cookie", undefined],
    ["with a cookie signed with another secret", confirmationCookie(guestConfirmation, "x".repeat(64))],
    ["with an unsigned cookie", "store_order_confirmation=e30"],
    ["with a signed cookie that is not a confirmation", `store_order_confirmation=${signValue("e30", testConfig.sessionSecret)}`],
    ["with the cookie of an order without an id", confirmationCookie({ ...guestConfirmation, orderId: undefined } as never)],
  ])("answers 404 „Nie znaleźliśmy zamówienia.” without asking the API %s", async (_name, cookie) => {
    const response = await pay({ cookie });

    expect(response.status).toBe(404);
    expect(await response.json()).toEqual({ detail: NOT_FOUND });
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(setup.calls).toEqual([]);
  });

  it("answers 503 with no-store when its dependencies cannot be set up", async () => {
    vi.mocked(getCartDeps).mockRejectedValue(new Error("Missing environment variables: STORE_URL."));
    const response = await pay({ cookie: confirmationCookie() });

    expect(response.status).toBe(503);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
  });
});

describe("POST /api/checkout/payment: the endpoint follows the cookie", () => {
  it("starts the payment of a guest order with its access token, the key and the provider", async () => {
    setup.answer("POST", GUEST_PAY, () => Response.json(paymentView(), { status: 201 }));
    const response = await pay({ cookie: confirmationCookie() });

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual({ redirectUrl: REDIRECT_URL });
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(setup.calls).toEqual([
      { method: "POST", path: GUEST_PAY, authorization: null, idempotencyKey: KEY, orderAccessToken: ORDER_ACCESS_TOKEN, body: { provider: "demo-card" } },
    ]);
  });

  it("uses the guest endpoint also when the guest signed in after the order (the token decides, not the session)", async () => {
    const { cookie } = await signedIn();
    setup.answer("POST", GUEST_PAY, () => Response.json(paymentView()));
    const response = await pay({ cookie: `${cookie}; ${confirmationCookie()}` });

    expect(response.status).toBe(200);
    expect(setup.calls).toHaveLength(1);
    expect(setup.calls[0]).toMatchObject({ path: GUEST_PAY, authorization: null, orderAccessToken: ORDER_ACCESS_TOKEN });
    expect(setup.deps.freshAccessToken).not.toHaveBeenCalled();
  });

  it("starts the payment of a customer's order with the token of the session and no access token", async () => {
    const { cookie } = await signedIn();
    setup.answer("POST", CUSTOMER_PAY, () => Response.json(paymentView(), { status: 201 }));
    const response = await pay({ cookie: `${cookie}; ${confirmationCookie(customerConfirmation)}` });

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual({ redirectUrl: REDIRECT_URL });
    expect(setup.calls).toEqual([
      { method: "POST", path: CUSTOMER_PAY, authorization: "Bearer access-9", idempotencyKey: KEY, body: { provider: "demo-card" } },
    ]);
  });

  it("answers 401 without asking the API when a customer's order has no session", async () => {
    const response = await pay({ cookie: confirmationCookie(customerConfirmation) });

    expect(response.status).toBe(401);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(setup.calls).toEqual([]);
  });

  it("answers 401 without asking the API when the session id is signed with another secret", async () => {
    await signedIn();
    const forged = `${SESSION_COOKIE}=${signValue("session-1", "x".repeat(64))}`;
    const response = await pay({ cookie: `${forged}; ${confirmationCookie(customerConfirmation)}` });

    expect(response.status).toBe(401);
    expect(setup.calls).toEqual([]);
  });

  it("ends the session and answers 401 when the token of a customer's session cannot be refreshed", async () => {
    const { id, cookie } = await signedIn();
    setup.deps.freshAccessToken.mockResolvedValue(null);
    const response = await pay({ cookie: `${cookie}; ${confirmationCookie(customerConfirmation)}` });

    expect(response.status).toBe(401);
    expect(setup.calls).toEqual([]);
    expect(setup.sessions.has(id)).toBe(false);
    expect(isCleared(setCookie(response, SESSION_COOKIE))).toBe(true);
  });

  it("sends only the provider: other fields of the browser are dropped", async () => {
    setup.answer("POST", GUEST_PAY, () => Response.json(paymentView()));
    await pay({ cookie: confirmationCookie(), body: { provider: "demo-transfer", orderId: "other", amount: 1, redirectUrl: "http://evil.example" } });

    expect(setup.calls[0].body).toEqual({ provider: "demo-transfer" });
    expect(setup.calls[0].path).toBe(GUEST_PAY);
  });

  it("passes a missing provider on, so that the API names the field", async () => {
    const violations = [{ propertyPath: "provider", title: "Wybierz sposób płatności." }];
    setup.answer("POST", GUEST_PAY, () =>
      Response.json({ type: "about:blank", title: "Unprocessable Content", status: 422, detail: "Wybierz sposób płatności.", violations }, { status: 422 }),
    );
    const response = await pay({ cookie: confirmationCookie(), body: {} });

    expect(setup.calls[0].body).toEqual({});
    expect(response.status).toBe(422);
    expect(await response.json()).toEqual({ detail: "Wybierz sposób płatności.", violations });
  });
});

describe("POST /api/checkout/payment: the results", () => {
  it("answers the address of the provider for a repeated request too (200)", async () => {
    setup.answer("POST", GUEST_PAY, () => Response.json(paymentView(), { status: 200 }));
    const response = await pay({ cookie: confirmationCookie() });

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual({ redirectUrl: REDIRECT_URL });
  });

  it("answers a null address when the payment has none any more", async () => {
    setup.answer("POST", GUEST_PAY, () => Response.json(paymentView({ status: "failed", redirectUrl: null })));
    const response = await pay({ cookie: confirmationCookie() });

    expect(await response.json()).toEqual({ redirectUrl: null });
  });

  it.each([
    ["order-already-paid", "Zamówienie jest już opłacone."],
    ["payment-deadline-passed", "Minął termin płatności za to zamówienie."],
    ["payment-in-progress", "Płatność za to zamówienie jest w toku. Spróbuj za chwilę."],
    ["payment-idempotency-key-reused", "Ten klucz żądania był już użyty dla innej płatności."],
  ])("passes a 409 %s with its type and detail", async (slug, detail) => {
    setup.answer("POST", GUEST_PAY, () => conflict(slug, detail));
    const response = await pay({ cookie: confirmationCookie() });

    expect(response.status).toBe(409);
    expect(await response.json()).toEqual({ type: `${PROBLEMS}${slug}`, detail });
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(response.headers.get("set-cookie")).toBeNull();
  });

  it("passes a 404 with its detail", async () => {
    setup.answer("POST", GUEST_PAY, () => problem(404, NOT_FOUND));
    const response = await pay({ cookie: confirmationCookie() });

    expect(response.status).toBe(404);
    expect(await response.json()).toEqual({ detail: NOT_FOUND });
  });

  it("passes a 422 with its violations", async () => {
    const violations = [{ propertyPath: "provider", title: "Nieznany sposób płatności." }];
    setup.answer("POST", GUEST_PAY, () =>
      Response.json({ type: "about:blank", title: "Unprocessable Content", status: 422, violations }, { status: 422 }),
    );
    const response = await pay({ cookie: confirmationCookie() });

    expect(response.status).toBe(422);
    expect(await response.json()).toEqual({ violations });
  });

  it("ends the session on a 401 of the API for a customer's order", async () => {
    const { id, cookie } = await signedIn();
    setup.answer("POST", CUSTOMER_PAY, () => problem(401, "Authentication is required."));
    const response = await pay({ cookie: `${cookie}; ${confirmationCookie(customerConfirmation)}` });

    expect(response.status).toBe(401);
    expect(setup.sessions.has(id)).toBe(false);
    expect(isCleared(setCookie(response, SESSION_COOKIE))).toBe(true);
  });

  it.each([[400], [401], [415], [500], [503]])("answers 503 to a %s of the API for a guest order", async (code) => {
    setup.answer("POST", GUEST_PAY, () => problem(code, code < 500 ? "The request is invalid." : undefined));
    const response = await pay({ cookie: confirmationCookie() });

    expect(response.status).toBe(503);
    expect(await response.json()).toEqual({});
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(response.headers.get("set-cookie")).toBeNull();
  });

  it("answers 503 when the API cannot be reached", async () => {
    setup.on(() => {
      throw new TypeError("fetch failed");
    });
    const response = await pay({ cookie: confirmationCookie() });

    expect(response.status).toBe(503);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
  });

  it("answers 503 and keeps the session when the token cannot be refreshed right now", async () => {
    const { id, cookie } = await signedIn();
    setup.deps.freshAccessToken.mockRejectedValue(new Error("Keycloak is down"));
    const response = await pay({ cookie: `${cookie}; ${confirmationCookie(customerConfirmation)}` });

    expect(response.status).toBe(503);
    expect(setup.sessions.has(id)).toBe(true);
    expect(response.headers.get("set-cookie")).toBeNull();
  });

  it("does not log the access token", async () => {
    const log = vi.spyOn(console, "error").mockImplementation(() => {});
    setup.answer("POST", GUEST_PAY, () => problem(500));
    await pay({ cookie: confirmationCookie() });

    expect(JSON.stringify(log.mock.calls)).not.toContain(ORDER_ACCESS_TOKEN);
  });
});

describe("GET /api/checkout/payment", () => {
  it("answers the status of a guest order with its access token", async () => {
    setup.answer("GET", GUEST_STATUS, () => Response.json(statusView()));
    const response = await status(confirmationCookie());

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual(statusView());
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(setup.calls).toEqual([{ method: "GET", path: GUEST_STATUS, authorization: null, orderAccessToken: ORDER_ACCESS_TOKEN, body: undefined }]);
  });

  it("reads a guest order with the guest endpoint also when a session exists", async () => {
    const { cookie } = await signedIn();
    setup.answer("GET", GUEST_STATUS, () => Response.json(statusView()));
    const response = await status(`${cookie}; ${confirmationCookie()}`);

    expect(response.status).toBe(200);
    expect(setup.calls.map((call) => call.path)).toEqual([GUEST_STATUS]);
  });

  it("answers the status of a customer's order with the token of the session", async () => {
    const { cookie } = await signedIn();
    setup.answer("GET", CUSTOMER_STATUS, () => Response.json(statusView({ orderStatus: "paid", canStartPayment: false })));
    const response = await status(`${cookie}; ${confirmationCookie(customerConfirmation)}`);

    expect(response.status).toBe(200);
    expect((await response.json()).orderStatus).toBe("paid");
    expect(setup.calls).toEqual([{ method: "GET", path: CUSTOMER_STATUS, authorization: "Bearer access-9", body: undefined }]);
  });

  it.each([
    ["without the cookie", undefined],
    ["with a forged cookie", confirmationCookie(guestConfirmation, "x".repeat(64))],
  ])("answers 404 without asking the API %s", async (_name, cookie) => {
    const response = await status(cookie);

    expect(response.status).toBe(404);
    expect(await response.json()).toEqual({ detail: NOT_FOUND });
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(setup.calls).toEqual([]);
  });

  it("answers 401 without asking the API when a customer's order has no session", async () => {
    const response = await status(confirmationCookie(customerConfirmation));

    expect(response.status).toBe(401);
    expect(setup.calls).toEqual([]);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
  });

  it("ends the session and answers 401 on a 401 of the API for a customer's order", async () => {
    const { id, cookie } = await signedIn();
    setup.answer("GET", CUSTOMER_STATUS, () => problem(401));
    const response = await status(`${cookie}; ${confirmationCookie(customerConfirmation)}`);

    expect(response.status).toBe(401);
    expect(setup.sessions.has(id)).toBe(false);
    expect(isCleared(setCookie(response, SESSION_COOKIE))).toBe(true);
  });

  it("answers 404 with the detail when the API does not know the order", async () => {
    setup.answer("GET", GUEST_STATUS, () => problem(404, NOT_FOUND));
    const response = await status(confirmationCookie());

    expect(response.status).toBe(404);
    expect(await response.json()).toEqual({ detail: NOT_FOUND });
  });

  it.each([[400], [401], [500], [503]])("answers 503 to a %s of the API for a guest order", async (code) => {
    setup.answer("GET", GUEST_STATUS, () => problem(code));
    const response = await status(confirmationCookie());

    expect(response.status).toBe(503);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(response.headers.get("set-cookie")).toBeNull();
  });

  it("answers 503 when the API answers something else than a status", async () => {
    setup.answer("GET", GUEST_STATUS, () => Response.json({ nothing: true }, { status: 200 }));
    const response = await status(confirmationCookie());

    expect(response.status).toBe(503);
  });

  it("answers 503 when the API cannot be reached", async () => {
    setup.on(() => {
      throw new TypeError("fetch failed");
    });
    const response = await status(confirmationCookie());

    expect(response.status).toBe(503);
  });
});

describe("POST /api/checkout/payment: the rate limit of the API", () => {
  const tooManyRequests = () =>
    Response.json(
      { type: "about:blank", title: "Too Many Requests", status: 429, detail: "Zbyt wiele prób. Spróbuj ponownie za kilka minut." },
      { status: 429, headers: { "Content-Type": "application/problem+json", "Retry-After": "120" } },
    );

  it("passes a 429 of a guest's start with its detail and Retry-After", async () => {
    setup.answer("POST", GUEST_PAY, tooManyRequests);
    const response = await pay({ cookie: confirmationCookie(guestConfirmation) });

    expect(response.status).toBe(429);
    expect(await response.json()).toEqual({ detail: "Zbyt wiele prób. Spróbuj ponownie za kilka minut." });
    expect(response.headers.get("retry-after")).toBe("120");
    expect(response.headers.get("cache-control")).toBe("private, no-store");
  });

  it("passes a 429 of a customer's start and keeps the session", async () => {
    const { id, cookie } = await signedIn();
    setup.answer("POST", CUSTOMER_PAY, tooManyRequests);
    const response = await pay({ cookie: `${cookie}; ${confirmationCookie(customerConfirmation)}` });

    expect(response.status).toBe(429);
    expect(response.headers.get("retry-after")).toBe("120");
    expect(await setup.deps.sessions.get(id)).not.toBeNull();
    expect(setCookie(response, SESSION_COOKIE)).toBeUndefined();
  });
});

describe("POST /api/checkout/payment: the address of the client", () => {
  afterEach(() => {
    vi.unstubAllEnvs();
  });

  it("copies the header named by CLIENT_IP_HEADER from the request of the browser to the API", async () => {
    vi.stubEnv("CLIENT_IP_HEADER", "CF-Connecting-IP");
    setup.answer("POST", GUEST_PAY, () => Response.json(paymentView(), { status: 201 }));
    await pay({ cookie: confirmationCookie(guestConfirmation), headers: { "CF-Connecting-IP": "203.0.113.7" } });

    expect(setup.calls.map((call) => [call.path, call.clientIp])).toEqual([[GUEST_PAY, "203.0.113.7"]]);
  });

  it("copies it for a customer too", async () => {
    vi.stubEnv("CLIENT_IP_HEADER", "CF-Connecting-IP");
    const { cookie } = await signedIn();
    setup.answer("POST", CUSTOMER_PAY, () => Response.json(paymentView(), { status: 201 }));
    await pay({ cookie: `${cookie}; ${confirmationCookie(customerConfirmation)}`, headers: { "CF-Connecting-IP": "203.0.113.7" } });

    expect(setup.calls.map((call) => [call.path, call.clientIp])).toEqual([[CUSTOMER_PAY, "203.0.113.7"]]);
  });

  it("sends no address of the client without CLIENT_IP_HEADER", async () => {
    setup.answer("POST", GUEST_PAY, () => Response.json(paymentView(), { status: 201 }));
    await pay({ cookie: confirmationCookie(guestConfirmation), headers: { "CF-Connecting-IP": "203.0.113.7" } });

    expect(setup.calls.map((call) => call.clientIp)).toEqual([undefined]);
  });
});
