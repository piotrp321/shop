import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { listingSitemapResponse, productChunkSitemapResponse, sitemapIndexResponse } from "./sitemap";

const json = (body: unknown, status = 200, contentType = "application/json") =>
  new Response(JSON.stringify(body), { status, headers: { "Content-Type": contentType } });
const problem = (status: number) => json({ type: "about:blank", title: "Error", status }, status, "application/problem+json");

const node = (path: string, children: unknown[] = []) => ({ id: path, name: path, slug: path.split("/").at(-1), path, children });

const CHUNKS = { chunks: [{ key: "Zmlyc3Q" }, { key: "c2Vjb25k" }] };
const TREE = [node("bieganie", [node("bieganie/buty", [node("bieganie/buty/trailowe")]), node("bieganie/odziez")]), node("turystyka")];

function stubApi(handler: (url: URL) => Response | Promise<Response>) {
  const fetchMock = vi.fn<(input: Request | string | URL, init?: RequestInit) => Promise<Response>>(async (input) => {
    return handler(new URL(input instanceof Request ? input.url : String(input)));
  });
  vi.stubGlobal("fetch", fetchMock);
  return fetchMock;
}
const requestsOf = (fetchMock: ReturnType<typeof stubApi>) => fetchMock.mock.calls.map(([input]) => input as Request);
const locs = (xml: string) => [...xml.matchAll(/<loc>([^<]*)<\/loc>/g)].map((match) => match[1]);

let logged: ReturnType<typeof vi.spyOn>;

beforeEach(() => {
  vi.stubEnv("STORE_URL", "http://store.test");
  vi.stubEnv("API_CATALOG_URL", "http://catalog.test");
  vi.stubEnv("API_INTERNAL_URL", "http://api.test");
  logged = vi.spyOn(console, "error").mockImplementation(() => {});
});

afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  vi.unstubAllEnvs();
});

function expectServed(response: Response) {
  expect(response.status).toBe(200);
  expect(response.headers.get("Content-Type")).toBe("application/xml; charset=utf-8");
  expect(response.headers.get("Cache-Control")).toBe("public, max-age=300");
}

function expectUnavailable(response: Response) {
  expect(response.status).toBe(503);
  expect(response.headers.get("Cache-Control")).toBe("no-store");
  expect(logged).toHaveBeenCalledTimes(1);
}

describe("sitemapIndexResponse", () => {
  it("lists the listing map first, then one map per chunk in the order of the API", async () => {
    const api = stubApi(() => json(CHUNKS));
    const response = await sitemapIndexResponse();
    expectServed(response);
    const xml = await response.text();
    expect(xml).toContain("<sitemapindex");
    expect(locs(xml)).toEqual([
      "http://store.test/sitemaps/listing.xml",
      "http://store.test/sitemaps/products/Zmlyc3Q.xml",
      "http://store.test/sitemaps/products/c2Vjb25k.xml",
    ]);
    expect(xml).not.toContain("lastmod");
    expect(requestsOf(api).map((request) => request.url)).toEqual(["http://catalog.test/api/v1/sitemap/product-chunks"]);
  });

  it("reads the API on every call and keeps the read out of the Next.js data cache", async () => {
    const api = stubApi(() => json(CHUNKS));
    await sitemapIndexResponse();
    await sitemapIndexResponse();
    const requests = requestsOf(api);
    expect(requests).toHaveLength(2);
    for (const request of requests) {
      expect(request.cache).toBe("no-store");
    }
  });

  it("lists only the listing map for an empty list of chunks", async () => {
    stubApi(() => json({ chunks: [] }));
    expect(locs(await (await sitemapIndexResponse()).text())).toEqual(["http://store.test/sitemaps/listing.xml"]);
  });

  it("answers 503 no-store and logs an API error, never a partial map", async () => {
    stubApi(() => problem(500));
    const response = await sitemapIndexResponse();
    expectUnavailable(response);
    expect(await response.text()).not.toContain("<sitemapindex");
  });

  it("answers 503 no-store and logs a network error", async () => {
    stubApi(() => Promise.reject(new TypeError("fetch failed")));
    expectUnavailable(await sitemapIndexResponse());
  });

  it.each([
    ["a body that is not an object", []],
    ["no chunks", {}],
    ["chunks that are not a list", { chunks: "x" }],
    ["a chunk without a key", { chunks: [{ key: "a" }, {}] }],
    ["a key that is not text", { chunks: [{ key: 7 }] }],
    ["an empty key", { chunks: [{ key: "" }] }],
  ])("answers 503 no-store and logs %s", async (_label, body) => {
    stubApi(() => json(body));
    expectUnavailable(await sitemapIndexResponse());
  });

  it("answers 503 for a 200 that is not JSON", async () => {
    stubApi(() => new Response("<html></html>", { status: 200, headers: { "Content-Type": "text/html" } }));
    expectUnavailable(await sitemapIndexResponse());
  });

  it("is a configuration error without STORE_URL", async () => {
    vi.stubEnv("STORE_URL", "");
    stubApi(() => json(CHUNKS));
    await expect(sitemapIndexResponse()).rejects.toThrow("STORE_URL");
  });

  it("is a configuration error without API_CATALOG_URL, with no fallback to the direct API", async () => {
    vi.stubEnv("API_CATALOG_URL", "");
    const api = stubApi(() => json(CHUNKS));
    await expect(sitemapIndexResponse()).rejects.toThrow("API_CATALOG_URL");
    expect(api).not.toHaveBeenCalled();
  });
});

