import { describe, expect, it } from "vitest";
import { buildSitemapIndex, buildUrlSet, escapeXml } from "./xml";

const HEAD = '<?xml version="1.0" encoding="UTF-8"?>';

describe("escapeXml", () => {
  it("escapes & < > \" and ' and leaves other text, Polish letters included, as it is", () => {
    expect(escapeXml(`a&b<c>d"e'f`)).toBe("a&amp;b&lt;c&gt;d&quot;e&apos;f");
    expect(escapeXml("zażółć gęślą jaźń")).toBe("zażółć gęślą jaźń");
  });

  it("escapes & first, so an entity is not escaped twice", () => {
    expect(escapeXml("&lt;")).toBe("&amp;lt;");
  });
});

describe("buildSitemapIndex", () => {
  it("lists the addresses in the given order, each in a sitemap element", () => {
    expect(buildSitemapIndex(["https://shop.test/sitemaps/listing.xml", "https://shop.test/sitemaps/products/b.xml", "https://shop.test/sitemaps/products/a.xml"])).toBe(
      `${HEAD}\n` +
        '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' +
        "  <sitemap><loc>https://shop.test/sitemaps/listing.xml</loc></sitemap>\n" +
        "  <sitemap><loc>https://shop.test/sitemaps/products/b.xml</loc></sitemap>\n" +
        "  <sitemap><loc>https://shop.test/sitemaps/products/a.xml</loc></sitemap>\n" +
        "</sitemapindex>\n",
    );
  });

  it("escapes the addresses", () => {
    expect(buildSitemapIndex(["https://shop.test/a?x=1&y=2"])).toContain("<loc>https://shop.test/a?x=1&amp;y=2</loc>");
  });
});

describe("buildUrlSet", () => {
  it("lists the entries in the given order, with lastmod only where an entry has one", () => {
    expect(
      buildUrlSet([
        { loc: "https://shop.test/" },
        { loc: "https://shop.test/p/a", lastmod: "2026-11-02T10:00:00+01:00" },
      ]),
    ).toBe(
      `${HEAD}\n` +
        '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' +
        "  <url><loc>https://shop.test/</loc></url>\n" +
        "  <url><loc>https://shop.test/p/a</loc><lastmod>2026-11-02T10:00:00+01:00</lastmod></url>\n" +
        "</urlset>\n",
    );
  });

  it("gives an empty urlset for no entries", () => {
    expect(buildUrlSet([])).toBe(`${HEAD}\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n</urlset>\n`);
  });

  it("escapes the address and the lastmod", () => {
    const xml = buildUrlSet([{ loc: `https://shop.test/p/<a>&"'`, lastmod: "x&y" }]);
    expect(xml).toContain("<loc>https://shop.test/p/&lt;a&gt;&amp;&quot;&apos;</loc>");
    expect(xml).toContain("<lastmod>x&amp;y</lastmod>");
  });
});
