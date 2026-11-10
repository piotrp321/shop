import Link from "next/link";
import { type ReactNode, Suspense } from "react";
import { AccountMenu } from "@/account/AccountMenu";
import type { components } from "@/api/schema";
import { CartBadge } from "@/cart/CartBadge";
import { STORE_NAME } from "@/site";
import { MegaMenu } from "./MegaMenu";

type CategoryNode = components["schemas"]["CategoryNode"];

/**
 * The header of every page: the wordmark, the department menu, the cart, the account and the search box. `categories`
 * is `null` when the tree could not be read, and the header then has no menu. `search` is the search box
 * (`SearchBox`), the `@search` slot of the root layout, so that `/szukaj` can put its phrase in. Nothing here depends
 * on the session.
 *
 * The document order is the visual order at every width (WCAG 2.4.3), so the tab order needs no CSS that reorders it and
 * is the same in every browser: the wordmark, the departments („Menu” below `lg`), the cart, the account, the search. From
 * `lg` (1024 px) it is one row in that order; below it the first row is the wordmark, „Menu”, the cart and the account and
 * the search box has a row of its own under it, on the full width. The notice of the cart (`CartBadge`) is the last child,
 * one more row in the flow under the others, only when there is one. There are no `order` utilities and no grid areas that
 * move an element away from its place in the document. The search form is in the HTML once; where the row has no room for
 * it (1024 to 1279 px) `SearchBox` is a button that opens it as a panel under the header.
 */
export function SiteHeader({ categories, search }: { categories: CategoryNode[] | null; search: ReactNode }) {
  return (
    <header className="relative z-30 border-b border-border bg-background">
      <div
        className={
          "mx-auto grid max-w-content grid-cols-[auto_minmax(0,1fr)_auto_auto] items-center gap-y-2.5 px-gutter py-3 " +
          "[grid-template-areas:'brand_nav_cart_account''search_search_search_search'] " +
          "lg:grid-cols-[auto_minmax(0,1fr)_auto_auto_auto] lg:py-4.5 lg:[grid-template-areas:'brand_nav_cart_account_search'] " +
          "xl:grid-cols-[auto_minmax(0,1fr)_auto_auto_14rem]"
        }
      >
        <Link href="/" className="mr-3 [grid-area:brand] text-2xl leading-none font-bold tracking-brand lg:mr-4 xl:mr-6">
          {STORE_NAME}
        </Link>
        {categories !== null && categories.length > 0 && <MegaMenu categories={categories} />}
        {/* The counter loads in the browser, so the HTML is the same for everybody. `CartBadge` renders its link first, then
            what follows it in the row (the account and the search), and last the notice of the cart, so the notice is the
            last row of the header in the document too. The account box reads the URL for returnTo and loads the session
            in the browser. */}
        <CartBadge>
          <div className="flex [grid-area:account]">
            <Suspense fallback={null}>
              <AccountMenu />
            </Suspense>
          </div>
          {search}
        </CartBadge>
      </div>
    </header>
  );
}
