import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { makeDetails, makeOffer } from "./fixtures";
import { loadProduct } from "./load-product";

const json = (body: unknown, status = 200, contentType = "application/json") =>
  new Response(JSON.stringify(body), { status, headers: { "Content-Type": contentType } });
const problem = (status: number) => json({ type: "about:blank", title: "Error", status }, status, "application/problem+json");

const DETAILS = makeDetails(1);
const OFFER = makeOffer(1, { fromGross: 5490 });

type Call = { url: URL; init: (RequestInit & { next?: { tags?: string[] } }) | undefined };

function stubApi(handler: (url: URL) => Response | Promise<Response>) {
  const calls: Call[] = [];
  vi.stubGlobal(
    "fetch",
    vi.fn(async (input: Request | string | URL, init?: Call["init"]) => {
      const url = new URL(input instanceof Request ? input.url : String(input));
      calls.push({ url, init });
      return handler(url);
    }),
  );
  return calls;
}

const okApi = (url: URL) => (url.pathname.startsWith("/api/v1/products/") ? json(DETAILS) : json({ items: [OFFER] }));

beforeEach(() => {
  vi.stubEnv("API_CATALOG_URL", "http://catalog.test");
  vi.stubEnv("API_INTERNAL_URL", "http://api.test");
});

afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  vi.unstubAllEnvs();
});

describe("loadProduct", () => {
  it("returns the details and the offer of the product", async () => {
    stubApi(okApi);
    expect(await loadProduct("produkt-1")).toEqual({ kind: "ok", details: DETAILS, offer: OFFER });
  });

  it("reads the details by slug and then the offer of that product, both from API_CATALOG_URL", async () => {
    const calls = stubApi(okApi);
    await loadProduct("produkt-1");
    expect(calls.map((call) => call.url.href)).toEqual([
      "http://catalog.test/api/v1/products/produkt-1",
      `http://catalog.test/api/v1/product-offers?productId=${DETAILS.id}`,
    ]);
  });

  it("tags the details with catalog and the offer with catalog and the product", async () => {
    const calls = stubApi(okApi);
    await loadProduct("produkt-1");
    expect(calls[0].init?.next?.tags).toEqual(["catalog"]);
    expect(calls[1].init?.next?.tags).toEqual(["catalog", `product-${DETAILS.id}`]);
  });

  it("writes the product tag with the id in lower case", async () => {
    const id = "ABCDEF00-0000-4000-8000-00000000000A";
    const calls = stubApi((url) =>
      url.pathname.startsWith("/api/v1/products/") ? json({ ...DETAILS, id }) : json({ items: [{ ...OFFER, productId: id }] }),
    );
    await loadProduct("produkt-1");
    expect(calls[1].init?.next?.tags).toEqual(["catalog", `product-${id.toLowerCase()}`]);
  });

  it("encodes the slug in the address", async () => {
    const calls = stubApi(() => problem(404));
    await loadProduct("a/b?c");
    expect(calls[0].url.pathname).toBe("/api/v1/products/a%2Fb%3Fc");
  });

  it("answers not-found for a 404 of the details, without asking for the offer", async () => {
    const calls = stubApi(() => problem(404));
    expect(await loadProduct("nie-ma")).toEqual({ kind: "not-found" });
    expect(calls).toHaveLength(1);
  });

  describe("failures are thrown, not turned into a page", () => {
    it.each([500, 503, 401, 400])("details answer %i", async (status) => {
      stubApi(() => problem(status));
      await expect(loadProduct("produkt-1")).rejects.toThrow(`/api/v1/products/{slug} failed with status ${status}`);
    });

    it("details cannot be reached", async () => {
      stubApi(() => {
        throw new TypeError("fetch failed");
      });
      await expect(loadProduct("produkt-1")).rejects.toThrow("fetch failed");
    });

    it.each([
      ["a list", []],
      ["no variants", { ...DETAILS, variants: undefined }],
      ["no images", { ...DETAILS, images: "x" }],
      ["no breadcrumb", { ...DETAILS, breadcrumb: null }],
      ["no short description", { ...DETAILS, shortDescription: undefined }],
      ["a numeric short description", { ...DETAILS, shortDescription: 5 }],
    ])("details with an unexpected body (%s)", async (_name, body) => {
      stubApi((url) => (url.pathname.startsWith("/api/v1/products/") ? json(body) : json({ items: [OFFER] })));
      await expect(loadProduct("produkt-1")).rejects.toThrow("unexpected");
    });

    it.each([500, 503, 400])("the offer answers %i", async (status) => {
      stubApi((url) => (url.pathname.startsWith("/api/v1/products/") ? json(DETAILS) : problem(status)));
      await expect(loadProduct("produkt-1")).rejects.toThrow(`/api/v1/product-offers failed with status ${status}`);
    });

    it("the offer cannot be reached", async () => {
      stubApi((url) => {
        if (url.pathname.startsWith("/api/v1/products/")) return json(DETAILS);
        throw new TypeError("fetch failed");
      });
      await expect(loadProduct("produkt-1")).rejects.toThrow("fetch failed");
    });

    it.each([
      ["no offer for the product", { items: [] }],
      ["the offer of another product", { items: [{ ...OFFER, productId: "00000000-0000-4000-8000-0000000000ff" }] }],
      ["no list", { nothing: true }],
      ["offer variants that are not a list", { items: [{ ...OFFER, variants: null }] }],
    ])("the offer has an unexpected body (%s)", async (_name, body) => {
      stubApi((url) => (url.pathname.startsWith("/api/v1/products/") ? json(DETAILS) : json(body)));
      await expect(loadProduct("produkt-1")).rejects.toThrow("unexpected");
    });

    it("API_CATALOG_URL is not set", async () => {
      vi.stubEnv("API_CATALOG_URL", "");
      await expect(loadProduct("produkt-1")).rejects.toThrow("API_CATALOG_URL");
    });
  });
});
