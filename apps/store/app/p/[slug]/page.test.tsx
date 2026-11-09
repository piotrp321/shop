// @vitest-environment jsdom
import { cleanup, render } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("@/catalog/load-product", () => ({ loadProductForRequest: vi.fn() }));

import { loadProductForRequest as loadProduct } from "@/catalog/load-product";
import { makeDetails, makeOffer, makeVariant } from "@/catalog/fixtures";
import ProductPage, * as route from "./page";

const props = (slug = "produkt-1") => ({ params: Promise.resolve({ slug }) }) as unknown as Parameters<typeof ProductPage>[0];
const metadataProps = (slug = "produkt-1") => ({ params: Promise.resolve({ slug }) }) as unknown as Parameters<typeof route.generateMetadata>[0];

beforeEach(() => {
  vi.mocked(loadProduct).mockReset();
  vi.stubEnv("STORE_URL", "http://store.test");
});

afterEach(() => {
  cleanup();
  vi.unstubAllEnvs();
});

const ok = (details = makeDetails(1), offer = makeOffer(1)) => ({ kind: "ok" as const, details, offer });

describe("route configuration", () => {
  it("is regenerated at most once an hour and prerenders no product at build time", async () => {
    expect(route.revalidate).toBe(3600);
    expect(await route.generateStaticParams()).toEqual([]);
  });
});

describe("product page", () => {
  it("loads the product by the slug of the address", async () => {
    vi.mocked(loadProduct).mockResolvedValue(ok());
    await ProductPage(props("buty-asfalt"));
    expect(loadProduct).toHaveBeenCalledWith("buty-asfalt");
  });

  it("renders the Next.js 404 for an unknown product", async () => {
    vi.mocked(loadProduct).mockResolvedValue({ kind: "not-found" });
    const failure = await ProductPage(props("nie-ma")).catch((error: unknown) => error);
    expect(failure).toMatchObject({ digest: "NEXT_HTTP_ERROR_FALLBACK;404" });
  });

  it("does not catch a failing detail or offer: the error reaches app/error.tsx", async () => {
    vi.mocked(loadProduct).mockRejectedValue(new Error("GET /api/v1/product-offers failed with status 503."));
    await expect(ProductPage(props())).rejects.toThrow("status 503");
  });

  it("renders the name of the product and two JSON-LD scripts: Product and BreadcrumbList", async () => {
    vi.mocked(loadProduct).mockResolvedValue(ok());
    const { container } = render(await ProductPage(props()));
    expect(container.querySelector("h1")?.textContent).toBe("Produkt 1");
    const scripts = [...container.querySelectorAll('script[type="application/ld+json"]')].map((script) => JSON.parse(script.textContent ?? ""));
    expect(scripts.map((data) => data["@type"])).toEqual(["Product", "BreadcrumbList"]);
    expect(scripts[0].url).toBe("http://store.test/p/produkt-1");
    expect(scripts[1].itemListElement[0].item).toBe("http://store.test/");
  });

  it("escapes < in the JSON-LD of a product whose texts contain markup", async () => {
    const details = makeDetails(1, { name: "</script><img src=x onerror=alert(1)>", shortDescription: "a < b" });
    vi.mocked(loadProduct).mockResolvedValue(ok(details));
    const { container } = render(await ProductPage(props()));
    for (const script of container.querySelectorAll('script[type="application/ld+json"]')) {
      expect(script.innerHTML).not.toContain("<");
    }
    expect(container.querySelector("img[src=x]")).toBeNull();
  });

  it("puts an offer in the JSON-LD only for the variants with a price", async () => {
    const offer = makeOffer(1, {
      variants: [makeVariant(1, 1, { label: "a", gross: 1000 }), makeVariant(1, 2, { label: "b", gross: null, purchasable: false })],
    });
    vi.mocked(loadProduct).mockResolvedValue(ok(makeDetails(1), offer));
    const { container } = render(await ProductPage(props()));
    const product = JSON.parse(container.querySelector('script[type="application/ld+json"]')?.textContent ?? "");
    expect(product.offers.map((entry: { name: string }) => entry.name)).toEqual(["a"]);
  });

  it("fails loudly without STORE_URL", async () => {
    vi.stubEnv("STORE_URL", "");
    vi.mocked(loadProduct).mockResolvedValue(ok());
    await expect(ProductPage(props())).rejects.toThrow("STORE_URL");
  });
});

describe("generateMetadata", () => {
  it("builds the title, the description, the canonical address and Open Graph from the details", async () => {
    vi.mocked(loadProduct).mockResolvedValue(ok(makeDetails(1, { name: "Buty Asfalt", shortDescription: "Lekkie buty." })));
    const metadata = await route.generateMetadata(metadataProps("produkt-1"));
    expect(loadProduct).toHaveBeenCalledWith("produkt-1");
    expect(metadata).toMatchObject({
      title: "Buty Asfalt | Worf",
      description: "Lekkie buty.",
      alternates: { canonical: "http://store.test/p/produkt-1" },
      openGraph: { images: [{ url: "http://imgproxy.test/a1-1600/plain.webp" }] },
    });
  });

  it("leaves the 404 of an unknown product to the page", async () => {
    vi.mocked(loadProduct).mockResolvedValue({ kind: "not-found" });
    expect(await route.generateMetadata(metadataProps("nie-ma"))).toEqual({});
  });

  it("fails loudly without STORE_URL", async () => {
    vi.stubEnv("STORE_URL", "");
    vi.mocked(loadProduct).mockResolvedValue(ok());
    await expect(route.generateMetadata(metadataProps())).rejects.toThrow("STORE_URL");
  });

  it("does not catch a failing detail or offer", async () => {
    vi.mocked(loadProduct).mockRejectedValue(new Error("boom"));
    await expect(route.generateMetadata(metadataProps())).rejects.toThrow("boom");
  });
});
