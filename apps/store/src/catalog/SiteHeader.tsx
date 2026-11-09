import Link from "next/link";
import { type ReactNode, Suspense } from "react";
import { AccountMenu } from "@/account/AccountMenu";
import type { components } from "@/api/schema";
import { CartBadge } from "@/cart/CartBadge";
import { STORE_NAME } from "@/site";
import { MegaMenu } from "./MegaMenu";

type CategoryNode = components["schemas"]["CategoryNode"];

/**
 * The header of every page: the logo, the search box, the cart and the account, and the department menu under them. `categories` is
 * `null` when the tree could not be read, and the header then has no menu. `search` is the search box (`SearchBox`), the
 * `@search` slot of the root layout, so that `/szukaj` can put its phrase in. Nothing here depends on the session.
 */
export function SiteHeader({ categories, search }: { categories: CategoryNode[] | null; search: ReactNode }) {
  return (
    <header className="border-b border-border">
      {/* Below `sm` the search box takes a row of its own under the logo, the cart and the account. */}
      <div className="mx-auto flex max-w-7xl flex-wrap items-center gap-x-4 gap-y-3 px-4 py-4">
        <Link href="/" className="text-xl font-semibold">
          {STORE_NAME}
        </Link>
        <div className="order-last w-full sm:order-none sm:ml-auto sm:w-auto">
          {search}
        </div>
        <div className="ml-auto flex items-center gap-4 sm:ml-0">
          {/* The counter loads in the browser, so the HTML is the same for everybody. */}
          <CartBadge />
          {/* The account box reads the URL for returnTo and loads the session in the browser. */}
          <Suspense fallback={null}>
            <AccountMenu />
          </Suspense>
        </div>
      </div>
      {categories !== null && categories.length > 0 && <MegaMenu categories={categories} />}
    </header>
  );
}
