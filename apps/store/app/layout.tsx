import type { Metadata } from "next";
import { loadMenuCategories } from "@/catalog/category-tree";
import { SearchBox } from "@/catalog/SearchBox";
import { SiteFooter } from "@/catalog/SiteFooter";
import { SiteHeader } from "@/catalog/SiteHeader";
import { STORE_NAME } from "@/site";
import { DemoNotice, SiteSwitchesProvider } from "@/components/SiteSwitches";
import { fontVariables } from "@/styles/fonts";
import "./globals.css";

export const metadata: Metadata = {
  title: STORE_NAME,
  description: `Sklep internetowy ${STORE_NAME}`,
};

/**
 * `search` is the search box of the header (`app/@search`): on `/szukaj` with the phrase, elsewhere empty. The 404 page of
 * an address no route has gets no slot at all, so the layout puts in the empty box itself.
 */
export default async function RootLayout({ children, search }: LayoutProps<"/">) {
  // The tree is read once per request (shared with the page and its metadata) and serves the header and the footer. A failure gives a header without the
  // menu, so the pages that do not need the catalog (the cart) keep working.
  const categories = await loadMenuCategories();
  return (
    <html lang="pl" className={fontVariables}>
      <body className="min-h-screen antialiased">
        {/* The switches of the deployment (`/api/site`) are read in the browser, so this HTML is the same everywhere. */}
        <SiteSwitchesProvider>
          {/* The page grows, so the footer is at the bottom of a short page (the 404). */}
          <div className="flex min-h-screen flex-col">
            <DemoNotice />
            <SiteHeader categories={categories} search={search ?? <SearchBox />} />
            <div className="flex-1">{children}</div>
            <SiteFooter categories={categories} />
          </div>
        </SiteSwitchesProvider>
      </body>
    </html>
  );
}
