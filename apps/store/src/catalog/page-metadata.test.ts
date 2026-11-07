import { describe, expect, it } from "vitest";
import { EMPTY_FACETS, makeProduct, node } from "./fixtures";
import { buildCatalogMetadata, buildCategoryMetadata, buildSearchMetadata } from "./page-metadata";

const STORE = "http://store.test";
const page = (n: number, items = [makeProduct(1)]) => ({ items, page: n, perPage: 24, total: items.length, facets: EMPTY_FACETS });
const ok = (n = 1, items = [makeProduct(1)]) => ({ kind: "ok" as const, products: page(n, items), offers: {} });
// Buty at /c/bieganie/buty.
const BUTY = { ...node("buty", "Buty"), path: "bieganie/buty" };

describe("buildCatalogMetadata (the whole catalogue)", () => {
  it("describes the whole catalogue", () => {
    expect(buildCatalogMetadata(ok(), {}, STORE)).toEqual({
      title: "Wszystkie produkty | Rytm",
      description: "Wszystkie produkty w sklepie Rytm.",
      alternates: { canonical: "http://store.test/" },
    });
  });

  it("adds the page to the title and the canonical address after page 1", () => {
    expect(buildCatalogMetadata(ok(3), { page: "3", utm_source: "x" } as never, STORE)).toMatchObject({
      title: "Wszystkie produkty – strona 3 | Rytm",
      alternates: { canonical: "http://store.test/?page=3" },
    });
  });

  it("leaves page 1 out of the title and the canonical address", () => {
    expect(buildCatalogMetadata(ok(1), { page: "1" }, STORE)).toMatchObject({
      title: "Wszystkie produkty | Rytm",
      alternates: { canonical: "http://store.test/" },
    });
  });

  it("normalises a zero-padded page", () => {
    expect(buildCatalogMetadata(ok(2), { page: "02" }, STORE)).toMatchObject({
      alternates: { canonical: "http://store.test/?page=2" },
    });
  });

  it("tolerates a trailing slash in the store address", () => {
    expect(buildCatalogMetadata(ok(), {}, "http://store.test/").alternates?.canonical).toBe("http://store.test/");
  });

  it("does not set robots for a normal page", () => {
    expect(buildCatalogMetadata(ok(), {}, STORE)).not.toHaveProperty("robots");
    expect(buildCatalogMetadata(ok(2), { page: "2" }, STORE)).not.toHaveProperty("robots");
  });

  it("does not set robots for an empty first page", () => {
    expect(buildCatalogMetadata(ok(1, []), {}, STORE)).not.toHaveProperty("robots");
  });

  it("sets noindex for an empty page beyond the range", () => {
    expect(buildCatalogMetadata(ok(99, []), { page: "99" }, STORE)).toMatchObject({
      robots: { index: false },
      alternates: { canonical: "http://store.test/?page=99" },
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

describe("buildCategoryMetadata (a category page)", () => {
  it("names the category and makes its own address canonical", () => {
    expect(buildCategoryMetadata(ok(), BUTY, undefined, STORE)).toEqual({
      title: "Buty | Rytm",
      description: "Produkty z kategorii Buty w sklepie Rytm.",
      alternates: { canonical: "http://store.test/c/bieganie/buty" },
    });
  });

  it("adds the page to the title and the canonical address after page 1", () => {
    expect(buildCategoryMetadata(ok(2), BUTY, "2", STORE)).toMatchObject({
      title: "Buty – strona 2 | Rytm",
      alternates: { canonical: "http://store.test/c/bieganie/buty?page=2" },
    });
  });

  it("leaves page 1 out of the title and the canonical address and normalises a zero-padded page", () => {
    expect(buildCategoryMetadata(ok(1), BUTY, "1", STORE)).toMatchObject({
      title: "Buty | Rytm",
      alternates: { canonical: "http://store.test/c/bieganie/buty" },
    });
    expect(buildCategoryMetadata(ok(2), BUTY, "02", STORE).alternates?.canonical).toBe("http://store.test/c/bieganie/buty?page=2");
  });

  it("tolerates a trailing slash in the store address", () => {
    expect(buildCategoryMetadata(ok(), BUTY, undefined, "http://store.test/").alternates?.canonical).toBe("http://store.test/c/bieganie/buty");
  });

  it("does not set robots for a normal page or an empty first page", () => {
    expect(buildCategoryMetadata(ok(), BUTY, undefined, STORE)).not.toHaveProperty("robots");
    expect(buildCategoryMetadata(ok(2), BUTY, "2", STORE)).not.toHaveProperty("robots");
    expect(buildCategoryMetadata(ok(1, []), BUTY, undefined, STORE)).not.toHaveProperty("robots");
  });

  it("sets noindex for an empty page beyond the range", () => {
    expect(buildCategoryMetadata(ok(99, []), BUTY, "99", STORE)).toMatchObject({
      robots: { index: false },
      alternates: { canonical: "http://store.test/c/bieganie/buty?page=99" },
    });
  });

  it("sets noindex for an invalid page and points the canonical address at the category", () => {
    const metadata = buildCategoryMetadata({ kind: "invalid-filter" }, BUTY, "abc", STORE);
    expect(metadata).toMatchObject({
      title: "Nieprawidłowy filtr | Rytm",
      robots: { index: false },
      alternates: { canonical: "http://store.test/c/bieganie/buty" },
    });
    expect(metadata).not.toHaveProperty("description");
  });
});

describe("a refined listing (a sort or a filter in the address)", () => {
  const NOINDEX_FOLLOW = { index: false, follow: true };

  it("is noindex, follow with the plain catalogue address as canonical, without the page", () => {
    expect(buildCatalogMetadata(ok(3), { page: "3", refined: true }, STORE)).toMatchObject({
      title: "Wszystkie produkty – strona 3 | Rytm",
      robots: NOINDEX_FOLLOW,
      alternates: { canonical: "http://store.test/" },
    });
  });

  it("is noindex, follow with the category address as canonical, without the page", () => {
    expect(buildCategoryMetadata(ok(2), BUTY, "2", STORE, true)).toMatchObject({
      robots: NOINDEX_FOLLOW,
      alternates: { canonical: "http://store.test/c/bieganie/buty" },
    });
  });

  it("stays noindex, follow for an empty result", () => {
    expect(buildCategoryMetadata(ok(1, []), BUTY, undefined, STORE, true)).toMatchObject({ robots: NOINDEX_FOLLOW });
  });

  it("keeps the plain pages indexable: no robots without `refined`", () => {
    expect(buildCatalogMetadata(ok(), { refined: false }, STORE)).not.toHaveProperty("robots");
    expect(buildCategoryMetadata(ok(), BUTY, undefined, STORE, false)).not.toHaveProperty("robots");
  });

  it("is noindex for an invalid filter, as before", () => {
    expect(buildCategoryMetadata({ kind: "invalid-filter" }, BUTY, undefined, STORE, true)).toMatchObject({
      title: "Nieprawidłowy filtr | Rytm",
      robots: { index: false },
    });
  });
});

describe("buildSearchMetadata (/szukaj)", () => {
  it("titles the page with the phrase, is noindex, follow and has no canonical address", () => {
    const metadata = buildSearchMetadata(ok(), "kurtki");
    expect(metadata).toMatchObject({ title: "Wyniki dla „kurtki” | Rytm", robots: { index: false, follow: true } });
    expect(metadata).not.toHaveProperty("alternates");
  });

  it("is noindex, follow also for an empty result and a later page", () => {
    expect(buildSearchMetadata(ok(1, []), "xyz")).toMatchObject({ robots: { index: false, follow: true } });
    expect(buildSearchMetadata(ok(2), "kurtki")).toMatchObject({ title: "Wyniki dla „kurtki” – strona 2 | Rytm" });
  });

  it("has a title without a phrase when the phrase is too short to search", () => {
    const metadata = buildSearchMetadata({ kind: "short-phrase" }, undefined);
    expect(metadata).toMatchObject({ title: "Wyszukiwanie | Rytm", robots: { index: false, follow: true } });
    expect(metadata).not.toHaveProperty("alternates");
  });

  it("is noindex for an invalid filter and keeps the phrase out of the title", () => {
    const metadata = buildSearchMetadata({ kind: "invalid-filter" }, "<script>");
    expect(metadata).toMatchObject({ title: "Nieprawidłowy filtr | Rytm", robots: { index: false } });
    expect(metadata).not.toHaveProperty("alternates");
  });
});
