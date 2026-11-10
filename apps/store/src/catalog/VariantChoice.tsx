"use client";

import { useState, type ReactNode } from "react";
import type { components } from "@/api/schema";
import { AddToCartButton } from "@/cart/AddToCartButton";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import { formatPrice } from "./format-price";
import { ProductPrice } from "./ProductPrice";
import { axisValues, buildChoiceVariants, chosenVariant, initialSelection, selectValue, valueState, type Selection } from "./variant-choice";

type ProductDetails = components["schemas"]["ProductDetails"];
type ProductOffer = components["schemas"]["ProductOfferResponse"];

/**
 * The variant choice of the product page and its „Do koszyka” button. One choice per axis, in the order of the axes (the
 * rules are in `variant-choice.ts`); the later axis stays disabled until the earlier ones are chosen. A product with one
 * variant shows its values as text. `children` (the price and the level of the product, rendered on the server) stay until
 * a variant is chosen; then the page shows the price and the level of that variant. Everything shown comes from the
 * offer of the API; the page only matches the chosen values to a variant. The first render is the same for every
 * visitor, so the server can keep the HTML in the ISR cache.
 */
export function VariantChoice({ details, offer, children }: { details: ProductDetails; offer: ProductOffer; children: ReactNode }) {
  const variants = buildChoiceVariants(details, offer);
  const axes = details.variantAxes;
  const [selection, setSelection] = useState<Selection>(() => initialSelection(variants, axes.length));
  const chosen = chosenVariant(variants, selection);
  const single = variants.length === 1;

  return (
    <>
      {chosen !== undefined && chosen.gross !== null ? (
        <ProductPrice price={formatPrice(chosen.gross, offer.currency)} lowestPriorGross={chosen.lowestPriorGross} currency={offer.currency} level={chosen.level} />
      ) : (
        children
      )}
      {single ? (
        <ul className="flex flex-col gap-1 text-base">
          {axes.map((axis, index) => (
            <li key={axis}>
              {axis}: {variants[0].values[index]}
            </li>
          ))}
        </ul>
      ) : (
        axes.map((axis, index) => (
          <fieldset key={axis} className="flex flex-col gap-2">
            <legend className="mb-2 text-ui font-semibold">{axis}</legend>
            <div className="flex flex-wrap gap-2.5">
              {axisValues(variants, index).map((value) => {
                const state = valueState(variants, selection, index, value);
                const isChosen = selection[index] === value;
                return (
                  <Button
                    key={value}
                    type="button"
                    variant="outline"
                    aria-pressed={isChosen}
                    disabled={state !== "enabled"}
                    onClick={() => setSelection(selectValue(variants, selection, index, value))}
                    className={cn(
                      "min-w-11 border-input bg-card px-4 font-normal",
                      isChosen && "border-inverse bg-inverse text-inverse-foreground hover:bg-inverse disabled:border-inverse disabled:bg-inverse disabled:text-inverse-foreground",
                      state === "unavailable" && "border-dashed",
                    )}
                  >
                    {value}
                    {state === "unavailable" ? (
                      <>
                        {" "}
                        <span className="text-xs font-normal">niedostępny</span>
                      </>
                    ) : null}
                  </Button>
                );
              })}
            </div>
          </fieldset>
        ))
      )}
      <AddToCartButton key={chosen?.variantId ?? ""} variantId={chosen?.variantId ?? null} size="lg" className="mt-2" />
    </>
  );
}
