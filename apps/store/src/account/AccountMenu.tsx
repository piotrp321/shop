"use client";

import Link from "next/link";
import { usePathname, useSearchParams } from "next/navigation";
import { LogOutIcon, PackageIcon, UserIcon } from "lucide-react";
import { useEffect, useState } from "react";
import { type AccountSessionResult, loadAccountSession } from "@/api/client";
import { useSiteSwitches } from "@/components/SiteSwitches";
import { Button } from "@/components/ui/button";

const accountLink =
  "inline-flex min-h-11 min-w-11 items-center justify-center gap-2 px-2.5 text-ui underline-offset-4 hover:underline";

/**
 * Account box in the header. Loads the session in the browser from `/auth/session`, so the catalog HTML stays the
 * same for everybody. Shows nothing while loading or when the session cannot be checked. A store without customer
 * accounts (`customerAccounts` of `/api/site`) has no login link; the link also waits for the switches, so it never
 * shows up and disappears.
 */
export function AccountMenu() {
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const { customerAccounts, loaded } = useSiteSwitches();
  const [result, setResult] = useState<AccountSessionResult | null>(null);

  useEffect(() => {
    let active = true;
    void loadAccountSession().then((loaded) => {
      if (active) setResult(loaded);
    });
    return () => {
      active = false;
    };
  }, []);

  if (result?.status !== "ok") {
    return null;
  }

  if (result.session === null) {
    if (!loaded || !customerAccounts) {
      return null;
    }
    const query = searchParams.toString();
    const returnTo = query ? `${pathname}?${query}` : pathname;
    return (
      <a href={`/auth/login?returnTo=${encodeURIComponent(returnTo)}`} className={accountLink}>
        <UserIcon aria-hidden className="size-5" />
        {/* The word is shown where the row has room for it (from 90rem); below it the link is the icon. */}
        <span className="sr-only min-[90rem]:not-sr-only">Zaloguj się</span>
      </a>
    );
  }

  // Icons at every width, with the words for screen readers and as the tooltip (`title`): the three controls of a
  // customer (the name, the orders, logout) do not fit with their words into the one row of the header (the cart, the
  // search and the departments are in it), and the 44 px targets stay.
  return (
    <div className="flex items-center">
      <span className="sr-only">Zalogowany jako {result.session.name}</span>
      <Link href="/konto/zamowienia" title="Moje zamówienia" className={accountLink}>
        <PackageIcon aria-hidden className="size-5" />
        <span className="sr-only">Moje zamówienia</span>
      </Link>
      <form method="post" action="/auth/logout">
        <Button type="submit" variant="ghost" size="sm" title="Wyloguj się" className="h-11 min-w-11 px-2.5">
          <LogOutIcon aria-hidden />
          <span className="sr-only">Wyloguj się</span>
        </Button>
      </form>
    </div>
  );
}
