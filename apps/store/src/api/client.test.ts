import { afterEach, describe, expect, it, vi } from "vitest";
import { addCartItem, changeCartItemQuantity, loadAccountSession, loadCart, loadCartSummary, removeCartItem } from "./client";

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
    const fetch = stubFetch(async () => Response.json({ name: "Jan" }));

    expect(await loadAccountSession()).toEqual({ status: "ok", session: { name: "Jan" } });
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
      body: JSON.stringify({ productId: PRODUCT, quantity: 1 }),
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
    [409, { status: "conflict", detail: "Nie mamy tylu sztuk tego produktu." }],
    [404, { status: "not_found", detail: "Produkt nie jest już dostępny." }],
    [401, { status: "unauthorized" }],
    [403, { status: "failed" }],
    [422, { status: "failed" }],
    [503, { status: "failed" }],
  ])("maps a %s to %j", async (status, expected) => {
    stubFetch(async () => Response.json(status === 401 || status === 403 || status === 503 ? {} : { detail: expected.detail ?? "x" }, { status }));

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
