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
