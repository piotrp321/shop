import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { EMPTY_FACETS, makeOffer, makeProduct } from "./fixtures";
import { loadCatalog } from "./load-catalog";

const json = (body: unknown, status = 200, contentType = "application/json") =>
  new Response(JSON.stringify(body), { status, headers: { "Content-Type": contentType } });
const problem = (status: number) => json({ type: "about:blank", title: "Error", status }, status, "application/problem+json");

const PAGE = { items: [makeProduct(1)], page: 1, perPage: 24, total: 1, facets: EMPTY_FACETS };
const TWO = { items: [makeProduct(1), makeProduct(2)], page: 1, perPage: 24, total: 2, facets: EMPTY_FACETS };
const OFFER_1 = makeOffer(1, { fromGross: 5490, level: "low_stock" });
const OFFERS = { items: [OFFER_1] };

function stubApi(handler: (url: URL) => Response | Promise<Response>) {
  const fetchMock = vi.fn<(input: Request | string | URL, init?: RequestInit) => Promise<Response>>(async (input) => {
    const url = new URL(input instanceof Request ? input.url : String(input));
    return handler(url);
  });
  vi.stubGlobal("fetch", fetchMock);
  return fetchMock;
}

const okApi = (url: URL) => {
  if (url.pathname === "/api/v1/product-offers") return json(OFFERS);
  return json(PAGE);
};

const urlsOf = (fetchMock: ReturnType<typeof stubApi>) =>
  fetchMock.mock.calls.map(([input]) => new URL(String(input instanceof Request ? input.url : input)));

