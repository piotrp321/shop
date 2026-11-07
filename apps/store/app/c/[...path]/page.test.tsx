import { renderToStaticMarkup } from "react-dom/server";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

// The page renders client components (filters, sort) that need a router; the tests only read their markup.
vi.mock("next/navigation", async (importOriginal) => ({ ...(await importOriginal<typeof import("next/navigation")>()), useRouter: () => ({ push: vi.fn() }) }));
vi.mock("@/catalog/load-catalog", () => ({ loadCatalogForRequest: vi.fn() }));
vi.mock("@/catalog/category-tree", () => ({ loadCategoryTreeForRequest: vi.fn() }));

import { loadCategoryTreeForRequest as loadTree } from "@/catalog/category-tree";
import { EMPTY_FACETS, TREE, makeOffer, makeProduct, node } from "@/catalog/fixtures";
import { loadCatalogForRequest as loadCatalog } from "@/catalog/load-catalog";
import CategoryPage, { generateMetadata } from "./page";

type Query = Record<string, string | string[]>;
const props = (path: string[], query: Query = {}) =>
  ({ params: Promise.resolve({ path }), searchParams: Promise.resolve(query) }) as unknown as Parameters<typeof CategoryPage>[0];
const metaProps = (path: string[], query: Query = {}) => props(path, query) as unknown as Parameters<typeof generateMetadata>[0];

const okPage = (page = 1, total = 1) => ({
  kind: "ok" as const,
  products: { items: [makeProduct(1)], page, perPage: 24, total, facets: EMPTY_FACETS },
  offers: { [makeProduct(1).id]: makeOffer(1) },
});
const html = async (path: string[], query: Query = {}) => renderToStaticMarkup(await CategoryPage(props(path, query)));
const ldOf = (markup: string) => [...markup.matchAll(/<script type="application\/ld\+json">(.*?)<\/script>/g)].map((match) => JSON.parse(match[1]));

beforeEach(() => {
  vi.mocked(loadTree).mockReset().mockResolvedValue(TREE);
  vi.mocked(loadCatalog).mockReset().mockResolvedValue(okPage());
  vi.stubEnv("STORE_URL", "http://store.test");
});

afterEach(() => {
  vi.unstubAllEnvs();
});

