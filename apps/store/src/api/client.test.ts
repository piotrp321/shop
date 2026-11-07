import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  addCartItem,
  changeCartItemQuantity,
  createApiClient,
  getPaymentStatus,
  loadAccountSession,
  loadCart,
  loadCartSummary,
  loadSiteSwitches,
  placeOrder,
  removeCartItem,
  startPayment,
} from "./client";

// Node has its own navigator.locks; every test decides whether the browser has Web Locks API. By default it has not.
beforeEach(() => {
  vi.stubGlobal("navigator", {});
});

afterEach(() => {
  vi.unstubAllGlobals();
});

const stubFetch = (implementation: () => Promise<Response>) => {
  const fetch = vi.fn(implementation);
  vi.stubGlobal("fetch", fetch);
  return fetch;
};

describe("loadAccountSession", () => {
  it("asks the store's own /auth/session, same origin and uncached", async () => {
    const fetch = stubFetch(async () => Response.json({ name: "Jan", email: "jan.kowalski@example.com" }));

    expect(await loadAccountSession()).toEqual({ status: "ok", session: { name: "Jan", email: "jan.kowalski@example.com" } });
    expect(fetch).toHaveBeenCalledWith("/auth/session", { credentials: "same-origin", cache: "no-store" });
  });

  it("passes null through as signed out", async () => {
    stubFetch(async () => Response.json(null));
    expect(await loadAccountSession()).toEqual({ status: "ok", session: null });
  });

  it("reports an error status as unavailable", async () => {
    stubFetch(async () => Response.json(null, { status: 503 }));
    expect(await loadAccountSession()).toEqual({ status: "unavailable" });
  });

  it("reports a network error as unavailable", async () => {
    stubFetch(async () => {
      throw new TypeError("Failed to fetch");
    });
    expect(await loadAccountSession()).toEqual({ status: "unavailable" });
  });
});

describe("loadSiteSwitches", () => {
  const DEFAULTS = { demoNotice: false, customerAccounts: true };

  it("asks the store's own /api/site, same origin and uncached", async () => {
    const fetch = stubFetch(async () => Response.json({ demoNotice: true, customerAccounts: false }));

    expect(await loadSiteSwitches()).toEqual({ demoNotice: true, customerAccounts: false });
    expect(fetch).toHaveBeenCalledWith("/api/site", { credentials: "same-origin", cache: "no-store" });
  });

  it("gives the defaults for an error status", async () => {
    stubFetch(async () => Response.json({ demoNotice: true, customerAccounts: false }, { status: 503 }));
    expect(await loadSiteSwitches()).toEqual(DEFAULTS);
  });

  it("gives the defaults for a network error", async () => {
    stubFetch(async () => {
      throw new TypeError("Failed to fetch");
    });
    expect(await loadSiteSwitches()).toEqual(DEFAULTS);
  });

  it("gives the defaults for a body that is not JSON", async () => {
    stubFetch(async () => new Response("<html>", { status: 200 }));
    expect(await loadSiteSwitches()).toEqual(DEFAULTS);
  });

  it("takes the default of every switch that is missing or not a boolean", async () => {
    stubFetch(async () => Response.json({ demoNotice: "true" }));
    expect(await loadSiteSwitches()).toEqual(DEFAULTS);
    stubFetch(async () => Response.json(null));
    expect(await loadSiteSwitches()).toEqual(DEFAULTS);
    stubFetch(async () => Response.json({ demoNotice: true }));
    expect(await loadSiteSwitches()).toEqual({ demoNotice: true, customerAccounts: true });
  });
});

