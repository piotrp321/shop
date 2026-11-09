import { renderToStaticMarkup } from "react-dom/server";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

// The page renders client components (filters, sort) that need a router; the tests only read their markup.
vi.mock("next/navigation", async (importOriginal) => ({ ...(await importOriginal<typeof import("next/navigation")>()), useRouter: () => ({ push: vi.fn() }) }));
vi.mock("@/catalog/load-catalog", () => ({ loadCatalogForRequest: vi.fn() }));
vi.mock("@/catalog/category-tree", () => ({ loadCategoryTreeForRequest: vi.fn() }));

import { loadCategoryTreeForRequest as loadTree } from "@/catalog/category-tree";
import { EMPTY_FACETS, TREE, makeOffer, makeProduct } from "@/catalog/fixtures";
import { loadCatalogForRequest as loadCatalog } from "@/catalog/load-catalog";
import SearchPage, { generateMetadata } from "./page";

type Query = Record<string, string | string[]>;
const props = (query: Query = {}) => ({ searchParams: Promise.resolve(query) }) as unknown as Parameters<typeof SearchPage>[0];
const metaProps = (query: Query = {}) => props(query) as unknown as Parameters<typeof generateMetadata>[0];
const html = async (query: Query = {}) => renderToStaticMarkup(await SearchPage(props(query)));

const okPage = (page = 1, total = 1, items = [makeProduct(1)]) => ({
  kind: "ok" as const,
  products: { items, page, perPage: 24, total, facets: EMPTY_FACETS },
  offers: { [makeProduct(1).id]: makeOffer(1) },
});

beforeEach(() => {
  vi.mocked(loadTree).mockReset().mockResolvedValue(TREE);
  vi.mocked(loadCatalog).mockReset().mockResolvedValue(okPage());
  vi.stubEnv("STORE_URL", "http://store.test");
});

afterEach(() => {
  vi.unstubAllEnvs();
});

