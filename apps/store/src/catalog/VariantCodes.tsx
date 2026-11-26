import type { components } from "@/api/schema";

type DetailsVariant = components["schemas"]["ProductDetailsVariant"];

/**
 * „Kod produktu: <SKU>” and „EAN: <EAN>” of a variant, each only when it is not empty; nothing when both are empty. The EAN
 * is shown whatever it is: the card does not judge it (the structured data takes the `gtin` of the API instead).
 */
export function VariantCodes({ variant }: { variant: Pick<DetailsVariant, "sku" | "ean"> }) {
  if (variant.sku === "" && variant.ean === "") {
    return null;
  }
  return (
    <div data-testid="variant-codes" className="flex flex-col gap-0.5 text-sm text-muted-foreground [overflow-wrap:anywhere]">
      {variant.sku !== "" && <p>Kod produktu: {variant.sku}</p>}
      {variant.ean !== "" && <p>EAN: {variant.ean}</p>}
    </div>
  );
}
