import { afterEach, beforeEach, expect, it, vi } from "vitest";
import { dynamic, GET } from "./route";

const call = (file: string) => GET(new Request("http://store.test/x"), { params: Promise.resolve({ file }) });

beforeEach(() => {
  vi.stubEnv("STORE_URL", "http://store.test");
  vi.stubEnv("API_CATALOG_URL", "http://catalog.test");
  vi.stubGlobal("fetch", vi.fn(async () => Response.json({ items: [{ slug: "a", updatedAt: "2026-11-02T10:00:00+01:00" }] })));
});
afterEach(() => {
  vi.unstubAllGlobals();
  vi.unstubAllEnvs();
});

it("is rendered on every request", () => {
  expect(dynamic).toBe("force-dynamic");
});

it("serves the chunk named by the path", async () => {
  const response = await call("k1.xml");
  expect(response.status).toBe(200);
  expect(await response.text()).toContain("<loc>http://store.test/p/a</loc>");
});

it("answers 404 for an address without .xml", async () => {
  expect((await call("k1")).status).toBe(404);
});
