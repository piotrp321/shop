import Link from "next/link";
import { Suspense } from "react";
import { AccountMenu } from "@/account/AccountMenu";
import type { components } from "@/api/schema";
import { CartBadge } from "@/cart/CartBadge";
import { MegaMenu } from "./MegaMenu";
import { SearchBox } from "./SearchBox";

type CategoryNode = components["schemas"]["CategoryNode"];

/**
 * The header of every page: the logo, the search box, the cart and the account, and the department menu under them. `categories` is
 * `null` when the tree could not be read, and the header then has no menu. Nothing here depends on the session.
 */
export function SiteHeader({ categories }: { categories: CategoryNode[] | null }) {
  return (
    <header className="border-b border-border">
      <div className="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-4">
        <Link href="/" className="text-xl font-semibold">
          Rytm
        </Link>
        <div className="flex items-center gap-4">
          <SearchBox />
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
