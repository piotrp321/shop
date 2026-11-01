import type { components } from "@/api/schema";
import { Card, CardContent } from "@/components/ui/card";
import { formatPrice } from "./format-price";
import { ProductPhoto } from "./ProductPhoto";

type ProductListItem = components["schemas"]["ProductListItem"];
type PriceItem = components["schemas"]["PriceItem"];

export function ProductTile({
  product,
  price,
  sizes,
  priority,
}: {
  product: ProductListItem;
  price?: PriceItem;
  sizes: string;
  priority?: boolean;
}) {
  return (
    <Card className="h-full pt-0">
      <ProductPhoto image={product.image} sizes={sizes} priority={priority} />
      <CardContent className="flex flex-col gap-1">
        <h2 className="text-sm leading-snug font-medium">{product.name}</h2>
        <p className={price ? "text-sm font-semibold" : "text-sm text-muted-foreground"}>
          {price ? formatPrice(price.gross, price.currency) : "Cena niedostępna"}
        </p>
      </CardContent>
    </Card>
  );
}
