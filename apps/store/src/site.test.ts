import { renderToStaticMarkup } from "react-dom/server";
import { describe, expect, it, vi } from "vitest";

vi.mock("@/site", () => ({ STORE_NAME: "Sklep Próbny" }));

import { metadata as layoutMetadata } from "../app/layout";
import { EMPTY_FACETS, makeDetails, makeProduct } from "./catalog/fixtures";
import { buildCatalogMetadata, buildCategoryMetadata, buildSearchMetadata } from "./catalog/page-metadata";
import { buildProductMetadata } from "./catalog/product-metadata";
import { SiteHeader } from "./catalog/SiteHeader";
import { buildHomeJsonLd } from "./seo/home-json-ld";

const STORE = "http://store.test";
const ok = { kind: "ok" as const, products: { items: [makeProduct(1)], page: 1, perPage: 24, total: 1, facets: EMPTY_FACETS }, offers: {} };

// The store name is one constant (`STORE_NAME`); changing it changes every place that shows it.
describe("STORE_NAME", () => {
  it("is the name in the layout metadata", () => {
    expect(layoutMetadata).toMatchObject({ title: "Sklep Próbny", description: "Sklep internetowy Sklep Próbny" });
  });

  it("is the name in the titles and descriptions of the listing, category, search and product pages", () => {
    expect(buildCatalogMetadata(ok, {}, STORE)).toMatchObject({ title: "Wszystkie produkty | Sklep Próbny", description: "Wszystkie produkty w sklepie Sklep Próbny." });
    expect(buildCatalogMetadata({ kind: "invalid-filter" }, {}, STORE).title).toBe("Nieprawidłowy filtr | Sklep Próbny");
    expect(buildCategoryMetadata(ok, { name: "Buty", path: "buty" }, undefined, STORE)).toMatchObject({
      title: "Buty | Sklep Próbny",
      description: "Produkty z kategorii Buty w sklepie Sklep Próbny.",
    });
    expect(buildSearchMetadata(ok, "kurtka")).toMatchObject({ title: "Wyniki dla „kurtka” | Sklep Próbny", description: "Wyniki wyszukiwania w sklepie Sklep Próbny." });
    expect(buildSearchMetadata({ kind: "short-phrase" }, undefined).title).toBe("Wyszukiwanie | Sklep Próbny");
    expect(buildProductMetadata(makeDetails(1, { name: "Buty Asfalt" }), STORE).title).toBe("Buty Asfalt | Sklep Próbny");
  });

  it("is the word mark in the header", () => {
    const markup = renderToStaticMarkup(SiteHeader({ categories: null, search: null }));
    expect(markup).toMatch(/<a[^>]*href="\/"[^>]*>Sklep Próbny<\/a>/);
  });

  it("is the name in the structured data of the home page", () => {
    expect(buildHomeJsonLd(STORE).map((data) => data.name)).toEqual(["Sklep Próbny", "Sklep Próbny"]);
  });
});
