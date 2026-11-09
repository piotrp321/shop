import { afterEach, beforeEach, expect, it, vi } from "vitest";
import { dynamic, GET } from "./route";

beforeEach(() => {
  vi.stubEnv("STORE_URL", "http://store.test");
  vi.stubEnv("API_CATALOG_URL", "http://catalog.test");
  vi.stubGlobal("fetch", vi.fn(async () => Response.json({ chunks: [{ key: "k1" }] })));
});
afterEach(() => {
  vi.unstubAllGlobals();
  vi.unstubAllEnvs();
});

it("is rendered on every request", () => {
  expect(dynamic).toBe("force-dynamic");
});

it("serves the sitemap index", async () => {
  const response = await GET();
  expect(response.status).toBe(200);
  expect(await response.text()).toContain("<loc>http://store.test/sitemaps/products/k1.xml</loc>");
});
