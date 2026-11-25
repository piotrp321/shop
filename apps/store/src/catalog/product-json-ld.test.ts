import { describe, expect, it } from "vitest";
import { makeDetails, makeOffer, makeVariant, variantId } from "./fixtures";
import { buildBreadcrumbJsonLd, buildProductJsonLd, serializeJsonLd } from "./product-json-ld";

const STORE = "http://store.test";

const SHOES = makeOffer(1, {
  variantAxes: ["Kolor", "Rozmiar"],
  fromGross: 30000,
  hasPriceRange: true,
  variants: [
    makeVariant(1, 1, { label: "czarne, 42", gross: 30000, level: "in_stock" }),
    makeVariant(1, 2, { label: "czarne, 43", gross: null, level: "in_stock", purchasable: false }),
    makeVariant(1, 3, { label: "czarne, 44", gross: 32050, level: "low_stock" }),
    makeVariant(1, 4, { label: "czarne, 45", gross: 31000, level: "out_of_stock", purchasable: false }),
  ],
});

describe("buildProductJsonLd", () => {
  it("describes the product with the name, the description, the largest width of every photo and the canonical address", () => {
    const ld = buildProductJsonLd(makeDetails(1, { name: "Buty Asfalt", shortDescription: "Lekkie buty." }), SHOES, STORE);
    expect(ld).toMatchObject({
      "@context": "https://schema.org",
      "@type": "Product",
      name: "Buty Asfalt",
      description: "Lekkie buty.",
      image: ["http://imgproxy.test/a1-1600/plain.webp", "http://imgproxy.test/b1-1600/plain.webp"],
      url: "http://store.test/p/produkt-1",
    });
  });

  it("has an Offer only for the variants with a price, named by the label, with the price in zloty with a dot", () => {
    const ld = buildProductJsonLd(makeDetails(1), SHOES, STORE);
    expect(ld.offers).toEqual([
      { "@type": "Offer", name: "czarne, 42", price: "300.00", priceCurrency: "PLN", availability: "https://schema.org/InStock", url: "http://store.test/p/produkt-1" },
      { "@type": "Offer", name: "czarne, 44", price: "320.50", priceCurrency: "PLN", availability: "https://schema.org/LimitedAvailability", url: "http://store.test/p/produkt-1" },
      { "@type": "Offer", name: "czarne, 45", price: "310.00", priceCurrency: "PLN", availability: "https://schema.org/OutOfStock", url: "http://store.test/p/produkt-1" },
    ]);
  });

  it("maps the level of a variant to the availability", () => {
    const levels = { in_stock: "InStock", low_stock: "LimitedAvailability", out_of_stock: "OutOfStock" } as const;
    for (const [level, availability] of Object.entries(levels)) {
      const offer = makeOffer(1, { variants: [makeVariant(1, 1, { gross: 100, level: level as keyof typeof levels })] });
      expect(buildProductJsonLd(makeDetails(1), offer, STORE).offers).toMatchObject([{ availability: `https://schema.org/${availability}` }]);
    }
  });

  it("leaves the name of the offer out for the default variant, which has no label", () => {
    const ld = buildProductJsonLd(makeDetails(1), makeOffer(1), STORE);
    expect(ld.offers).toHaveLength(1);
    expect(ld.offers[0]).not.toHaveProperty("name");
    expect(ld.offers[0]).toMatchObject({ price: "123.00" });
  });

  it("has no offers for a product without a variant with a price, and no image for one without photos", () => {
    const offer = makeOffer(1, { fromGross: null, level: "out_of_stock", variants: [makeVariant(1, 1, { gross: null, level: "out_of_stock", purchasable: false })] });
    const ld = buildProductJsonLd(makeDetails(1, { images: [], shortDescription: "" }), offer, STORE);
    expect(ld.offers).toEqual([]);
    expect(ld).not.toHaveProperty("image");
    expect(ld).not.toHaveProperty("description");
  });
});

