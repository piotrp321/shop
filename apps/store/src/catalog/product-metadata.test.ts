import { describe, expect, it } from "vitest";
import { makeDetails } from "./fixtures";
import { buildProductMetadata } from "./product-metadata";

const STORE = "http://store.test";

describe("buildProductMetadata", () => {
  it("has the title with the shop name, the short description and the canonical address of the product", () => {
    const metadata = buildProductMetadata(makeDetails(1, { name: "Buty Asfalt", shortDescription: "Lekkie buty." }), STORE);
    expect(metadata.title).toBe("Buty Asfalt | Worf");
    expect(metadata.description).toBe("Lekkie buty.");
    expect(metadata.alternates).toEqual({ canonical: "http://store.test/p/produkt-1" });
  });

  it("builds the canonical address on STORE_URL with a trailing slash too", () => {
    expect(buildProductMetadata(makeDetails(1), "http://store.test/").alternates).toEqual({ canonical: "http://store.test/p/produkt-1" });
  });

  it("puts the first photo in its largest width in Open Graph, with the title, the description and the address", () => {
    const metadata = buildProductMetadata(makeDetails(1, { name: "Buty Asfalt", shortDescription: "Lekkie buty." }), STORE);
    expect(metadata.openGraph).toMatchObject({
      title: "Buty Asfalt | Worf",
      description: "Lekkie buty.",
      url: "http://store.test/p/produkt-1",
      images: [{ url: "http://imgproxy.test/a1-1600/plain.webp", width: 1600, height: 1600, alt: "Zdjęcie 1, pierwsze" }],
    });
  });

  it("has no Open Graph image for a product without photos", () => {
    const metadata = buildProductMetadata(makeDetails(1, { images: [] }), STORE);
    expect(metadata.openGraph).toBeDefined();
    expect(metadata.openGraph).not.toHaveProperty("images");
  });

  it("has no Open Graph image when the first photo has no webp variant", () => {
    const metadata = buildProductMetadata(makeDetails(1, { images: [{ alt: "x", size: "product", aspectRatio: "1:1", variants: {} }] }), STORE);
    expect(metadata.openGraph).not.toHaveProperty("images");
  });

  it("leaves the description out when the product has none", () => {
    const metadata = buildProductMetadata(makeDetails(1, { shortDescription: "" }), STORE);
    expect(metadata).not.toHaveProperty("description");
    expect(metadata.openGraph).not.toHaveProperty("description");
  });
});
