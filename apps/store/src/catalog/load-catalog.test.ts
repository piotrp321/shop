import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { TREE, makeOffer, makeProduct } from "./fixtures";
import { loadCatalog } from "./load-catalog";

const json = (body: unknown, status = 200, contentType = "application/json") =>
  new Response(JSON.stringify(body), { status, headers: { "Content-Type": contentType } });
const problem = (status: number) => json({ type: "about:blank", title: "Error", status }, status, "application/problem+json");

const PAGE = { items: [makeProduct(1)], page: 1, perPage: 24, total: 1 };
const TWO = { items: [makeProduct(1), makeProduct(2)], page: 1, perPage: 24, total: 2 };
const OFFER_1 = makeOffer(1, { fromGross: 5490, level: "low_stock" });
const OFFERS = { items: [OFFER_1] };

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
  if (url.pathname === "/api/v1/product-offers") return json(OFFERS);
  return json(PAGE);
};

const urlsOf = (fetchMock: ReturnType<typeof stubApi>) =>
  fetchMock.mock.calls.map(([input]) => new URL(String(input instanceof Request ? input.url : input)));

beforeEach(() => {
  // Catalog reads go through Varnish; the direct API address is set too, so a read sent there would be seen.
  vi.stubEnv("API_CATALOG_URL", "http://catalog.test");
  vi.stubEnv("API_INTERNAL_URL", "http://api.test");
});

afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  vi.unstubAllEnvs();
});

describe("loadCatalog", () => {
  it("returns the category tree, the product page and the offers by product id", async () => {
    stubApi(okApi);
    expect(await loadCatalog({})).toEqual({
      kind: "ok",
      categories: TREE,
      products: PAGE,
      offers: { [OFFER_1.productId]: OFFER_1 },
    });
  });

  it("sends every catalog read (categories, products, offers) to API_CATALOG_URL, none to API_INTERNAL_URL", async () => {
    const fetchMock = stubApi(okApi);
    await loadCatalog({ category: "buty", page: "2" });
    const urls = urlsOf(fetchMock);
    expect(urls.map((url) => url.pathname).sort()).toEqual(["/api/v1/categories", "/api/v1/product-offers", "/api/v1/products"]);
    expect(urls.map((url) => url.origin)).toEqual(["http://catalog.test", "http://catalog.test", "http://catalog.test"]);
  });

  describe("offers", () => {
    it("asks for the offers of the whole page with one request after the product page, the ids as a repeated key", async () => {
      const fetchMock = stubApi((url) => (url.pathname === "/api/v1/categories" ? json(TREE) : url.pathname === "/api/v1/product-offers" ? json(OFFERS) : json(TWO)));
      await loadCatalog({});
      const calls = urlsOf(fetchMock).filter((url) => url.pathname === "/api/v1/product-offers");
      expect(calls).toHaveLength(1);
      expect(calls[0].searchParams.getAll("productId")).toEqual([makeProduct(1).id, makeProduct(2).id]);
      expect(urlsOf(fetchMock).map((url) => url.pathname)).not.toContain("/api/v1/prices");
      expect(urlsOf(fetchMock).map((url) => url.pathname)).not.toContain("/api/v1/availability");
    });

    it("does not ask for offers on an empty page", async () => {
      const fetchMock = stubApi((url) => (url.pathname === "/api/v1/categories" ? json(TREE) : json({ ...PAGE, items: [], total: 0 })));
      expect(await loadCatalog({})).toEqual({ kind: "ok", categories: TREE, products: { ...PAGE, items: [], total: 0 }, offers: {} });
      expect(urlsOf(fetchMock).some((url) => url.pathname === "/api/v1/product-offers")).toBe(false);
    });

    it("does not ask for offers when the product list fails", async () => {
      const fetchMock = stubApi((url) => (url.pathname === "/api/v1/products" ? problem(400) : json(TREE)));
      await loadCatalog({});
      expect(urlsOf(fetchMock).some((url) => url.pathname === "/api/v1/product-offers")).toBe(false);
    });

    it.each([
      ["a 503", () => problem(503)],
      ["a 400", () => problem(400)],
      ["a response that is not an offer list", () => json({ unexpected: true })],
    ])("keeps the list without offers and logs the failure on %s", async (_name, failure) => {
      const log = vi.spyOn(console, "error").mockImplementation(() => {});
      stubApi((url) => (url.pathname === "/api/v1/product-offers" ? failure() : okApi(url)));
      expect(await loadCatalog({})).toEqual({ kind: "ok", categories: TREE, products: PAGE, offers: {} });
      expect(log).toHaveBeenCalledOnce();
      expect(String(log.mock.calls[0][0])).toContain("product-offers");
    });

    it("keeps the list without offers and logs when the offer request cannot be sent", async () => {
      const log = vi.spyOn(console, "error").mockImplementation(() => {});
      stubApi((url) => {
        if (url.pathname === "/api/v1/product-offers") throw new TypeError("fetch failed");
        return okApi(url);
      });
      expect(await loadCatalog({})).toEqual({ kind: "ok", categories: TREE, products: PAGE, offers: {} });
      expect(String(log.mock.calls[0][0])).toContain("fetch failed");
    });
  });

  it("fetches categories and products in parallel", async () => {
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
    expect(products.origin).toBe("http://catalog.test");
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

  it("fails clearly when the catalog API address is not configured, without falling back to the direct one", async () => {
    vi.stubEnv("API_CATALOG_URL", "");
    const fetchMock = stubApi(okApi);
    await expect(loadCatalog({})).rejects.toThrow(/API_CATALOG_URL/);
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
