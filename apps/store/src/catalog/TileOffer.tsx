"use client";

import { useId, useState, type ReactNode } from "react";
import type { components } from "@/api/schema";
import { AddToCartButton } from "@/cart/AddToCartButton";
import { AvailabilityLabel } from "./availability-label";
import { formatPrice } from "./format-price";

type ProductOffer = components["schemas"]["ProductOfferResponse"];

/**
 * The interactive part of a tile of a product with a purchasable variant. With one variant only, the button adds it.
 * With more, a list offers every variant in the order of the offer, without a default choice; a variant that cannot be
 * bought is listed as „niedostępny” and cannot be chosen. Until a choice, the tile shows `children` (the price and the
 * level of the product, rendered on the server) and the button is disabled; after it, the price and the level of the
 * chosen variant. Everything shown comes from the offer of the API.
 */
export function TileOffer({ offer, children }: { offer: ProductOffer; children: ReactNode }) {
  const listId = useId();
  const [chosenId, setChosenId] = useState("");
  const single = offer.variants.length === 1 ? offer.variants[0] : undefined;
  const chosen = single ?? offer.variants.find((variant) => variant.variantId === chosenId && variant.purchasable);

  return (
    <>
      {single === undefined && chosen !== undefined && chosen.gross !== null ? (
        <>
          <p data-testid="price" className="text-sm font-semibold">
            {formatPrice(chosen.gross, offer.currency)}
          </p>
          <AvailabilityLabel level={chosen.level} />
        </>
      ) : (
        children
      )}
      {single === undefined ? (
        <div className="flex flex-col gap-1">
          <label htmlFor={listId} className="text-xs text-muted-foreground">
            {offer.variantAxes.length > 0 ? offer.variantAxes.join(", ") : "Wybierz wariant"}
          </label>
          <select
            id={listId}
            value={chosenId}
            onChange={(event) => setChosenId(event.target.value)}
            className="h-8 rounded-md border border-input bg-background px-2 text-sm text-foreground"
          >
            <option value="" disabled>
              Wybierz wariant
            </option>
            {offer.variants.map((variant) => (
              <option key={variant.variantId} value={variant.variantId} disabled={!variant.purchasable}>
                {variant.purchasable ? variant.label : `${variant.label} – niedostępny`}
              </option>
            ))}
          </select>
        </div>
      ) : null}
      <AddToCartButton variantId={chosen?.variantId ?? null} />
    </>
  );
}