// The Request that the client handed to fetch, and the second argument (where Next.js reads `next.tags`).
const requestsOf = (fetchMock: ReturnType<typeof stubApi>) => fetchMock.mock.calls.map(([input]) => input as Request);

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
  it("returns the product page and the offers by product id", async () => {
    stubApi(okApi);
    expect(await loadCatalog({})).toEqual({
      kind: "ok",
      products: PAGE,
      offers: { [OFFER_1.productId]: OFFER_1 },
    });
  });

  it("sends the list reads (products, offers) to API_CATALOG_URL, none to API_INTERNAL_URL, and does not read the category tree", async () => {
    const fetchMock = stubApi(okApi);
    await loadCatalog({ category: "buty", page: "2" });
    const urls = urlsOf(fetchMock);
    expect(urls.map((url) => url.pathname)).toEqual(["/api/v1/products", "/api/v1/product-offers"]);
    expect(urls.map((url) => url.origin)).toEqual(["http://catalog.test", "http://catalog.test"]);
  });

  it("keeps every list read out of the Next.js data cache: cache no-store, no tags and no revalidate", async () => {
    const fetchMock = stubApi(okApi);
    await loadCatalog({});
    const requests = requestsOf(fetchMock);
    expect(requests).toHaveLength(2);
    for (const request of requests) {
      expect(request.cache).toBe("no-store");
    }
    for (const [, init] of fetchMock.mock.calls) {
      expect((init as { next?: unknown } | undefined)?.next).toBeUndefined();
    }
  });

  describe("offers", () => {
    it("asks for the offers of the whole page with one request after the product page, the ids as a repeated key", async () => {
      const fetchMock = stubApi((url) => (url.pathname === "/api/v1/product-offers" ? json(OFFERS) : json(TWO)));
      await loadCatalog({});
      const calls = urlsOf(fetchMock).filter((url) => url.pathname === "/api/v1/product-offers");
      expect(calls).toHaveLength(1);
      expect(calls[0].searchParams.getAll("productId")).toEqual([makeProduct(1).id, makeProduct(2).id]);
      expect(urlsOf(fetchMock).map((url) => url.pathname)).not.toContain("/api/v1/prices");
      expect(urlsOf(fetchMock).map((url) => url.pathname)).not.toContain("/api/v1/availability");
    });

    it("does not ask for offers on an empty page", async () => {
      const fetchMock = stubApi(() => json({ ...PAGE, items: [], total: 0 }));
      expect(await loadCatalog({})).toEqual({ kind: "ok", products: { ...PAGE, items: [], total: 0 }, offers: {} });
      expect(urlsOf(fetchMock).some((url) => url.pathname === "/api/v1/product-offers")).toBe(false);
    });

    it("does not ask for offers when the product list fails", async () => {
      const fetchMock = stubApi(() => problem(400));
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
      expect(await loadCatalog({})).toEqual({ kind: "ok", products: PAGE, offers: {} });
      expect(log).toHaveBeenCalledOnce();
      expect(String(log.mock.calls[0][0])).toContain("product-offers");
    });

    it("keeps the list without offers and logs when the offer request cannot be sent", async () => {
      const log = vi.spyOn(console, "error").mockImplementation(() => {});
      stubApi((url) => {
        if (url.pathname === "/api/v1/product-offers") throw new TypeError("fetch failed");
        return okApi(url);
      });
      expect(await loadCatalog({})).toEqual({ kind: "ok", products: PAGE, offers: {} });
      expect(String(log.mock.calls[0][0])).toContain("fetch failed");
    });
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

  it("forwards the phrase, the filters and the sort; the options as a repeated key, the numbers as given", async () => {
    const fetchMock = stubApi(okApi);
    await loadCatalog({
      q: "kurtka zimowa",
      option: ["Kolor:czarne", "Kolor:biały", "Rozmiar:42"],
      priceMin: "10000",
      priceMax: "25000",
      available: true,
      sort: "price_asc",
      page: "2",
    });
    const products = urlsOf(fetchMock).find((url) => url.pathname === "/api/v1/products")!;
    expect(products.searchParams.get("q")).toBe("kurtka zimowa");
    expect(products.searchParams.getAll("option")).toEqual(["Kolor:czarne", "Kolor:biały", "Rozmiar:42"]);
    expect(products.searchParams.get("priceMin")).toBe("10000");
    expect(products.searchParams.get("priceMax")).toBe("25000");
    expect(products.searchParams.get("available")).toBe("true");
    expect(products.searchParams.get("sort")).toBe("price_asc");
    expect(products.searchParams.get("page")).toBe("2");
    expect(products.searchParams.has("category")).toBe(false);
  });

  it("forwards a malformed price so that the API rejects it", async () => {
    const fetchMock = stubApi(() => problem(400));
    expect(await loadCatalog({ priceMin: "12.5" })).toEqual({ kind: "invalid-filter" });
    expect(urlsOf(fetchMock)[0].searchParams.get("priceMin")).toBe("12.5");
  });

  it("omits absent parameters", async () => {
    const fetchMock = stubApi(okApi);
    await loadCatalog({});
    const urls = fetchMock.mock.calls.map(([input]) => new URL(String(input instanceof Request ? input.url : input)));
    expect(urls.find((url) => url.pathname === "/api/v1/products")!.search).toBe("");
  });

  it("forwards a malformed page so that the API rejects it", async () => {
    const fetchMock = stubApi(() => problem(400));
    expect(await loadCatalog({ page: "abc" })).toEqual({ kind: "invalid-filter" });
    const urls = fetchMock.mock.calls.map(([input]) => new URL(String(input instanceof Request ? input.url : input)));
    expect(urls.find((url) => url.pathname === "/api/v1/products")!.searchParams.get("page")).toBe("abc");
  });

  it("returns invalid-filter for a 400 from the API", async () => {
    stubApi(() => problem(400));
    expect(await loadCatalog({ category: "not-a-uuid" })).toEqual({ kind: "invalid-filter" });
  });

  it("returns unknown-category for a 404 from the product list", async () => {
    stubApi(() => problem(404));
    expect(await loadCatalog({ category: "00000000-0000-4000-8000-0000000000ff" })).toEqual({ kind: "unknown-category" });
  });

  it("rethrows a 503 from the API", async () => {
    stubApi(() => problem(503));
    await expect(loadCatalog({})).rejects.toThrow(/503/);
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
