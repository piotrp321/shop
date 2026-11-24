import { revalidateTag } from "next/cache";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { nextCacheTagOf } from "@/catalog/isr-tags";
import { POST } from "./route";

vi.mock("next/cache", () => ({ revalidateTag: vi.fn() }));

const SECRET = "a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90";
const PRODUCT_TAG = "product-0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60";

/**
 * 50 slug tags and 50 product tags whose names all fall into one bucket (`product-bucket-72c4`), found once by trying
 * `product-slug-k-<n>` and `product-00000000-0000-4000-8000-<n in hex>` for n from 0 in order.
 */
const SAME_BUCKET_SLUGS = [
  241545, 275595, 284905, 494103, 587136, 645005, 700998, 785979, 996036, 1111696, 1330119, 1384048, 1528170, 1698592,
  1723829, 1785455, 1906254, 1955214, 2078095, 2167807, 2253336, 2277597, 2403844, 2416672, 2526940, 2565354, 2631537,
  2651706, 2672790, 2708596, 2740360, 2865194, 2878110, 2887572, 2925265, 3022706, 3148398, 3238009, 3278394, 3320361,
  3400285, 3458881, 3511414, 3561080, 3577576, 3613441, 3679437, 3705411, 3794073, 3853993,
].map((n) => `product-slug-k-${n}`);
const SAME_BUCKET_IDS = [
  54662, 158117, 256193, 268373, 382307, 422041, 425890, 526758, 558065, 593549, 639333, 741580, 785781, 970307, 980810,
  1085437, 1143066, 1205704, 1370293, 1536529, 1538668, 1625649, 1641866, 1653450, 1674380, 1709383, 1793747, 1805194,
  1837381, 1884289, 1972060, 2132668, 2141096, 2241354, 2287064, 2371178, 2523223, 2603128, 2609264, 2619567, 2736961,
  2800220, 2805434, 2935017, 2998285, 3173278, 3194797, 3315837, 3355158, 3385255,
].map((n) => `product-00000000-0000-4000-8000-${n.toString(16).padStart(12, "0")}`);

let logs: ReturnType<typeof vi.spyOn>[];

beforeEach(() => {
  vi.mocked(revalidateTag).mockReset();
  vi.stubEnv("STORE_REVALIDATE_SECRET", SECRET);
  logs = (["log", "info", "warn", "error", "debug"] as const).map((level) => vi.spyOn(console, level).mockImplementation(() => {}));
});

afterEach(() => {
  // The secret never reaches a log line, whatever the answer was.
  for (const log of logs) expect(JSON.stringify(log.mock.calls)).not.toContain(SECRET);
  vi.unstubAllEnvs();
  vi.restoreAllMocks();
});

function revalidate(body: unknown, headers: Record<string, string> = {}): Promise<Response> {
  return POST(
    new Request("http://store:3000/api/revalidate", {
      method: "POST",
      headers: { authorization: `Bearer ${SECRET}`, "content-type": "application/json", ...headers },
      body: typeof body === "string" ? body : JSON.stringify(body),
    }),
  );
}

async function expectRefused(response: Response, status: number): Promise<void> {
  expect(response.status).toBe(status);
  expect(response.headers.get("cache-control")).toBe("private, no-store");
  expect(revalidateTag).not.toHaveBeenCalled();
}