describe("buildProductJsonLd product data", () => {
  const variant = (n: number, sku: string, ean: string, gtin: string) => ({ variantId: variantId(1, n), label: "", values: [], sku, ean, gtin });
  const TWO_VARIANTS = makeOffer(1, { variants: [makeVariant(1, 1, { label: "M" }), makeVariant(1, 2, { label: "L" })] });

  it("has the brand as a Brand of the product only when the brand is not empty", () => {
    expect(buildProductJsonLd(makeDetails(1, { brand: "Hallen" }), makeOffer(1), STORE)).toMatchObject({ brand: { "@type": "Brand", name: "Hallen" } });
    expect(buildProductJsonLd(makeDetails(1, { brand: "" }), makeOffer(1), STORE)).not.toHaveProperty("brand");
  });

  it("takes sku and gtin of an Offer from the fields of its variant, each only when not empty", () => {
    const details = makeDetails(1, { variants: [variant(1, "A-M", "5901234123457", "5901234123457"), variant(2, "", "", "")] });
    const ld = buildProductJsonLd(details, TWO_VARIANTS, STORE);
    expect(ld.offers[0]).toMatchObject({ sku: "A-M", gtin: "5901234123457" });
    expect(ld.offers[1]).not.toHaveProperty("sku");
    expect(ld.offers[1]).not.toHaveProperty("gtin");
  });

  it("leaves gtin out for a variant with an EAN and an empty gtin, and does not judge the EAN", () => {
    const details = makeDetails(1, { variants: [variant(1, "A-M", "2000000000008", ""), variant(2, "A-L", "5901234123457", "5901234123457")] });
    const ld = buildProductJsonLd(details, TWO_VARIANTS, STORE);
    expect(ld.offers[0]).toMatchObject({ sku: "A-M" });
    expect(ld.offers[0]).not.toHaveProperty("gtin");
    expect(ld.offers[1]).toMatchObject({ sku: "A-L", gtin: "5901234123457" });
  });

  it("takes the gtin as the API gave it, even when it differs from the EAN", () => {
    const ld = buildProductJsonLd(makeDetails(1, { variants: [variant(1, "A", "05901234123457", "5901234123457")] }), makeOffer(1), STORE);
    expect(ld.offers[0]).toMatchObject({ gtin: "5901234123457" });
  });

  it("keeps the short description as the description, whatever the long one says", () => {
    const ld = buildProductJsonLd(makeDetails(1, { shortDescription: "Krótki.", description: "Długi opis.\n\nDrugi akapit." }), makeOffer(1), STORE);
    expect(ld).toMatchObject({ description: "Krótki." });
    expect(JSON.stringify(ld)).not.toContain("Długi opis");
  });

  it("keeps the parameters out of the data", () => {
    const ld = buildProductJsonLd(makeDetails(1, { parameters: [{ name: "Masa", value: "3 kg" }] }), makeOffer(1), STORE);
    expect(JSON.stringify(ld)).not.toContain("Masa");
  });

  it("is the same as before the product data for a product without it", () => {
    expect(buildProductJsonLd(makeDetails(1, { name: "Buty", shortDescription: "Lekkie." }), makeOffer(1), STORE)).toStrictEqual({
      "@context": "https://schema.org",
      "@type": "Product",
      name: "Buty",
      description: "Lekkie.",
      image: ["http://imgproxy.test/a1-1600/plain.webp", "http://imgproxy.test/b1-1600/plain.webp"],
      url: "http://store.test/p/produkt-1",
      offers: [{ "@type": "Offer", price: "123.00", priceCurrency: "PLN", availability: "https://schema.org/InStock", url: "http://store.test/p/produkt-1" }],
    });
  });
});

describe("buildProductJsonLd promotion", () => {
  const promoted = (endsAt: string) =>
    makeOffer(1, { variants: [makeVariant(1, 1, { gross: 24000, regularGross: 30000, promotion: { endsAt, lowestPriorGross: 29900 } })] });

  it.each([
    ["an end at midnight Warsaw time (CET) is the previous day", "2026-12-01T00:00:00+01:00", "2026-11-30"],
    ["the same moment written in UTC", "2026-11-30T23:00:00+00:00", "2026-11-30"],
    ["an end at midnight Warsaw time in summer (CEST)", "2026-07-01T00:00:00+02:00", "2026-06-30"],
    ["an end in the evening Warsaw time", "2026-12-01T20:30:00+01:00", "2026-12-01"],
    ["an end at 00:00:01 Warsaw time", "2026-12-01T00:00:01+01:00", "2026-12-01"],
    ["an end that is the first second of a year in Warsaw", "2027-01-01T00:00:00+01:00", "2026-12-31"],
  ])("gives priceValidUntil of endsAt - 1 s in Warsaw: %s", (_name, endsAt, expected) => {
    const ld = buildProductJsonLd(makeDetails(1), promoted(endsAt), STORE);
    expect(ld.offers[0]).toMatchObject({ price: "240.00", priceValidUntil: expected });
  });

  it("has no priceValidUntil outside a promotion and keeps the price", () => {
    const ld = buildProductJsonLd(makeDetails(1), makeOffer(1), STORE);
    expect(ld.offers[0]).not.toHaveProperty("priceValidUntil");
    expect(ld.offers[0]).toMatchObject({ price: "123.00" });
  });

  it("has priceValidUntil also when the promotion has no lowestPriorGross", () => {
    const offer = makeOffer(1, { variants: [makeVariant(1, 1, { gross: 24000, regularGross: 30000, promotion: { endsAt: "2026-12-01T00:00:00+01:00", lowestPriorGross: null } })] });
    expect(buildProductJsonLd(makeDetails(1), offer, STORE).offers[0]).toMatchObject({ price: "240.00", priceValidUntil: "2026-11-30" });
  });
});

describe("buildBreadcrumbJsonLd", () => {
  it("lists All products and the categories of the path with absolute addresses", () => {
    expect(buildBreadcrumbJsonLd(makeDetails(1), STORE)).toEqual({
      "@context": "https://schema.org",
      "@type": "BreadcrumbList",
      itemListElement: [
        { "@type": "ListItem", position: 1, name: "Wszystkie produkty", item: "http://store.test/" },
        { "@type": "ListItem", position: 2, name: "Bieganie", item: "http://store.test/c/bieganie" },
        { "@type": "ListItem", position: 3, name: "Buty", item: "http://store.test/c/bieganie/buty" },
      ],
    });
  });
});

describe("serializeJsonLd", () => {
  it("escapes every < as a Unicode escape, so no text can close the script element", () => {
    const text = serializeJsonLd({ name: "</script><script>alert(1)</script> a<b" });
    expect(text).not.toContain("<");
    expect(text).toContain("\\u003c/script>");
    expect(JSON.parse(text)).toEqual({ name: "</script><script>alert(1)</script> a<b" });
  });
});
