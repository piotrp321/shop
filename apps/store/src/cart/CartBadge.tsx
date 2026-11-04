"use client";

import Link from "next/link";
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
    <div className="flex items-center gap-4">
      {notice === "merge_truncated" ? (
        <p role="status" className="text-sm text-muted-foreground">
          {MERGE_TRUNCATED}
        </p>
      ) : null}
      <Link href="/cart" className="flex items-center gap-2 text-sm underline-offset-4 hover:underline">
        Koszyk
        {itemCount === null ? null : (
          <span data-testid="cart-count" className="rounded-full bg-primary px-2 py-0.5 text-xs font-medium text-primary-foreground">
            {itemCount}
          </span>
        )}
      </Link>
    </div>
  );
}
