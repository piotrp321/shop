import { describe, expect, it } from "vitest";
import { buildBreadcrumbListJsonLd, crumbsOf } from "./breadcrumb";

const PATH = [
  { name: "Bieganie", path: "bieganie" },
  { name: "Buty", path: "bieganie/buty" },
];

describe("crumbsOf", () => {
  it("starts with All products and links each category of the path to its category page", () => {
    expect(crumbsOf(PATH)).toEqual([
      { name: "Wszystkie produkty", href: "/" },
      { name: "Bieganie", href: "/c/bieganie" },
      { name: "Buty", href: "/c/bieganie/buty" },
    ]);
  });

  it("is only All products for an empty path", () => {
    expect(crumbsOf([])).toEqual([{ name: "Wszystkie produkty", href: "/" }]);
  });
});

describe("buildBreadcrumbListJsonLd", () => {
  it("is a schema.org BreadcrumbList with positions and absolute addresses", () => {
    expect(buildBreadcrumbListJsonLd(crumbsOf(PATH), "http://store.test")).toEqual({
      "@context": "https://schema.org",
      "@type": "BreadcrumbList",
      itemListElement: [
        { "@type": "ListItem", position: 1, name: "Wszystkie produkty", item: "http://store.test/" },
        { "@type": "ListItem", position: 2, name: "Bieganie", item: "http://store.test/c/bieganie" },
        { "@type": "ListItem", position: 3, name: "Buty", item: "http://store.test/c/bieganie/buty" },
      ],
    });
  });

  it("tolerates a trailing slash in the store address", () => {
    const ld = buildBreadcrumbListJsonLd(crumbsOf(PATH), "http://store.test/");
    expect(ld.itemListElement.map((item) => item.item)).toEqual(["http://store.test/", "http://store.test/c/bieganie", "http://store.test/c/bieganie/buty"]);
  });
});