describe("the cart calls", () => {
  const cart = { id: null, lines: [], totals: { gross: 0, currency: "PLN", itemCount: 0 }, hasExcludedLines: false };
  const PRODUCT = "00000000-0000-4000-8000-000000000001";

  it("loads the cart from the store's own /api/cart, same origin and uncached", async () => {
    const fetch = stubFetch(async () => Response.json(cart));

    expect(await loadCart()).toEqual({ status: "ok", data: cart });
    expect(fetch).toHaveBeenCalledWith("/api/cart", { method: "GET", credentials: "same-origin", cache: "no-store" });
  });

  it("loads the summary from /api/cart/summary", async () => {
    const fetch = stubFetch(async () => Response.json({ itemCount: 3, notice: null }));

    expect(await loadCartSummary()).toEqual({ status: "ok", data: { itemCount: 3, notice: null } });
    expect(fetch).toHaveBeenCalledWith("/api/cart/summary", { method: "GET", credentials: "same-origin", cache: "no-store" });
  });

  it("adds a product with a JSON body", async () => {
    const fetch = stubFetch(async () => Response.json(cart));

    expect(await addCartItem(PRODUCT, 1)).toEqual({ status: "ok", data: cart });
    expect(fetch).toHaveBeenCalledWith("/api/cart/items", {
      method: "POST",
      credentials: "same-origin",
      cache: "no-store",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ variantId: PRODUCT, quantity: 1 }),
    });
  });

  it("changes a quantity with PATCH", async () => {
    const fetch = stubFetch(async () => Response.json(cart));

    await changeCartItemQuantity(PRODUCT, 3);
    expect(fetch).toHaveBeenCalledWith(`/api/cart/items/${PRODUCT}`, {
      method: "PATCH",
      credentials: "same-origin",
      cache: "no-store",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ quantity: 3 }),
    });
  });

  it("removes a line with DELETE", async () => {
    const fetch = stubFetch(async () => Response.json(cart));

    await removeCartItem(PRODUCT);
    expect(fetch).toHaveBeenCalledWith(`/api/cart/items/${PRODUCT}`, { method: "DELETE", credentials: "same-origin", cache: "no-store" });
  });

  it.each([
    [409, { detail: "Nie mamy tylu sztuk tego produktu." }, { status: "conflict", detail: "Nie mamy tylu sztuk tego produktu." }],
    [404, { detail: "Produkt nie jest już dostępny." }, { status: "not_found", detail: "Produkt nie jest już dostępny." }],
    [404, {}, { status: "not_found", detail: undefined }],
    [401, {}, { status: "unauthorized" }],
    [403, {}, { status: "failed" }],
    [422, { detail: "x" }, { status: "failed" }],
    [503, {}, { status: "failed" }],
  ])("maps a %s with %j to %j", async (status, body, expected) => {
    stubFetch(async () => Response.json(body, { status }));

    expect(await addCartItem(PRODUCT, 1)).toEqual(expected);
  });

  it("reports a network error as failed", async () => {
    stubFetch(async () => {
      throw new TypeError("Failed to fetch");
    });

    expect(await loadCart()).toEqual({ status: "failed" });
  });

  it("reports a body that is not JSON as failed", async () => {
    stubFetch(async () => new Response("<html>", { status: 200 }));

    expect(await loadCart()).toEqual({ status: "failed" });
  });
});

type LockCallback = (lock: { name: string; mode: string }) => Promise<unknown>;

/**
 * A stand-in for navigator.locks: one exclusive lock per name, granted in the order of the requests, like the browser
 * does across all tabs of an origin. `held` is the name of the lock held right now.
 */
const lockManagerDouble = () => {
  const tails = new Map<string, Promise<unknown>>();
  const state = { held: null as string | null };
  const request = vi.fn((name: string, options: { mode?: string }, callback: LockCallback) => {
    const run = (tails.get(name) ?? Promise.resolve()).then(async () => {
      state.held = name;
      try {
        return await callback({ name, mode: options.mode ?? "exclusive" });
      } finally {
        state.held = null;
      }
    });
    tails.set(name, run.catch(() => undefined));
    return run;
  });
  vi.stubGlobal("navigator", { locks: { request } });
  return { request, state };
};

const gatedFetch = () => {
  const gates: Array<(response: Response | Error) => void> = [];
  const fetch = vi.fn(
    () =>
      new Promise<Response>((resolve, reject) => {
        gates.push((response) => (response instanceof Error ? reject(response) : resolve(response)));
      }),
  );
  vi.stubGlobal("fetch", fetch);
  return { fetch, gates };
};

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

