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
        <span className="sr-only xl:not-sr-only">Zaloguj się</span>
      </a>
    );
  }

  // Below `xl` the controls are icons (the words stay for screen readers), so the header keeps its first row on 375 px.
  return (
    <div className="flex items-center">
      <span className="sr-only xl:not-sr-only xl:mr-2 xl:text-sm xl:text-muted-foreground">Zalogowany jako {result.session.name}</span>
      <Link href="/konto/zamowienia" className={accountLink}>
        <PackageIcon aria-hidden className="size-5" />
        <span className="sr-only xl:not-sr-only">Moje zamówienia</span>
      </Link>
      <form method="post" action="/auth/logout">
        <Button type="submit" variant="ghost" size="sm" className="h-11 min-w-11 px-2.5">
          <LogOutIcon aria-hidden />
          <span className="sr-only xl:not-sr-only">Wyloguj się</span>
        </Button>
      </form>
    </div>
  );
}
