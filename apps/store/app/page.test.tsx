import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("@/catalog/load-catalog", () => ({ loadCatalogForRequest: vi.fn() }));

import { loadCatalogForRequest as loadCatalog } from "@/catalog/load-catalog";
import { TREE, makeProduct } from "@/catalog/fixtures";
import Home, { generateMetadata } from "./page";

const props = { searchParams: Promise.resolve({ category: "00000000-0000-4000-8000-0000000000ff" }) } as unknown as Parameters<typeof Home>[0];

beforeEach(() => {
  vi.mocked(loadCatalog).mockReset();
  vi.stubEnv("STORE_URL", "http://store.test");
});

afterEach(() => {
  vi.unstubAllEnvs();
});

describe("Home page", () => {
  it("renders the Next.js 404 for an unknown category", async () => {
    vi.mocked(loadCatalog).mockResolvedValue({ kind: "unknown-category" });
    const failure = await Home(props).catch((error: unknown) => error);
    expect(failure).toMatchObject({ digest: "NEXT_HTTP_ERROR_FALLBACK;404" });
  });

  it("does not turn an invalid filter into a 404", async () => {
    vi.mocked(loadCatalog).mockResolvedValue({ kind: "invalid-filter" });
    await expect(Home(props)).resolves.toBeTruthy();
  });

  describe("generateMetadata", () => {
    const query = (params: Record<string, string | string[]>) =>
      ({ searchParams: Promise.resolve(params) }) as unknown as Parameters<typeof generateMetadata>[0];
    const okPage = (page: number, items = [makeProduct(1)]) => ({
      kind: "ok" as const,
      categories: TREE,
      products: { items, page, perPage: 24, total: items.length },
      prices: {},
    });

    it("loads the catalogue for the first value of each repeated parameter", async () => {
      vi.mocked(loadCatalog).mockResolvedValue(okPage(2));
      const metadata = await generateMetadata(query({ category: ["buty", "rower"], page: ["2", "3"], utm_source: "x" }));
      expect(loadCatalog).toHaveBeenCalledWith("buty", "2");
      expect(metadata).toMatchObject({
        title: "Buty – strona 2 | Rytm",
        alternates: { canonical: "http://store.test/?category=buty&page=2" },
      });
    });

    it("marks an invalid filter noindex", async () => {
      vi.mocked(loadCatalog).mockResolvedValue({ kind: "invalid-filter" });
      expect(await generateMetadata(query({ category: "x" }))).toMatchObject({ robots: { index: false } });
    });

    it("leaves the 404 for an unknown category to the page", async () => {
      vi.mocked(loadCatalog).mockResolvedValue({ kind: "unknown-category" });
      expect(await generateMetadata(query({ category: "gone" }))).toEqual({});
    });

    it("fails loudly without STORE_URL", async () => {
      vi.stubEnv("STORE_URL", "");
      vi.mocked(loadCatalog).mockResolvedValue(okPage(1));
      await expect(generateMetadata(query({}))).rejects.toThrow("STORE_URL");
    });
  });
});