describe.each([
  ["with Web Locks API", () => void lockManagerDouble()],
  ["without Web Locks API", () => vi.stubGlobal("navigator", {})],
])("writes to the cart from the browser %s", (_, setUpLocks) => {
  const PRODUCT = "00000000-0000-4000-8000-000000000001";
  const OTHER = "22222222-2222-4222-8222-222222222222";

  beforeEach(() => {
    setUpLocks();
  });

  it("starts the next write only after the previous one has finished", async () => {
    const { fetch, gates } = gatedFetch();

    const first = addCartItem(PRODUCT, 1);
    const second = addCartItem(OTHER, 1);
    await flush();
    expect(fetch).toHaveBeenCalledTimes(1);

    gates[0](Response.json({ id: "cart" }));
    expect(await first).toEqual({ status: "ok", data: { id: "cart" } });
    await flush();
    expect(fetch).toHaveBeenCalledTimes(2);

    gates[1](Response.json({ id: "cart" }));
    expect(await second).toEqual({ status: "ok", data: { id: "cart" } });
  });

  it("queues changes and removals behind the writes before them", async () => {
    const { fetch, gates } = gatedFetch();

    const results = [addCartItem(PRODUCT, 1), changeCartItemQuantity(PRODUCT, 2), removeCartItem(PRODUCT)];
    for (let i = 0; i < 3; i++) {
      await flush();
      expect(fetch).toHaveBeenCalledTimes(i + 1);
      gates[i](Response.json({ id: "cart" }));
    }
    await Promise.all(results);
  });

  it.each([
    ["a network error", new TypeError("Failed to fetch")],
    ["an HTTP error", Response.json({}, { status: 503 })],
  ])("starts the next write after %s", async (_, failure) => {
    const { fetch, gates } = gatedFetch();

    const first = addCartItem(PRODUCT, 1);
    const second = addCartItem(OTHER, 1);
    await flush();
    gates[0](failure);
    expect(await first).toEqual({ status: "failed" });
    await flush();
    expect(fetch).toHaveBeenCalledTimes(2);

    gates[1](Response.json({ id: "cart" }));
    expect(await second).toEqual({ status: "ok", data: { id: "cart" } });
  });

  it("does not queue reads behind a write", async () => {
    const { fetch, gates } = gatedFetch();

    const write = addCartItem(PRODUCT, 1);
    void loadCart();
    void loadCartSummary();
    await flush();

    expect(fetch).toHaveBeenCalledTimes(3);
    gates.forEach((gate) => gate(Response.json({ id: "cart" })));
    await write;
  });
});

describe("the lock across the tabs of the browser", () => {
  const PRODUCT = "00000000-0000-4000-8000-000000000001";
  const OTHER = "22222222-2222-4222-8222-222222222222";
  const cart = { id: "cart" };

  it("runs every write inside an exclusive request of one lock name", async () => {
    const { request, state } = lockManagerDouble();
    const heldAtFetch: Array<string | null> = [];
    stubFetch(async () => {
      heldAtFetch.push(state.held);
      return Response.json(cart);
    });

    await Promise.all([addCartItem(PRODUCT, 1), changeCartItemQuantity(PRODUCT, 2), removeCartItem(OTHER)]);

    expect(request).toHaveBeenCalledTimes(3);
    const names = new Set(request.mock.calls.map(([name]) => name));
    expect(names.size).toBe(1);
    const [name] = names;
    expect(name).not.toBe("");
    request.mock.calls.forEach(([, options]) => expect(options).toEqual({ mode: "exclusive" }));
    expect(heldAtFetch).toEqual([name, name, name]);
  });

  it("does not take the lock for reads", async () => {
    const { request, state } = lockManagerDouble();
    const heldAtFetch: Array<string | null> = [];
    stubFetch(async () => {
      heldAtFetch.push(state.held);
      return Response.json(cart);
    });

    await loadCart();
    await loadCartSummary();

    expect(request).not.toHaveBeenCalled();
    expect(heldAtFetch).toEqual([null, null]);
  });

  it("waits with a write while another tab holds the lock", async () => {
    const { request } = lockManagerDouble();
    const fetch = stubFetch(async () => Response.json(cart));
    // The first write of this tab learns the lock name; then another tab takes the same lock and keeps it.
    await addCartItem(PRODUCT, 1);
    expect(request).toHaveBeenCalledTimes(1);
    const [name] = request.mock.calls[0];
    let releaseOtherTab = () => {};
    void navigator.locks.request(name, { mode: "exclusive" }, () => new Promise<void>((resolve) => (releaseOtherTab = resolve)));

    const write = addCartItem(OTHER, 1);
    await flush();
    expect(fetch).toHaveBeenCalledTimes(1);

    releaseOtherTab();
    expect(await write).toEqual({ status: "ok", data: cart });
    expect(fetch).toHaveBeenCalledTimes(2);
  });

  it("reports a rejected lock request as failed without calling the store, and goes on with the next write", async () => {
    const { request } = lockManagerDouble();
    request.mockImplementationOnce(() => Promise.reject(new DOMException("The request is not allowed.", "SecurityError")));
    const fetch = stubFetch(async () => Response.json(cart));

    const first = addCartItem(PRODUCT, 1);
    const second = addCartItem(OTHER, 1);

    expect(await first).toEqual({ status: "failed" });
    expect(await second).toEqual({ status: "ok", data: cart });
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(fetch).toHaveBeenCalledWith("/api/cart/items", expect.objectContaining({ body: JSON.stringify({ variantId: OTHER, quantity: 1 }) }));
  });

  it("reports a lock request that throws at once as failed without calling the store", async () => {
    const { request } = lockManagerDouble();
    request.mockImplementationOnce(() => {
      throw new DOMException("The request is not allowed.", "SecurityError");
    });
    const fetch = stubFetch(async () => Response.json(cart));

    expect(await addCartItem(PRODUCT, 1)).toEqual({ status: "failed" });
    expect(fetch).not.toHaveBeenCalled();
  });
});