describe("the category page", () => {
  it("lists the products of the category found by the slug path, asking the API for its id and the page", async () => {
    await html(["bieganie", "buty"], { page: ["2", "3"] });
    expect(loadCatalog).toHaveBeenCalledWith({ category: "buty", page: "2" });
  });

  it("resolves a department and a third level category too", async () => {
    await html(["bieganie"]);
    expect(loadCatalog).toHaveBeenLastCalledWith({ category: "bieganie" });
    await html(["bieganie", "buty", "trailowe"]);
    expect(loadCatalog).toHaveBeenLastCalledWith({ category: "trailowe" });
  });

  it("answers 404 for a path that is not in the tree, without asking for products", async () => {
    for (const path of [["nie-ma"], ["bieganie", "kaski"], ["buty"], ["bieganie", "buty", "trailowe", "dalej"]]) {
      const failure = await CategoryPage(props(path)).catch((error: unknown) => error);
      expect(failure).toMatchObject({ digest: "NEXT_HTTP_ERROR_FALLBACK;404" });
    }
    expect(loadCatalog).not.toHaveBeenCalled();
  });

  it("answers 404 when the API no longer knows the category", async () => {
    vi.mocked(loadCatalog).mockResolvedValue({ kind: "unknown-category" });
    const failure = await CategoryPage(props(["bieganie", "buty"])).catch((error: unknown) => error);
    expect(failure).toMatchObject({ digest: "NEXT_HTTP_ERROR_FALLBACK;404" });
  });

  it("reads the products only after the tree (it needs the id of the category)", async () => {
    let release!: (tree: typeof TREE) => void;
    vi.mocked(loadTree).mockReturnValue(new Promise((resolve) => (release = resolve)));
    const rendering = CategoryPage(props(["bieganie", "buty"]));
    await vi.waitFor(() => expect(loadTree).toHaveBeenCalled());
    expect(loadCatalog).not.toHaveBeenCalled();
    release(TREE);
    await rendering;
    expect(loadCatalog).toHaveBeenCalledWith({ category: "buty" });
  });

  it("fails (the error page) when the tree cannot be read", async () => {
    vi.mocked(loadTree).mockRejectedValue(new Error("GET /api/v1/categories failed with status 503."));
    await expect(CategoryPage(props(["bieganie"]))).rejects.toThrow(/503/);
  });

  it("shows the breadcrumbs with the categories of the path linked and the last one as text, the title and the count", async () => {
    const markup = await html(["bieganie", "buty"]);
    expect(markup).toContain('<a class="underline-offset-4 hover:underline" href="/">Wszystkie produkty</a>');
    expect(markup).toContain('href="/c/bieganie">Bieganie</a>');
    expect(markup).toContain('<span aria-current="page">Buty</span>');
    expect(markup).not.toContain('href="/c/bieganie/buty">Buty</a>');
    expect(markup).toMatch(/<h1[^>]*>Buty<\/h1><p[^>]*>1 produkt<\/p>/);
  });

  it("paginates under the category address", async () => {
    vi.mocked(loadCatalog).mockResolvedValue(okPage(2, 60));
    const markup = await html(["bieganie", "buty"], { page: "2" });
    expect(markup).toContain('href="/c/bieganie/buty?page=3"');
    expect(markup).toContain('href="/c/bieganie/buty"');
  });

  it("writes a JSON-LD BreadcrumbList with absolute addresses", async () => {
    expect(ldOf(await html(["bieganie", "buty"]))).toEqual([
      {
        "@context": "https://schema.org",
        "@type": "BreadcrumbList",
        itemListElement: [
          { "@type": "ListItem", position: 1, name: "Wszystkie produkty", item: "http://store.test/" },
          { "@type": "ListItem", position: 2, name: "Bieganie", item: "http://store.test/c/bieganie" },
          { "@type": "ListItem", position: 3, name: "Buty", item: "http://store.test/c/bieganie/buty" },
        ],
      },
    ]);
  });

  it("encodes < in the JSON-LD like the product page, so a category name cannot close the script element", async () => {
    vi.mocked(loadTree).mockResolvedValue([node("x", "A</script><b>")]);
    const markup = await html(["x"]);
    expect(markup.match(/<\/script>/g)).toHaveLength(1);
    expect(markup).toContain("\\u003c/script>");
    expect(ldOf(markup)[0].itemListElement[1].name).toBe("A</script><b>");
  });

  it("fails loudly without STORE_URL", async () => {
    vi.stubEnv("STORE_URL", "");
    await expect(CategoryPage(props(["bieganie"]))).rejects.toThrow("STORE_URL");
  });

  it("shows the invalid filter message (not a 404) for a page number the API rejects", async () => {
    vi.mocked(loadCatalog).mockResolvedValue({ kind: "invalid-filter" });
    const markup = await html(["bieganie", "buty"], { page: "abc" });
    expect(markup).toContain("Nieprawidłowy filtr");
    expect(markup).toContain('href="/">Zobacz cały katalog</a>');
  });

  it("links from the invalid filter message back to the category without its filters and sort", async () => {
    vi.mocked(loadCatalog).mockResolvedValue({ kind: "invalid-filter" });
    const markup = await html(["bieganie", "buty"], { "cena-od": "99,50", sort: "nazwa", o: ["Kolor:czarne"] });
    expect(markup).toContain('href="/c/bieganie/buty">Pokaż wyniki bez filtrów</a>');
  });
});

