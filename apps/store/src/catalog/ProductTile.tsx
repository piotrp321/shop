import type { components } from "@/api/schema";
import { Card, CardContent } from "@/components/ui/card";
import { formatPrice } from "./format-price";
import { ProductPhoto } from "./ProductPhoto";

type ProductListItem = components["schemas"]["ProductListItem"];
type PriceItem = components["schemas"]["PriceItem"];
type AvailabilityItem = components["schemas"]["AvailabilityItemResponse"];

// The level is decided by the API; the store only names it.
const AVAILABILITY_LABELS: Record<AvailabilityItem["level"], { text: string; className: string }> = {
  in_stock: { text: "Dostępny", className: "text-primary" },
  low_stock: { text: "Ostatnie sztuki", className: "text-destructive" },
  out_of_stock: { text: "Niedostępny", className: "text-muted-foreground" },
};

export function ProductTile({
  product,
  price,
  availability,
  sizes,
  priority,
}: {
  product: ProductListItem;
  price?: PriceItem;
  availability?: AvailabilityItem;
  sizes: string;
  priority?: boolean;
}) {
  return (
    <Card className="h-full pt-0">
      <ProductPhoto image={product.image} sizes={sizes} priority={priority} />
      <CardContent className="flex flex-col gap-1">
        <h2 className="text-sm leading-snug font-medium">{product.name}</h2>
        <p data-testid="price" className={price ? "text-sm font-semibold" : "text-sm text-muted-foreground"}>
          {price ? formatPrice(price.gross, price.currency) : "Cena niedostępna"}
        </p>
        {availability ? (
          <p data-testid="availability" className={`text-xs font-medium ${AVAILABILITY_LABELS[availability.level].className}`}>{AVAILABILITY_LABELS[availability.level].text}</p>
        ) : null}
      </CardContent>
    </Card>
  );
}
