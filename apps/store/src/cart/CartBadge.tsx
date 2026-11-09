"use client";

import Link from "next/link";
import { ShoppingCartIcon } from "lucide-react";
import { type ReactNode, useEffect, useState } from "react";
import { type CartSummaryBody, loadCartSummary } from "@/api/client";
import { subscribeToCartChanges } from "./events";
import { MERGE_TRUNCATED } from "./messages";

/**
 * "Koszyk" link of the header with the number of units. The number is loaded in the browser from `/api/cart/summary`,
 * so the catalog HTML stays the same for everybody, and follows the cart through the change event. When it cannot be
 * loaded the number is hidden and the link stays. The summary carries the notice of a guest cart that did not fit
 * into the customer's at the login once; the badge shows it as a row of the header under the others (full width, in
 * the flow, so it never covers the page).
 *
 * Renders two direct children of the header grid: the `utils` cell (the link and `children`, the account box that the
 * header puts next to it) and, when there is a notice, the row of the notice. They are the grid's own children because
 * `reading-flow: grid-rows` of the header orders only those.
 */
export function CartBadge({ children }: { children?: ReactNode }) {
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
    <>
      <div className="flex items-center [grid-area:utils]">
        <Link
          href="/cart"
          className="relative inline-flex min-h-11 min-w-11 items-center justify-center gap-2 px-2.5 text-ui underline-offset-4 hover:underline"
        >
          <ShoppingCartIcon aria-hidden className="size-5" />
          {/* The word is shown from `xl`; below it the cart is the icon (and the count over it). */}
          <span className="sr-only xl:not-sr-only">Koszyk</span>
          {itemCount === null ? null : (
            <span
              data-testid="cart-count"
              className="absolute top-0.5 right-0 inline-grid h-5.5 min-w-5.5 place-items-center rounded-full bg-primary px-1.5 text-xs font-bold text-primary-foreground xl:static"
            >
              {itemCount}
            </span>
          )}
        </Link>
        {children}
      </div>
      {notice === "merge_truncated" ? (
        // The row under the others (the third on a phone, the second on a wide screen), over the whole width.
        <p role="status" className="col-span-full row-start-3 border border-input bg-card px-3.5 py-2 text-sm text-foreground lg:row-start-2">
          {MERGE_TRUNCATED}
        </p>
      ) : null}
    </>
  );
}
