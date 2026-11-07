import type { Metadata } from "next";
import { loadMenuCategories } from "@/catalog/category-tree";
import { SearchBox } from "@/catalog/SearchBox";
import { SiteHeader } from "@/catalog/SiteHeader";
import "./globals.css";

export const metadata: Metadata = {
  title: "Rytm",
  description: "Sklep internetowy Rytm",
};

/**
 * `search` is the search box of the header (`app/@search`): on `/szukaj` with the phrase, elsewhere empty. The 404 page of
 * an address no route has gets no slot at all, so the layout puts in the empty box itself.
 */
export default async function RootLayout({ children, search }: LayoutProps<"/">) {
  // The tree is read once per request (shared with the page and its metadata). A failure gives a header without the
  // menu, so the pages that do not need the catalog (the cart) keep working.
  const categories = await loadMenuCategories();
  return (
    <html lang="pl">
      <body className="min-h-screen antialiased">
        <SiteHeader categories={categories} search={search ?? <SearchBox />} />
        {children}
      </body>
    </html>
  );
}
