"use client";

import { useState } from "react";
import { addCartItem, type CartFailure } from "@/api/client";
import { Button } from "@/components/ui/button";
import { announceCartChange } from "./events";
import { PRODUCT_GONE, SAVE_FAILED, SessionExpired } from "./messages";

type Feedback = { kind: "added" } | { kind: "error"; failure: CartFailure; returnTo: string };

/**
 * Adds one unit of the variant to the cart; without a variant (none chosen yet) the button is disabled. The API decides
 * whether it can be added (limits, price, availability); its answer is shown here and the header counter gets the
 * number of units from it.
 */
export function AddToCartButton({ variantId }: { variantId: string | null }) {
  const [pending, setPending] = useState(false);
  const [feedback, setFeedback] = useState<Feedback | null>(null);

  async function add() {
    if (variantId === null) {
      return;
    }
    setPending(true);
    setFeedback(null);
    const result = await addCartItem(variantId, 1);
    if (result.status === "ok") {
      announceCartChange(result.data.totals.itemCount);
      setFeedback({ kind: "added" });
    } else {
      setFeedback({ kind: "error", failure: result, returnTo: window.location.pathname + window.location.search });
    }
    setPending(false);
  }

  return (
    <div className="flex flex-col gap-1">
      <Button type="button" size="sm" disabled={pending || variantId === null} onClick={() => void add()}>
        Do koszyka
      </Button>
      {feedback?.kind === "added" ? (
        <p role="status" className="text-xs text-primary">
          Dodano do koszyka
        </p>
      ) : null}
      {feedback?.kind === "error" ? (
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
