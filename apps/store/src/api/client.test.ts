import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { addCartItem, changeCartItemQuantity, loadAccountSession, loadCart, loadCartSummary, placeOrder, removeCartItem } from "./client";

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
  };

  it("posts the form as JSON to the store's own /api/checkout with the Idempotency-Key, same origin and uncached", async () => {
    const fetch = stubFetch(async () => Response.json({ number: "R-100001" }, { status: 201 }));

    expect(await placeOrder(input, KEY)).toEqual({ status: "ok", number: "R-100001" });
    expect(fetch).toHaveBeenCalledWith("/api/checkout", {
      method: "POST",
      credentials: "same-origin",
      cache: "no-store",
      headers: { "Content-Type": "application/json", "Idempotency-Key": KEY },
      body: JSON.stringify(input),
    });
  });

  it("takes a repeated request (200) as placed", async () => {
    stubFetch(async () => Response.json({ number: "R-100001" }));
    expect(await placeOrder(input, KEY)).toEqual({ status: "ok", number: "R-100001" });
  });

  const violations = [{ propertyPath: "phone", title: "Podaj 9-cyfrowy numer telefonu." }];

  it.each([
    [422, { violations }, { status: "invalid", detail: undefined, violations }],
    [422, { detail: "Nieprawidłowe dane." }, { status: "invalid", detail: "Nieprawidłowe dane.", violations: [] }],
    [409, { detail: "Koszyk jest pusty." }, { status: "conflict", detail: "Koszyk jest pusty." }],
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
