import { afterEach, beforeEach, expect, it, vi } from "vitest";
import { dynamic, GET } from "./route";

beforeEach(() => {
  vi.stubEnv("STORE_URL", "http://store.test");
  vi.stubEnv("API_CATALOG_URL", "http://catalog.test");
  vi.stubGlobal("fetch", vi.fn(async () => Response.json([{ id: "a", name: "A", slug: "a", path: "a", children: [] }])));
});
afterEach(() => {
  vi.unstubAllGlobals();
  vi.unstubAllEnvs();
});

it("is rendered on every request", () => {
  expect(dynamic).toBe("force-dynamic");
});

it("serves the listing sitemap", async () => {
  const response = await GET();
  expect(response.status).toBe(200);
  const xml = await response.text();
  expect(xml).toContain("<loc>http://store.test/</loc>");
  expect(xml).toContain("<loc>http://store.test/c/a</loc>");
});
