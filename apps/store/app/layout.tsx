import type { Metadata } from "next";
import Link from "next/link";
import { Suspense } from "react";
import { AccountMenu } from "@/account/AccountMenu";
import { CartBadge } from "@/cart/CartBadge";
import "./globals.css";

export const metadata: Metadata = {
  title: "Rytm",
  description: "Sklep internetowy Rytm",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="pl">
      <body className="min-h-screen antialiased">
        <header className="border-b border-border">
          <div className="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-4">
            <Link href="/" className="text-xl font-semibold">
              Rytm
            </Link>
            <div className="flex items-center gap-4">
              {/* The counter loads in the browser, so the HTML is the same for everybody. */}
              <CartBadge />
              {/* The account box reads the URL for returnTo and loads the session in the browser. */}
              <Suspense fallback={null}>
                <AccountMenu />
              </Suspense>
            </div>
          </div>
        </header>
        {children}
      </body>
    </html>
  );
}