describe("placeOrder", () => {
  const KEY = "5f0c3c1e-8d7a-4b55-9b0a-0e8e3c1d2a11";
  const input = {
    email: "anna.nowak@example.com",
    fullName: "Anna Nowak",
    phone: "600 100 200",
    street: "ul. Długa 5/3",
    postalCode: "00-950",
    city: "Warszawa",
    expectedTotalGross: 39998,
    provider: "demo-transfer",
  };
  const REDIRECT_URL = "http://localhost:8000/payment-simulator/demo-transfer/pay/7d3f0a8e-4a1b-4c55-9f1e-2b3c4d5e6f70";

  it("posts the form as JSON to the store's own /api/checkout with the Idempotency-Key, same origin and uncached", async () => {
    const fetch = stubFetch(async () => Response.json({ number: "R-100001", redirectUrl: REDIRECT_URL }, { status: 201 }));

    expect(await placeOrder(input, KEY)).toEqual({ status: "ok", number: "R-100001", redirectUrl: REDIRECT_URL });
    expect(fetch).toHaveBeenCalledWith("/api/checkout", {
      method: "POST",
      credentials: "same-origin",
      cache: "no-store",
      headers: { "Content-Type": "application/json", "Idempotency-Key": KEY },
      body: JSON.stringify(input),
    });
  });

  it("takes a repeated request (200) as placed", async () => {
    stubFetch(async () => Response.json({ number: "R-100001", redirectUrl: REDIRECT_URL }));
    expect(await placeOrder(input, KEY)).toEqual({ status: "ok", number: "R-100001", redirectUrl: REDIRECT_URL });
  });

  it.each([
    ["null", { number: "R-100001", redirectUrl: null }],
    ["missing", { number: "R-100001" }],
    ["not a string", { number: "R-100001", redirectUrl: 5 }],
  ])("takes an order whose payment did not start (address %s) as placed, without an address", async (_name, body) => {
    stubFetch(async () => Response.json(body, { status: 201 }));
    expect(await placeOrder(input, KEY)).toEqual({ status: "ok", number: "R-100001", redirectUrl: null });
  });

  const PROBLEMS = "tag:piotrp321.github.io,2026:shop/problems/";
  const violations = [{ propertyPath: "phone", title: "Podaj 9-cyfrowy numer telefonu." }];

  it.each([
    [422, { violations }, { status: "invalid", detail: undefined, violations }],
    [422, { detail: "Nieprawidłowe dane." }, { status: "invalid", detail: "Nieprawidłowe dane.", violations: [] }],
    [409, { type: `${PROBLEMS}cart-empty`, detail: "Koszyk jest pusty." }, { status: "conflict", reason: "cart_empty", detail: "Koszyk jest pusty." }],
    [409, { type: `${PROBLEMS}cart-not-orderable`, detail: "x" }, { status: "conflict", reason: "cart_not_orderable", detail: "x" }],
    [409, { type: `${PROBLEMS}insufficient-stock-for-order`, detail: "x" }, { status: "conflict", reason: "insufficient_stock", detail: "x" }],
    [409, { type: `${PROBLEMS}order-total-changed`, detail: "x" }, { status: "conflict", reason: "total_changed", detail: "x" }],
    [409, { type: `${PROBLEMS}idempotency-key-reused`, detail: "x" }, { status: "conflict", reason: "key_reused", detail: "x" }],
    [409, { type: "about:blank", detail: "Koszyk jest pusty." }, { status: "conflict", reason: "unknown", detail: "Koszyk jest pusty." }],
    [409, { type: `${PROBLEMS}something-new`, detail: "x" }, { status: "conflict", reason: "unknown", detail: "x" }],
    [409, { type: 42, detail: "x" }, { status: "conflict", reason: "unknown", detail: "x" }],
    [409, { type: "constructor", detail: "x" }, { status: "conflict", reason: "unknown", detail: "x" }],
    [409, { detail: "Koszyk jest pusty." }, { status: "conflict", reason: "unknown", detail: "Koszyk jest pusty." }],
    [409, {}, { status: "conflict", reason: "unknown", detail: undefined }],
    [404, { detail: "Koszyk nie jest już dostępny." }, { status: "not_found", detail: "Koszyk nie jest już dostępny." }],
    [401, {}, { status: "unauthorized" }],
    [403, {}, { status: "failed" }],
    [500, {}, { status: "failed" }],
    [503, {}, { status: "failed" }],
  ])("maps a %s with %j to %j", async (status, body, expected) => {
    stubFetch(async () => Response.json(body, { status }));

    expect(await placeOrder(input, KEY)).toEqual(expected);
  });

  it("reports a network error as failed", async () => {
    stubFetch(async () => {
      throw new TypeError("Failed to fetch");
    });

    expect(await placeOrder(input, KEY)).toEqual({ status: "failed" });
  });

  it("reports a success without a number as failed", async () => {
    stubFetch(async () => Response.json({}, { status: 201 }));

    expect(await placeOrder(input, KEY)).toEqual({ status: "failed" });
  });
});

