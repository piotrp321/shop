import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { TREE, makeProduct } from "./fixtures";
import { loadCatalog } from "./load-catalog";

const json = (body: unknown, status = 200, contentType = "application/json") =>
  new Response(JSON.stringify(body), { status, headers: { "Content-Type": contentType } });
const problem = (status: number) => json({ type: "about:blank", title: "Error", status }, status, "application/problem+json");

const PAGE = { items: [makeProduct(1)], page: 1, perPage: 24, total: 1 };
const TWO = { items: [makeProduct(1), makeProduct(2)], page: 1, perPage: 24, total: 2 };
const PRICE_1 = { productId: makeProduct(1).id, gross: 5490, currency: "PLN" };
const PRICES = { items: [PRICE_1] };
const AVAILABILITY_1 = { productId: makeProduct(1).id, level: "low_stock" };
const AVAILABILITY = { items: [AVAILABILITY_1] };

function stubApi(handler: (url: URL) => Response | Promise<Response>) {
  const fetchMock = vi.fn(async (input: Request | string | URL) => {
    const url = new URL(input instanceof Request ? input.url : String(input));
    return handler(url);
  });
  vi.stubGlobal("fetch", fetchMock);
  return fetchMock;
}

const okApi = (url: URL) => {
  if (url.pathname === "/api/v1/categories") return json(TREE);
  if (url.pathname === "/api/v1/prices") return json(PRICES);
  if (url.pathname === "/api/v1/availability") return json(AVAILABILITY);
  return json(PAGE);
};

const urlsOf = (fetchMock: ReturnType<typeof stubApi>) =>
  fetchMock.mock.calls.map(([input]) => new URL(String(input instanceof Request ? input.url : input)));

beforeEach(() => {
  vi.stubEnv("API_INTERNAL_URL", "http://api.test");
});

afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  vi.unstubAllEnvs();
});

