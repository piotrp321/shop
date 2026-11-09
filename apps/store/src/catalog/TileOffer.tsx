"use client";

import { ChevronDown } from "lucide-react";
import { useEffect, useId, useRef, useState, type ReactNode } from "react";
import type { components } from "@/api/schema";
import { AddToCartButton } from "@/cart/AddToCartButton";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import { AvailabilityLabel, availabilityText } from "./availability-label";
import { formatPrice } from "./format-price";
import { PromotionNotice } from "./PromotionNotice";

type ProductOffer = components["schemas"]["ProductOfferResponse"];

/**
 * The interactive part of a tile of a product with a purchasable variant. With one variant only, „Do koszyka” adds it.
 * With more, the tile has one action, „Wybierz wariant”; it gives way to the variants of the offer in the order of the
 * offer, without a default choice (the first available one gets the focus; a variant that cannot be bought says
 * „niedostępny” and cannot be chosen). One axis shows them as buttons in a row, two or more as a list of whole labels
 * one under another (the offer has the label only, so it is not split into axes). The choice is a group of radio
 * buttons: one stop of the Tab key, arrows move the choice, and it can be changed. Until a choice the tile shows `children`
 * (the price and the level of the product, rendered on the server); after it the price, the promotion notice (if any)
 * and the level of the chosen variant, and „Do koszyka”. A visually hidden live region announces the expansion and every
 * choice. Everything shown comes from the offer of the API.
 */
export function TileOffer({ offer, children }: { offer: ProductOffer; children: ReactNode }) {
  const groupName = useId();
  const [expanded, setExpanded] = useState(false);
  const [chosenId, setChosenId] = useState("");
  const options = useRef<HTMLDivElement>(null);
  const single = offer.variants.length === 1 ? offer.variants[0] : undefined;
  const chosen = single ?? offer.variants.find((variant) => variant.variantId === chosenId && variant.purchasable);
  const axes = offer.variantAxes.length > 0 ? offer.variantAxes.join(", ") : "Wybierz wariant";
  const available = offer.variants.filter((variant) => variant.purchasable).length;

  // „Wybierz wariant” is gone after the click, so the focus cannot stay on it: it goes to the first variant that can be chosen.
  useEffect(() => {
    if (expanded) {
      options.current?.querySelector<HTMLInputElement>("input:not(:disabled)")?.focus();
    }
  }, [expanded]);

  let announcement = "";
  if (single === undefined && chosen !== undefined && chosen.gross !== null) {
    announcement = `Wybrano wariant: ${chosen.label}. ${formatPrice(chosen.gross, offer.currency)}, ${availabilityText(chosen.level)}.`;
  } else if (expanded) {
    announcement = `Rozwinięto wybór wariantu: ${axes}. Dostępne warianty: ${available} z ${offer.variants.length}.`;
  }

  return (
    <>
      {single === undefined && chosen !== undefined && chosen.gross !== null ? (
        <>
          <p data-testid="price" className="text-lg leading-snug font-semibold">
            {formatPrice(chosen.gross, offer.currency)}
          </p>
          <PromotionNotice lowestPriorGross={chosen.promotion?.lowestPriorGross} currency={offer.currency} />
          <AvailabilityLabel level={chosen.level} />
        </>
      ) : (
        children
      )}
      <p role="status" aria-live="polite" aria-atomic="true" data-testid="tile-live" className="sr-only">
        {announcement}
      </p>
      <div className="mt-auto flex flex-col gap-2 pt-1.5">
        {single === undefined && !expanded ? (
          <Button type="button" variant="outline" className="w-full gap-1.5 px-3" onClick={() => setExpanded(true)}>
            Wybierz wariant
            <ChevronDown aria-hidden="true" className="size-4" />
          </Button>
        ) : null}
        {single === undefined && expanded ? (
          <fieldset className="flex min-w-0 flex-col gap-1.5">
            <legend className="mb-1.5 text-xs text-muted-foreground">{axes}</legend>
            <div
              ref={options}
              data-testid="variant-options"
              data-form={offer.variantAxes.length > 1 ? "list" : "buttons"}
              className={cn("flex gap-2", offer.variantAxes.length > 1 ? "flex-col" : "flex-col lg:flex-row lg:flex-wrap")}
            >
              {offer.variants.map((variant) => (
                <label
                  key={variant.variantId}
                  className={cn(
                    "relative flex min-h-11 min-w-0 flex-auto cursor-pointer flex-col justify-center rounded-md border border-input bg-card px-3 py-1 text-center text-ui leading-tight",
                    offer.variantAxes.length > 1 && "lg:flex-none",
                    "has-checked:border-inverse has-checked:bg-inverse has-checked:text-inverse-foreground",
                    "has-disabled:cursor-not-allowed has-disabled:border-dashed has-disabled:bg-transparent has-disabled:text-muted-foreground",
                    "has-focus-visible:outline-3 has-focus-visible:outline-offset-3 has-focus-visible:outline-ring has-focus-visible:outline-solid",
                  )}
                >
                  <input
                    type="radio"
                    name={groupName}
                    value={variant.variantId}
                    checked={chosenId === variant.variantId}
                    disabled={!variant.purchasable}
                    onChange={() => setChosenId(variant.variantId)}
                    className="absolute inset-0 size-full cursor-[inherit] appearance-none opacity-0"
                  />
                  <span className="break-words">{variant.label}</span>
                  {variant.purchasable ? null : <span className="block text-xs">niedostępny</span>}
                </label>
              ))}
            </div>
          </fieldset>
        ) : null}
        {chosen !== undefined ? <AddToCartButton key={chosen.variantId} variantId={chosen.variantId} className="w-full" /> : null}
      </div>
    </>
  );
}