describe("startPayment", () => {
  const KEY = "5f0c3c1e-8d7a-4b55-9b0a-0e8e3c1d2a11";
  const REDIRECT_URL = "http://localhost:8000/payment-simulator/demo-card/pay/7d3f0a8e-4a1b-4c55-9f1e-2b3c4d5e6f70";
  const PROBLEMS = "tag:piotrp321.github.io,2026:shop/problems/";

  it("posts the provider as JSON to the store's own /api/checkout/payment with the Idempotency-Key, same origin and uncached", async () => {
    const fetch = stubFetch(async () => Response.json({ redirectUrl: REDIRECT_URL }));

    expect(await startPayment("demo-card", KEY)).toEqual({ status: "ok", redirectUrl: REDIRECT_URL });
    expect(fetch).toHaveBeenCalledWith("/api/checkout/payment", {
      method: "POST",
      credentials: "same-origin",
      cache: "no-store",
      headers: { "Content-Type": "application/json", "Idempotency-Key": KEY },
      body: JSON.stringify({ provider: "demo-card" }),
    });
  });

  it.each([
    ["null", { redirectUrl: null }],
    ["missing", {}],
    ["not a string", { redirectUrl: 5 }],
  ])("answers ok without an address when it is %s", async (_name, body) => {
    stubFetch(async () => Response.json(body));
    expect(await startPayment("demo-card", KEY)).toEqual({ status: "ok", redirectUrl: null });
  });

  const violations = [{ propertyPath: "provider", title: "Wybierz sposób płatności." }];

  it.each([
    [409, { type: `${PROBLEMS}order-already-paid`, detail: "x" }, { status: "conflict", reason: "already_paid", detail: "x" }],
    [409, { type: `${PROBLEMS}payment-deadline-passed`, detail: "x" }, { status: "conflict", reason: "deadline_passed", detail: "x" }],
    [409, { type: `${PROBLEMS}payment-in-progress`, detail: "x" }, { status: "conflict", reason: "in_progress", detail: "x" }],
    [409, { type: `${PROBLEMS}payment-idempotency-key-reused`, detail: "x" }, { status: "conflict", reason: "key_reused", detail: "x" }],
    [409, { type: `${PROBLEMS}something-new`, detail: "x" }, { status: "conflict", reason: "unknown", detail: "x" }],
    [409, { type: "constructor", detail: "x" }, { status: "conflict", reason: "unknown", detail: "x" }],
    [409, {}, { status: "conflict", reason: "unknown", detail: undefined }],
    [422, { detail: "Wybierz sposób płatności.", violations }, { status: "invalid", detail: "Wybierz sposób płatności.", violations }],
    [422, { detail: "Nieprawidłowe dane." }, { status: "invalid", detail: "Nieprawidłowe dane.", violations: [] }],
    [404, { detail: "Nie znaleźliśmy zamówienia." }, { status: "not_found", detail: "Nie znaleźliśmy zamówienia." }],
    [401, {}, { status: "unauthorized" }],
    [403, {}, { status: "failed" }],
    [500, {}, { status: "failed" }],
    [503, {}, { status: "failed" }],
  ])("maps a %s with %j to %j", async (status, body, expected) => {
    stubFetch(async () => Response.json(body, { status }));

    expect(await startPayment("demo-card", KEY)).toEqual(expected);
  });

  it("reports a network error as failed", async () => {
    stubFetch(async () => {
      throw new TypeError("Failed to fetch");
    });

    expect(await startPayment("demo-card", KEY)).toEqual({ status: "failed" });
  });
});