describe("POST /api/revalidate", () => {
  it("revalidates the Next.js tag of every tag with expire 0 and answers with their number", async () => {
    const response = await revalidate({ tags: [PRODUCT_TAG, "catalog"] });

    expect(response.status).toBe(200);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(await response.json()).toEqual({ revalidated: 2 });
    expect(vi.mocked(revalidateTag).mock.calls).toEqual([
      [nextCacheTagOf(PRODUCT_TAG), { expire: 0 }],
      ["catalog", { expire: 0 }],
    ]);
    expect(nextCacheTagOf(PRODUCT_TAG)).toMatch(/^product-bucket-[0-9a-f]{4}$/);
  });

  it("never revalidates a tag with the id or the slug of a product", async () => {
    await revalidate({ tags: [PRODUCT_TAG, "product-slug-kurtka-puchowa-2"] });

    const revalidated = vi.mocked(revalidateTag).mock.calls.map(([tag]) => tag);
    expect(revalidated).toHaveLength(2);
    for (const tag of revalidated) {
      expect(tag).toMatch(/^product-bucket-[0-9a-f]{4}$/);
      expect(tag).not.toContain("0192f0c4");
      expect(tag).not.toContain("kurtka");
    }
  });

  it("revalidates one bucket once for 100 tags of that bucket, ids and slugs alike", async () => {
    const tags = [...SAME_BUCKET_SLUGS, ...SAME_BUCKET_IDS];
    expect(new Set(tags).size).toBe(100);
    expect(new Set(tags.map(nextCacheTagOf))).toEqual(new Set(["product-bucket-72c4"]));

    const response = await revalidate({ tags });

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual({ revalidated: 100 });
    expect(vi.mocked(revalidateTag).mock.calls).toEqual([["product-bucket-72c4", { expire: 0 }]]);
  });

  it("revalidates a tag repeated in the body once and counts every tag of the body", async () => {
    const response = await revalidate({ tags: [PRODUCT_TAG, "catalog", PRODUCT_TAG, "catalog"] });

    expect(await response.json()).toEqual({ revalidated: 4 });
    expect(vi.mocked(revalidateTag).mock.calls).toEqual([
      [nextCacheTagOf(PRODUCT_TAG), { expire: 0 }],
      ["catalog", { expire: 0 }],
    ]);
  });

  it("accepts a JSON content type with parameters", async () => {
    const response = await revalidate({ tags: ["catalog"] }, { "content-type": "application/json; charset=utf-8" });

    expect(response.status).toBe(200);
    expect(revalidateTag).toHaveBeenCalledTimes(1);
  });

  it("accepts the tag of a product slug", async () => {
    const tags = [PRODUCT_TAG, "product-slug-kurtka-puchowa-2", `product-slug-${"a".repeat(200)}`];
    // Three different buckets, so every tag shows up as its own call.
    expect(new Set(tags.map(nextCacheTagOf)).size).toBe(3);

    const response = await revalidate({ tags });

    expect(response.status).toBe(200);
    expect(vi.mocked(revalidateTag).mock.calls).toEqual([
      [nextCacheTagOf(PRODUCT_TAG), { expire: 0 }],
      [nextCacheTagOf("product-slug-kurtka-puchowa-2"), { expire: 0 }],
      [nextCacheTagOf(`product-slug-${"a".repeat(200)}`), { expire: 0 }],
    ]);
  });

  it("accepts 100 tags", async () => {
    const response = await revalidate({ tags: Array.from({ length: 100 }, () => "catalog") });

    expect(await response.json()).toEqual({ revalidated: 100 });
    expect(vi.mocked(revalidateTag).mock.calls).toEqual([["catalog", { expire: 0 }]]);
  });

  it.each([
    ["no Authorization", { authorization: "" }],
    ["a wrong secret", { authorization: `Bearer ${SECRET.slice(0, -1)}1` }],
    ["a shorter secret", { authorization: `Bearer ${SECRET.slice(0, 10)}` }],
    ["a longer secret", { authorization: `Bearer ${SECRET}0` }],
    ["the secret without the Bearer scheme", { authorization: SECRET }],
    ["another scheme", { authorization: `Basic ${SECRET}` }],
  ])("is 401 with %s", async (_case, headers) => {
    const response = await revalidate({ tags: ["catalog"] }, headers);

    await expectRefused(response, 401);
  });

  it("checks the secret before the body", async () => {
    await expectRefused(await revalidate("not json", { authorization: "Bearer wrong" }), 401);
  });

  it.each([
    ["not JSON", "{tags:"],
    ["not an object", ["catalog"]],
    ["no tags", {}],
    ["tags that are not a list", { tags: "catalog" }],
    ["no tag", { tags: [] }],
    ["101 tags", { tags: Array.from({ length: 101 }, () => "catalog") }],
    ["a tag that is not a string", { tags: [42] }],
    ["a product tag in upper case", { tags: ["product-0192F0C4-7C1A-7C4E-9A6E-3F1D2B4C5A60"] }],
    ["a product tag without a UUID", { tags: ["product-42"] }],
    ["a product tag with more after the UUID", { tags: [`${PRODUCT_TAG}0`] }],
    ["a tag outside the dictionary", { tags: ["category-0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60"] }],
    ["one bad tag among good ones", { tags: ["catalog", "Catalog"] }],
    ["a slug tag without a slug", { tags: ["product-slug-"] }],
    ["a slug tag in upper case", { tags: ["product-slug-Kurtka"] }],
    ["a slug tag with a Polish letter", { tags: ["product-slug-kurtka-różowa"] }],
    ["a slug tag with a dash at the end", { tags: ["product-slug-kurtka-"] }],
    ["a slug tag with two dashes", { tags: ["product-slug-kurtka--puchowa"] }],
    ["a slug tag with a space", { tags: ["product-slug-kurtka puchowa"] }],
    ["a slug tag with a line break", { tags: ["product-slug-kurtka\n"] }],
    ["a slug tag with a slash", { tags: ["product-slug-kurtka/puchowa"] }],
    ["a slug tag over 200 characters", { tags: [`product-slug-${"a".repeat(201)}`] }],
    ["a Next.js bucket tag", { tags: ["product-bucket-0000"] }],
    ["the bucket of a known tag", { tags: [nextCacheTagOf(PRODUCT_TAG)] }],
    ["an empty tag", { tags: [""] }],
  ])("is 400 for %s", async (_case, body) => {
    await expectRefused(await revalidate(body), 400);
  });

  it.each([["text/plain"], ["application/x-www-form-urlencoded"], [""]])("is 400 for the content type %j", async (contentType) => {
    await expectRefused(await revalidate({ tags: ["catalog"] }, { "content-type": contentType }), 400);
  });

  it.each([["unset", undefined], ["empty", ""]])("is 503 when STORE_REVALIDATE_SECRET is %s", async (_case, value) => {
    vi.stubEnv("STORE_REVALIDATE_SECRET", value);

    await expectRefused(await revalidate({ tags: ["catalog"] }), 503);
    await expectRefused(await revalidate({ tags: ["catalog"] }, { authorization: "Bearer " }), 503);
  });
});
