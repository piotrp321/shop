import Link from "next/link";
import type { components } from "@/api/schema";
import { ProductTile } from "./ProductTile";

type ProductListItem = components["schemas"]["ProductListItem"];

// Matches the grid below: 4 columns from `lg` (1024px), 3 from `sm` (640px), otherwise 2.
const TILE_SIZES = "(min-width: 1024px) 25vw, (min-width: 640px) 33vw, 50vw";
// The first row is above the fold on large screens.
const EAGER_TILES = 4;

export function ProductGrid({ products }: { products: ProductListItem[] }) {
  if (products.length === 0) {
    return (
      <div className="flex flex-col items-start gap-2 py-8">
        <p>Brak produktów do wyświetlenia.</p>
        <Link href="/" className="text-sm underline underline-offset-4">
          Zobacz cały katalog
        </Link>
      </div>
    );
  }
  return (
    <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
      {products.map((product, index) => (
        <li key={product.id}>
          <ProductTile product={product} sizes={TILE_SIZES} priority={index < EAGER_TILES} />
        </li>
      ))}
    </ul>
  );
}