describe("listingSitemapResponse", () => {
  it("lists the home page and every category depth-first, without lastmod", async () => {
    const api = stubApi(() => json(TREE));
    const response = await listingSitemapResponse();
    expectServed(response);
    const xml = await response.text();
    expect(xml).toContain("<urlset");
    expect(locs(xml)).toEqual([
      "http://store.test/",
      "http://store.test/c/bieganie",
      "http://store.test/c/bieganie/buty",
      "http://store.test/c/bieganie/buty/trailowe",
      "http://store.test/c/bieganie/odziez",
      "http://store.test/c/turystyka",
    ]);
    expect(xml).not.toContain("lastmod");
    const [request] = requestsOf(api);
    expect(request.url).toBe("http://catalog.test/api/v1/categories");
    expect(request.cache).toBe("no-store");
  });

  it("lists only the home page for an empty tree", async () => {
    stubApi(() => json([]));
    expect(locs(await (await listingSitemapResponse()).text())).toEqual(["http://store.test/"]);
  });

  it("answers 503 no-store and logs an API error", async () => {
    stubApi(() => problem(503));
    expectUnavailable(await listingSitemapResponse());
  });

  it("answers 503 no-store and logs a network error", async () => {
    stubApi(() => Promise.reject(new TypeError("fetch failed")));
    expectUnavailable(await listingSitemapResponse());
  });

  it.each([
    ["a tree that is not a list", { categories: [] }],
    ["a category without a path", [{ id: "a", name: "a", slug: "a", children: [] }]],
    ["a category without children", [{ id: "a", name: "a", slug: "a", path: "a" }]],
    ["a bad category below the top level", [node("a", [{ id: "b", path: 3, children: [] }])]],
  ])("answers 503 no-store and logs %s", async (_label, body) => {
    stubApi(() => json(body));
    expectUnavailable(await listingSitemapResponse());
  });

  it("is a configuration error without STORE_URL", async () => {
    vi.stubEnv("STORE_URL", "");
    stubApi(() => json(TREE));
    await expect(listingSitemapResponse()).rejects.toThrow("STORE_URL");
  });
});

describe("productChunkSitemapResponse", () => {
  const ITEMS = {
    items: [
      { slug: "buty-a", updatedAt: "2026-11-02T10:00:00+01:00" },
      { slug: "kurtka-b", updatedAt: "2026-11-03T08:30:00+00:00" },
    ],
  };

  it("lists the product pages with lastmod from updatedAt, in the order of the API", async () => {
    const api = stubApi(() => json(ITEMS));
    const response = await productChunkSitemapResponse("Zmlyc3Q.xml");
    expectServed(response);
    const xml = await response.text();
    expect(locs(xml)).toEqual(["http://store.test/p/buty-a", "http://store.test/p/kurtka-b"]);
    expect(xml).toContain("<lastmod>2026-11-02T10:00:00+01:00</lastmod>");
    expect(xml).toContain("<lastmod>2026-11-03T08:30:00+00:00</lastmod>");
    const [request] = requestsOf(api);
    expect(request.url).toBe("http://catalog.test/api/v1/sitemap/products?chunk=Zmlyc3Q");
    expect(request.cache).toBe("no-store");
  });

  it("hands the key to the API as it is, without decoding it", async () => {
    const api = stubApi(() => json({ items: [] }));
    await productChunkSitemapResponse("eyJmIjpudWxsLCJ0IjoiYTEifQ-_.xml");
    expect(new URL(requestsOf(api)[0].url).searchParams.get("chunk")).toBe("eyJmIjpudWxsLCJ0IjoiYTEifQ-_");
  });

  it("gives 200 with an empty urlset for a chunk without products", async () => {
    stubApi(() => json({ items: [] }));
    const response = await productChunkSitemapResponse("a.xml");
    expectServed(response);
    const xml = await response.text();
    expect(xml).toContain("<urlset");
    expect(xml).not.toContain("<url>");
  });

  it("answers 404 when the API says the key is not valid (400)", async () => {
    stubApi(() => problem(400));
    const response = await productChunkSitemapResponse("nope.xml");
    expect(response.status).toBe(404);
    expect(response.headers.get("Cache-Control")).toBe("no-store");
    expect(logged).not.toHaveBeenCalled();
  });

  it.each(["abc", "abc.XML", "abc.xml.gz", ".xml", ""])("answers 404 for %j without calling the API", async (file) => {
    const api = stubApi(() => json(ITEMS));
    const response = await productChunkSitemapResponse(file);
    expect(response.status).toBe(404);
    expect(api).not.toHaveBeenCalled();
  });

  it("answers 503 no-store and logs an API error", async () => {
    stubApi(() => problem(500));
    expectUnavailable(await productChunkSitemapResponse("a.xml"));
  });

  it("answers 503 no-store and logs a 404 of the API too (only 400 means a bad key)", async () => {
    stubApi(() => problem(404));
    expectUnavailable(await productChunkSitemapResponse("a.xml"));
  });

  it("answers 503 no-store and logs a network error", async () => {
    stubApi(() => Promise.reject(new TypeError("fetch failed")));
    expectUnavailable(await productChunkSitemapResponse("a.xml"));
  });

  it.each([
    ["a body without items", {}],
    ["items that are not a list", { items: {} }],
    ["an item without a slug", { items: [{ updatedAt: "2026-11-02T10:00:00+01:00" }] }],
    ["an item without updatedAt", { items: [{ slug: "a" }] }],
    ["an empty slug", { items: [{ slug: "", updatedAt: "2026-11-02T10:00:00+01:00" }] }],
  ])("answers 503 no-store and logs %s", async (_label, body) => {
    stubApi(() => json(body));
    expectUnavailable(await productChunkSitemapResponse("a.xml"));
  });

  it("is a configuration error without STORE_URL", async () => {
    vi.stubEnv("STORE_URL", "");
    stubApi(() => json(ITEMS));
    await expect(productChunkSitemapResponse("a.xml")).rejects.toThrow("STORE_URL");
  });
});