describe("getPaymentStatus", () => {
  const view = {
    orderNumber: "R-100001",
    orderStatus: "new",
    paymentDeadline: "2026-11-05T13:00:00+00:00",
    totalGross: 39998,
    currency: "PLN",
    canStartPayment: true,
    latestPayment: null,
  };

  it("reads the store's own /api/checkout/payment, same origin and uncached", async () => {
    const fetch = stubFetch(async () => Response.json(view));

    expect(await getPaymentStatus()).toEqual({ status: "ok", data: view });
    expect(fetch).toHaveBeenCalledWith("/api/checkout/payment", { method: "GET", credentials: "same-origin", cache: "no-store" });
  });

  it.each([
    [404, { detail: "Nie znaleźliśmy zamówienia." }, { status: "not_found" }],
    [401, {}, { status: "unauthorized" }],
    [503, {}, { status: "failed" }],
    [500, {}, { status: "failed" }],
  ])("maps a %s to %j", async (status, body, expected) => {
    stubFetch(async () => Response.json(body, { status }));

    expect(await getPaymentStatus()).toEqual(expected);
  });

  it("reports a success that is not a status as failed", async () => {
    stubFetch(async () => Response.json({ nothing: true }));

    expect(await getPaymentStatus()).toEqual({ status: "failed" });
  });

  it("reports a network error as failed", async () => {
    stubFetch(async () => {
      throw new TypeError("Failed to fetch");
    });

    expect(await getPaymentStatus()).toEqual({ status: "failed" });
  });
});

describe("createApiClient cache tags", () => {
  it("passes the tags to fetch as next.tags, so the page that reads the data is tagged", async () => {
    const fetch = vi.fn(async () => Response.json([]));
    const client = createApiClient({ baseUrl: "http://api.test", fetch, tags: ["catalog", "product-1"] });

    await client.GET("/api/v1/categories");

    expect(fetch).toHaveBeenCalledTimes(1);
    const calls = fetch.mock.calls as unknown as [Request, { next?: { tags?: string[] } }][];
    expect(calls[0][0].url).toBe("http://api.test/api/v1/categories");
    expect(calls[0][1]).toEqual({ next: { tags: ["catalog", "product-1"] } });
  });

  it("leaves fetch alone without tags", async () => {
    const fetch = vi.fn(async () => Response.json([]));
    const client = createApiClient({ baseUrl: "http://api.test", fetch });

    await client.GET("/api/v1/categories");

    expect((fetch.mock.calls as unknown as unknown[][])[0][1]).toBeUndefined();
  });
});