describe("loadCatalog", () => {
  it("returns the category tree, the product page, and the prices and availability by product id", async () => {
    stubApi(okApi);
    expect(await loadCatalog({})).toEqual({
      kind: "ok",
      categories: TREE,
      products: PAGE,
      prices: { [PRICE_1.productId]: PRICE_1 },
      availability: { [AVAILABILITY_1.productId]: AVAILABILITY_1 },
    });
  });

  describe("prices", () => {
    it("asks for the prices of the whole page with one request, the ids as a repeated key", async () => {
      const fetchMock = stubApi((url) => (url.pathname === "/api/v1/categories" ? json(TREE) : url.pathname === "/api/v1/prices" ? json(PRICES) : json(TWO)));
      await loadCatalog({});
      const priceCalls = urlsOf(fetchMock).filter((url) => url.pathname === "/api/v1/prices");
      expect(priceCalls).toHaveLength(1);
      expect(priceCalls[0].origin).toBe("http://api.test");
      expect(priceCalls[0].search).toBe(`?productId=${makeProduct(1).id}&productId=${makeProduct(2).id}`);
    });

    it("skips the price request for an empty page", async () => {
      const fetchMock = stubApi((url) => (url.pathname === "/api/v1/categories" ? json(TREE) : json({ ...PAGE, items: [], total: 0 })));
      expect(await loadCatalog({})).toEqual({ kind: "ok", categories: TREE, products: { ...PAGE, items: [], total: 0 }, prices: {}, availability: {} });
      expect(urlsOf(fetchMock).some((url) => url.pathname === "/api/v1/prices")).toBe(false);
      expect(urlsOf(fetchMock).some((url) => url.pathname === "/api/v1/availability")).toBe(false);
    });

    it("does not ask for prices when the product list fails", async () => {
      const fetchMock = stubApi((url) => (url.pathname === "/api/v1/products" ? problem(404) : json(TREE)));
      await loadCatalog({ category: "00000000-0000-4000-8000-0000000000ff" });
      expect(urlsOf(fetchMock).some((url) => url.pathname === "/api/v1/prices")).toBe(false);
    });

    it.each([
      ["a 400", () => problem(400)],
      ["a 503", () => problem(503)],
      ["a response that is not a price list", () => json({ unexpected: true })],
    ])("keeps the list without prices and logs the failure on %s", async (_name, failure) => {
      const log = vi.spyOn(console, "error").mockImplementation(() => {});
      stubApi((url) => (url.pathname === "/api/v1/prices" ? failure() : okApi(url)));
      expect(await loadCatalog({})).toEqual({ kind: "ok", categories: TREE, products: PAGE, prices: {}, availability: { [AVAILABILITY_1.productId]: AVAILABILITY_1 } });
      expect(log).toHaveBeenCalledTimes(1);
      expect(String(log.mock.calls[0][0])).toContain("prices");
    });

    it("keeps the list without prices and logs when the price request cannot be sent", async () => {
      const log = vi.spyOn(console, "error").mockImplementation(() => {});
      stubApi((url) => {
        if (url.pathname === "/api/v1/prices") throw new TypeError("fetch failed");
        return okApi(url);
      });
      expect(await loadCatalog({})).toEqual({ kind: "ok", categories: TREE, products: PAGE, prices: {}, availability: { [AVAILABILITY_1.productId]: AVAILABILITY_1 } });
      expect(log).toHaveBeenCalledTimes(1);
    });
  });

  describe("availability", () => {
    it("asks for the availability of the whole page with one request, the ids as a repeated key", async () => {
      const fetchMock = stubApi((url) =>
        url.pathname === "/api/v1/categories" ? json(TREE) : url.pathname === "/api/v1/availability" ? json(AVAILABILITY) : url.pathname === "/api/v1/prices" ? json(PRICES) : json(TWO),
      );
      await loadCatalog({});
      const calls = urlsOf(fetchMock).filter((url) => url.pathname === "/api/v1/availability");
      expect(calls).toHaveLength(1);
      expect(calls[0].origin).toBe("http://api.test");
      expect(calls[0].search).toBe(`?productId=${makeProduct(1).id}&productId=${makeProduct(2).id}`);
    });

    it("asks for prices and availability in parallel, after the product page", async () => {
      let release!: () => void;
      const gate = new Promise<void>((resolve) => (release = resolve));
      const started: string[] = [];
      stubApi(async (url) => {
        started.push(url.pathname);
        if (url.pathname === "/api/v1/prices" || url.pathname === "/api/v1/availability") await gate;
        return okApi(url);
      });
      const result = loadCatalog({});
      await vi.waitFor(() => expect(started.sort()).toEqual(["/api/v1/availability", "/api/v1/categories", "/api/v1/prices", "/api/v1/products"]));
      release();
      await result;
    });

    it("does not ask for availability when the product list fails", async () => {
      const fetchMock = stubApi((url) => (url.pathname === "/api/v1/products" ? problem(404) : json(TREE)));
      await loadCatalog({ category: "00000000-0000-4000-8000-0000000000ff" });
      expect(urlsOf(fetchMock).some((url) => url.pathname === "/api/v1/availability")).toBe(false);
    });

    it.each([
      ["a 400", () => problem(400)],
      ["a 503", () => problem(503)],
      ["a response that is not an availability list", () => json({ unexpected: true })],
    ])("keeps the list and the prices without availability and logs the failure on %s", async (_name, failure) => {
      const log = vi.spyOn(console, "error").mockImplementation(() => {});
      stubApi((url) => (url.pathname === "/api/v1/availability" ? failure() : okApi(url)));
      expect(await loadCatalog({})).toEqual({ kind: "ok", categories: TREE, products: PAGE, prices: { [PRICE_1.productId]: PRICE_1 }, availability: {} });
      expect(log).toHaveBeenCalledTimes(1);
      expect(String(log.mock.calls[0][0])).toContain("availability");
    });

    it("keeps the list and the prices and logs when the availability request cannot be sent", async () => {
      const log = vi.spyOn(console, "error").mockImplementation(() => {});
      stubApi((url) => {
        if (url.pathname === "/api/v1/availability") throw new TypeError("fetch failed");
        return okApi(url);
      });
      expect(await loadCatalog({})).toEqual({ kind: "ok", categories: TREE, products: PAGE, prices: { [PRICE_1.productId]: PRICE_1 }, availability: {} });
      expect(log).toHaveBeenCalledTimes(1);
      expect(String(log.mock.calls[0][0])).toContain("availability");
    });

    it("keeps the availability when the prices fail", async () => {
      vi.spyOn(console, "error").mockImplementation(() => {});
      stubApi((url) => (url.pathname === "/api/v1/prices" ? problem(503) : okApi(url)));
      expect(await loadCatalog({})).toEqual({ kind: "ok", categories: TREE, products: PAGE, prices: {}, availability: { [AVAILABILITY_1.productId]: AVAILABILITY_1 } });
    });
  });

  it("fetches categories and products in parallel from the internal address", async () => {
    let release!: () => void;
    const gate = new Promise<void>((resolve) => (release = resolve));
    const started: string[] = [];
    stubApi(async (url) => {
      started.push(url.pathname);
      await gate;
      return okApi(url);
    });
    const result = loadCatalog({});
    await vi.waitFor(() => expect(started.sort()).toEqual(["/api/v1/categories", "/api/v1/products"]));
    release();
    await result;
  });

  it("passes category and page to the API unchanged", async () => {
    const fetchMock = stubApi(okApi);
    await loadCatalog({ category: "abc", page: "3" });
    const urls = fetchMock.mock.calls.map(([input]) => new URL(String(input instanceof Request ? input.url : input)));
    const products = urls.find((url) => url.pathname === "/api/v1/products")!;
    expect(products.searchParams.get("category")).toBe("abc");
    expect(products.searchParams.get("page")).toBe("3");
    expect(products.origin).toBe("http://api.test");
  });

  it("omits absent parameters", async () => {
    const fetchMock = stubApi(okApi);
    await loadCatalog({});
    const urls = fetchMock.mock.calls.map(([input]) => new URL(String(input instanceof Request ? input.url : input)));
    expect(urls.find((url) => url.pathname === "/api/v1/products")!.search).toBe("");
  });

  it("forwards a malformed page so that the API rejects it", async () => {
    const fetchMock = stubApi((url) => (url.pathname === "/api/v1/products" ? problem(400) : json(TREE)));
    expect(await loadCatalog({ page: "abc" })).toEqual({ kind: "invalid-filter" });
    const urls = fetchMock.mock.calls.map(([input]) => new URL(String(input instanceof Request ? input.url : input)));
    expect(urls.find((url) => url.pathname === "/api/v1/products")!.searchParams.get("page")).toBe("abc");
  });

  it("returns invalid-filter for a 400 from the API", async () => {
    stubApi((url) => (url.pathname === "/api/v1/products" ? problem(400) : json(TREE)));
    expect(await loadCatalog({ category: "not-a-uuid" })).toEqual({ kind: "invalid-filter" });
  });

  it("returns unknown-category for a 404 from the product list", async () => {
    stubApi((url) => (url.pathname === "/api/v1/products" ? problem(404) : json(TREE)));
    expect(await loadCatalog({ category: "00000000-0000-4000-8000-0000000000ff" })).toEqual({ kind: "unknown-category" });
  });

  it("rethrows a 404 from the category tree", async () => {
    stubApi((url) => (url.pathname === "/api/v1/categories" ? problem(404) : json(PAGE)));
    await expect(loadCatalog({})).rejects.toThrow(/404/);
  });

  it("rethrows a 503 from the API", async () => {
    stubApi((url) => (url.pathname === "/api/v1/products" ? problem(503) : json(TREE)));
    await expect(loadCatalog({})).rejects.toThrow(/503/);
  });

  it("rethrows a failing category request", async () => {
    stubApi((url) => (url.pathname === "/api/v1/categories" ? problem(500) : json(PAGE)));
    await expect(loadCatalog({})).rejects.toThrow(/500/);
  });

  it("rethrows a network error", async () => {
    stubApi(() => {
      throw new TypeError("fetch failed");
    });
    await expect(loadCatalog({})).rejects.toThrow("fetch failed");
  });

  it("fails clearly when the internal API address is not configured", async () => {
    vi.stubEnv("API_INTERNAL_URL", "");
    stubApi(okApi);
    await expect(loadCatalog({})).rejects.toThrow(/API_INTERNAL_URL/);
  });
});
