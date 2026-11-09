import { renderToStaticMarkup } from "react-dom/server";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

// React's `cache` memoises only inside a server render, and this test runs outside one. This stand-in memoises by
// arguments until `newRequest()` is called, which is what the real `cache` does within one request; the test then
// proves that `generateMetadata` and the page ask the cached loader for the same arguments.
const memo = vi.hoisted(() => {
  const results = new Map<unknown, Map<string, unknown>>();
  return {
    newRequest: () => results.clear(),
    cache: <A extends unknown[], R>(fn: (...args: A) => R) =>
      (...args: A): R => {
        const perFn = results.get(fn) ?? new Map<string, unknown>();
        results.set(fn, perFn);
        const key = JSON.stringify(args);
        if (!perFn.has(key)) perFn.set(key, fn(...args));
        return perFn.get(key) as R;
      },
  };
});
vi.mock("react", async (importOriginal) => ({ ...(await importOriginal<typeof import("react")>()), cache: memo.cache }));

// The page renders client components (filters, sort) that need a router; the test only reads their markup.
vi.mock("next/navigation", async (importOriginal) => ({ ...(await importOriginal<typeof import("next/navigation")>()), useRouter: () => ({ push: vi.fn() }) }));

import { loadMenuCategories } from "@/catalog/category-tree";
import { EMPTY_FACETS, TREE, makeOffer, makeProduct } from "@/catalog/fixtures";
import CategoryPage, { generateMetadata as categoryMetadata } from "./c/[...path]/page";
import RootLayout from "./layout";
import Home, { generateMetadata } from "./page";
import SearchPage, { generateMetadata as searchMetadata } from "./szukaj/page";

const json = (body: unknown) => new Response(JSON.stringify(body), { headers: { "Content-Type": "application/json" } });
const PAGE = { items: [makeProduct(1)], page: 2, perPage: 24, total: 40, facets: EMPTY_FACETS };

let requested: string[];

beforeEach(() => {
  memo.newRequest();
  requested = [];
  vi.stubEnv("API_CATALOG_URL", "http://catalog.test");
  vi.stubEnv("STORE_URL", "http://store.test");
  vi.stubGlobal(
    "fetch",
    vi.fn(async (input: Request | string | URL) => {
      const url = new URL(input instanceof Request ? input.url : String(input));
      requested.push(url.pathname);
      if (url.pathname === "/api/v1/categories") return json(TREE);
      if (url.pathname === "/api/v1/product-offers") return json({ items: [makeOffer(1, { fromGross: 5490 })] });
      return json(PAGE);
    }),
  );
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.unstubAllEnvs();
});

const props = (params: Record<string, string | string[]>) =>
  ({ searchParams: Promise.resolve(params) }) as unknown as Parameters<typeof Home>[0];
const categoryProps = (path: string[], params: Record<string, string> = {}) =>
  ({ params: Promise.resolve({ path }), searchParams: Promise.resolve(params) }) as unknown as Parameters<typeof CategoryPage>[0];

// The root layout reads the tree through `loadMenuCategories`, in parallel with the page.
describe("catalogue page requests", () => {
  it("makes one categories, one products and one product offers request for the layout, the metadata and the page together", async () => {
    const request = props({ page: "2" });
    await Promise.all([loadMenuCategories(), generateMetadata(request), Home(request)]);
    expect(requested.sort()).toEqual(["/api/v1/categories", "/api/v1/product-offers", "/api/v1/products"]);
  });

  it("makes the same three requests for the default state, which shows the hero and the tiles from the tree the layout reads", async () => {
    const request = props({});
    const [, , page] = await Promise.all([loadMenuCategories(), generateMetadata(request), Home(request)]);
    expect(requested.sort()).toEqual(["/api/v1/categories", "/api/v1/product-offers", "/api/v1/products"]);
    const markup = renderToStaticMarkup(page);
    expect(markup).toContain("Sprzęt na każdy szlak");
    expect(markup).toContain('href="/c/bieganie"');
  });

  it("makes one request per endpoint for a category page too", async () => {
    const request = categoryProps(["bieganie", "buty"], { page: "2" });
    await Promise.all([loadMenuCategories(), categoryMetadata(request as never), CategoryPage(request)]);
    expect(requested.sort()).toEqual(["/api/v1/categories", "/api/v1/product-offers", "/api/v1/products"]);
  });

  it("makes one request per endpoint for the search page, with filters", async () => {
    const request = props({ q: "kurtki", sort: "cena-rosnaco", o: "Kolor:czarne", page: "2" });
    await Promise.all([loadMenuCategories(), searchMetadata(request as never), SearchPage(request as never)]);
    expect(requested.sort()).toEqual(["/api/v1/categories", "/api/v1/product-offers", "/api/v1/products"]);
  });

  it("makes one request per endpoint for a search narrowed to a category", async () => {
    const request = props({ q: "kurtki", kategoria: "bieganie/buty" });
    await Promise.all([loadMenuCategories(), searchMetadata(request as never), SearchPage(request as never)]);
    expect(requested.sort()).toEqual(["/api/v1/categories", "/api/v1/product-offers", "/api/v1/products"]);
  });

  it("makes no products request for a phrase that is too short", async () => {
    const request = props({ q: "k" });
    await Promise.all([loadMenuCategories(), searchMetadata(request as never), SearchPage(request as never)]);
    expect(requested).toEqual(["/api/v1/categories"]);
  });

  it("makes one request per endpoint for filters on the catalogue page", async () => {
    const request = props({ "cena-od": "100", o: ["Kolor:czarne", "Rozmiar:42"] });
    await Promise.all([loadMenuCategories(), generateMetadata(request), Home(request)]);
    expect(requested.sort()).toEqual(["/api/v1/categories", "/api/v1/product-offers", "/api/v1/products"]);
  });

  it("makes one request per endpoint for the redirect from /?category=<id>", async () => {
    const request = props({ category: "buty" });
    await Promise.all([loadMenuCategories(), generateMetadata(request), Home(request).catch(() => undefined)]);
    expect(requested.filter((path) => path === "/api/v1/categories")).toHaveLength(1);
    expect(requested.filter((path) => path === "/api/v1/products")).toHaveLength(1);
  });

  it("makes no categories request of its own for the footer: layout (header and footer) and page share one", async () => {
    const request = props({ page: "2" });
    const [layout] = await Promise.all([
      RootLayout({ children: null } as unknown as Parameters<typeof RootLayout>[0]),
      generateMetadata(request),
      Home(request),
    ]);
    expect(renderToStaticMarkup(layout)).toMatch(/<footer[\s\S]*href="\/c\/bieganie"[\s\S]*<\/footer>/);
    expect(requested.filter((path) => path === "/api/v1/categories")).toHaveLength(1);
  });

  it("asks again on the next request", async () => {
    await generateMetadata(props({}));
    memo.newRequest();
    await generateMetadata(props({}));
    expect(requested).toHaveLength(4);
  });
});
