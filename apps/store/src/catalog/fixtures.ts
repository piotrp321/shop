import type { components } from "@/api/schema";

type ProductImage = components["schemas"]["ProductImage"];
type ProductListItem = components["schemas"]["ProductListItem"];
type CategoryNode = components["schemas"]["CategoryNode"];

export const WIDTHS = [256, 384, 512, 768] as const;

export function makeImage(overrides: Partial<ProductImage> = {}): ProductImage {
  return {
    alt: "Biegaczka na leśnej ścieżce",
    size: "listing",
    aspectRatio: "1:1",
    variants: {
      webp: WIDTHS.map((width) => ({ width, url: `http://imgproxy.test/u${width}/plain.webp` })),
    },
    ...overrides,
  };
}

export function makeProduct(n: number, overrides: Partial<ProductListItem> = {}): ProductListItem {
  return {
    id: `00000000-0000-4000-8000-${String(n).padStart(12, "0")}`,
    name: `Produkt ${n}`,
    slug: `produkt-${n}`,
    shortDescription: `Opis ${n}`,
    image: makeImage({ alt: `Zdjęcie ${n}` }),
    ...overrides,
  };
}

export function node(id: string, name: string, children: CategoryNode[] = []): CategoryNode {
  return { id, name, children };
}

// Two branches: Bieganie > Buty > Trailowe, and Rower > Kaski.
export const TREE: CategoryNode[] = [
  node("bieganie", "Bieganie", [node("buty", "Buty", [node("trailowe", "Trailowe")]), node("odziez", "Odzież")]),
  node("rower", "Rower", [node("kaski", "Kaski")]),
];

type ProductOffer = components["schemas"]["ProductOfferResponse"];
type VariantOffer = components["schemas"]["VariantOfferResponse"];

/** A variant id of product n: never the id of the product. */
export function variantId(n: number, v: number): string {
  return `00000000-0000-4000-9000-${String(n * 100 + v).padStart(12, "0")}`;
}

export function makeVariant(n: number, v: number, overrides: Partial<VariantOffer> = {}): VariantOffer {
  return { variantId: variantId(n, v), label: "", gross: 12300, level: "in_stock", purchasable: true, ...overrides };
}

/** The offer of product n; by default one purchasable default variant at 123 zł. */
export function makeOffer(n: number, overrides: Partial<ProductOffer> = {}): ProductOffer {
  return {
    productId: makeProduct(n).id,
    variantAxes: [],
    fromGross: 12300,
    hasPriceRange: false,
    currency: "PLN",
    level: "in_stock",
    variants: [makeVariant(n, 1)],
    ...overrides,
  };
}
