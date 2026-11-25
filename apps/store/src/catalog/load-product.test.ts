import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { makeDetails, makeOffer } from "./fixtures";
import { nextCacheTagOf } from "./isr-tags";
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

const BUCKET = /^product-bucket-[0-9a-f]{4}$/;

/** The tags of a read: catalog and one bucket, and no tag carries the id or the slug of the product. */
function expectBucketTags(tags: string[] | undefined, apiTag: string, ...secrets: string[]) {
  expect(tags).toEqual(["catalog", nextCacheTagOf(apiTag)]);
  expect(tags?.[1]).toMatch(BUCKET);
  for (const secret of secrets) for (const tag of tags ?? []) expect(tag.toLowerCase()).not.toContain(secret.toLowerCase());
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

  it("tags the details with catalog and the bucket of the slug tag, and the offer with catalog and the bucket of the product tag", async () => {
    const calls = stubApi(okApi);
    await loadProduct("produkt-1");
    expectBucketTags(calls[0].init?.next?.tags, "product-slug-produkt-1", "produkt-1", DETAILS.id);
    expectBucketTags(calls[1].init?.next?.tags, `product-${DETAILS.id}`, "produkt-1", DETAILS.id);
  });

  it("tags the read of a slug that is not found with the bucket of its slug tag, so the publication of the product revalidates it", async () => {
    const calls = stubApi(() => problem(404));
    await loadProduct("kurtka-puchowa-2");
    expectBucketTags(calls[0].init?.next?.tags, "product-slug-kurtka-puchowa-2", "kurtka-puchowa-2");
  });

  it("tags a slug of 200 characters with the bucket of its slug tag", async () => {
    const calls = stubApi(() => problem(404));
    await loadProduct("a".repeat(200));
    expectBucketTags(calls[0].init?.next?.tags, `product-slug-${"a".repeat(200)}`, "a".repeat(200));
  });

  it.each([
    ["upper case", "Produkt-1"],
    ["a Polish letter", "kurtka-różowa"],
    ["a dash at the end", "kurtka-"],
    ["a dash at the start", "-kurtka"],
    ["two dashes", "kurtka--puchowa"],
    ["a space", "kurtka puchowa"],
    ["a dot", "kurtka.puchowa"],
    ["a slash and a query", "a/b?c"],
    ["an encoded character", "kurtka%2Fpuchowa"],
    ["a line break", "kurtka\npuchowa"],
    ["more than 200 characters", "a".repeat(201)],
    ["nothing", ""],
  ])("answers not-found for a slug with %s without calling the API", async (_case, slug) => {
    const calls = stubApi(okApi);
    expect(await loadProduct(slug)).toEqual({ kind: "not-found" });
    expect(calls).toHaveLength(0);
  });

  it("takes the bucket of the product tag with the id in lower case", async () => {
    const id = "ABCDEF00-0000-4000-8000-00000000000A";
    // The upper-case name falls into another bucket, so the test tells the two apart.
    expect(nextCacheTagOf(`product-${id}`)).not.toBe(nextCacheTagOf(`product-${id.toLowerCase()}`));
    const calls = stubApi((url) =>
      url.pathname.startsWith("/api/v1/products/") ? json({ ...DETAILS, id }) : json({ items: [{ ...OFFER, productId: id }] }),
    );
    await loadProduct("produkt-1");
    expectBucketTags(calls[1].init?.next?.tags, `product-${id.toLowerCase()}`, id);
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
