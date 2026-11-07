import type { Metadata } from "next";
import { loadMenuCategories } from "@/catalog/category-tree";
import { SiteHeader } from "@/catalog/SiteHeader";
import { DemoNotice, SiteSwitchesProvider } from "@/components/SiteSwitches";
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
        {/* The switches of the deployment (`/api/site`) are read in the browser, so this HTML is the same everywhere. */}
        <SiteSwitchesProvider>
          <DemoNotice />
          <SiteHeader categories={categories} />
          {children}
        </SiteSwitchesProvider>
      </body>
    </html>
  );
}
