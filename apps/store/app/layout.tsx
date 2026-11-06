import type { Metadata } from "next";
import { loadMenuCategories } from "@/catalog/category-tree";
import { SiteHeader } from "@/catalog/SiteHeader";
import "./globals.css";

export const metadata: Metadata = {
  title: "Rytm",
  description: "Sklep internetowy Rytm",
};

export default async function RootLayout({ children }: LayoutProps<"/">) {
  // The tree is read once per request (shared with the page and its metadata). A failure gives a header without the
  // menu, so the pages that do not need the catalog (the cart) keep working.
  const categories = await loadMenuCategories();
  return (
    <html lang="pl">
      <body className="min-h-screen antialiased">
        <SiteHeader categories={categories} />
        {children}
      </body>
    </html>
  );
}
