import { describe, expect, it } from "vitest";
import { TREE, makeProduct } from "./fixtures";
import { buildCatalogMetadata } from "./page-metadata";

const STORE = "http://store.test";
const page = (n: number, items = [makeProduct(1)]) => ({ items, page: n, perPage: 24, total: items.length });
const ok = (n = 1, items = [makeProduct(1)]) => ({ kind: "ok" as const, categories: TREE, products: page(n, items), offers: {} });

describe("buildCatalogMetadata", () => {
  it("describes the whole catalogue", () => {
    expect(buildCatalogMetadata(ok(), {}, STORE)).toEqual({
      title: "Wszystkie produkty | Rytm",
      description: "Wszystkie produkty w sklepie Rytm.",
      alternates: { canonical: "http://store.test/" },
    });
  });

  it("names the category from the tree, including a nested one", () => {
    expect(buildCatalogMetadata(ok(), { category: "buty" }, STORE)).toMatchObject({
      title: "Buty | Rytm",
      description: "Produkty z kategorii Buty w sklepie Rytm.",
      alternates: { canonical: "http://store.test/?category=buty" },
    });
  });

  it("adds the page to the title and the canonical address after page 1", () => {
    expect(buildCatalogMetadata(ok(2), { category: "buty", page: "2", utm_source: "x" } as never, STORE)).toMatchObject({
      title: "Buty – strona 2 | Rytm",
      alternates: { canonical: "http://store.test/?category=buty&page=2" },
    });
    expect(buildCatalogMetadata(ok(3), { page: "3" }, STORE)).toMatchObject({
      title: "Wszystkie produkty – strona 3 | Rytm",
      alternates: { canonical: "http://store.test/?page=3" },
    });
  });

  it("leaves page 1 out of the title and the canonical address", () => {
    expect(buildCatalogMetadata(ok(1), { category: "buty", page: "1" }, STORE)).toMatchObject({
      title: "Buty | Rytm",
      alternates: { canonical: "http://store.test/?category=buty" },
    });
  });

  it("normalises a zero-padded page", () => {
    expect(buildCatalogMetadata(ok(2), { page: "02" }, STORE)).toMatchObject({
      alternates: { canonical: "http://store.test/?page=2" },
    });
  });

  it("tolerates a trailing slash in the store address", () => {
    expect(buildCatalogMetadata(ok(), { category: "buty" }, "http://store.test/").alternates?.canonical).toBe(
      "http://store.test/?category=buty",
    );
  });

  it("does not set robots for a normal page", () => {
    expect(buildCatalogMetadata(ok(), {}, STORE)).not.toHaveProperty("robots");
    expect(buildCatalogMetadata(ok(2), { page: "2" }, STORE)).not.toHaveProperty("robots");
  });

  it("does not set robots for an empty first page", () => {
    expect(buildCatalogMetadata(ok(1, []), { category: "buty" }, STORE)).not.toHaveProperty("robots");
  });

  it("sets noindex for an empty page beyond the range", () => {
    expect(buildCatalogMetadata(ok(99, []), { category: "buty", page: "99" }, STORE)).toMatchObject({
      robots: { index: false },
      alternates: { canonical: "http://store.test/?category=buty&page=99" },
    });
  });

  it("sets noindex for an invalid filter and keeps the invalid value out of the title", () => {
    const metadata = buildCatalogMetadata({ kind: "invalid-filter" }, { category: "<script>", page: "abc" }, STORE);
    expect(metadata).toMatchObject({
      title: "Nieprawidłowy filtr | Rytm",
      robots: { index: false },
      alternates: { canonical: "http://store.test/?category=%3Cscript%3E" },
    });
    expect(metadata).not.toHaveProperty("description");
  });

  it("ignores a page that is not a positive number in the canonical address", () => {
    for (const bad of ["abc", "0", "-2", "2.5", "1e3", ""]) {
      expect(buildCatalogMetadata({ kind: "invalid-filter" }, { page: bad }, STORE).alternates?.canonical).toBe("http://store.test/");
    }
  });
});