describe("filters and sorting on the category page", () => {
  const FILTERED: Query = { sort: "cena-malejaco", "cena-od": "100", dostepne: "1", o: ["Kolor:czarne"], page: "3" };

  it("asks the API for the category, the sort, the filters and the page", async () => {
    await html(["bieganie", "buty"], FILTERED);
    expect(loadCatalog).toHaveBeenCalledExactlyOnceWith({
      category: "buty",
      option: ["Kolor:czarne"],
      priceMin: "10000",
      available: true,
      sort: "price_desc",
      page: "3",
    });
  });

  it("ignores the phrase and the category of the search page", async () => {
    const markup = await html(["bieganie", "buty"], { q: "kurtka", kategoria: "rower" });
    expect(loadCatalog).toHaveBeenCalledExactlyOnceWith({ category: "buty" });
    expect(markup).not.toContain("kurtka");
    expect(markup).not.toContain("kategoria");
  });

  it("shows the invalid filter message without asking for products for an unknown sort, after the 404 check of the path", async () => {
    expect(await html(["bieganie", "buty"], { sort: "popularne" })).toContain("Nieprawidłowy filtr");
    expect(loadCatalog).not.toHaveBeenCalled();
    const failure = await CategoryPage(props(["nie-ma"], { sort: "popularne" })).catch((error: unknown) => error);
    expect(failure).toMatchObject({ digest: "NEXT_HTTP_ERROR_FALLBACK;404" });
  });

  it("keeps the filters in the pagination under the category address", async () => {
    vi.mocked(loadCatalog).mockResolvedValue(okPage(3, 100));
    const markup = await html(["bieganie", "buty"], FILTERED);
    expect(markup).toContain("/c/bieganie/buty?sort=cena-malejaco&amp;cena-od=100&amp;dostepne=1&amp;o=Kolor%3Aczarne&amp;page=4");
  });

  it("is noindex, follow with the category address as canonical, without the page, for a sort or a filter", async () => {
    expect(await generateMetadata(metaProps(["bieganie", "buty"], FILTERED))).toMatchObject({
      robots: { index: false, follow: true },
      alternates: { canonical: "http://store.test/c/bieganie/buty" },
    });
    expect(await generateMetadata(metaProps(["bieganie", "buty"], { o: "Kolor:czarne" }))).toMatchObject({ robots: { index: false, follow: true } });
  });

  it("keeps the JSON-LD BreadcrumbList on a filtered page", async () => {
    expect(ldOf(await html(["bieganie", "buty"], FILTERED))).toHaveLength(1);
  });
});

describe("generateMetadata of the category page", () => {
  it("titles the page with the category name and makes the category address canonical, with the page above 1", async () => {
    vi.mocked(loadCatalog).mockResolvedValue(okPage(2, 60));
    expect(await generateMetadata(metaProps(["bieganie", "buty"], { page: ["2", "3"], utm_source: "x" }))).toMatchObject({
      title: "Buty – strona 2 | Rytm",
      description: "Produkty z kategorii Buty w sklepie Rytm.",
      alternates: { canonical: "http://store.test/c/bieganie/buty?page=2" },
    });
    expect(loadCatalog).toHaveBeenCalledWith({ category: "buty", page: "2" });
  });

  it("has the plain category address as canonical on the first page", async () => {
    expect(await generateMetadata(metaProps(["bieganie", "buty"]))).toMatchObject({
      title: "Buty | Rytm",
      alternates: { canonical: "http://store.test/c/bieganie/buty" },
    });
  });

  it("marks an empty page beyond the range and an invalid page number noindex", async () => {
    vi.mocked(loadCatalog).mockResolvedValue({ ...okPage(99), products: { items: [], page: 99, perPage: 24, total: 3, facets: EMPTY_FACETS } });
    expect(await generateMetadata(metaProps(["bieganie"], { page: "99" }))).toMatchObject({ robots: { index: false } });
    vi.mocked(loadCatalog).mockResolvedValue({ kind: "invalid-filter" });
    expect(await generateMetadata(metaProps(["bieganie"], { page: "abc" }))).toMatchObject({
      robots: { index: false },
      alternates: { canonical: "http://store.test/c/bieganie" },
    });
  });

  it("leaves the 404 of an unknown path or category to the page", async () => {
    expect(await generateMetadata(metaProps(["nie-ma"]))).toEqual({});
    expect(loadCatalog).not.toHaveBeenCalled();
    vi.mocked(loadCatalog).mockResolvedValue({ kind: "unknown-category" });
    expect(await generateMetadata(metaProps(["bieganie"]))).toEqual({});
  });

  it("fails loudly without STORE_URL", async () => {
    vi.stubEnv("STORE_URL", "");
    await expect(generateMetadata(metaProps(["bieganie"]))).rejects.toThrow("STORE_URL");
  });
});
