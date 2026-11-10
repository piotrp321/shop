"use client";

import { useState } from "react";
import { addCartItem, type CartFailure } from "@/api/client";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import { announceCartChange } from "./events";
import { PRODUCT_GONE, SAVE_FAILED, SessionExpired } from "./messages";

// `variantId`: the variant the request was for; the message is shown only while that variant is the one on the button.
type Feedback = { kind: "added"; variantId: string } | { kind: "error"; variantId: string; failure: CartFailure; returnTo: string };

/**
 * Adds one unit of the variant to the cart (`size="lg"` is the full-width button of the product page); without a variant (none chosen yet) the button is disabled. The API decides
 * whether it can be added (limits, price, availability); its answer is shown here and the header counter gets the
 * number of units from it.
 */
export function AddToCartButton({ variantId, size = "sm", className }: { variantId: string | null; size?: "sm" | "lg"; className?: string }) {
  const [pending, setPending] = useState(false);
  const [feedback, setFeedback] = useState<Feedback | null>(null);
  // The message belongs to the variant it was made for: another variant (the tile lets the choice change) starts without one,
  // while a running request keeps the button blocked.
  const [shownFor, setShownFor] = useState(variantId);
  if (shownFor !== variantId) {
    setShownFor(variantId);
    setFeedback(null);
  }

  async function add() {
    if (variantId === null) {
      return;
    }
    setPending(true);
    setFeedback(null);
    const result = await addCartItem(variantId, 1);
    if (result.status === "ok") {
      announceCartChange(result.data.totals.itemCount);
      setFeedback({ kind: "added", variantId });
    } else {
      setFeedback({ kind: "error", variantId, failure: result, returnTo: window.location.pathname + window.location.search });
    }
    setPending(false);
  }

  return (
    <div className={cn("flex flex-col gap-1", className)}>
      <Button type="button" size={size} disabled={pending || variantId === null} onClick={() => void add()}>
        Do koszyka
      </Button>
      {feedback?.kind === "added" && feedback.variantId === variantId ? (
        <p role="status" className="text-xs text-success">
          Dodano do koszyka
        </p>
      ) : null}
      {feedback?.kind === "error" && feedback.variantId === variantId ? (
        <p role="alert" className="text-xs text-destructive">
          {errorMessage(feedback)}
        </p>
      ) : null}
    </div>
  );
}

function errorMessage({ failure, returnTo }: { failure: CartFailure; returnTo: string }) {
  switch (failure.status) {
    case "conflict":
      return failure.detail ?? SAVE_FAILED;
    case "not_found":
      return PRODUCT_GONE;
    case "unauthorized":
      return <SessionExpired returnTo={returnTo} />;
    case "failed":
      return SAVE_FAILED;
  }
}
