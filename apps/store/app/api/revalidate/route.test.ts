import { revalidateTag } from "next/cache";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { POST } from "./route";

vi.mock("next/cache", () => ({ revalidateTag: vi.fn() }));

const SECRET = "a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90";
const PRODUCT_TAG = "product-0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60";

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
  it("revalidates every tag with expire 0 and answers with their number", async () => {
    const response = await revalidate({ tags: [PRODUCT_TAG, "catalog"] });

    expect(response.status).toBe(200);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(await response.json()).toEqual({ revalidated: 2 });
    expect(vi.mocked(revalidateTag).mock.calls).toEqual([
      [PRODUCT_TAG, { expire: 0 }],
      ["catalog", { expire: 0 }],
    ]);
  });

  it("accepts a JSON content type with parameters", async () => {
    const response = await revalidate({ tags: ["catalog"] }, { "content-type": "application/json; charset=utf-8" });

    expect(response.status).toBe(200);
    expect(revalidateTag).toHaveBeenCalledTimes(1);
  });

  it("accepts the tag of a product slug", async () => {
    const response = await revalidate({ tags: [PRODUCT_TAG, "product-slug-kurtka-puchowa-2", `product-slug-${"a".repeat(200)}`] });

    expect(response.status).toBe(200);
    expect(vi.mocked(revalidateTag).mock.calls).toEqual([
      [PRODUCT_TAG, { expire: 0 }],
      ["product-slug-kurtka-puchowa-2", { expire: 0 }],
      [`product-slug-${"a".repeat(200)}`, { expire: 0 }],
    ]);
  });

  it("accepts 100 tags", async () => {
    const response = await revalidate({ tags: Array.from({ length: 100 }, () => "catalog") });

    expect(await response.json()).toEqual({ revalidated: 100 });
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
