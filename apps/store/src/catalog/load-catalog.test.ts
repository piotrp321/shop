import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { TREE, makeProduct } from "./fixtures";
import { loadCatalog } from "./load-catalog";

const json = (body: unknown, status = 200, contentType = "application/json") =>
  new Response(JSON.stringify(body), { status, headers: { "Content-Type": contentType } });
const problem = (status: number) => json({ type: "about:blank", title: "Error", status }, status, "application/problem+json");

const PAGE = { items: [makeProduct(1)], page: 1, perPage: 24, total: 1 };

function stubApi(handler: (url: URL) => Response | Promise<Response>) {
  const fetchMock = vi.fn(async (input: Request | string | URL) => {
    const url = new URL(input instanceof Request ? input.url : String(input));
    return handler(url);
  });
  vi.stubGlobal("fetch", fetchMock);
  return fetchMock;
}

const okApi = (url: URL) => (url.pathname === "/api/v1/categories" ? json(TREE) : json(PAGE));

beforeEach(() => {
  vi.stubEnv("API_INTERNAL_URL", "http://api.test");
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.unstubAllEnvs();
});

describe("loadCatalog", () => {
  it("returns the category tree and the product page", async () => {
    stubApi(okApi);
    expect(await loadCatalog({})).toEqual({ kind: "ok", categories: TREE, products: PAGE });
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
