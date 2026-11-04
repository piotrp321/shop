"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { loadCartSummary } from "@/api/client";
import { subscribeToCartChanges } from "./events";

/**
 * "Koszyk" link of the header with the number of units. The number is loaded in the browser from `/api/cart/summary`,
 * so the catalog HTML stays the same for everybody, and follows the cart through the change event. When it cannot be
 * loaded the number is hidden and the link stays.
 */
export function CartBadge() {
  const [itemCount, setItemCount] = useState<number | null>(null);

  useEffect(() => {
    let active = true;
    // A change announced while the summary was loading is newer than the summary.
    let changed = false;
    void loadCartSummary().then((result) => {
      if (active && !changed && result.status === "ok") setItemCount(result.data.itemCount);
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
    <Link href="/cart" className="flex items-center gap-2 text-sm underline-offset-4 hover:underline">
      Koszyk
      {itemCount === null ? null : (
        <span data-testid="cart-count" className="rounded-full bg-primary px-2 py-0.5 text-xs font-medium text-primary-foreground">
          {itemCount}
        </span>
      )}
    </Link>
  );
}
