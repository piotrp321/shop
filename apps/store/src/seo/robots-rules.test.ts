import { describe, expect, it } from "vitest";
import { buildRobots } from "./robots-rules";

describe("buildRobots", () => {
  it("blocks the pages without content and points to the sitemap index with an absolute address", () => {
    expect(buildRobots("https://shop.test")).toEqual({
      rules: { userAgent: "*", disallow: ["/cart", "/checkout", "/konto", "/auth/", "/api/"] },
      sitemap: "https://shop.test/sitemap.xml",
    });
  });

  it("does not block the listing, the search or the product pages", () => {
    const { rules } = buildRobots("https://shop.test");
    const disallow = (Array.isArray(rules) ? rules[0] : rules).disallow;
    for (const path of ["/", "/c/", "/szukaj", "/p/"]) {
      expect(disallow).not.toContain(path);
    }
  });

  it("does not double the slash of an address that ends with one", () => {
    expect(buildRobots("https://shop.test/").sitemap).toBe("https://shop.test/sitemap.xml");
  });
});
