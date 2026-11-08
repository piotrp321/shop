import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import robots, { dynamic } from "./robots";

beforeEach(() => vi.stubEnv("STORE_URL", "http://store.test"));
afterEach(() => vi.unstubAllEnvs());

describe("robots.txt", () => {
  it("is rendered on every request, so STORE_URL is the one of the running store and not of the build", () => {
    expect(dynamic).toBe("force-dynamic");
  });

  it("has the rules of the shop and the sitemap index from STORE_URL", () => {
    expect(robots()).toEqual({
      rules: { userAgent: "*", disallow: ["/cart", "/checkout", "/konto", "/auth/", "/api/"] },
      sitemap: "http://store.test/sitemap.xml",
    });
  });

  it("reads STORE_URL at the request: a later value is used", () => {
    vi.stubEnv("STORE_URL", "http://other.test");
    expect(robots().sitemap).toBe("http://other.test/sitemap.xml");
  });

  it("is a configuration error without STORE_URL", () => {
    vi.stubEnv("STORE_URL", "");
    expect(() => robots()).toThrow("STORE_URL");
  });
});
