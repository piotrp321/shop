import type { components } from "@/api/schema";
import { Card, CardContent } from "@/components/ui/card";
import { ProductPhoto } from "./ProductPhoto";

type ProductListItem = components["schemas"]["ProductListItem"];

export function ProductTile({ product, sizes, priority }: { product: ProductListItem; sizes: string; priority?: boolean }) {
  return (
    <Card className="h-full pt-0">
      <ProductPhoto image={product.image} sizes={sizes} priority={priority} />
      <CardContent>
        <h2 className="text-sm leading-snug font-medium">{product.name}</h2>
      </CardContent>
    </Card>
  );
}
