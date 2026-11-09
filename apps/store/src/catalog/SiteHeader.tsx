import Link from "next/link";
import { type ReactNode, Suspense } from "react";
import { AccountMenu } from "@/account/AccountMenu";
import type { components } from "@/api/schema";
import { CartBadge } from "@/cart/CartBadge";
import { STORE_NAME } from "@/site";
import { MegaMenu } from "./MegaMenu";

type CategoryNode = components["schemas"]["CategoryNode"];

/**
 * The header of every page: the wordmark, the department menu, the search box, the cart and the account. `categories`
 * is `null` when the tree could not be read, and the header then has no menu. `search` is the search box
 * (`SearchBox`), the `@search` slot of the root layout, so that `/szukaj` can put its phrase in. Nothing here depends
 * on the session.
 *
 * One row from `lg` (wordmark, departments, search, cart, account); below it two rows: the wordmark, „Menu”, the cart
 * and the account, then the search box on the full width. The notice of the cart (`CartBadge`) is one more row under
 * them, in the flow, only when there is one. The document order is the one of the phone (wordmark, menu, cart and
 * account, search); on a wide screen the grid areas put the search box before the cart and `reading-flow: grid-rows`
 * makes the tab order (and the order of assistive technology) follow the grid, so it is the visual order (WCAG 2.4.3).
 * The children of the grid are the direct children of the container, which `reading-flow` needs (`CartBadge` renders
 * two of them, the account is its child). A browser without `reading-flow` keeps the document order on a wide screen.
 * The search form is in the HTML once.
 */
export function SiteHeader({ categories, search }: { categories: CategoryNode[] | null; search: ReactNode }) {
  return (
    <header className="relative z-30 border-b border-border bg-background">
      <div
        className={
          "mx-auto grid [reading-flow:grid-rows] max-w-content grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-2.5 px-gutter py-3 " +
          "[grid-template-areas:'brand_nav_utils''search_search_search'] " +
          "lg:grid-cols-[auto_minmax(0,1fr)_12rem_auto] xl:grid-cols-[auto_minmax(0,1fr)_16rem_auto] lg:gap-x-6 lg:py-4.5 lg:[grid-template-areas:'brand_nav_search_utils']"
        }
      >
        <Link href="/" className="[grid-area:brand] text-2xl leading-none font-bold tracking-brand">
          {STORE_NAME}
        </Link>
        {categories !== null && categories.length > 0 && <MegaMenu categories={categories} />}
        {/* The counter loads in the browser, so the HTML is the same for everybody. The account box (it reads the URL for
            returnTo and loads the session in the browser) sits in the same cell. */}
        <CartBadge>
          <Suspense fallback={null}>
            <AccountMenu />
          </Suspense>
        </CartBadge>
        <div className="min-w-0 [grid-area:search]">{search}</div>
      </div>
    </header>
  );
}
