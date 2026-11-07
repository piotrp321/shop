import { renderToStaticMarkup } from "react-dom/server";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

// The page renders client components (filters, sort) that need a router; the tests only read their markup.
vi.mock("next/navigation", async (importOriginal) => ({ ...(await importOriginal<typeof import("next/navigation")>()), useRouter: () => ({ push: vi.fn() }) }));
vi.mock("@/catalog/load-catalog", () => ({ loadCatalogForRequest: vi.fn() }));
vi.mock("@/catalog/category-tree", () => ({ loadCategoryTreeForRequest: vi.fn() }));

import { loadCategoryTreeForRequest as loadTree } from "@/catalog/category-tree";
import { EMPTY_FACETS, TREE, makeOffer, makeProduct } from "@/catalog/fixtures";
import { loadCatalogForRequest as loadCatalog } from "@/catalog/load-catalog";
import Home, { generateMetadata } from "./page";

type Query = Record<string, string | string[]>;
const props = (query: Query = {}) => ({ searchParams: Promise.resolve(query) }) as unknown as Parameters<typeof Home>[0];
const metaProps = (query: Query = {}) => props(query) as unknown as Parameters<typeof generateMetadata>[0];

const okPage = (page = 1, items = [makeProduct(1)]) => ({
  kind: "ok" as const,
  products: { items, page, perPage: 24, total: items.length, facets: EMPTY_FACETS },
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

describe("Home page", () => {
  it("lists all the products with the count, without breadcrumbs and without reading the tree itself", async () => {
    const markup = renderToStaticMarkup(await Home(props({ page: ["2", "3"] })));
    expect(markup).toMatch(/<h1[^>]*>Wszystkie produkty<\/h1><p[^>]*>1 produkt<\/p>/);
    expect(markup).not.toContain('aria-label="Ścieżka"');
    expect(loadCatalog).toHaveBeenCalledWith({ page: "2" });
    expect(loadTree).not.toHaveBeenCalled();
  });

  describe("filters and sorting", () => {
    const FILTERED: Query = {
      sort: "cena-rosnaco",
      "cena-od": "100",
      "cena-do": "250",
      dostepne: "1",
      o: ["Kolor:czarne", "Rozmiar:42"],
      page: "2",
    };

    it("asks the API for the sort, the filters (whole zloty as grosze), the options and the page", async () => {
      await Home(props(FILTERED));
      expect(loadCatalog).toHaveBeenCalledExactlyOnceWith({
        option: ["Kolor:czarne", "Rozmiar:42"],
        priceMin: "10000",
        priceMax: "25000",
        available: true,
        sort: "price_asc",
        page: "2",
      });
    });

    it("ignores the phrase and the category of the search page: neither reaches the API nor the addresses", async () => {
      const markup = renderToStaticMarkup(await Home(props({ q: "kurtka", kategoria: "bieganie", sort: "nazwa" })));
      expect(loadCatalog).toHaveBeenCalledExactlyOnceWith({ sort: "name" });
      expect(markup).not.toContain("kurtka");
      expect(markup).not.toContain("kategoria");
      expect(markup).not.toContain("q=");
    });

    it("passes a price that is not whole zloty unchanged, so that the API's 400 becomes the invalid filter message", async () => {
      vi.mocked(loadCatalog).mockResolvedValue({ kind: "invalid-filter" });
      expect(renderToStaticMarkup(await Home(props({ "cena-od": "12,5" })))).toContain("Nieprawidłowy filtr");
      expect(loadCatalog).toHaveBeenCalledExactlyOnceWith({ priceMin: "12,5" });
    });

    it("shows the invalid filter message without asking for products for an unknown sort or a dostepne other than 1", async () => {
      const queries: Query[] = [{ sort: "popularne" }, { dostepne: "0" }];
      for (const query of queries) {
        expect(renderToStaticMarkup(await Home(props(query)))).toContain("Nieprawidłowy filtr");
        expect(await generateMetadata(metaProps(query))).toMatchObject({ title: "Nieprawidłowy filtr | Rytm", robots: { index: false } });
      }
      expect(loadCatalog).not.toHaveBeenCalled();
    });

    it("shows the filters in the page: active filters, sort and the pagination keep them", async () => {
      vi.mocked(loadCatalog).mockResolvedValue({ ...okPage(2), products: { ...okPage(2).products, total: 60 } });
      const markup = renderToStaticMarkup(await Home(props(FILTERED)));
      expect(markup).toContain("Usuń filtr: Kolor: czarne");
      expect(markup).toMatch(/<option[^>]*selected=""[^>]*>Cena rosnąco<\/option>/);
      expect(markup).toContain("/?sort=cena-rosnaco&amp;cena-od=100&amp;cena-do=250&amp;dostepne=1&amp;o=Kolor%3Aczarne&amp;o=Rozmiar%3A42&amp;page=3");
    });

    it("is noindex, follow with the plain catalogue address as canonical for a sort or any filter, and not for a page alone", async () => {
      for (const query of [{ sort: "nazwa" }, { "cena-od": "1" }, { "cena-do": "1" }, { dostepne: "1" }, { o: "Kolor:czarne" }, FILTERED]) {
        expect(await generateMetadata(metaProps(query))).toMatchObject({
          robots: { index: false, follow: true },
          alternates: { canonical: "http://store.test/" },
        });
      }
      expect(await generateMetadata(metaProps({ page: "2", q: "x", kategoria: "y" }))).not.toHaveProperty("robots");
    });
  });

  describe("/?category=<id>", () => {
    const redirectOf = async (query: Query) => {
      const failure = (await Home(props(query)).catch((error: unknown) => error)) as { digest?: string };
      return failure.digest;
    };

    it("redirects permanently (308) to the category address", async () => {
      expect(await redirectOf({ category: "buty" })).toBe("NEXT_REDIRECT;replace;/c/bieganie/buty;308;");
      expect(await redirectOf({ category: "bieganie" })).toBe("NEXT_REDIRECT;replace;/c/bieganie;308;");
    });

    it("keeps the other parameters, repeated ones included, and drops every category value", async () => {
      expect(await redirectOf({ category: ["trailowe", "rower"], page: "2", utm: ["a", "b"] })).toBe(
        "NEXT_REDIRECT;replace;/c/bieganie/buty/trailowe?page=2&utm=a&utm=b;308;",
      );
    });

    it("reads the tree and the list together, and the metadata of a redirecting address is empty", async () => {
      let release!: (tree: typeof TREE) => void;
      vi.mocked(loadTree).mockReturnValue(new Promise((resolve) => (release = resolve)));
      const rendering = Home(props({ category: "buty" })).catch((error: unknown) => error);
      await vi.waitFor(() => expect(loadTree).toHaveBeenCalled());
      expect(loadCatalog).toHaveBeenCalledWith({ category: "buty" });
      release(TREE);
      await rendering;
      vi.mocked(loadTree).mockResolvedValue(TREE);
      expect(await generateMetadata(metaProps({ category: "buty" }))).toEqual({});
    });

    it("answers 404 for an unknown category", async () => {
      vi.mocked(loadCatalog).mockResolvedValue({ kind: "unknown-category" });
      expect(await redirectOf({ category: "00000000-0000-4000-8000-0000000000ff" })).toBe("NEXT_HTTP_ERROR_FALLBACK;404");
      expect(await generateMetadata(metaProps({ category: "gone" }))).toEqual({});
    });

    it("shows the invalid filter message, not a 404 or a redirect, for a malformed category", async () => {
      vi.mocked(loadCatalog).mockResolvedValue({ kind: "invalid-filter" });
      const markup = renderToStaticMarkup(await Home(props({ category: "not-a-uuid" })));
      expect(markup).toContain("Nieprawidłowy filtr");
      expect(await generateMetadata(metaProps({ category: "not-a-uuid" }))).toMatchObject({
        title: "Nieprawidłowy filtr | Rytm",
        robots: { index: false },
      });
    });

    it("answers 404 when the list is fine but the tree has no such category (a stale tree)", async () => {
      expect(await redirectOf({ category: "stale" })).toBe("NEXT_HTTP_ERROR_FALLBACK;404");
    });

    it("fails (the error page) when the tree cannot be read", async () => {
      vi.mocked(loadTree).mockRejectedValue(new Error("GET /api/v1/categories failed with status 503."));
      await expect(Home(props({ category: "buty" }))).rejects.toThrow(/503/);
    });
  });

  describe("generateMetadata", () => {
    it("loads the list for the first value of each repeated parameter", async () => {
      vi.mocked(loadCatalog).mockResolvedValue(okPage(2));
      const metadata = await generateMetadata(metaProps({ page: ["2", "3"], utm_source: "x" }));
      expect(loadCatalog).toHaveBeenCalledWith({ page: "2" });
      expect(metadata).toMatchObject({
        title: "Wszystkie produkty – strona 2 | Rytm",
        alternates: { canonical: "http://store.test/?page=2" },
      });
    });

    it("fails loudly without STORE_URL", async () => {
      vi.stubEnv("STORE_URL", "");
      await expect(generateMetadata(metaProps())).rejects.toThrow("STORE_URL");
    });
  });
});