describe("the search page", () => {
  it("searches the phrase and shows the results under „Wyniki dla”, with the count", async () => {
    const markup = await html({ q: "kurtki" });
    expect(loadCatalog).toHaveBeenCalledExactlyOnceWith({ q: "kurtki" });
    expect(markup).toMatch(/<h1[^>]*>Wyniki dla „kurtki”<\/h1><p[^>]*>1 produkt<\/p>/);
    expect(markup).toContain('aria-label="Ścieżka"');
    expect(markup).toContain('<span aria-current="page">Wyniki wyszukiwania</span>');
  });

  it("has no structured data (the Organization and the WebSite belong to the home page only)", async () => {
    expect(await html({ q: "kurtki" })).not.toContain("ld+json");
    expect(await html()).not.toContain("ld+json");
  });

  it("trims the phrase and cuts it to 100 characters, taking the first value of a repeated q", async () => {
    await html({ q: ["  kurtka  ", "x"] });
    expect(loadCatalog).toHaveBeenLastCalledWith({ q: "kurtka" });
    await html({ q: "a".repeat(120) });
    expect(loadCatalog).toHaveBeenLastCalledWith({ q: "a".repeat(100) });
  });

  describe("a phrase of fewer than 2 characters", () => {
    it.each<Query>([{}, { q: "" }, { q: " " }, { q: "k" }, { q: " k " }, { q: "ą" }])("says to type 2 characters, without asking for products or the tree: %j", async (query) => {
      const markup = await html(query);
      expect(markup).toContain("Wpisz co najmniej 2 znaki.");
      expect(loadCatalog).not.toHaveBeenCalled();
      expect(loadTree).not.toHaveBeenCalled();
    });

    it("has the title Wyszukiwanie, is noindex, follow and has no canonical address", async () => {
      const metadata = await generateMetadata(metaProps({ q: "k" }));
      expect(metadata).toMatchObject({ title: "Wyszukiwanie | Rytm", robots: { index: false, follow: true } });
      expect(metadata).not.toHaveProperty("alternates");
      expect(loadCatalog).not.toHaveBeenCalled();
    });
  });

  it("maps the sort, the filters and the page like a listing does", async () => {
    await html({ q: "kurtka", sort: "trafnosc", "cena-od": "100", "cena-do": "250", dostepne: "1", o: ["Kolor:czarne", "Rozmiar:42"], page: "2" });
    expect(loadCatalog).toHaveBeenCalledExactlyOnceWith({
      q: "kurtka",
      option: ["Kolor:czarne", "Rozmiar:42"],
      priceMin: "10000",
      priceMax: "25000",
      available: true,
      sort: "relevance",
      page: "2",
    });
  });

  it("narrows the search to the category of `kategoria`, by its slug path, with the path in the breadcrumbs and a removable filter", async () => {
    const markup = await html({ q: "kurtka", kategoria: "bieganie/buty" });
    expect(loadCatalog).toHaveBeenCalledExactlyOnceWith({ category: "buty", q: "kurtka" });
    expect(markup).toContain('href="/szukaj?q=kurtka">Wyniki wyszukiwania</a>');
    expect(markup).toContain('href="/szukaj?q=kurtka&amp;kategoria=bieganie">Bieganie</a>');
    expect(markup).toContain('<span aria-current="page">Buty</span>');
    expect(markup).toContain("Usuń filtr: Kategoria: Buty");
    expect(markup).toContain('<input type="hidden" name="kategoria" value="bieganie/buty"/>');
  });

  it("shows the invalid filter message, without asking for products, for an unknown `kategoria`", async () => {
    for (const kategoria of ["nie-ma", "buty", "bieganie/kaski", "bieganie//buty", "bieganie/buty/trailowe/dalej"]) {
      expect(await html({ q: "kurtka", kategoria })).toContain("Nieprawidłowy filtr");
    }
    expect(loadCatalog).not.toHaveBeenCalled();
  });

  it("shows the invalid filter message, without asking for products, for an unknown sort or a dostepne other than 1", async () => {
    expect(await html({ q: "kurtka", sort: "popularne" })).toContain("Nieprawidłowy filtr");
    expect(await html({ q: "kurtka", dostepne: "tak" })).toContain("Nieprawidłowy filtr");
    expect(loadCatalog).not.toHaveBeenCalled();
  });

  it("shows the invalid filter message when the API rejects a value, and the page is noindex", async () => {
    vi.mocked(loadCatalog).mockResolvedValue({ kind: "invalid-filter" });
    expect(await html({ q: "kurtka", "cena-od": "abc" })).toContain("Nieprawidłowy filtr");
    expect(loadCatalog).toHaveBeenCalledExactlyOnceWith({ q: "kurtka", priceMin: "abc" });
    const metadata = await generateMetadata(metaProps({ q: "kurtka", "cena-od": "abc" }));
    expect(metadata).toMatchObject({ title: "Nieprawidłowy filtr | Rytm", robots: { index: false } });
    expect(metadata).not.toHaveProperty("alternates");
  });

  it("links from the invalid filter message back to the search for the phrase, without filters and sort", async () => {
    vi.mocked(loadCatalog).mockResolvedValue({ kind: "invalid-filter" });
    const markup = await html({ q: "kurtka zimowa", "cena-od": "99,50", sort: "popularne", kategoria: "nie-ma" });
    expect(markup).toContain('href="/szukaj?q=kurtka+zimowa">Pokaż wyniki bez filtrów</a>');
  });

  it("treats a category the API no longer knows as an invalid filter", async () => {
    vi.mocked(loadCatalog).mockResolvedValue({ kind: "unknown-category" });
    expect(await html({ q: "kurtka", kategoria: "bieganie" })).toContain("Nieprawidłowy filtr");
  });

  it("fails (the error page) when the tree needed for `kategoria` cannot be read, and only then", async () => {
    vi.mocked(loadTree).mockRejectedValue(new Error("GET /api/v1/categories failed with status 503."));
    await expect(html({ q: "kurtka", kategoria: "bieganie" })).rejects.toThrow(/503/);
    expect(await html({ q: "kurtka" })).toContain("Wyniki dla");
  });

  it("says nothing was found and links to all the products", async () => {
    vi.mocked(loadCatalog).mockResolvedValue(okPage(1, 0, []));
    const markup = await html({ q: "xyz" });
    expect(markup).toContain("Nie znaleźliśmy produktów.");
    expect(markup).toContain('href="/">Zobacz wszystkie produkty</a>');
  });

  it("keeps the phrase and the filters in the pagination", async () => {
    vi.mocked(loadCatalog).mockResolvedValue(okPage(2, 60));
    const markup = await html({ q: "kurtka", o: "Kolor:czarne", page: "2" });
    expect(markup).toContain("/szukaj?q=kurtka&amp;o=Kolor%3Aczarne&amp;page=3");
    expect(markup).toContain('href="/szukaj?q=kurtka&amp;o=Kolor%3Aczarne"');
  });

  describe("generateMetadata", () => {
    it("titles the page with the phrase, is noindex, follow and has no canonical address, whatever the parameters", async () => {
      const queries: Query[] = [{ q: "kurtki" }, { q: "kurtki", page: "2" }, { q: "kurtki", sort: "cena-rosnaco", o: "Kolor:czarne" }, { q: "kurtki", kategoria: "bieganie" }];
      for (const query of queries) {
        const metadata = await generateMetadata(metaProps(query));
        expect(metadata).toMatchObject({ title: expect.stringContaining("Wyniki dla „kurtki”"), robots: { index: false, follow: true } });
        expect(metadata).not.toHaveProperty("alternates");
      }
    });

    it("needs no STORE_URL: the page has no address of its own to publish", async () => {
      vi.stubEnv("STORE_URL", "");
      await expect(generateMetadata(metaProps({ q: "kurtki" }))).resolves.toMatchObject({ title: "Wyniki dla „kurtki” | Rytm" });
    });
  });
});
