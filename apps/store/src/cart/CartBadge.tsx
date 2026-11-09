"use client";

import Link from "next/link";
import { ShoppingCartIcon } from "lucide-react";
import { useEffect, useState } from "react";
import { type CartSummaryBody, loadCartSummary } from "@/api/client";
import { subscribeToCartChanges } from "./events";
import { MERGE_TRUNCATED } from "./messages";

/**
 * "Koszyk" link of the header with the number of units. The number is loaded in the browser from `/api/cart/summary`,
 * so the catalog HTML stays the same for everybody, and follows the cart through the change event. When it cannot be
 * loaded the number is hidden and the link stays. The summary carries the notice of a guest cart that did not fit
 * into the customer's at the login once; the badge shows it next to the link.
 */
export function CartBadge() {
  const [itemCount, setItemCount] = useState<number | null>(null);
  const [notice, setNotice] = useState<CartSummaryBody["notice"]>(null);

  useEffect(() => {
    let active = true;
    // A change announced while the summary was loading is newer than the summary.
    let changed = false;
    void loadCartSummary().then((result) => {
      if (!active || result.status !== "ok") return;
      setNotice(result.data.notice);
      if (!changed) setItemCount(result.data.itemCount);
    });
    const unsubscribe = subscribeToCartChanges((count) => {
      changed = true;
      setItemCount(count);
    });
    return () => {
      active = false;
      unsubscribe();
    };
  }, []);

  return (
    <div className="flex items-center">
      {notice === "merge_truncated" ? (
        // A bar under the header, so the long text does not push the icons of the header around.
        <p role="status" className="absolute inset-x-gutter top-full z-40 border border-input bg-card px-3.5 py-2 text-sm text-foreground">
          {MERGE_TRUNCATED}
        </p>
      ) : null}
      <Link
        href="/cart"
        className="relative inline-flex min-h-11 min-w-11 items-center justify-center gap-2 px-2.5 text-ui underline-offset-4 hover:underline"
      >
        <ShoppingCartIcon aria-hidden className="size-5" />
        {/* The word is shown from `lg`; below it the cart is the icon (and the count above it). */}
        <span className="sr-only lg:not-sr-only">Koszyk</span>
        {itemCount === null ? null : (
          <span
            data-testid="cart-count"
            className="absolute top-0.5 right-0 inline-grid h-5.5 min-w-5.5 place-items-center rounded-full bg-primary px-1.5 text-xs font-bold text-primary-foreground lg:static"
          >
            {itemCount}
          </span>
        )}
      </Link>
    </div>
  );
}
