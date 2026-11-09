import type { components } from "@/api/schema";
import { ProductTile } from "./ProductTile";

type ProductListItem = components["schemas"]["ProductListItem"];
type ProductOffer = components["schemas"]["ProductOfferResponse"];

// Matches the grid below: 4 columns from `lg` (1024px), 3 from `sm` (640px), otherwise 2.
const TILE_SIZES = "(min-width: 1024px) 25vw, (min-width: 640px) 33vw, 50vw";
// The first row is above the fold on large screens.
const EAGER_TILES = 4;

/**
 * The tiles of a listing. On the home page (`home`) the names are h3 under the h2 „Wszystkie produkty” and no photo
 * loads with priority (the hero does); elsewhere the names are h2 and the first row does. `data-testid` lets the tests find
 * the grid whatever the level of the names.
 */
export function ProductGrid({
  products,
  offers,
  home = false,
}: {
  products: ProductListItem[];
  offers: Record<string, ProductOffer>;
  home?: boolean;
}) {
  return (
    <ul data-testid="product-grid" className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
      {products.map((product, index) => (
        <li key={product.id}>
          <ProductTile
            product={product}
            offer={offers[product.id]}
            sizes={TILE_SIZES}
            priority={!home && index < EAGER_TILES}
            headingLevel={home ? "h3" : "h2"}
          />
        </li>
      ))}
    </ul>
  );
}
