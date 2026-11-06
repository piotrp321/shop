"use client";

import { useRouter } from "next/navigation";
import { createContext, type ReactNode, useContext, useOptimistic, useTransition } from "react";
import { listingHref, type ListingState } from "./listing-query";

type Listing = {
  basePath: string;
  /** What the page shows until the new one arrives: the last change made, in the panel or in the sort. */
  state: ListingState;
  /** Navigates to the listing with the given state and shows it at once. */
  go: (next: ListingState) => void;
};

const ListingContext = createContext<Listing | null>(null);

/**
 * The state of a listing shared by the filter panel and the sort. A change builds its address from the last change, not
 * from the state of the page it started on, so changes made in quick succession do not overwrite each other.
 */
export function ListingProvider({ basePath, state, children }: { basePath: string; state: ListingState; children: ReactNode }) {
  const router = useRouter();
  const [, startTransition] = useTransition();
  const [current, setCurrent] = useOptimistic(state);
  const go = (next: ListingState) => {
    startTransition(() => {
      setCurrent(next);
      router.push(listingHref(basePath, next));
    });
  };
  return <ListingContext value={{ basePath, state: current, go }}>{children}</ListingContext>;
}

export function useListing(): Listing {
  const listing = useContext(ListingContext);
  if (!listing) throw new Error("useListing needs a ListingProvider");
  return listing;
}
